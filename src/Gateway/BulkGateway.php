<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

use Admin\Controls\AdminGrid;
use Admin\DB\ChangelogRepository;
use DoryoApi\Http\ApiException;
use Nette\Application\UI\Presenter;
use Nette\Forms\Container;
use Nette\Forms\Controls\BaseControl;
use Nette\Forms\Controls\Button;
use Nette\Forms\Controls\Checkbox;
use Nette\Forms\Controls\HiddenField;
use Nette\Forms\Controls\MultiSelectBox;
use Nette\Forms\Form;
use Nette\Utils\Arrays;
use Nette\Utils\Strings;
use StORM\DIConnection;
use StORM\Entity;
use StORM\Meta\RelationNxN;

/**
 * Brána nad „Hromadnou úpravou" gridu administrace.
 *
 * Hromadná úprava je jediné místo, kde admin umí sáhnout na desítky záznamů najednou, a přesně
 * to Levior po agentovi chtěl jako první („přidej těmhle zákazníkům ceník"). Stejně jako u formulářů se nic
 * nekopíruje: cílem je komponenta `bulkForm` gridu, spouští se normální cestou a platí její
 * sémantika včetně té nečekané.
 *
 * Sémantika vychází z {@see \Admin\Controls\AdminGrid::createComponentBulkForm()} a je pro model
 * nejdůležitější věc na celém endpointu:
 *
 * - **`add`** = zaškrtnuté „Původní" plus hodnota. U vztahu N:N (ceníky, ceníky pro export,
 *   seznamy viditelnosti) se hodnota **přidá** k tomu, co zákazník má.
 * - **`replace`** = odškrtnuté „Původní" plus hodnota: stávající vazby se **zahodí** a nastaví
 *   se poslané.
 * - **`set`** = totéž pro obyčejná pole (sleva, fiktivní ceník).
 * - **odebrat jednu položku vztahu admin neumí** — a brána si to nedomýšlí, protože by to byla
 *   jiná operace než ta, kterou dělá člověk.
 */
final class BulkGateway
{
	/** Kolik cílů se vypíše jmenovitě; zbytek je jen počet, jinak by odpověď u filtru utekla. */
	private const SAMPLE = 20;

	private const MODE_ADD = 'add';
	private const MODE_REPLACE = 'replace';
	private const MODE_SET = 'set';

	/** Jméno tlačítka, kterým se hromadná úprava odesílá; podle něj se pozná, kam se po uložení jde. */
	private const SUBMIT = 'submitAndBack';

	public function __construct(
		private PresenterRunner $runner,
		private PreviewToken $previewToken,
		private ChangelogRepository $changelogRepository,
		private DIConnection $connection,
	) {
	}

	/**
	 * Co hromadná úprava umí: které vstupy nabízí (a v jakých režimech), podle čeho jde vybrat
	 * cílové záznamy a jak vypadá tělo požadavku.
	 * @return array<string, mixed>
	 */
	public function schema(BulkTarget $target): array
	{
		return $this->runner->withAdministrator(function () use ($target): array {
			$run = $this->runner->open($target->presenter, self::params($target));
			$grid = self::grid($run->presenter, $target);
			$form = self::bulkForm($grid, $target);
			$inputs = $this->describeInputs($target, $form);

			return [
				'presenter' => $target->presenter,
				'grid' => $target->grid,
				'label' => $target->label,
				'confirmFrom' => $target->confirmFrom,
				'inputs' => $inputs,
				'filters' => GridReader::describeFilters($grid, $target->filterNotes),
				'write' => [
					'endpoint' => 'POST ' . $target->getEndpoint(),
					'note' => 'Cíle se zadají buď výčtem („ids"), nebo filtrem gridu („filter"); obojí naráz ne. '
						. 'Nejdřív volej s "dryRun": true — vrátí se počet cílů, ukázka a previewToken. '
						. 'Ten ukaž uživateli a teprve pak zapiš s tímhle tokenem.',
					'body' => [
						'ids' => ['<id záznamu>', '<id záznamu>'],
						'values' => ['<jméno vstupu>' => ['<režim ze seznamu „modes">' => '<hodnota>']],
						'dryRun' => true,
						'actor' => '<jméno člověka, za kterého se zapisuje>',
					],
					'examples' => self::examples($target, $inputs),
				],
				'note' => 'Odebrat jednu položku vztahu (třeba jeden ceník z pěti) hromadná úprava v administraci '
					. 'neumí, takže to neumí ani brána. Buď se pošle „replace" s výsledným seznamem, nebo se '
					. 'zákazníci upraví jednotlivě přes formulář.',
			];
		});
	}

