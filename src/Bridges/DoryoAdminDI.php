<?php

declare(strict_types=1);

namespace DoryoAdmin\Bridges;

use Admin\Administrator;
use DoryoAdmin\Gateway\ActionGateway;
use DoryoAdmin\Gateway\AdminEndpoint;
use DoryoAdmin\Gateway\BulkGateway;
use DoryoAdmin\Gateway\DeleteGateway;
use DoryoAdmin\Gateway\EmailAdministratorResolver;
use DoryoAdmin\Gateway\FormGateway;
use DoryoAdmin\Gateway\Popisky;
use DoryoAdmin\Gateway\GatewayConfig;
use DoryoAdmin\Gateway\GridGateway;
use DoryoAdmin\Gateway\MerchantAdministratorResolver;
use DoryoAdmin\Gateway\PageMap;
use DoryoAdmin\Gateway\PresenterRunner;
use DoryoAdmin\Gateway\PreviewToken;
use DoryoAdmin\Gateway\ServiceAccountResolver;
use DoryoAdmin\Gateway\Uploads;
use DoryoAdmin\Latte\KolegoExtension;
use DoryoApi\Bridges\DoryoApiDI;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\FactoryDefinition;
use Nette\DI\Definitions\Reference;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\DI\Definitions\Statement;
use Nette\DI\InvalidConfigurationException;
use Nette\Schema\Elements\AnyOf;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use Nette\Utils\Arrays;

/**
 * Registrace brány administrace do shopu.
 *
 * Shop přidá `doryoAdmin: DoryoAdmin\Bridges\DoryoAdminDI` mezi rozšíření (vedle `doryoApi`),
 * nastaví login účtu agenta a whitelist stránek. Služby si balík zaregistruje sám a cesty
 * `/v1/admin/…` přidá do API balíku eshop-doryo-api — viz {@see self::registerEndpoint()}.
 */
