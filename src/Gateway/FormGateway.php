<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

use Admin\DB\ChangelogRepository;
use DoryoApi\Http\ApiException;
use Nette\Application\UI\Presenter;
use Nette\Forms\Controls\BaseControl;
use Nette\Forms\Controls\Button;
use Nette\Forms\Controls\Checkbox;
use Nette\Forms\Controls\HiddenField;
use Nette\Forms\Controls\MultiChoiceControl;
use Nette\Forms\Controls\SubmitButton;
use Nette\Forms\Controls\UploadControl;
use Nette\Forms\Form;
use Nette\Utils\Arrays;
use Nette\Utils\Strings;
use StORM\DIConnection;
use StORM\Entity;

/**
 * Brána nad detailovými formuláři administrace: schéma, zkušební běh a ostrý zápis.
 *
 * Formuláře jsou introspektovatelné i spustitelné, takže jedna implementace pokryje všechny
 * stránky administrace včetně těch, jejichž formulář i logika jsou ve vendoru — bez duplikace
 * a bez driftu, když někdo formulář změní.
 *
 * Zápis je vždycky na dvě kola: `dryRun` jen validuje a spočítá diff, ostrý zápis chce
 * {@see PreviewToken} z toho náhledu. Viz `docs/doryo-api-zapis.md`.
 */
final class FormGateway
{
	public function __construct(
		private PresenterRunner $runner,
		private PreviewToken $previewToken,
		private ChangelogRepository $changelogRepository,
		private DIConnection $connection,
	) {
	}

	/**
	 * Schéma formuláře a aktuální hodnoty záznamu. Bez `id` jde o schéma prázdného formuláře
	 * pro založení nového záznamu — to umí jen formulář, který to má v konfiguraci povolené.
	 * @return array<string, mixed>
	 */
	public function schema(FormTarget $target, ?string $id): array
	{
		if ($id === null) {
			self::assertCreatable($target);
		}

		return $this->runner->withAdministrator(function () use ($target, $id): array {
			$form = $id === null ? $this->openNew($target, null) : $this->open($target, $id);
			$described = FormReader::describe($form);

			$schema = [
				'presenter' => $target->presenter,
				'form' => $target->form,
				'id' => $id,
				'create' => $id === null,
				'label' => $target->label,
				'description' => $target->description,
				'groups' => $described['groups'],
				'fields' => $described['fields'],
				'write' => [
					'endpoint' => 'POST ' . $target->getEndpoint(),
					'note' => $id === null
						? 'Zakládání je taky dvoufázové: pošli tělo bez "id" s "dryRun": true, dostaneš zpátky '
							. 'vygenerované "id", diff a previewToken. Ostrý zápis pak pošli s tím "id", '
							. 's "create": true a s tokenem.'
						: 'Posílej jen pole, která se mají změnit. Nejdřív s "dryRun": true — vrátí se diff '
							. 'a previewToken, ten ukaž uživateli a teprve pak zapiš s tímhle tokenem.',
					'body' => $id === null
						? [
							'values' => ['<jméno pole>' => '<hodnota>'],
							'dryRun' => true,
							'actor' => '<jméno člověka, za kterého se zapisuje>',
						]
						: [
							'id' => $id,
							'values' => ['<jméno pole>' => '<hodnota>'],
							'dryRun' => true,
							'actor' => '<jméno člověka, za kterého se zapisuje>',
						],
				],
			];

			if ($target->create && $target->createNote !== '') {
				$schema['createNote'] = $target->createNote;
			}

			return $schema;
		});
	}