	/**
	 * Zkušební běh nebo ostrý zápis hromadné úpravy.
	 * @param array<mixed> $body
	 * @return array<string, mixed>
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function submit(BulkTarget $target, array $body): array
	{
		$byIds = isset($body['ids']);
		$byFilter = isset($body['filter']);

		if ($byIds === $byFilter) {
			throw ApiException::badRequest('Cíle se zadávají buď výčtem „ids", nebo filtrem „filter" — '
				. ($byIds ? 'nejde obojí naráz.' : 'jedno z toho v těle chybí.'));
		}

		$dryRun = (bool) ($body['dryRun'] ?? false);
		$filter = $byFilter ? self::readFilter($body['filter']) : [];
		$given = $byIds ? self::readIds($body['ids']) : [];

		return $this->runner->withAdministrator(function () use ($target, $body, $dryRun, $byFilter, $filter, $given): array {
			// hromadná úprava mění data, ale `checkRequirements()` u akce `bulkEdit` kontroluje jen
			// přístup na stránku — v administraci je ochranou to, že tlačítko vidí jen ten, kdo na
			// stránku smí. Přes API je to málo.
			$this->runner->assertManager($target->getResource(), $target->label);

			$run = $this->runner->open($target->presenter, self::params($target, $given, $filter));
			$grid = self::grid($run->presenter, $target);
			$form = self::bulkForm($grid, $target);

			if ($byFilter) {
				GridReader::assertFilters($grid, $filter, $target->getEndpoint());
			}

			$ids = $byFilter ? self::resolve($grid) : $this->assertExist($target, $given);
			$plan = $this->readPlan($target, $form, $body['values'] ?? null);
			$changes = self::describeChanges($form, $plan);
			$targets = $this->describeTargets($target, $ids);
			$scope = 'bulk:' . $target->presenter . '/' . $target->grid;
			$fingerprint = self::fingerprint($ids);
			$requiresConfirm = \count($ids) > $target->confirmFrom;

			if ($dryRun) {
				$preview = $this->previewToken->issue($scope, $fingerprint, self::signature($plan), []);

				return [
					'dryRun' => true,
					'targets' => $targets,
					'changes' => $changes,
					'requiresConfirm' => $requiresConfirm,
					'previewToken' => $preview['token'],
					'expiresAt' => $preview['expiresAt'],
					'note' => $requiresConfirm
						? \sprintf(
							'Cílů je %d, tedy víc než %d — ostrý zápis chce vedle previewTokenu i "confirm": true.',
							$targets['count'],
							$target->confirmFrom,
						)
						: 'Ostrý zápis pošli se stejným tělem, s "dryRun": false a s previewTokenem z téhle odpovědi.',
				];
			}

			if ($requiresConfirm && ($body['confirm'] ?? null) !== true) {
				throw ApiException::badRequest(\sprintf(
					'Hromadná úprava by sáhla na %d záznamů, což je víc než %d. Ukaž uživateli, kolik jich je '
						. 'a co se změní, a ostrý zápis zopakuj s "confirm": true.',
					$targets['count'],
					$target->confirmFrom,
				));
			}

			$this->previewToken->verify(
				\is_string($body['previewToken'] ?? null) ? $body['previewToken'] : null,
				$scope,
				$fingerprint,
				self::signature($plan),
				[],
				'Cílová množina se od náhledu změnila — filtr teď vrací jiné záznamy, než které uživatel viděl. '
					. 'Zopakuj dotaz s "dryRun": true, ukaž nový výběr a zapiš znovu.',
			);

			return $this->write($target, $form, $ids, $plan, $targets, $changes, isset($body['actor']) ? (string) $body['actor'] : null);
		});
	}

	/**
	 * Ostrý běh: druhý průchod presenterem, tentokrát POSTem na signál `bulkForm`, takže se
	 * odehraje přesně to, co po stisknutí „Uložit a zpět" v administraci.
	 *
	 * Cíle se posílají vždycky výčtem (`bulkType = selected`), i když je uživatel zadal filtrem.
	 * Věrnější adminu by byl `bulkType = all`, jenže ten si filtr vyhodnotí ještě jednou uvnitř
	 * zápisu — zapsalo by se tedy do množiny, kterou nikdo neschválil (mezitím mohl přibýt
	 * zákazník). Brána proto množinu spočítá, porovná s náhledem a zapíše přesně ta id, která
	 * uživatel viděl. Výsledek je stejný, jen se nemůže rozejít.
	 * @param \Nette\Forms\Form $form Formulář z prvního průchodu; z něj se berou jména vstupů
	 * @param array<string> $ids
	 * @param array<string, array{mode: string, value: mixed}> $plan
	 * @param array<string, mixed> $targets
	 * @param array<array<string, mixed>> $changes
	 * @return array<string, mixed>
	 * @throws \DoryoApi\Http\ApiException
	 */
	private function write(BulkTarget $target, Form $form, array $ids, array $plan, array $targets, array $changes, ?string $actor): array
	{
		$run = $this->runner->run(
			$target->presenter,
			self::params($target, $ids) + ['do' => $target->grid . '-bulkForm-submit'],
			self::buildPost($form, $plan),
		);

		$submitted = self::bulkForm(self::grid($run->presenter, $target), $target);

		if (!$submitted->isSubmitted()) {
			throw new ApiException(500, 'Zápis se nepovedl', 'Hromadná úprava nepřijala odeslaná data. '
				. 'Zopakuj dotaz s "dryRun": true a nahlas to vývojářům.');
		}

		$errors = $submitted->getErrors();

		if ($errors) {
			throw new ApiException(422, 'Hromadná úprava neprošla validací', \implode(' ', \array_map('\strval', $errors)));
		}

		$this->changelogRepository->createOne([
			'user' => FormGateway::auditUser($actor),
			'entity' => $target->presenter,
			'objectId' => null,
			'type' => \sprintf('bulk:%s:%d', $target->grid, \count($ids)),
		]);

		return [
			'dryRun' => false,
			'written' => true,
			'targets' => $targets,
			'changes' => $changes,
			'flashes' => $run->flashes,
		];
	}

