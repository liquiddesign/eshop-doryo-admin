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
use Nette\Forms\Controls\SelectBox;
use Nette\Forms\Form;
use Nette\Utils\Arrays;
use Nette\Utils\Strings;
use StORM\DIConnection;
use StORM\Meta\Column;
use StORM\Meta\RelationNxN;

/**
 * Brána nad „Hromadnou úpravou" gridu administrace.
 *
 * Hromadná úprava je jediné místo, kde admin umí sáhnout na desítky záznamů najednou, a přesně
 * to Levior po agentovi chtěl jako první („přidej těmhle zákazníkům ceník"). Stejně jako u formulářů se nic
 * nekopíruje: cílem je komponenta `bulkForm` gridu, spouští se normální cestou a platí její
 * sémantika včetně té nečekané.
 *
 * Sémantika vychází z {@see \Admin\Controls\AdminGrid::createComponentBulkForm()} (liquiddesign/admin
 * 2.1) a je pro model nejdůležitější věc na celém endpointu. U každého vstupu je v kontejneru `keep`
 * volba, co s hodnotou udělat:
 *
 * - u výběru více položek (`MultiSelectBox`) je to select „Původní / Nahradit / Přidat / Odebrat".
 *   U vztahu N:N (ceníky, seznamy viditelnosti) **`add`** hodnotu **přidá** k tomu, co záznam má,
 *   **`remove`** ji **odebere** a **`replace`** stávající vazby zahodí a nastaví poslané. U výběru,
 *   který vztahem N:N není, admin přidat ani odebrat neumí (hodnotu by stejně nahradil), takže
 *   brána nabízí jen `replace`;
 * - u ostatních polí je to zaškrtávátko „Původní": odškrtnuté + hodnota = **`set`** (sleva,
 *   fiktivní ceník) nastaví hodnotu všem cílům.
 *
 * Starší admin (Levior) měl u všech vstupů jen zaškrtávátko a „přidat" bylo zaškrtnuté „Původní"
 * plus hodnota; odebrat jednu položku neuměl. Admin 2.1 to má jinak — brána proto čte, jaký prvek
 * `keep` u vstupu je, a nabízí jen režimy, které ten prvek skutečně umí.
 */
final class BulkGateway
{
	private const MODE_ADD = 'add';
	private const MODE_REPLACE = 'replace';
	private const MODE_REMOVE = 'remove';
	private const MODE_SET = 'set';

	/** Volba selectu „Původní" — vstup se nemění. */
	private const KEEP_NONE = 'none';

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
				'note' => 'Režimy jsou přesně to, co nabízí hromadná úprava v administraci: u vztahu N:N „add" přidá, '
					. '„remove" odebere a „replace" nahradí celý seznam, u obyčejného pole „set" nastaví hodnotu. '
					. 'Co pole v „modes" nemá, administrace neumí, takže to neumí ani brána.',
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
		$given = $byIds ? GridTargets::readIds($body['ids']) : [];

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

			$ids = $byFilter ? self::resolve($grid) : GridTargets::assertVisible($grid, $given);

			if (!$ids) {
				throw ApiException::badRequest('Filtr nevrací žádný záznam, hromadná úprava by na nic nesáhla. '
					. 'Filtry a jejich popis vrátí GET ' . $target->getEndpoint() . '.');
			}

			$plan = $this->readPlan($target, $form, $body['values'] ?? null);
			$changes = self::describeChanges($form, $plan);
			$targets = GridTargets::sample($this->connection, $target->entity, $ids);
			$scope = 'bulk:' . $target->presenter . '/' . $target->grid;
			$fingerprint = GridTargets::fingerprint($ids);
			$state = $this->state($target, $ids, $plan);
			$requiresConfirm = \count($ids) > $target->confirmFrom;

			if ($dryRun) {
				$preview = $this->previewToken->issue($scope, $fingerprint, self::signature($plan), $state);

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
				$state,
				'Cílová množina se od náhledu změnila — filtr teď vrací jiné záznamy, než které uživatel viděl. '
					. 'Zopakuj dotaz s "dryRun": true, ukaž nový výběr a zapiš znovu.',
				'Od náhledu se u cílových záznamů změnila pole, která hromadná úprava mění — mohl je upravit někdo '
					. 'v administraci. Zopakuj dotaz s "dryRun": true, ukaž nový náhled a zapiš znovu.',
			);

			return $this->write($target, $form, $ids, $plan, $targets, $changes, PresenterRunner::actor($body));
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