	/**
	 * Zkušební běh nebo ostrý zápis.
	 * @param array<mixed> $body
	 * @return array<string, mixed>
	 */
	public function submit(FormTarget $target, array $body): array
	{
		$dryRun = (bool) ($body['dryRun'] ?? false);

		// Zakládání se od editace pozná výhradně z těla, ne z toho, že id v databázi není:
		// „neznámé id = založ" by z překlepu v id udělalo tiché založení duplicity. Náhled
		// (tělo bez id) se tedy ptá na založení sám, ostrý zápis to musí zopakovat `"create": true`.
		$create = ($body['create'] ?? null) === true || !isset($body['id']);

		if ($create) {
			self::assertCreatable($target);
		}

		if ($create && !isset($body['id'])) {
			if (!$dryRun) {
				throw ApiException::badRequest('Ostrý zápis nového záznamu potřebuje „id" z náhledu. '
					. 'Zavolej nejdřív totéž s "dryRun": true — brána id vygeneruje a vrátí ho i s previewTokenem.');
			}

			$id = DIConnection::generateUuid();
		} else {
			$id = self::requireString($body, 'id');

			if ($create) {
				$this->assertFree($target, $id);
			}
		}

		$values = $body['values'] ?? null;

		if (!\is_array($values) || !$values) {
			throw ApiException::badRequest('V těle chybí "values" — objekt „jméno pole => hodnota". '
				. 'Schéma polí vrátí GET ' . $target->getEndpoint() . ($create ? '' : '?id=' . $id));
		}

		return $this->runner->withAdministrator(function () use ($target, $id, $values, $dryRun, $create, $body): array {
			$form = $create ? $this->openNew($target, $id) : $this->open($target, $id);
			$before = FormReader::values($form);

			self::apply($form, $values);

			$after = FormReader::values($form);
			$diff = self::diff($form, $before, $after);
			$changes = self::changes($diff);
			$scope = ($create ? 'create:' : 'form:') . $target->presenter . '/' . $target->form;

			if ($dryRun) {
				$form->validate();

				$errors = self::errors($form);
				$preview = $this->previewToken->issue($scope, $id, $changes, $before);
				$response = [
					'dryRun' => true,
					'create' => $create,
					'id' => $id,
					'valid' => !$errors,
					'previewToken' => $errors ? null : $preview['token'],
					'expiresAt' => $errors ? null : $preview['expiresAt'],
					'diff' => $diff,
					'errors' => $errors,
					'warnings' => self::warnings($form, $diff),
				];

				if ($create) {
					$response['note'] = 'Ostrý zápis pošli s "id" a "create": true z téhle odpovědi a s previewTokenem.';

					if ($target->createNote !== '') {
						$response['createNote'] = $target->createNote;
					}
				}

				return $response;
			}

			$this->previewToken->verify(
				\is_string($body['previewToken'] ?? null) ? $body['previewToken'] : null,
				$scope,
				$id,
				$changes,
				$before,
			);

			return $this->write($target, $id, $form, $diff, $create, isset($body['actor']) ? (string) $body['actor'] : null);
		});
	}

	/**
	 * Kdo zápis chtěl, do auditu. Jméno vyplňuje model, tak se jen zkrátí na délku sloupce.
	 */
	public static function auditUser(?string $actor): string
	{
		$actor = $actor !== null ? Strings::trim($actor) : '';

		return $actor !== '' ? Strings::substring($actor, 0, 200) . ' via Doryo' : 'doryo';
	}