	/**
	 * Vstupy hromadné úpravy: to, co si `bulkForm` přenesl z detailového formuláře do kontejneru
	 * `values`. Co grid roli bez „Plného přístupu" nenabízí, se sem nedostane, takže se o tom
	 * model ani nedozví.
	 * @return array<array<string, mixed>>
	 */
	private function describeInputs(BulkTarget $target, Form $form): array
	{
		$inputs = [];

		foreach (self::values($form)->getComponents() as $name => $control) {
			if (!$control instanceof BaseControl || $control instanceof Button || $control instanceof HiddenField) {
				continue;
			}

			$name = (string) $name;
			$field = FormReader::describeControl($name, $control);
			unset($field['value'], $field['valueLabel'], $field['required'], $field['readOnly']);
			$field['modes'] = $this->modes($target, $name, $control);
			$field['note'] = Arrays::contains($field['modes'], self::MODE_ADD)
				? 'Vztah N:N: „add" přidá k tomu, co záznam má, „replace" původní vazby zahodí a nastaví poslané.'
				: 'Obyčejné pole: „set" hodnotu nastaví všem cílům.';

			$inputs[] = $field;
		}

		return $inputs;
	}

	/**
	 * Co poslaly „values" přeložené na to, co s tím hromadná úprava udělá.
	 * @return array<string, array{mode: string, value: mixed}>
	 * @throws \DoryoApi\Http\ApiException
	 */
	private function readPlan(BulkTarget $target, Form $form, mixed $values): array
	{
		if (!\is_array($values) || !$values) {
			throw ApiException::badRequest('V těle chybí "values" — objekt „jméno vstupu => {režim: hodnota}". '
				. 'Co hromadná úprava nabízí, vrátí GET ' . $target->getEndpoint());
		}

		$container = self::values($form);
		$plan = [];

		foreach ($values as $name => $spec) {
			$name = (string) $name;
			$control = $container->getComponent($name, false);

			if (!$control instanceof BaseControl || $control instanceof Button || $control instanceof HiddenField) {
				throw ApiException::badRequest(\sprintf(
					'Pole „%s" hromadná úprava nenabízí. Buď ho nemá vůbec, nebo ho účtu agenta nenabízí kvůli '
						. 'chybějícímu „Plnému přístupu" (777) na téhle stránce. Seznam vrátí GET %s.',
					$name,
					$target->getEndpoint(),
				));
			}

			$modes = $this->modes($target, $name, $control);
			[$mode, $value] = self::readMode($name, $spec, $modes);

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

			$plan[$name] = ['mode' => $mode, 'value' => FormReader::normalize($control->getValue())];
		}

		return $plan;
	}

