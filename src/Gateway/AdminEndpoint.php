<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

use DoryoApi\Endpoint\Endpoint;
use DoryoApi\Endpoint\MethodAware;
use DoryoApi\Http\ApiException;
use DoryoApi\Http\Query;
use DoryoApi\Http\Response;
use DoryoApi\OpenApi\SpecificationPart;

/**
 * Zápis do e-shopu přes administraci: mapa stránek, schéma a zápis formuláře, spuštění akce.
 *
 * Cesty jsou schválně generické — tři endpointy místo desítek ručně psaných per doména. Výjimkou
 * jsou importy z K2: to nejsou formuláře ani signály (tlačítko přesměruje na veřejnou URL importu),
 * takže mají vlastní dvě cesty ({@see K2ImportGateway}). Popis
 * konkrétního formuláře si model tahá až na dotaz, což přesně sedí na to, jak s API pracuje
 * konektor v Doryo: introspect, najdi endpoint, přečti detail, zavolej.
 */
final class AdminEndpoint implements Endpoint, MethodAware, SpecificationPart
{
	private const ROUTE_PAGES = 'v1/admin/pages';
	private const ROUTE_FORMS = 'v1/admin/forms/{presenter}/{form}';
	private const ROUTE_ACTIONS = 'v1/admin/actions/{presenter}/{signal}';
	private const ROUTE_BULK = 'v1/admin/bulk/{presenter}/{grid}';
	private const ROUTE_GRIDS = 'v1/admin/grids/{presenter}/{grid}';
	private const ROUTE_K2_IMPORTS = 'v1/admin/k2/imports';
	private const ROUTE_K2_REIMPORT = 'v1/admin/k2/reimport/{kind}';
	private const ROUTE_K2_PRICES = 'v1/admin/k2/prices';