	/**
	 * Ostrý zápis: druhý průchod presenterem, tentokrát POSTem na signál formuláře, takže se
	 * odehraje přesně to, co by se stalo po kliknutí na „Uložit" — validace, `onSuccess`,
	 * flash zprávy i přesměrování.
	 * @param array<array<string, mixed>> $diff
	 * @return array<string, mixed>
	 */
	private function write(FormTarget $target, string $id, Form $prepared, array $diff, bool $create, ?string $actor): array
	{
		$post = self::buildPost($prepared);
		$action = $create ? $target->createAction : $target->action;
		$params = $create ? ['action' => $action] : ['action' => $action, $target->parameter => $id];

		$run = $this->runner->run(
			$target->presenter,
			$params + ['do' => $target->form . '-submit'],
			$post,
		);

		$form = self::component($run->presenter, $target->form);

		if (!$form->isSubmitted()) {
			throw new ApiException(500, 'Zápis se nepovedl', 'Formulář nepřijal odeslaná data. '
				. 'Zopakuj dotaz s "dryRun": true a nahlas to vývojářům.');
		}

		$errors = self::errors($form);

		if ($errors) {
			throw new ApiException(422, 'Formulář neprošel validací', \sprintf(
				'Uložení neprošlo: %s',
				\implode(' ', \array_map(static fn (array $error): string => \sprintf('%s: %s', $error['label'], $error['message']), $errors)),
			));
		}

		// Validaci formulář prošel, ale `onSuccess` mohl zápis odmítnout sám — typicky „Produkt nebyl nalezen"
		// jako varování a přesměrování. Formulář pak vypadá odeslaný a bez chyb, jenže nic neuložil.
		$refusals = \array_values(\array_filter($run->flashes, static fn (array $flash): bool => Arrays::contains(['error', 'danger', 'warning'], $flash['type'])));

		if ($refusals) {
			throw new ApiException(422, 'Zápis odmítnut', \sprintf(
				'Formulář prošel validací, ale uložení odmítl: %s',
				\implode(' ', \array_column($refusals, 'message')),
			));
		}

		$this->changelogRepository->createOne([
			'user' => self::auditUser($actor),
			'entity' => $target->presenter,
			'objectId' => $id,
			'type' => 'form:' . $action,
		]);

		$response = [
			'dryRun' => false,
			'written' => true,
			'id' => $id,
			'diff' => $diff,
			'flashes' => $run->flashes,
		];

		if (!$create) {
			return $response;
		}

		$response['created'] = true;
		$response['record'] = $this->record($target, $id);

		return $response;
	}

	/**
	 * Po založení se přes repozitář ověří, že záznam opravdu vznikl. Formulář sice hlásí úspěch
	 * flash zprávou, ale `onSuccess` si záznam ukládá sám a brána o jeho podobě nic neví —
	 * bez téhle kontroly by se „založeno" hlásilo i tehdy, kdyby uložení uvnitř tiše selhalo.
	 * @return array<string, mixed>
	 * @throws \DoryoApi\Http\ApiException
	 */
	private function record(FormTarget $target, string $id): array
	{
		if ($target->entity === null) {
			return ['id' => $id];
		}

		/** @var \StORM\Entity|null $entity */
		$entity = $this->connection->findRepository($target->entity)->one($id);

		if ($entity === null) {
			throw new ApiException(500, 'Záznam nevznikl', \sprintf(
				'Formulář se odeslal bez chyby, ale záznam %s s id %s v databázi není. Nic dalšího nedělej '
					. 'a nahlas to vývojářům.',
				$target->entity,
				$id,
			));
		}

		$record = ['id' => $id];

		foreach (['code', 'company', 'name', 'fullname', 'email'] as $property) {
			$value = self::property($entity, $property);

			if ($value === null) {
				continue;
			}

			$record[$property] = $value;
		}

		return $record;
	}

	/**
	 * Načte formulář tak, jak ho vidí administrace: s oprávněními role a s hodnotami záznamu.
	 */
	private function open(FormTarget $target, string $id): Form
	{
		$run = $this->runner->run($target->presenter, ['action' => $target->action, $target->parameter => $id]);

		return self::component($run->presenter, $target->form);
	}