	/**
	 * V jakých režimech se dá pole měnit. Přidávat jde jen do vztahu N:N — jinde by `relate()`
	 * neměl co dělat a hromadná úprava by hodnotu tiše zahodila.
	 * @return array<string>
	 */
	private function modes(BulkTarget $target, string $name, BaseControl $control): array
	{
		if (!$control instanceof MultiSelectBox) {
			return [self::MODE_SET];
		}

		$relation = $this->connection->findRepository($target->entity)->getStructure()->getRelation($name);

		return $relation instanceof RelationNxN ? [self::MODE_ADD, self::MODE_REPLACE] : [self::MODE_REPLACE];
	}

	/**
	 * Kolik cílů to je a pár z nich jmenovitě, ať uživatel v náhledu čte firmy, a ne uuid.
	 * @param array<string> $ids
	 * @return array<string, mixed>
	 */
	private function describeTargets(BulkTarget $target, array $ids): array
	{
		$sample = [];

		foreach ($this->connection->findRepository($target->entity)->many()->where('this.uuid', \array_slice($ids, 0, self::SAMPLE))->toArray() as $id => $entity) {
			$row = ['id' => (string) $id];

			foreach (['code', 'company', 'name', 'fullname'] as $property) {
				$value = self::property($entity, $property);

				if ($value === null || $value === '') {
					continue;
				}

				$row[$property] = $value;

				break;
			}

			$sample[] = $row;
		}

		return [
			'count' => \count($ids),
			'sample' => $sample,
			'truncated' => \count($ids) > self::SAMPLE,
		];
	}

	/**
	 * Existují všechna poslaná id? Neexistující se nedá tiše přeskočit — uživatel by dostal
	 * „hotovo" u záznamů, na které se nesáhlo.
	 * @param array<string> $ids
	 * @return array<string>
	 * @throws \DoryoApi\Http\ApiException
	 */
	private function assertExist(BulkTarget $target, array $ids): array
	{
		$known = \array_keys($this->connection->findRepository($target->entity)->many()->where('this.uuid', $ids)->toArray());
		$missing = \array_diff($ids, \array_map('\strval', $known));

		if ($missing) {
			throw ApiException::notFound(\sprintf('Tahle id neexistují: %s.', \implode(', ', $missing)));
		}

		return $ids;
	}

	/**
	 * Cílová id podle filtrů gridu — přesně ta množina, kterou by člověk viděl ve výpisu.
	 * @return array<string>
	 */
	private static function resolve(AdminGrid $grid): array
	{
		return \array_values(\array_map('\strval', $grid->getFilteredSource()->toArrayOf($grid->getSourceIdName())));
	}

	/**
	 * Parametry požadavku na presenter: akce hromadné úpravy, jméno gridu a jeho stav (vybraná id
	 * a filtry), tedy totéž, co by v adrese bylo po kliknutí na „Hromadná úprava".
	 * @param array<string> $ids
	 * @param array<string, mixed> $filter
	 * @return array<string, mixed>
	 */
	private static function params(BulkTarget $target, array $ids = [], array $filter = []): array
	{
		$params = ['action' => $target->action, 'grid' => $target->grid];

		if ($ids) {
			$params[$target->grid . '-selected'] = $ids;
		}

		foreach ($filter as $name => $value) {
			$params[$target->grid . '-' . $name] = $value;
		}

		return $params;
	}

	/**
	 * Tělo POSTu tak, jak by ho poslal prohlížeč.
	 *
	 * Zaškrtnuté „Původní" je v administraci rozdíl mezi „přidat" a „nahradit", a hlavně: co
	 * zaškrtnuté zůstane, to hromadná úprava vůbec neřeší. Proto se posílá u všech vstupů, které
	 * se nemění — jinak by se jejich prázdná hodnota zapsala všem cílům (u vztahu jako smazání
	 * vazeb, u čísla jako NULL do sloupce, který NULL nebere).
	 * @param array<string, array{mode: string, value: mixed}> $plan
	 * @return array<string, mixed>
	 * @throws \DoryoApi\Http\ApiException
	 */
	private static function buildPost(Form $form, array $plan): array
	{
		$post = ['bulkType' => 'selected', 'keep' => [], 'values' => [], self::SUBMIT => 'Uložit a zpět'];

		foreach (self::keep($form)->getComponents() as $checkbox) {
			if (!$checkbox instanceof BaseControl) {
				continue;
			}

			$name = (string) $checkbox->getName();
			$mode = $plan[$name]['mode'] ?? null;

			if ($mode === self::MODE_REPLACE || $mode === self::MODE_SET) {
				continue;
			}

			$post['keep'][$name] = '1';
		}

		foreach ($plan as $name => $change) {
			$value = $change['value'];

			if ($value === false) {
				// nezaškrtnuté políčko prohlížeč vůbec neposílá
				continue;
			}

			$post['values'][$name] = $value === true ? '1' : (\is_array($value) ? \array_values(\array_map('\strval', $value)) : (string) $value);
		}

		return $post;
	}