final class DoryoAdminDI extends CompilerExtension
{
	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			// administrátorský účet (login), pod kterým brána jede; co smí, nastavuje Administrace → Role
			'administratorLogin' => Expect::string('doryo'),
			// jméno agenta do auditu: „<administrátor> via Kolego (actor)"
			'agentName' => Expect::string('Kolego'),
			// platnost náhledového tokenu v sekundách
			'previewTokenLifetime' => Expect::int(600)->min(60),
			// od kolika cílů chce hromadná úprava potvrzení; dá se přebít u konkrétního gridu
			'bulkConfirmFrom' => Expect::int(50)->min(1),
			// strop nahrávaného souboru v bajtech (pole souborů ve formulářích, akce s `file`); 0 = nahrávání vypnuté
			'maxUploadBytes' => Expect::int(0)->min(0),
			// složka souborů e-shopu ({"eshop": "/userfiles/…"} = soubor, který už v e-shopu je); null = parametr userDir, jinak %wwwDir%/userfiles
			'userFilesDir' => Expect::string()->nullable(),
			// požadavek s obchodníkem (X-Doryo-Za / merchantId) jede pod jeho administrátorským účtem;
			// vypnuto = brána jede jen pod servisním účtem a požadavek za obchodníka odmítne
			'merchantAdministrators' => Expect::bool(false),
			// sloupec tabulky administrátorů s vazbou na obchodníka (For Baby: fk_merchant)
			'merchantColumn' => Expect::string('fk_merchant')->pattern('[A-Za-z_][A-Za-z0-9_]*'),
			// člověk z Kolega (hlavička X-Doryo-Email) jede pod administrátorem s tímtéž loginem (nebo podle administratorEmails)
			'emailAdministrators' => Expect::bool(false),
			// párování e-mail → login účtu administrace tam, kde login není e-mail
			'administratorEmails' => Expect::arrayOf('string', 'string'),
			// vlastní IdentityResolver projektu; přebíjí merchantAdministrators
			'identityResolver' => self::service()->nullable(),
			// služba uživatele administrace (Admin\Administrator); null = najde se podle typu
			'administratorService' => Expect::string()->nullable(),
			// cesty /v1/admin/… se samy přidají do doryoApi: endpoints:; false = projekt si je přidá sám
			'registerEndpoint' => Expect::bool(true),
			// makro n:kolego v šablonách webu (značky pro rozšíření Kolego); false = projekt si ho do Latte přidá sám
			'latte' => Expect::bool(true),
			// projektové doplňky mapy stránek: služby implementující DoryoAdmin\Gateway\PageDescriber
			'describers' => Expect::listOf(self::service()),
			// whitelist formulářů; `create` povoluje i zakládání přes akci `createAction`
			'forms' => Expect::listOf(Expect::structure([
				'presenter' => Expect::string()->required(),
				'form' => Expect::string('form'),
				'action' => Expect::string('edit'),
				'parameter' => Expect::string()->required(),
				'label' => Expect::string()->nullable(),
				'description' => Expect::string(''),
				'create' => Expect::bool(false),
				'createAction' => Expect::string('new'),
				'entity' => Expect::string()->nullable(),
				'createNote' => Expect::string(''),
				// pole, která brána nevydá ani nepřijme (tajné klíče, hesla)
				'hidden' => Expect::listOf('string'),
				// uložení formuláře pošle e-mail zákazníkovi (Kolego to řekne na kartě)
				'customerEmail' => Expect::bool(false),
				// parametr zakládací akce s nadřazeným záznamem (kupón akce: discount); v těle `parent`
				'createParameter' => Expect::string()->nullable(),
				// formulář jedné položky záznamu (`detailOrderItemForm-{item}`): entita položky, sloupec s id záznamu, popisek
				'item' => Expect::arrayOf('string', 'string'),
				// druh značky na webu (n:kolego v šabloně) — rozšíření Kolego podle ní pozná, čím se prvek mění
				'znacka' => Expect::string()->pattern('[a-z][a-z0-9-]{0,30}')->nullable(),
				// pole, která rozšíření ukáže v bublině u prvku na webu
				'bublina' => Expect::listOf('string'),
				// popisky polí bez vlastního popisku: 'prices-{id}-price' => [Cena bez DPH, Eshop\DB\Pricelist] (Popisky)
				'popisky' => Expect::arrayOf(Expect::listOf('string'), 'string'),
				// záložka presenteru, kterou administrace bere při uložení (kategorie: tab = typ stromu) — ze záznamu, nebo z nadřazeného
				'tabParameter' => Expect::string()->nullable(),
				'tabColumn' => Expect::string()->nullable(),
				'tabFromField' => Expect::string()->nullable(),
			])->castTo('array')),
			// whitelist signálů (handle*): popis parametrů a význam z kódu vyčíst nejde, proto ručně
			'actions' => Expect::listOf(Expect::structure([
				'presenter' => Expect::string()->required(),
				'signal' => Expect::string()->required(),
				'action' => Expect::string('default'),
				'label' => Expect::string()->nullable(),
				'params' => Expect::listOf('string'),
				'destructive' => Expect::bool(false),
				'entity' => Expect::string()->nullable(),
				'idParam' => Expect::string()->nullable(),
				// akce pošle e-mail zákazníkovi (Kolego to řekne na kartě)
				'customerEmail' => Expect::bool(false),
				// HTML jméno souboru, který signál čte z požadavku (dropzone: `file`); jen s maxUploadBytes > 0
				'file' => Expect::string()->nullable(),
				'fileImage' => Expect::bool(false),
				// akce nad položkou záznamu: parametr s id položky, entita, sloupec s id záznamu (`idParam`), popisek
				'item' => Expect::arrayOf('string', 'string'),
				// akce jen maže cache webu (výpisy, menu, počty), data nemění — Kolego ji smí nabídnout po zápisu
				'cache' => Expect::bool(false),
			])->castTo('array')),
			// seznamy (gridy), které smí agent číst; popis je pro model, grid sám zná jen hlavičky
			'grids' => Expect::listOf(Expect::structure([
				'presenter' => Expect::string()->required(),
				'grid' => Expect::string('grid'),
				'action' => Expect::string('default'),
				'label' => Expect::string()->nullable(),
				'description' => Expect::string(''),
				'filters' => Expect::arrayOf('string', 'string'),
				'parameter' => Expect::string()->nullable(),
				'tabParameter' => Expect::string()->nullable(),
			])->castTo('array')),
			// hromadné úpravy gridů („Hromadná úprava" nad výběrem nebo nad celým filtrem)
			'bulk' => Expect::listOf(Expect::structure([
				'presenter' => Expect::string()->required(),
				'grid' => Expect::string('grid'),
				'form' => Expect::string('form'),
				'action' => Expect::string('bulkEdit'),
				'label' => Expect::string()->nullable(),
				'entity' => Expect::string()->required(),
				'bulkConfirmFrom' => Expect::int()->min(1)->nullable(),
				'filters' => Expect::arrayOf('string', 'string'),
			])->castTo('array')),
			// mazání z gridu tlačítkem „Smazat" nad vybranými řádky; vždy náhled, token a "confirm": true
			'delete' => Expect::listOf(Expect::structure([
				'presenter' => Expect::string()->required(),
				'grid' => Expect::string('grid'),
				'action' => Expect::string('default'),
				'label' => Expect::string()->nullable(),
				'entity' => Expect::string()->required(),
				// co smazání znamená, pro model a kartu („produkt zmizí z webu i z feedů")
				'description' => Expect::string(''),
				'parameter' => Expect::string()->nullable(),
				'tabParameter' => Expect::string()->nullable(),
			])->castTo('array')),
		]);
	}

	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		/** @var \stdClass $config */
		$config = $this->getConfig();

		$builder->addDefinition($this->prefix('config'))
			->setFactory(GatewayConfig::class, [
				'administratorLogin' => $config->administratorLogin,
				'forms' => $config->forms,
				'actions' => $config->actions,
				'bulk' => $config->bulk,
				'grids' => $config->grids,
				'delete' => $config->delete,
				'previewTokenLifetime' => $config->previewTokenLifetime,
				'bulkConfirmFrom' => $config->bulkConfirmFrom,
				'agentName' => $config->agentName,
				'maxUploadBytes' => $config->maxUploadBytes,
			]);

		// resolvery se předávají runneru výslovně: jsou to dvě služby téhož rozhraní
		$builder->addDefinition($this->prefix('serviceAccount'))
			->setFactory(ServiceAccountResolver::class, ['login' => $config->administratorLogin])
			->setAutowired(false);

		$identityResolver = $config->identityResolver !== null
			? self::serviceArgument($config->identityResolver)
			: '@' . $this->prefix('serviceAccount');

		if ($config->identityResolver === null && $config->merchantAdministrators) {
			$builder->addDefinition($this->prefix('merchantAdministrators'))
				->setFactory(MerchantAdministratorResolver::class, [
					'serviceAccount' => '@' . $this->prefix('serviceAccount'),
					'column' => $config->merchantColumn,
				])
				->setAutowired(false);

			$identityResolver = '@' . $this->prefix('merchantAdministrators');
		}

		if ($config->identityResolver === null && $config->emailAdministrators) {
			$builder->addDefinition($this->prefix('emailAdministrators'))
				->setFactory(EmailAdministratorResolver::class, [
					'fallback' => $identityResolver,
					'emails' => $config->administratorEmails,
				])
				->setAutowired(false);

			$identityResolver = '@' . $this->prefix('emailAdministrators');
		}

		// uživatel administrace se dosadí v beforeCompile — služba není autowirovaná (shop má i zákaznického uživatele)
		$builder->addDefinition($this->prefix('runner'))
			->setFactory(PresenterRunner::class, ['identityResolver' => $identityResolver]);

		$builder->addDefinition($this->prefix('previewToken'))->setFactory(PreviewToken::class);
		$parametry = $builder->parameters;
		$userDir = $config->userFilesDir
			?? (\is_string($parametry['userDir'] ?? null) ? $parametry['userDir'] : (\is_string($parametry['wwwDir'] ?? null) ? $parametry['wwwDir'] . '/userfiles' : null));
		$builder->addDefinition($this->prefix('uploads'))->setFactory(Uploads::class, ['maxBytes' => $config->maxUploadBytes, 'userDir' => $userDir]);
		$builder->addDefinition($this->prefix('pageMap'))->setFactory(PageMap::class, [
			'describers' => \array_map(self::serviceArgument(...), $config->describers),
		]);
		$builder->addDefinition($this->prefix('popisky'))->setFactory(Popisky::class);
		$builder->addDefinition($this->prefix('formGateway'))->setFactory(FormGateway::class);
		$builder->addDefinition($this->prefix('actionGateway'))->setFactory(ActionGateway::class);
		$builder->addDefinition($this->prefix('bulkGateway'))->setFactory(BulkGateway::class);
		$builder->addDefinition($this->prefix('gridGateway'))->setFactory(GridGateway::class);
		$builder->addDefinition($this->prefix('deleteGateway'))->setFactory(DeleteGateway::class);
		$builder->addDefinition($this->prefix('endpoint'))->setFactory(AdminEndpoint::class);
	}

	public function beforeCompile(): void
	{
		$builder = $this->getContainerBuilder();
		/** @var \stdClass $config */
		$config = $this->getConfig();

		/** @var \Nette\DI\Definitions\ServiceDefinition $runner */
		$runner = $builder->getDefinition($this->prefix('runner'));
		$administrator = $config->administratorService !== null ? \ltrim($config->administratorService, '@') : $this->findAdministratorService();
		$runner->setArgument('administrator', '@' . $administrator);

		if ($config->latte) {
			$this->registerLatte();
		}

		if (!$config->registerEndpoint) {
			return;
		}

		$this->registerEndpoint();
	}

	/**
	 * Makro `n:kolego` do každého Latte enginu aplikace (LatteFactory z nette/application). Bez Latte nic.
	 */
	private function registerLatte(): void
	{
		if (!\interface_exists(LatteFactory::class) || !\class_exists(\Latte\Extension::class)) {
			return;
		}

		$builder = $this->getContainerBuilder();

		foreach ($builder->findByType(LatteFactory::class) as $definition) {
			if ($definition instanceof FactoryDefinition) {
				$definition->getResultDefinition()->addSetup('addExtension', [new Statement(KolegoExtension::class, ['config' => '@' . $this->prefix('config')])]);
			}
		}
	}

	/**
	 * Uživatel administrace (`Admin\Administrator`, v adminu `admin.administrator`). Hledá se podle
	 * typu, ne podle jména: jméno služby závisí na tom, pod jakým klíčem má projekt rozšíření adminu.
	 */
	private function findAdministratorService(): string
	{
		$names = \array_keys($this->getContainerBuilder()->findByType(Administrator::class));

		if (\count($names) === 1) {
			return (string) $names[0];
		}

		if (\count($names) > 1 && Arrays::contains($names, 'admin.administrator')) {
			return 'admin.administrator';
		}

		throw new InvalidConfigurationException(\count($names) === 0
			? 'doryoAdmin: chybí služba ' . Administrator::class . ' — je zaregistrované rozšíření Admin\Bridges\AdminDI?'
			: \sprintf(
				'doryoAdmin: služeb %s je víc (%s), nastav doryoAdmin: administratorService.',
				Administrator::class,
				\implode(', ', $names),
			));
	}

	/**
	 * Přidá `@doryoAdmin.endpoint` do endpointů API, jako kdyby ho projekt uvedl v `doryoApi: endpoints:`.
	 *
	 * `DoryoApiDI` předává seznam endpointů routeru a specifikaci jako argument jejich továrny už
	 * v `loadConfiguration()`; tady se do obou argumentů přidá, co v nich ještě není. Rozšíření
	 * eshop-doryo-api se hledá podle třídy, takže nezáleží na tom, pod jakým klíčem ho projekt má.
	 * Když tam projekt endpoint uvedl sám, nepřidá se podruhé (a i kdyby, router vezme první shodu
	 * a specifikace stejné cesty jen sloučí).
	 */
	private function registerEndpoint(): void
	{
		$builder = $this->getContainerBuilder();
		$endpoint = $this->prefix('endpoint');

		foreach ($this->compiler->getExtensions(DoryoApiDI::class) as $extension) {
			// Router dostává seznam jako první argument, Specification jako pojmenovaný `endpoints`
			foreach (['router' => 0, 'specification' => 'endpoints'] as $service => $key) {
				$name = $extension->prefix($service);

				if (!$builder->hasDefinition($name)) {
					continue;
				}

				$definition = $builder->getDefinition($name);

				if (!$definition instanceof ServiceDefinition) {
					continue;
				}

				$endpoints = $definition->getFactory()->arguments[$key] ?? [];

				if (!\is_array($endpoints) || self::listsEndpoint($endpoints, $endpoint)) {
					continue;
				}

				$endpoints[] = '@' . $endpoint;
				$definition->setArgument($key, $endpoints);
			}
		}
	}

	/**
	 * Služba v configu: `@jméno`, třída, nebo třída s argumenty.
	 */
	private static function service(): AnyOf
	{
		return Expect::anyOf(Expect::string(), Expect::type(Statement::class));
	}

	/**
	 * Holé jméno třídy by Nette předalo jako řetězec, ne jako službu — z něj se udělá Statement.
	 */
	private static function serviceArgument(string|Statement $service): string|Statement
	{
		return \is_string($service) && !\str_starts_with($service, '@') ? new Statement($service) : $service;
	}

	/**
	 * @param array<mixed> $endpoints
	 */
	private static function listsEndpoint(array $endpoints, string $name): bool
	{
		foreach ($endpoints as $item) {
			$reference = match (true) {
				$item instanceof Reference => $item->getValue(),
				$item instanceof Statement && \is_string($item->getEntity()) => $item->getEntity(),
				\is_string($item) => $item,
				default => null,
			};

			if ($reference !== null && Arrays::contains([$name, AdminEndpoint::class], \ltrim($reference, '@'))) {
				return true;
			}
		}

		return false;
	}
}