	/**
	 * Prázdný formulář pro založení: akce `new` bez parametru, tedy přesně ta stránka, na které
	 * nový záznam zakládá i člověk. Formuláře se bez parametru chovají jinak (u zákazníka je
	 * e-mail povinný a naopak povolený k vyplnění), a právě o to jde — brána žádné vlastní
	 * „zakládací" chování nevymýšlí.
	 *
	 * Skrytému poli `uuid` se dosadí id, které brána vydala v náhledu: administrace ho jinak nechává
	 * prázdné a klíč vyrobí až `syncOne`. Bez toho by se nedalo slíbit, pod jakým id záznam vznikne,
	 * a náhled by tím pádem nešlo na nic navázat.
	 */
	private function openNew(FormTarget $target, ?string $id): Form
	{
		$run = $this->runner->run($target->presenter, ['action' => $target->createAction]);
		$form = self::component($run->presenter, $target->form);

		if ($id !== null) {
			$uuid = $form->getComponent('uuid', false);

			if (!$uuid instanceof HiddenField) {
				throw new ApiException(500, 'Zakládání se nepovedlo', \sprintf(
					'Formulář „%s" nemá skryté pole uuid, takže brána neumí určit id nového záznamu. '
						. 'Nahlas to vývojářům.',
					$target->label,
				));
			}

			$uuid->setValue($id);
		}

		return $form;
	}

	/**
	 * Založit jde jen pod id, které v databázi není. `syncOne` by existující záznam přepsal
	 * hodnotami z formuláře — z „založ zákazníka" by se stala tichá editace cizího záznamu.
	 * @throws \DoryoApi\Http\ApiException
	 */
	private function assertFree(FormTarget $target, string $id): void
	{
		if ($target->entity === null || $this->connection->findRepository($target->entity)->one($id) === null) {
			return;
		}

		throw new ApiException(409, 'Záznam už existuje', \sprintf(
			'Záznam %s s id %s už v databázi je, založit pod ním nový nejde. Pro úpravu existujícího záznamu '
				. 'pošli tělo bez "create"; pro založení zopakuj náhled bez "id" a použij id, které brána vrátí.',
			$target->entity,
			$id,
		));
	}

	private static function property(Entity $entity, string $property): ?string
	{
		try {
			$value = $entity->getValue($property);
		} catch (\Throwable) {
			return null;
		}

		return \is_scalar($value) ? (string) $value : null;
	}

	/**
	 * @throws \DoryoApi\Http\ApiException
	 */
	private static function assertCreatable(FormTarget $target): void
	{
		if ($target->create) {
			return;
		}

		throw ApiException::badRequest(\sprintf(
			'Formulář „%s" umí jen editaci existujícího záznamu, zakládat přes něj nejde. '
				. 'Do těla patří „id" upravovaného záznamu.',
			$target->label,
		));
	}

	private static function component(Presenter $presenter, string $name): Form
	{
		$form = $presenter->getComponent($name, false);

		if (!$form instanceof Form) {
			throw ApiException::notFound("Komponenta $name na presenteru není formulář.");
		}

		return $form;
	}

	/**
	 * Dosadí do formuláře, co poslal model. Vstup je částečný — posílají se jen změny.
	 * @param array<mixed> $values
	 */
	private static function apply(Form $form, array $values): void
	{
		$controls = FormReader::controls($form);

		foreach ($values as $name => $value) {
			$name = (string) $name;
			$control = $controls[$name] ?? null;

			if ($control === null) {
				throw ApiException::badRequest(\sprintf(
					'Pole „%s" formulář nemá. Seznam polí vrátí GET na tenhle endpoint s parametrem id.',
					$name,
				));
			}

			if ($control->isDisabled() || $control instanceof UploadControl) {
				throw new ApiException(403, 'Pole je jen ke čtení', \sprintf(
					'Pole „%s" (%s) je ve formuláři vypnuté, měnit se nedá — zápis se proto neprovedl. '
						. 'Buď jde o pole, které u existujícího záznamu měnit nelze, nebo roli chybí „Plný přístup" '
						. '(777) na téhle stránce (nastavuje se v Administrace → Role).',
					FormReader::label($control),
					$name,
				));
			}

			try {
				$control->setValue(self::coerce($control, $value));
			} catch (\Throwable $e) {
				throw new ApiException(422, 'Neplatná hodnota', \sprintf(
					'Pole „%s" (%s) hodnotu nepřijalo: %s',
					FormReader::label($control),
					$name,
					$e->getMessage(),
				));
			}
		}
	}