	/**
	 * Co se změní, česky — do náhledu i do odpovědi po zápisu.
	 * @param array<string, array{mode: string, value: mixed}> $plan
	 * @return array<array<string, mixed>>
	 */
	private static function describeChanges(Form $form, array $plan): array
	{
		$container = self::values($form);
		$changes = [];

		foreach ($plan as $name => $change) {
			$control = $container->getComponent($name, false);
			$row = [
				'field' => $name,
				'label' => $control instanceof BaseControl ? FormReader::label($control) : $name,
				'mode' => $change['mode'],
				'value' => $change['value'],
			];

			$label = $control instanceof BaseControl ? FormReader::valueLabel($control, $change['value']) : null;

			if ($label !== null) {
				$row['valueLabel'] = $label;
			}

			$changes[] = $row;
		}

		return $changes;
	}

	/**
	 * Otisk cílové množiny. Na pořadí nezáleží, na obsahu ano — token se tím váže na přesně ty
	 * záznamy, které uživatel v náhledu viděl.
	 * @param array<string> $ids
	 */
	private static function fingerprint(array $ids): string
	{
		\sort($ids);

		return Strings::substring(\hash('sha256', \implode('|', $ids)), 0, 32);
	}

	/**
	 * @param array<string, array{mode: string, value: mixed}> $plan
	 * @return array<string, mixed>
	 */
	private static function signature(array $plan): array
	{
		$signature = [];

		foreach ($plan as $name => $change) {
			$signature[$name] = $change['mode'] . ':' . \serialize($change['value']);
		}

		return $signature;
	}

	/**
	 * @param array<string> $modes
	 * @return array{0: string, 1: mixed}
	 * @throws \DoryoApi\Http\ApiException
	 */
	private static function readMode(string $name, mixed $spec, array $modes): array
	{
		if (!\is_array($spec) || \array_is_list($spec)) {
			// holá hodnota: u obyčejného pole je jednoznačná, u vztahu ne — a mlčky zvolit
			// „nahradit" by znamenalo smazat vazby, o kterých uživatel nemluvil
			if (Arrays::contains($modes, self::MODE_SET)) {
				return [self::MODE_SET, $spec];
			}

			throw ApiException::badRequest(\sprintf(
				'U pole „%s" se musí říct, co se s hodnotou má stát: {"add": [...]} přidá k tomu, co záznam má, '
					. '{"replace": [...]} původní vazby zahodí a nastaví poslané.',
				$name,
			));
		}

		if (\count($spec) !== 1) {
			throw ApiException::badRequest(\sprintf(
				'U pole „%s" se čeká právě jeden režim (%s), poslané jsou: %s.',
				$name,
				\implode(' nebo ', $modes),
				\implode(', ', \array_map('\strval', \array_keys($spec))),
			));
		}

		$mode = (string) \array_key_first($spec);

		if ($mode === 'remove') {
			throw ApiException::badRequest(\sprintf(
				'Odebrat jednu položku z pole „%s" hromadná úprava v administraci neumí, takže to neumí ani brána. '
					. 'Buď pošli {"replace": [...]} s výsledným seznamem, nebo uprav zákazníky jednotlivě přes '
					. 'formulář zákazníka.',
				$name,
			));
		}

		if (!Arrays::contains($modes, $mode)) {
			throw ApiException::badRequest(\sprintf(
				'Pole „%s" režim „%s" nemá. Umí: %s.',
				$name,
				$mode,
				\implode(', ', $modes),
			));
		}

		return [$mode, $spec[$mode]];
	}

	/**
	 * Drobné srovnání typů, aby model nemusel hádat, jestli se posílá řetězec nebo číslo.
	 */
	private static function coerce(BaseControl $control, mixed $value): mixed
	{
		if ($control instanceof Checkbox) {
			return (bool) $value;
		}

		if ($control instanceof MultiSelectBox) {
			return \array_values(\array_map('\strval', (array) $value));
		}

		return \is_scalar($value) ? (string) $value : $value;
	}