		// Levior: tady chyběla kontrola flash zpráv, takže odmítnutí z `onSuccess` (projektový
		// `onBeforeProcess` / `onProcess`) se hlásilo jako „written": true. Stejně jako u formulářů
		// je chyba nebo varování po odeslání 422 a do auditu nic nejde.
		$refusals = $run->getRefusals();

		if ($refusals) {
			throw new ApiException(422, 'Hromadná úprava odmítnuta', \sprintf(
				'Hromadná úprava prošla validací, ale administrace ji odmítla: %s',
				\implode(' ', \array_column($refusals, 'message')),
			));
		}

		$this->changelogRepository->createOne([
			'user' => $this->runner->auditUser($actor),
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
			$field['modes'] = $this->modes($target, $form, $name, $control);
			$field['note'] = match (true) {
				Arrays::contains($field['modes'], self::MODE_ADD) => 'Vztah N:N: „add" přidá k tomu, co záznam má, '
					. '„remove" odebere poslané položky, „replace" původní vazby zahodí a nastaví poslané '
					. '(prázdný seznam = všechny vazby smazat).',
				Arrays::contains($field['modes'], self::MODE_REPLACE) => 'Výběr více položek: „replace" nastaví všem cílům '
					. 'přesně poslaný seznam; přidat ani odebrat jednu položku administrace u tohohle pole neumí.',
				default => 'Obyčejné pole: „set" hodnotu nastaví všem cílům.',
			};

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

			$modes = $this->modes($target, $form, $name, $control);
			[$mode, $value] = self::readMode($name, $spec, $modes);

			// Admin 2.1 bere „přidat" a „odebrat" s prázdnou hodnotou jako nahrazení seznamem
			// hodnot: `{"add": []}` i `{"remove": []}` by cílům smazaly všechny vazby.
			if (($mode === self::MODE_ADD || $mode === self::MODE_REMOVE) && \array_filter((array) $value, static fn ($item): bool => $item !== '' && $item !== null) === []) {
				throw ApiException::badRequest(\sprintf(
					'U pole „%s" je „%s" s prázdným seznamem — nic by se nepřidalo ani neodebralo, administrace by '
						. 'ale vazby nahradila prázdnem. Pošli položky, nebo „replace": [] když se mají vazby opravdu smazat.',
					$name,
					$mode,
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

			$plan[$name] = ['mode' => $mode, 'value' => FormReader::normalize($control->getValue())];
		}

		return $plan;
	}

	/**
	 * V jakých režimech se dá pole měnit. Rozhoduje, co nabízí administrace: prvek u vstupu
	 * v kontejneru `keep` a to, jestli je pole vztah N:N — přidávat a odebírat umí admin jen
	 * přes `RelationCollection` (`relate()` / `unrelate()`), jinde by hodnotu stejně nahradil.
	 * @return array<string>
	 * @throws \DoryoApi\Http\ApiException
	 */
	private function modes(BulkTarget $target, Form $form, string $name, BaseControl $control): array
	{
		if (!$control instanceof MultiSelectBox) {
			return [self::MODE_SET];
		}

		// select „Původní / Nahradit / Přidat / Odebrat" má jen admin 2.1; u zaškrtávátka umí brána jen nahradit
		if (!self::keep($form)->getComponent($name, false) instanceof SelectBox) {
			return [self::MODE_REPLACE];
		}

		return $this->isRelationNxN($target, $name) ? [self::MODE_ADD, self::MODE_REPLACE, self::MODE_REMOVE] : [self::MODE_REPLACE];
	}

	/**
	 * Stejné hledání vztahu jako v adminu: vstup z kontejneru detailového formuláře se jmenuje
	 * `<kontejner>_<pole>` a vztah je pak pod částí před podtržítkem.
	 */
	private function isRelationNxN(BulkTarget $target, string $name): bool
	{
		$structure = $this->connection->findRepository($target->entity)->getStructure();
		$prefix = \str_contains($name, '_') ? Strings::before($name, '_') : null;

		return ($prefix !== null && $structure->getRelation($prefix) instanceof RelationNxN)
			|| $structure->getRelation($name) instanceof RelationNxN;
	}

	/**
	 * Stav cílů v polích, která hromadná úprava mění — na něj se váže token, stejně jako u formuláře
	 * na stav záznamu. Když mezitím někdo cílům změní třeba ceníky, náhled neplatí a je to 409.
	 *
	 * Čte se levně, přímo z tabulek: jeden dotaz na vazební tabulku každého vztahu N:N a jeden na
	 * sloupce ostatních polí. Pole, které sloupcem není (vstup z kontejneru, jazykové mutace,
	 * dopočítaná hodnota), v otisku chybí — u něj se souběh nehlídá.
	 * @param array<string> $ids
	 * @param array<string, array{mode: string, value: mixed}> $plan
	 * @return array<string, mixed>
	 */
	private function state(BulkTarget $target, array $ids, array $plan): array
	{
		$structure = $this->connection->findRepository($target->entity)->getStructure();
		$table = $structure->getTable()->getName();
		$pk = $structure->getPK()->getName();
		$columns = [];
		$state = [];

		foreach (\array_keys($plan) as $name) {
			$relation = $structure->getRelation($name);

			if ($relation instanceof RelationNxN) {
				$rows = $this->connection->rows(['v' => $relation->getVia()], [
					's' => 'v.' . $relation->getSourceViaKey(),
					't' => 'v.' . $relation->getTargetViaKey(),
				])->where('v.' . $relation->getSourceViaKey(), $ids);
				$links = [];

				foreach ($rows as $row) {
					$links[(string) $row->s][] = (string) $row->t;
				}

				foreach ($links as $source => $linked) {
					\sort($linked);
					$links[$source] = $linked;
				}

				\ksort($links);
				$state[$name] = $links;

				continue;
			}

			$column = $relation !== null
				? ($relation->isKeyHolder() ? $relation->getSourceKey() : null)
				: $structure->getColumn($name);

			if ($column instanceof Column) {
				$column = $column->hasMutations() ? null : $column->getName();
			}

			if ($column === null) {
				continue;
			}

			$columns[$name] = $column;
		}

		if ($columns) {
			$select = ['id' => 't.' . $pk];

			foreach (\array_values($columns) as $i => $column) {
				$select['c' . $i] = 't.' . $column;
			}

			$values = [];

			foreach ($this->connection->rows(['t' => $table], $select)->where('t.' . $pk, $ids) as $row) {
				foreach (\array_keys($columns) as $i => $name) {
					$values[$name][(string) $row->id] = $row->{'c' . $i};
				}
			}

			foreach ($values as $name => $byId) {
				\ksort($byId);
				$state[$name] = $byId;
			}
		}

		\ksort($state);

		return $state;
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
	 * Co v `keep` zůstane na „Původní", to hromadná úprava vůbec neřeší. Proto se „Původní" posílá
	 * u všech vstupů, které se nemění — jinak by se jejich prázdná hodnota zapsala všem cílům (u vztahu
	 * jako smazání vazeb, u čísla jako NULL do sloupce, který NULL nebere). U selectu je to volba
	 * `none`, u zaškrtávátka zaškrtnutí; měněný vstup dostane u selectu svůj režim, zaškrtávátko se
	 * u něj neposílá (prohlížeč nezaškrtnuté políčko neposílá vůbec).
	 * @param array<string, array{mode: string, value: mixed}> $plan
	 * @return array<string, mixed>
	 * @throws \DoryoApi\Http\ApiException
	 */
	private static function buildPost(Form $form, array $plan): array
	{
		$post = ['bulkType' => 'selected', 'keep' => [], 'values' => [], self::SUBMIT => 'Uložit a zpět'];

		foreach (self::keep($form)->getComponents() as $keep) {
			if (!$keep instanceof BaseControl) {
				continue;
			}

			$name = (string) $keep->getName();
			$mode = $plan[$name]['mode'] ?? null;

			if ($keep instanceof SelectBox) {
				$post['keep'][$name] = $mode ?? self::KEEP_NONE;

				continue;
			}

			if ($mode !== null) {
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
				'U pole „%s" se musí říct, co se s hodnotou má stát (umí: %s): {"add": [...]} přidá k tomu, co záznam má, '
					. '{"remove": [...]} odebere, {"replace": [...]} původní vazby zahodí a nastaví poslané.',
				$name,
				\implode(', ', $modes),
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

		if (!Arrays::contains($modes, $mode)) {
			throw ApiException::badRequest(\sprintf(
				'Pole „%s" režim „%s" nemá, umí jen: %s.%s',
				$name,
				$mode,
				\implode(', ', $modes),
				$mode === self::MODE_REMOVE || $mode === self::MODE_ADD
					? ' Přidat a odebrat jednu položku umí administrace jen u vztahu N:N; tady pošli {"replace": [...]} '
						. 's výsledným seznamem, nebo uprav záznamy jednotlivě přes formulář.'
					: '',
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
			$examples['odebrat vybraným záznamům položku'] = [
				'ids' => ['<id>', '<id>'],
				'values' => [$relation => [self::MODE_REMOVE => ['<id položky>']]],
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