	/**
	 * Drobné srovnání typů, aby model nemusel hádat, jestli se posílá řetězec nebo číslo.
	 */
	private static function coerce(BaseControl $control, mixed $value): mixed
	{
		if ($control instanceof Checkbox) {
			return (bool) $value;
		}

		if ($control instanceof MultiChoiceControl) {
			return \array_values(\array_map('\strval', (array) $value));
		}

		return \is_scalar($value) ? (string) $value : $value;
	}

	/**
	 * Co se změnilo. U polí s číselníkem se vedle klíčů vrací i popisky, ať uživatel v náhledu
	 * čte „Velkoobchod B", a ne uuid.
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @return array<array<string, mixed>>
	 */
	private static function diff(Form $form, array $before, array $after): array
	{
		$controls = FormReader::controls($form);
		$diff = [];

		foreach ($after as $name => $value) {
			if (($before[$name] ?? null) === $value || !isset($controls[$name])) {
				continue;
			}

			$control = $controls[$name];
			$row = [
				'field' => $name,
				'label' => FormReader::label($control),
				'from' => $before[$name] ?? null,
				'to' => $value,
			];

			$fromLabel = FormReader::valueLabel($control, $before[$name] ?? null);

			if ($fromLabel !== null || FormReader::valueLabel($control, $value) !== null) {
				$row['fromLabel'] = $fromLabel;
				$row['toLabel'] = FormReader::valueLabel($control, $value);
			}

			$diff[] = $row;
		}

		return $diff;
	}

	/**
	 * @param array<array<string, mixed>> $diff
	 * @return array<string, mixed>
	 */
	private static function changes(array $diff): array
	{
		$changes = [];

		foreach ($diff as $row) {
			$changes[(string) $row['field']] = $row['to'];
		}

		return $changes;
	}

	/**
	 * @param array<array<string, mixed>> $diff
	 * @return array<string>
	 */
	private static function warnings(Form $form, array $diff): array
	{
		$warnings = [];

		if (!$diff) {
			$warnings[] = 'Nic se nemění — poslané hodnoty jsou stejné jako ty, které záznam už má.';
		}

		$readOnly = [];

		foreach (FormReader::controls($form) as $control) {
			if (!$control->isDisabled()) {
				continue;
			}

			$readOnly[] = FormReader::label($control);
		}

		if ($readOnly) {
			$warnings[] = \sprintf(
				'Pole %s jsou v tomhle formuláři jen ke čtení; pokus o jejich změnu skončí chybou 403.',
				'„' . \implode('", „', $readOnly) . '"',
			);
		}

		return $warnings;
	}

	/**
	 * @return array<array{field: string|null, label: string, message: string}>
	 */
	private static function errors(Form $form): array
	{
		$errors = [];

		foreach ($form->getComponents(true, BaseControl::class) as $control) {
			if (!$control instanceof BaseControl) {
				continue;
			}

			foreach ($control->getErrors() as $error) {
				$errors[] = [
					'field' => FormReader::name($control),
					'label' => FormReader::label($control),
					'message' => (string) $error,
				];
			}
		}

		foreach ($form->getOwnErrors() as $error) {
			$errors[] = ['field' => null, 'label' => 'Formulář', 'message' => (string) $error];
		}

		return $errors;
	}