	/**
	 * @throws \DoryoApi\Http\ApiException
	 */
	private static function grid(Presenter $presenter, BulkTarget $target): AdminGrid
	{
		$grid = $presenter->getComponent($target->grid, false);

		if (!$grid instanceof AdminGrid) {
			throw ApiException::notFound(\sprintf('Komponenta %s na presenteru %s není grid administrace.', $target->grid, $target->presenter));
		}

		return $grid;
	}

	/**
	 * @throws \DoryoApi\Http\ApiException
	 */
	private static function bulkForm(AdminGrid $grid, BulkTarget $target): Form
	{
		$form = $grid->getComponent('bulkForm', false);

		if (!$form instanceof Form) {
			throw ApiException::notFound(\sprintf(
				'Grid %s hromadnou úpravu nemá (chybí mu addButtonBulkEdit).',
				$target->grid,
			));
		}

		return $form;
	}

	/**
	 * @throws \DoryoApi\Http\ApiException
	 */
	private static function values(Form $form): Container
	{
		return self::container($form, 'values');
	}

	/**
	 * @throws \DoryoApi\Http\ApiException
	 */
	private static function keep(Form $form): Container
	{
		return self::container($form, 'keep');
	}

	/**
	 * @throws \DoryoApi\Http\ApiException
	 */
	private static function container(Form $form, string $name): Container
	{
		$container = $form->getComponent($name, false);

		if (!$container instanceof Container) {
			throw new ApiException(500, 'Chyba serveru', \sprintf(
				'Hromadná úprava nemá kontejner „%s". Nahlas to vývojářům.',
				$name,
			));
		}

		return $container;
	}

	/**
	 * @param array<array<string, mixed>> $inputs
	 * @return array<string, mixed>
	 */
	private static function examples(BulkTarget $target, array $inputs): array
	{
		$relation = null;
		$scalar = null;

		foreach ($inputs as $input) {
			$name = (string) $input['name'];

			if (Arrays::contains((array) $input['modes'], self::MODE_ADD)) {
				$relation ??= $name;

				continue;
			}

			$scalar ??= $name;
		}

		$examples = [];

		if ($relation !== null) {
			$examples['přidat vybraným záznamům položku'] = [
				'ids' => ['<id>', '<id>'],
				'values' => [$relation => [self::MODE_ADD => ['<id položky>']]],
				'dryRun' => true,
			];
			$examples['nahradit celý seznam'] = [
				'ids' => ['<id>'],
				'values' => [$relation => [self::MODE_REPLACE => ['<id položky>', '<id položky>']]],
				'dryRun' => true,
			];
		}

		if ($scalar !== null) {
			$examples['nastavit pole celému výsledku filtru'] = [
				'filter' => ['<jméno filtru>' => '<hodnota>'],
				'values' => [$scalar => [self::MODE_SET => '<hodnota>']],
				'dryRun' => true,
			];
		}

		$examples['endpoint'] = 'POST ' . $target->getEndpoint();

		return $examples;
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
	 * @return array<string>
	 * @throws \DoryoApi\Http\ApiException
	 */
	private static function readIds(mixed $ids): array
	{
		if (!\is_array($ids) || !$ids || !\array_is_list($ids)) {
			throw ApiException::badRequest('Parametr "ids" musí být neprázdné pole id záznamů.');
		}

		$result = [];

		foreach ($ids as $id) {
			if (!\is_scalar($id) || (string) $id === '') {
				throw ApiException::badRequest('Parametr "ids" smí obsahovat jen id záznamů.');
			}

			$result[] = (string) $id;
		}

		return \array_values(\array_unique($result));
	}

	/**
	 * @return array<string, mixed>
	 * @throws \DoryoApi\Http\ApiException
	 */
	private static function readFilter(mixed $filter): array
	{
		if (!\is_array($filter) || !$filter || \array_is_list($filter)) {
			throw ApiException::badRequest('Parametr "filter" musí být neprázdný objekt „jméno filtru => hodnota".');
		}

		$result = [];

		foreach ($filter as $name => $value) {
			$result[(string) $name] = \is_array($value) ? \array_values(\array_map('\strval', $value)) : (\is_scalar($value) ? (string) $value : '');
		}

		return $result;
	}
}
