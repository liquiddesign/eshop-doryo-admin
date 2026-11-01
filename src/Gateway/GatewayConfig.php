<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

use DoryoApi\Http\ApiException;

/**
 * Co smí agent v administraci: účet, pod kterým brána jede, a whitelist formulářů, signálů,
 * hromadných úprav, čitelných seznamů a mazání.
 *
 * Whitelist je schválně konfigurace, ne kód — další stránka se přidává v sekci `doryoAdmin:`
 * bez zásahu do PHP. Oprávnění se tím neřeší: ta drží role účtu agenta v Administrace → Role,
 * takže whitelist jen říká „tohle brána umí popsat", ne „tohle smí".
 */
final class GatewayConfig
{
	/**
	 * Verze balíku, když se nedá zjistit z Composeru (balík nasazený mimo composer install).
	 * Skutečná verze se bere z tagu přes {@see version()}, aby s vydáním nedriftovala.
	 */
	public const VERSION = '0.10.0';

	/** @var array<string, \DoryoAdmin\Gateway\FormTarget> */
	private array $forms = [];

	/** @var array<string, \DoryoAdmin\Gateway\ActionTarget> */
	private array $actions = [];

	/** @var array<string, \DoryoAdmin\Gateway\BulkTarget> */
	private array $bulks = [];

	/** @var array<string, \DoryoAdmin\Gateway\GridTarget> */
	private array $grids = [];

	/** @var array<string, \DoryoAdmin\Gateway\DeleteTarget> */
	private array $deletes = [];

	/**
	 * @param string $administratorLogin Login administrátorského účtu, pod kterým brána jede
	 * @param array<array<string, mixed>> $forms
	 * @param array<array<string, mixed>> $actions
	 * @param array<array<string, mixed>> $bulk
	 * @param array<array<string, mixed>> $grids Seznamy administrace, které smí agent číst
	 * @param array<array<string, mixed>> $delete Gridy, ze kterých smí agent mazat
	 * @param int $previewTokenLifetime Platnost náhledového tokenu v sekundách
	 * @param int $bulkConfirmFrom Výchozí práh, od kolika cílů chce hromadná úprava potvrzení
	 * @param string $agentName Jméno agenta do auditu („<administrátor> via Kolego")
	 * @param int $maxUploadBytes Strop nahrávaného souboru; 0 = brána soubory nenahrává
	 */
	public function __construct(
		private string $administratorLogin,
		array $forms = [],
		array $actions = [],
		array $bulk = [],
		array $grids = [],
		array $delete = [],
		private int $previewTokenLifetime = 600,
		private int $bulkConfirmFrom = 50,
		private string $agentName = 'Kolego',
		private int $maxUploadBytes = 0,
	) {
		foreach ($forms as $form) {
			$target = new FormTarget(
				presenter: (string) $form['presenter'],
				form: (string) ($form['form'] ?? 'form'),
				action: (string) ($form['action'] ?? 'edit'),
				parameter: (string) $form['parameter'],
				label: (string) ($form['label'] ?? $form['presenter']),
				description: (string) ($form['description'] ?? ''),
				create: (bool) ($form['create'] ?? false),
				createAction: (string) ($form['createAction'] ?? 'new'),
				entity: isset($form['entity']) ? (string) $form['entity'] : null,
				createNote: (string) ($form['createNote'] ?? ''),
				hidden: \array_values(\array_map('\strval', (array) ($form['hidden'] ?? []))),
				customerEmail: (bool) ($form['customerEmail'] ?? false),
				createParameter: isset($form['createParameter']) ? (string) $form['createParameter'] : null,
				item: ChildItem::fromConfig($form['item'] ?? null),
				znacka: isset($form['znacka']) ? (string) $form['znacka'] : null,
				bublina: \array_values(\array_map('\strval', (array) ($form['bublina'] ?? []))),
				popisky: \array_map(static fn ($p): array => \array_values(\array_map('\strval', (array) $p)), (array) ($form['popisky'] ?? [])),
				tabParameter: isset($form['tabParameter']) ? (string) $form['tabParameter'] : null,
				tabColumn: isset($form['tabColumn']) ? (string) $form['tabColumn'] : null,
				tabFromField: isset($form['tabFromField']) ? (string) $form['tabFromField'] : null,
			);

			$this->forms[self::key($target->presenter, $target->form)] = $target;
		}

		foreach ($actions as $action) {
			$target = new ActionTarget(
				presenter: (string) $action['presenter'],
				signal: (string) $action['signal'],
				action: (string) ($action['action'] ?? 'default'),
				label: (string) ($action['label'] ?? $action['signal']),
				params: \array_map('\strval', (array) ($action['params'] ?? [])),
				destructive: (bool) ($action['destructive'] ?? false),
				entity: isset($action['entity']) ? (string) $action['entity'] : null,
				idParam: isset($action['idParam']) ? (string) $action['idParam'] : null,
				customerEmail: (bool) ($action['customerEmail'] ?? false),
				file: isset($action['file']) ? (string) $action['file'] : null,
				fileImage: (bool) ($action['fileImage'] ?? false),
				item: ChildItem::fromConfig($action['item'] ?? null),
				cache: (bool) ($action['cache'] ?? false),
			);

			// Příznak „jen cache" čte Kolego jako „data se nemění" (bez karty na testu) — smí ho proto mít jen akce,
			// které nic nepředáváme: žádné parametry, záznam, soubor ani položka a nic nevratného.
			if ($target->cache && ($target->params || $target->entity !== null || $target->idParam !== null || $target->file !== null || $target->item !== null || $target->destructive)) {
				throw new \LogicException(\sprintf('doryoAdmin: akce %s s „cache: true" nesmí mít params, entity, idParam, file, item ani destructive.', $target->getEndpoint()));
			}

			$this->actions[self::key($target->presenter, $target->signal)] = $target;
		}

		foreach ($bulk as $item) {
			$target = new BulkTarget(
				presenter: (string) $item['presenter'],
				grid: (string) ($item['grid'] ?? 'grid'),
				form: (string) ($item['form'] ?? 'form'),
				action: (string) ($item['action'] ?? 'bulkEdit'),
				label: (string) ($item['label'] ?? $item['presenter']),
				entity: (string) $item['entity'],
				confirmFrom: (int) ($item['bulkConfirmFrom'] ?? $this->bulkConfirmFrom),
				filterNotes: \array_map('\strval', (array) ($item['filters'] ?? [])),
			);

			$this->bulks[self::key($target->presenter, $target->grid)] = $target;
		}

		foreach ($grids as $item) {
			$target = new GridTarget(
				presenter: (string) $item['presenter'],
				grid: (string) ($item['grid'] ?? 'grid'),
				action: (string) ($item['action'] ?? 'default'),
				label: (string) ($item['label'] ?? $item['presenter']),
				description: (string) ($item['description'] ?? ''),
				filterNotes: \array_map('\strval', (array) ($item['filters'] ?? [])),
				parameter: isset($item['parameter']) ? (string) $item['parameter'] : null,
				tabParameter: isset($item['tabParameter']) ? (string) $item['tabParameter'] : null,
			);

			$this->grids[self::key($target->presenter, $target->grid)] = $target;
		}

		foreach ($delete as $item) {
			$target = new DeleteTarget(
				presenter: (string) $item['presenter'],
				grid: (string) ($item['grid'] ?? 'grid'),
				action: (string) ($item['action'] ?? 'default'),
				label: (string) ($item['label'] ?? $item['presenter']),
				entity: (string) $item['entity'],
				description: (string) ($item['description'] ?? ''),
				parameter: isset($item['parameter']) ? (string) $item['parameter'] : null,
				tabParameter: isset($item['tabParameter']) ? (string) $item['tabParameter'] : null,
			);

			$this->deletes[self::key($target->presenter, $target->grid)] = $target;
		}
	}