	/**
	 * Složí tělo POSTu přesně tak, jak by ho poslal prohlížeč — včetně polí, která se nemění.
	 * Formulář jinak nezměněná pole vyprázdní, stejně jako by to udělal ručně odeslaný formulář
	 * s prázdnými vstupy.
	 * @return array<string, mixed>
	 */
	private static function buildPost(Form $form): array
	{
		$post = [];

		foreach ($form->getComponents(true, BaseControl::class) as $control) {
			if (!$control instanceof BaseControl || $control->isDisabled()) {
				continue;
			}

			if ($control instanceof UploadControl) {
				// Výběr obrázku / souboru (liquiddesign/forms UploadImage, UploadFile) má u uloženého souboru
				// skryté textové pole s jeho názvem a `upload()` ho při uložení bez nového souboru vrátí zpátky.
				// Bez něj vrátí null a `onSuccess` název v záznamu přepíše na prázdno — každé uložení akce přes
				// API by ji připravilo o obrázek i katalog. Nový soubor brána nahrát neumí, starý tedy zůstává.
				$current = self::currentUpload($control);

				if ($current !== null) {
					self::put($post, $control->getHtmlName(), $current);
				}

				continue;
			}

			if ($control instanceof Button) {
				continue;
			}

			$value = $control->getValue();

			if ($control instanceof Checkbox) {
				// nezaškrtnuté políčko prohlížeč vůbec neposílá
				if ($value) {
					self::put($post, $control->getHtmlName(), '1');
				}

				continue;
			}

			if (\is_array($value)) {
				self::put($post, $control->getHtmlName(), \array_values(\array_map('\strval', $value)));

				continue;
			}

			self::put($post, $control->getHtmlName(), self::postValue($control, $value));
		}

		// prohlížeč pošle jméno stisknutého tlačítka; podle něj se pozná, kam se po uložení jde
		foreach ($form->getComponents(false, SubmitButton::class) as $button) {
			if ($button->getName() !== 'submit') {
				continue;
			}

			self::put($post, $button->getHtmlName(), (string) $button->getCaption());
		}

		return $post;
	}

	/**
	 * Název souboru, který výběr obrázku / souboru právě drží (nastavuje ho `setDefaults()` ze záznamu).
	 * Getter controly nemají, proto reflexe; obyčejné Nette `UploadControl` žádný název nedrží.
	 */
	private static function currentUpload(UploadControl $control): ?string
	{
		if (!\property_exists($control, 'filename')) {
			return null;
		}

		$value = (new \ReflectionProperty($control, 'filename'))->getValue($control);

		return \is_string($value) && $value !== '' ? $value : null;
	}

	/**
	 * Skalární hodnota tak, jak by ji poslal prohlížeč. Datum a čas jde jako řetězec; pole s datem
	 * zatím žádný whitelistovaný formulář nemá, takže formát bude potřeba ověřit, až takový přibude.
	 */
	private static function postValue(BaseControl $control, mixed $value): string
	{
		if ($value === null) {
			return '';
		}

		if ($value instanceof \DateTimeInterface) {
			return $value->format('Y-m-d H:i:s');
		}

		if (!\is_scalar($value) && !$value instanceof \Stringable) {
			throw new ApiException(500, 'Zápis se nepovedl', \sprintf(
				'Pole „%s" má hodnotu typu %s, kterou brána neumí poslat formuláři. Nahlas to vývojářům.',
				FormReader::label($control),
				\get_debug_type($value),
			));
		}

		return (string) $value;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function put(array &$data, string $htmlName, mixed $value): void
	{
		$path = \explode('[', \str_replace(['[]', ']'], ['', ''], $htmlName));
		$target = &$data;

		foreach ($path as $segment) {
			if (!isset($target[$segment]) || !\is_array($target[$segment])) {
				$target[$segment] = [];
			}

			$target = &$target[$segment];
		}

		$target = $value;
	}

	/**
	 * @param array<mixed> $body
	 */
	private static function requireString(array $body, string $key): string
	{
		$value = $body[$key] ?? null;

		if (!\is_string($value) || $value === '') {
			throw ApiException::badRequest("V těle chybí „$key\".");
		}

		return $value;
	}
}