	public function __construct(
		private PageMap $pageMap,
		private FormGateway $formGateway,
		private ActionGateway $actionGateway,
		private BulkGateway $bulkGateway,
		private K2ImportGateway $k2ImportGateway,
		private K2PriceGateway $k2PriceGateway,
		private GridGateway $gridGateway,
		private GatewayConfig $config,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getRoutes(): array
	{
		return [
			self::ROUTE_PAGES => 'pages',
			self::ROUTE_FORMS => 'form',
			self::ROUTE_ACTIONS => 'action',
			self::ROUTE_BULK => 'bulk',
			self::ROUTE_GRIDS => 'grid',
			self::ROUTE_K2_IMPORTS => 'k2Imports',
			self::ROUTE_K2_REIMPORT => 'k2Reimport',
			self::ROUTE_K2_PRICES => 'k2Prices',
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getMethods(): array
	{
		return [
			self::ROUTE_FORMS => ['GET', 'HEAD', 'POST'],
			self::ROUTE_ACTIONS => ['POST'],
			self::ROUTE_BULK => ['GET', 'HEAD', 'POST'],
			self::ROUTE_GRIDS => ['GET', 'HEAD'],
			self::ROUTE_K2_IMPORTS => ['GET', 'HEAD'],
			self::ROUTE_K2_REIMPORT => ['POST'],
			self::ROUTE_K2_PRICES => ['GET', 'HEAD'],
		];
	}

	/**
	 * @param array<string, string> $params
	 */
	public function pages(array $params, Query $query): Response
	{
		unset($params, $query);

		return new Response($this->pageMap->build());
	}

	/**
	 * @param array<string, string> $params
	 */
	public function form(array $params, Query $query): Response
	{
		$target = $this->config->getForm($params['presenter'], $params['form']);

		if ($query->getMethod() === 'POST') {
			return new Response($this->formGateway->submit($target, $query->getBody() ?? []));
		}

		$id = $query->string('id');

		// bez id je to schéma prázdného formuláře pro založení — ale jen tam, kde zakládání
		// dává smysl. Jinde je vynechané id překlep a tiché vrácení prázdna by mátlo.
		if ($id === null && !$target->create) {
			throw ApiException::badRequest('Chybí parametr id — bez něj se neví, který záznam popsat. '
				. 'Přes tenhle formulář jde jen editovat existující záznam.');
		}

		return new Response($this->formGateway->schema($target, $id));
	}

	/**
	 * @param array<string, string> $params
	 */
	public function bulk(array $params, Query $query): Response
	{
		$target = $this->config->getBulk($params['presenter'], $params['grid']);

		if ($query->getMethod() === 'POST') {
			return new Response($this->bulkGateway->submit($target, $query->getBody() ?? []));
		}

		return new Response($this->bulkGateway->schema($target));
	}

	/**
	 * @param array<string, string> $params
	 */
	public function action(array $params, Query $query): Response
	{
		$target = $this->config->getAction($params['presenter'], $params['signal']);

		return new Response($this->actionGateway->run($target, $query->getBody() ?? []));
	}

	/**
	 * @param array<string, string> $params
	 */
	public function grid(array $params, Query $query): Response
	{
		$target = $this->config->getGrid($params['presenter'], $params['grid']);

		return new Response($this->gridGateway->read(
			$target,
			GridGateway::parseFilter($query->string('filter')),
			$query->int('page', 1) ?? 1,
			$query->int('limit', GridGateway::DEFAULT_PAGE_SIZE) ?? GridGateway::DEFAULT_PAGE_SIZE,
			$query->string('order'),
			$query->string('id'),
			$query->string('tab'),
		));
	}

	/**
	 * @param array<string, string> $params
	 */
	public function k2Imports(array $params, Query $query): Response
	{
		unset($params);

		return new Response($this->k2ImportGateway->status($query->string('target'), $query->string('type')));
	}

	/**
	 * @param array<string, string> $params
	 */
	public function k2Reimport(array $params, Query $query): Response
	{
		return new Response($this->k2ImportGateway->reimport($params['kind'], $query->getBody() ?? []));
	}

	/**
	 * @param array<string, string> $params
	 */
	public function k2Prices(array $params, Query $query): Response
	{
		unset($params);

		return new Response($this->k2PriceGateway->check((string) $query->string('customer'), (string) $query->string('product')));
	}

	/**
	 * @inheritDoc
	 */
	public function getOpenApiPaths(): array
	{
		return [
			'/v1/admin/pages' => [
				'get' => [
					'summary' => 'Mapa administrace',
					'description' => 'Stránky administrace, na které smí účet Doryo, a u nich formuláře '
						. 'a akce, které jde přes API použít. Odsud se vychází — jméno presenteru a formuláře '
						. 'z téhle odpovědi se dosazuje do ostatních cest.',
					'responses' => ['200' => ['description' => 'OK']],
				],
			],
			'/v1/admin/forms/{presenter}/{form}' => [
				'get' => [
					'summary' => 'Schéma formuláře a hodnoty záznamu',
					'description' => 'Pole formuláře administrace: jméno, typ, český popisek, nápověda, '
						. 'povinnost, položky číselníků a aktuální hodnota záznamu. Pole s "readOnly": true '
						. 'tahle role měnit nesmí.',
					'parameters' => [
						self::pathParam('presenter', 'Jméno presenteru, například Eshop:Admin:Customer.'),
						self::pathParam('form', 'Jméno formuláře, obvykle form.'),
						[
							'name' => 'id',
							'in' => 'query',
							'required' => false,
							'description' => 'Id upravovaného záznamu. Vynechá se jen u formuláře, který umí '
								. 'i zakládat (v /v1/admin/pages má "create": true) — pak se vrátí schéma prázdného '
								. 'formuláře a v "createNote" výhrada, kterou je potřeba uživateli přetlumočit.',
							'schema' => ['type' => 'string'],
						],
					],
					'responses' => ['200' => ['description' => 'OK']],
				],
				'post' => [
					'summary' => 'Zápis do formuláře (dvoufázový)',
					'description' => 'Nejdřív zavolej s "dryRun": true — nic se nezapíše, vrátí se diff '
						. '„co se změní" a previewToken. Diff ukaž uživateli a teprve pak zavolej znovu '
						. 's "dryRun": false a tím tokenem. Bez tokenu je odpověď 409, stejně jako když se '
						. 'záznam mezitím změnil. Posílají se jen pole, která se mění. '
						. 'ZALOŽENÍ nového záznamu: tělo bez "id" (jen u formuláře, který to má povolené) — '
						. 'náhled vygeneruje id, vrátí ho a k němu previewToken; ostrý zápis pak pošle to id, '
						. '"create": true a token. Zakládání zákazníka má výhradu, kterou vrací "createNote" '
						. 've schématu — přetlumoč ji uživateli dřív, než záznam založíš.',
					'parameters' => [
						self::pathParam('presenter', 'Jméno presenteru, například Eshop:Admin:Customer.'),
						self::pathParam('form', 'Jméno formuláře, obvykle form.'),
					],
					'requestBody' => [
						'required' => true,
						'content' => ['application/json' => [
							'schema' => [
								'type' => 'object',
								'required' => ['values'],
								'properties' => [
									'id' => ['type' => 'string', 'description' => 'Id upravovaného záznamu. '
										. 'U zakládání se v náhledu vynechá (brána ho vygeneruje a vrátí) a při ostrém '
										. 'zápisu se pošle to, které náhled vrátil.'],
									'create' => ['type' => 'boolean', 'description' => 'true = zakládá se nový záznam. '
										. 'Zakládání se nepozná z toho, že id v databázi není — bez "create": true je '
										. 'neznámé id chyba, ne pokyn k založení.'],
									'values' => [
										'type' => 'object',
										'description' => 'Jméno pole ze schématu => nová hodnota. Jen to, co se mění.',
										'additionalProperties' => true,
									],
									'dryRun' => ['type' => 'boolean', 'description' => 'true = jen validace a diff, nic se nezapíše.'],
									'previewToken' => ['type' => 'string', 'description' => 'Token z předchozího dryRun; u ostrého zápisu povinný.'],
									'actor' => ['type' => 'string', 'description' => 'Jméno člověka, za kterého se zapisuje; jde do auditu.'],
								],
							],
							'examples' => [
								'úprava existujícího záznamu' => ['value' => [
									'id' => 'a1b2c3d4',
									'values' => ['phone' => '+420 601 000 000'],
									'dryRun' => true,
									'actor' => 'Jan Novák',
								]],
								'náhled založení nového záznamu' => ['value' => [
									'values' => ['company' => 'Nováková s.r.o.', 'email' => 'novak@example.com', 'fullname' => 'Jan Novák'],
									'dryRun' => true,
									'actor' => 'Jan Novák',
								]],
								'ostrý zápis založení' => ['value' => [
									'id' => 'id z náhledu',
									'create' => true,
									'values' => ['company' => 'Nováková s.r.o.', 'email' => 'novak@example.com', 'fullname' => 'Jan Novák'],
									'dryRun' => false,
									'previewToken' => 'eyJ2Ijoi….9f3c',
									'actor' => 'Jan Novák',
								]],
							],
						]],
					],
					'responses' => [
						'200' => [
							'description' => 'Náhled (dryRun) nebo provedený zápis',
							'content' => ['application/json' => ['example' => [
								'dryRun' => true,
								'valid' => true,
								'previewToken' => 'eyJ2Ijoi….9f3c',
								'expiresAt' => '2026-09-11T14:32:00+02:00',
								'diff' => [[
									'field' => 'phone',
									'label' => 'Telefon',
									'from' => '+420 602 111 222',
									'to' => '+420 601 000 000',
								]],
								'errors' => [],
								'warnings' => ['Pole „Ceníky" jsou pro tuhle roli jen ke čtení; pokus o jejich změnu skončí chybou 403.'],
							]]],
						],
						'403' => ['description' => 'Role nesmí měnit některé z poslaných polí'],
						'409' => ['description' => 'Chybí previewToken, vypršel, nebo se záznam mezitím změnil'],
						'422' => ['description' => 'Formulář neprošel validací'],
					],
				],
			],
			'/v1/admin/actions/{presenter}/{signal}' => [
				'post' => [
					'summary' => 'Spuštění akce administrace',
					'description' => 'Stavové akce, které v administraci dělají tlačítka (storno objednávky, '
						. 'přijetí, dokončení, otevření). Stejně jako u formulářů: nejdřív "dryRun": true '
						. 'kvůli náhledu a tokenu, pak ostrý běh. Akce označené "destructive": true chtějí '
						. 'navíc "confirm": true.',
					'parameters' => [
						self::pathParam('presenter', 'Jméno presenteru, například Eshop:Admin:Order.'),
						self::pathParam('signal', 'Jméno akce, například cancelOrder.'),
					],
					'requestBody' => [
						'required' => true,
						'content' => ['application/json' => [
							'schema' => [
								'type' => 'object',
								'required' => ['params'],
								'properties' => [
									'params' => [
										'type' => 'object',
										'description' => 'Parametry akce podle /v1/admin/pages, například {"orderId": "…"}.',
										'additionalProperties' => ['type' => 'string'],
									],
									'dryRun' => ['type' => 'boolean'],
									'previewToken' => ['type' => 'string'],
									'confirm' => ['type' => 'boolean', 'description' => 'Povinné true u destruktivních akcí.'],
									'actor' => ['type' => 'string'],
								],
							],
							'example' => [
								'params' => ['orderId' => 'a1b2c3d4'],
								'dryRun' => true,
							],
						]],
					],
					'responses' => [
						'200' => [
							'description' => 'Náhled (dryRun) nebo provedená akce',
							'content' => ['application/json' => ['example' => [
								'dryRun' => true,
								'action' => 'cancelOrder',
								'label' => 'Stornovat objednávku',
								'destructive' => true,
								'record' => ['id' => 'a1b2c3d4', 'code' => '2026001234', 'state' => 'received'],
								'requiresConfirm' => true,
								'previewToken' => 'eyJ2Ijoi….9f3c',
								'expiresAt' => '2026-09-11T14:32:00+02:00',
							]]],
						],
						'403' => ['description' => 'Role nemá na té stránce plný přístup'],
						'409' => ['description' => 'Chybí previewToken, vypršel, nebo se záznam mezitím změnil'],
					],
				],
			],
			'/v1/admin/grids/{presenter}/{grid}' => [
				'get' => [
					'summary' => 'Seznam administrace (grid)',
					'description' => 'Řádky seznamu tak, jak je vidí člověk v administraci — text buněk pod hlavičkami '
						. 'sloupců, s filtry, stránkováním a řazením gridu. Které seznamy jde číst, vypíše /v1/admin/pages '
						. '(„grids"); jejich filtry vrátí první dotaz v „filters". Filtr, který grid nezná, je 400 — '
						. 'nevrací se nefiltrovaný seznam, který by vypadal jako odfiltrovaný.',
					'parameters' => [
						self::pathParam('presenter', 'Jméno presenteru, například Eshop:Admin:CustomerLead.'),
						self::pathParam('grid', 'Jméno gridu, například grid.'),
						['name' => 'filter', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'],
							'description' => 'JSON objekt jméno filtru → hodnota, například {"search":"Novák"}. Jména filtrů jsou v „filters".'],
						['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1], 'description' => 'Stránka, od 1.'],
						['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
							'description' => 'Řádků na stránku, výchozí 20, nejvýš 100.'],
						['name' => 'order', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'],
							'description' => '„<výraz>-ASC" nebo „<výraz>-DESC", výrazy jsou v „orderable".'],
						['name' => 'id', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'],
							'description' => 'Id záznamu u seznamu, který k němu patří (v /v1/admin/pages má „requiresId": true) — např. produkty jedné akce.'],
						['name' => 'tab', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'],
							'description' => 'Záložka seznamu, který ji má (v /v1/admin/pages „tabs") — např. která patička u menu.'],
					],
					'responses' => [
						'200' => ['description' => 'Stránka seznamu: total, pageCount, hasMore, columns, rows [{id, cells}], filters, orderable'],
						'400' => ['description' => 'Neznámý filtr, vadný JSON ve „filter" nebo neznámé řazení'],
						'403' => ['description' => 'Účet Doryo nesmí na stránku seznamu'],
						'404' => ['description' => 'Seznam brána číst neumí'],
					],
				],
			],
			'/v1/admin/k2/imports' => [
				'get' => [
					'summary' => 'Stav importů z K2',
					'description' => 'Poslední běh každého importu z K2 (zákazníci, produkty, ceníky, sklad, faktury…): stav, '
						. 'kdy začal a skončil, kdo ho spustil a konec zprávy. S „target" historie jednoho kódu, s „type" '
						. 'historie jednoho typu. Tady se začíná, když na B2B nesedí hodnota nastavená v K2 — nejdřív zjisti, '
						. 'jestli import doběhl.',
					'parameters' => [
						['name' => 'target', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'],
							'description' => 'Kód zákazníka (Z000123) nebo produktu (12345.01) v K2 — historie jeho jednotlivých importů.'],
						['name' => 'type', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'],
							'description' => 'Typ importu (k2Customers, k2AllPricelists, k2StoreAmounts…) — historie jeho běhů.'],
					],
					'responses' => ['200' => ['description' => 'OK'], '403' => ['description' => 'Účet nesmí na stránku Importy z K2']],
				],
			],
			'/v1/admin/k2/prices' => [
				'get' => [
					'summary' => 'Proč zákazník vidí na produktu tuhle cenu (kontrola ceny proti importu z K2)',
					'description' => 'Ceníky zákazníka s cenou produktu v každém z nich, který ceník podle pravidla e-shopu '
						. 'vyhrává (nižší priorita, pak nižší cena; akční ceníky mají přednost), ceníky přiřazené, ale nepočítané '
						. '(jiná měna, neaktivní), a stav importu cen z K2 — jestli dnes přišel ZIP a jestli se ceny opravdu '
						. 'nahrály. Živou cenu z K2 neobsahuje (dotaz do K2 trvá ~50 s) — viz „k2Live". Jen čtení.',
					'parameters' => [
						['name' => 'customer', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string'],
							'description' => 'Id zákazníka, číslo z K2 nebo IČ (u IČ s pobočkami vrátí 400 se seznamem).'],
						['name' => 'product', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string'],
							'description' => 'Kód produktu z K2 (např. 17014.01) nebo id produktu.'],
					],
					'responses' => [
						'200' => ['description' => 'customer, product, winner, pricelists, import, hints, k2Live'],
						'400' => ['description' => 'Chybí zákazník nebo produkt, nebo IČ patří víc zákazníkům'],
						'403' => ['description' => 'Účet Doryo nesmí na stránku Ceníky'],
						'404' => ['description' => 'Zákazník nebo produkt v e-shopu není'],
					],
				],
			],
			'/v1/admin/k2/reimport/{kind}' => [
				'post' => [
					'summary' => 'Znovu naimportovat jednoho zákazníka nebo produkt z K2 (destruktivní)',
					'description' => 'Tentýž import jako tlačítko na stránce Nastavení → Importy z K2. Záznam PŘEPÍŠE hodnotami '
						. 'z K2 a u zákazníka umí založit e-shopové účty s e-mailem s heslem na zákazníka, přesunout účet '
						. 'od jiného zákazníka a účty smazat. Postup: 1) "dryRun": true — náhled sáhne do K2 a vrátí, co se '
						. 'přepíše, co se stane s účty („accounts"), komu odejde e-mail a previewToken; 2) náhled ukaž '
						. 'uživateli a POČKEJ na jeho výslovné „ano" v konverzaci — souhlas s nástrojem nestačí; 3) teprve pak '
						. 'totéž s "dryRun": false, "confirm": true a tokenem. Když K2 neodpovídá, náhled nevznikne a reimport '
						. 'se nespustí. Kompletní import přes API spustit nejde.',
					'parameters' => [self::pathParam('kind', '„customer" nebo „product".')],
					'requestBody' => [
						'required' => true,
						'content' => ['application/json' => [
							'schema' => [
								'type' => 'object',
								'required' => ['code'],
								'properties' => [
									'code' => ['type' => 'string', 'description' => 'Kód v K2: zákazník Z000123, produkt 12345.01.'],
									'dryRun' => ['type' => 'boolean', 'description' => 'true = jen náhled, nic se nespustí.'],
									'previewToken' => ['type' => 'string', 'description' => 'Token z náhledu; u ostrého běhu povinný.'],
									'confirm' => ['type' => 'boolean', 'description' => 'U ostrého běhu povinné true — vždy.'],
									'actor' => ['type' => 'string', 'description' => 'Jméno člověka, za kterého se import spouští; jde do logu běhů a auditu.'],
								],
							],
							'example' => ['code' => 'Z000123', 'dryRun' => true, 'actor' => 'Jan Novák'],
						]],
					],
					'responses' => [
						'200' => ['description' => 'Náhled (dryRun), nebo proběhlý import — „ok": false znamená, že import nedoběhl'],
						'400' => ['description' => 'Chybí nebo je vadný kód, nebo u ostrého běhu chybí "confirm": true'],
						'403' => ['description' => 'Účet nemá na stránce Importy z K2 plný přístup (777)'],
						'404' => ['description' => 'Záznam v K2 není (mezi aktivními odběrateli / zbožními položkami)'],
						'409' => ['description' => 'Chybí previewToken, vypršel, nebo se K2 či e-shop od náhledu změnily'],
						'502' => ['description' => 'K2 neodpovědělo — náhled ani reimport nejde'],
					],
				],
			],
			'/v1/admin/bulk/{presenter}/{grid}' => [
				'get' => [
					'summary' => 'Co umí hromadná úprava gridu',
					'description' => 'Vstupy hromadné úpravy i s režimy („add" přidá k tomu, co záznam má, '
						. '„replace" původní vazby zahodí a nastaví poslané, „set" nastaví obyčejné pole), '
						. 'filtry, podle kterých jde vybrat cíle, a práh, od kterého ostrý zápis chce potvrzení. '
						. 'Co role bez „Plného přístupu" (777) nesmí, se v seznamu vůbec neobjeví.',
					'parameters' => [
						self::pathParam('presenter', 'Jméno presenteru, například Eshop:Admin:Customer.'),
						self::pathParam('grid', 'Jméno gridu, například customers.'),
					],
					'responses' => ['200' => ['description' => 'OK']],
				],
				'post' => [
					'summary' => 'Hromadná úprava (dvoufázová)',
					'description' => 'Cíle se zadají buď výčtem „ids", nebo filtrem gridu „filter" — jedno z toho, '
						. 'ne obojí. Nejdřív zavolej s "dryRun": true: vrátí se počet cílů, ukázka záznamů, seznam '
						. 'změn a previewToken. To ukaž uživateli a teprve pak zapiš s "dryRun": false a tím tokenem. '
						. 'U filtru se množina spočítá znovu i při zápisu; když se mezitím změnila, je odpověď 409. '
						. 'Je-li cílů víc než "confirmFrom" ze schématu, chce ostrý zápis navíc "confirm": true. '
						. 'ODEBRAT jednu položku vztahu (jeden ceník z pěti) hromadná úprava neumí — je to 400 '
						. 'a řeší se buď „replace" s výsledným seznamem, nebo úpravou jednotlivých záznamů.',
					'parameters' => [
						self::pathParam('presenter', 'Jméno presenteru, například Eshop:Admin:Customer.'),
						self::pathParam('grid', 'Jméno gridu, například customers.'),
					],
					'requestBody' => [
						'required' => true,
						'content' => ['application/json' => [
							'schema' => [
								'type' => 'object',
								'required' => ['values'],
								'properties' => [
									'ids' => [
										'type' => 'array',
										'description' => 'Id záznamů, na které se má sáhnout. Buď tohle, nebo „filter".',
										'items' => ['type' => 'string'],
									],
									'filter' => [
										'type' => 'object',
										'description' => 'Filtry gridu ze schématu („merchant", „pricelist", „ic"…). '
											. 'Cílem je celý odfiltrovaný výsledek. Buď tohle, nebo „ids".',
										'additionalProperties' => true,
									],
									'values' => [
										'type' => 'object',
										'description' => 'Jméno vstupu => {režim: hodnota}. Režimy: „add" a „replace" '
											. 'u vztahů N:N (ceníky, ceníky pro export, seznamy viditelnosti), „set" '
											. 'u obyčejných polí (sleva, fiktivní ceník) — tam se dá poslat i holá hodnota.',
										'additionalProperties' => true,
									],
									'dryRun' => ['type' => 'boolean', 'description' => 'true = jen náhled, nic se nezapíše.'],
									'previewToken' => ['type' => 'string', 'description' => 'Token z předchozího dryRun; u ostrého zápisu povinný.'],
									'confirm' => ['type' => 'boolean', 'description' => 'Povinné true, když náhled vrátil "requiresConfirm": true.'],
									'actor' => ['type' => 'string', 'description' => 'Jméno člověka, za kterého se zapisuje; jde do auditu.'],
								],
							],
							'examples' => [
								'přidej ceník dvěma zákazníkům' => ['value' => [
									'ids' => ['Z002462', 'Z002463'],
									'values' => ['pricelists' => ['add' => ['id-ceniku']]],
									'dryRun' => true,
									'actor' => 'Jan Novák',
								]],
								'nahraď ceníky celého seznamu' => ['value' => [
									'ids' => ['Z002462'],
									'values' => ['pricelists' => ['replace' => ['id-ceniku-a', 'id-ceniku-b']]],
									'dryRun' => true,
								]],
								'sleva všem zákazníkům jednoho obchodníka' => ['value' => [
									'filter' => ['merchant' => ['id-obchodnika']],
									'values' => ['discountLevelPct' => 5],
									'dryRun' => true,
								]],
							],
						]],
					],
					'responses' => [
						'200' => [
							'description' => 'Náhled (dryRun) nebo provedená hromadná úprava',
							'content' => ['application/json' => ['example' => [
								'dryRun' => true,
								'targets' => [
									'count' => 2,
									'sample' => [
										['id' => 'Z002462', 'company' => 'Novák s.r.o.'],
										['id' => 'Z002463', 'company' => 'Dvořák a syn'],
									],
									'truncated' => false,
								],
								'changes' => [[
									'field' => 'pricelists',
									'label' => 'Ceníky',
									'mode' => 'add',
									'value' => ['id-ceniku'],
									'valueLabel' => ['Velkoobchod B'],
								]],
								'requiresConfirm' => false,
								'previewToken' => 'eyJ2Ijoi….9f3c',
								'expiresAt' => '2026-09-11T14:32:00+02:00',
							]]],
						],
						'400' => ['description' => 'Neznámý filtr, pole, které hromadná úprava nenabízí, pokus o „remove", nebo chybějící confirm'],
						'403' => ['description' => 'Role nemá na té stránce plný přístup (777)'],
						'409' => ['description' => 'Chybí previewToken, vypršel, nebo se cílová množina mezitím změnila'],
					],
				],
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getOpenApiComponents(): array
	{
		return [];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function pathParam(string $name, string $description): array
	{
		return [
			'name' => $name,
			'in' => 'path',
			'required' => true,
			'description' => $description,
			'schema' => ['type' => 'string'],
		];
	}
}