	public function getAdministratorLogin(): string
	{
		return $this->administratorLogin;
	}

	public function getAgentName(): string
	{
		return $this->agentName;
	}

	public function getMaxUploadBytes(): int
	{
		return $this->maxUploadBytes;
	}

	public function getPreviewTokenLifetime(): int
	{
		return $this->previewTokenLifetime;
	}

	/**
	 * @return array<\DoryoAdmin\Gateway\FormTarget>
	 */
	public function getForms(): array
	{
		return \array_values($this->forms);
	}

	/**
	 * @return array<\DoryoAdmin\Gateway\ActionTarget>
	 */
	public function getActions(): array
	{
		return \array_values($this->actions);
	}

	/**
	 * @return array<\DoryoAdmin\Gateway\BulkTarget>
	 */
	public function getBulks(): array
	{
		return \array_values($this->bulks);
	}

	/**
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function getForm(string $presenter, string $form): FormTarget
	{
		return $this->forms[self::key($presenter, $form)] ?? throw ApiException::notFound(\sprintf(
			'Formulář %s/%s brána nezná. Co umí, vypíše /v1/admin/pages.',
			$presenter,
			$form,
		));
	}

	/**
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function getAction(string $presenter, string $signal): ActionTarget
	{
		return $this->actions[self::key($presenter, $signal)] ?? throw ApiException::notFound(\sprintf(
			'Akce %s/%s brána nezná. Co umí, vypíše /v1/admin/pages.',
			$presenter,
			$signal,
		));
	}

	/**
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function getBulk(string $presenter, string $grid): BulkTarget
	{
		return $this->bulks[self::key($presenter, $grid)] ?? throw ApiException::notFound(\sprintf(
			'Hromadnou úpravu %s/%s brána nezná. Co umí, vypíše /v1/admin/pages.',
			$presenter,
			$grid,
		));
	}

	/**
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function getGrid(string $presenter, string $grid): GridTarget
	{
		return $this->grids[self::key($presenter, $grid)] ?? throw ApiException::notFound(\sprintf(
			'Seznam %s/%s brána číst neumí. Co umí, vypíše /v1/admin/pages.',
			$presenter,
			$grid,
		));
	}

	/**
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function getDelete(string $presenter, string $grid): DeleteTarget
	{
		return $this->deletes[self::key($presenter, $grid)] ?? throw ApiException::notFound(\sprintf(
			'Mazání z %s/%s brána nezná. Co umí, vypíše /v1/admin/pages.',
			$presenter,
			$grid,
		));
	}

	/**
	 * Presentery, pro které whitelist něco má, s akcí, podle které se ptát na přístup — kvůli stránkám mimo menu.
	 * @return array<string, array{label: string, action: string}>
	 */
	public function getPresenters(): array
	{
		$presenters = [];

		foreach ([...$this->grids, ...$this->deletes, ...$this->forms, ...$this->actions, ...$this->bulks] as $target) {
			$presenters[$target->presenter] ??= ['label' => $target->label, 'action' => $target->action];
		}

		return $presenters;
	}

	/**
	 * Formuláře, akce, hromadné úpravy, seznamy a mazání patřící k jedné stránce administrace.
	 * @return array{
	 *     forms: array<\DoryoAdmin\Gateway\FormTarget>,
	 *     actions: array<\DoryoAdmin\Gateway\ActionTarget>,
	 *     bulks: array<\DoryoAdmin\Gateway\BulkTarget>,
	 *     grids: array<\DoryoAdmin\Gateway\GridTarget>,
	 *     deletes: array<\DoryoAdmin\Gateway\DeleteTarget>
	 * }
	 */
	public function getPage(string $presenter): array
	{
		return [
			'forms' => \array_values(\array_filter($this->forms, static fn (FormTarget $t): bool => $t->presenter === $presenter)),
			'actions' => \array_values(\array_filter($this->actions, static fn (ActionTarget $t): bool => $t->presenter === $presenter)),
			'bulks' => \array_values(\array_filter($this->bulks, static fn (BulkTarget $t): bool => $t->presenter === $presenter)),
			'grids' => \array_values(\array_filter($this->grids, static fn (GridTarget $t): bool => $t->presenter === $presenter)),
			'deletes' => \array_values(\array_filter($this->deletes, static fn (DeleteTarget $t): bool => $t->presenter === $presenter)),
		];
	}

	/**
	 * Verze balíku (vrací ji mapa stránek) — z nainstalovaného tagu, ne z konstanty, kterou by bylo
	 * potřeba při každém vydání ručně přepsat.
	 */
	public static function version(): string
	{
		if (!\class_exists(\Composer\InstalledVersions::class)) {
			return self::VERSION;
		}

		try {
			return \ltrim(\Composer\InstalledVersions::getPrettyVersion('liquiddesign/eshop-doryo-admin') ?? self::VERSION, 'v');
		} catch (\Throwable) {
			return self::VERSION;
		}
	}

	/**
	 * Formuláře se značkou na webu (Kolego nad e-shopem): druh → formulář. Druh smí mít jen jeden formulář —
	 * dva by rozšíření nerozlišilo, proto to spadne už při startu.
	 *
	 * @return array<string, \DoryoAdmin\Gateway\FormTarget>
	 */
	public function getZnacky(): array
	{
		$out = [];

		foreach ($this->forms as $form) {
			if ($form->znacka === null) {
				continue;
			}

			if (isset($out[$form->znacka])) {
				throw new \LogicException(\sprintf('doryoAdmin: značku „%s" mají dva formuláře (%s a %s).', $form->znacka, $out[$form->znacka]->getEndpoint(), $form->getEndpoint()));
			}

			$out[$form->znacka] = $form;
		}

		return $out;
	}

	/**
	 * Druh značky pro entitu ze šablony (`n:kolego="$product"`): formulář, jehož `entity` entita je (i potomek).
	 */
	public function getZnackaEntity(object $entity): ?FormTarget
	{
		foreach ($this->getZnacky() as $form) {
			if ($form->entity !== null && $entity instanceof $form->entity) {
				return $form;
			}
		}

		return null;
	}

	private static function key(string $presenter, string $name): string
	{
		return $presenter . '/' . $name;
	}
}
