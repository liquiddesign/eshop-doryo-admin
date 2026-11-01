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
 * Cesty jsou schválně generické — šest cest místo desítek ručně psaných per doména. Popis
 * konkrétního formuláře si model tahá až na dotaz, což přesně sedí na to, jak s API pracuje
 * konektor v Kolegovi: introspect, najdi endpoint, přečti detail, zavolej.
 *
 * Co je jen projektu (vlastní importy, kontroly, domény), do brány nepatří: projekt si přidá
 * vlastní endpoint přes `doryoApi: endpoints:` a do mapy stránek ho zapíše přes {@see PageDescriber}.
 */
final class AdminEndpoint implements Endpoint, MethodAware, SpecificationPart
{
	private const ROUTE_PAGES = 'v1/admin/pages';
	private const ROUTE_FORMS = 'v1/admin/forms/{presenter}/{form}';
	private const ROUTE_ACTIONS = 'v1/admin/actions/{presenter}/{signal}';
	private const ROUTE_BULK = 'v1/admin/bulk/{presenter}/{grid}';
	private const ROUTE_GRIDS = 'v1/admin/grids/{presenter}/{grid}';
	private const ROUTE_DELETE = 'v1/admin/delete/{presenter}/{grid}';

	private const MERCHANT_DESCRIPTION = 'Obchodník, za kterého se jedná (id). Brána se pak přihlásí jako jeho administrátorský '
		. 'účet, takže platí jeho role i to, co v administraci vidí. Dosazuje ho Kolego (případně hlavička X-Doryo-Za), '
		. 'model ho nemá vymýšlet ani měnit. Bez něj jede brána pod servisním účtem.';

	public function __construct(
		private PageMap $pageMap,
		private FormGateway $formGateway,
		private ActionGateway $actionGateway,
		private BulkGateway $bulkGateway,
		private GridGateway $gridGateway,
		private DeleteGateway $deleteGateway,
		private GatewayConfig $config,
		private PresenterRunner $runner,
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
			self::ROUTE_DELETE => 'delete',
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
			self::ROUTE_DELETE => ['POST'],
		];
	}

	/**
	 * @param array<string, string> $params
	 */
	public function pages(array $params, Query $query): Response
	{
		unset($params);
		$this->runner->identify($query);

		$detail = \in_array($query->string('detail'), ['1', 'true'], true);

		return new Response($this->pageMap->build($detail, $query->string('q'), $query->string('presenter')));
	}

	/**
	 * @param array<string, string> $params
	 */
	public function form(array $params, Query $query): Response
	{
		$target = $this->config->getForm($params['presenter'], $params['form']);
		$this->runner->identify($query);

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

		return new Response($this->formGateway->schema($target, $id, $query->string('item'), $query->string('parent'), $query->string('fields'), $query->string('tab')));
	}

	/**
	 * @param array<string, string> $params
	 */
	public function bulk(array $params, Query $query): Response
	{
		$target = $this->config->getBulk($params['presenter'], $params['grid']);
		$this->runner->identify($query);

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
		$this->runner->identify($query);

		return new Response($this->actionGateway->run($target, $query->getBody() ?? []));
	}

	/**
	 * @param array<string, string> $params
	 */
	public function delete(array $params, Query $query): Response
	{
		$target = $this->config->getDelete($params['presenter'], $params['grid']);
		$this->runner->identify($query);

		return new Response($this->deleteGateway->submit($target, $query->getBody() ?? []));
	}

	/**
	 * @param array<string, string> $params
	 */
	public function grid(array $params, Query $query): Response
	{
		$target = $this->config->getGrid($params['presenter'], $params['grid']);
		$this->runner->identify($query);

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
	 * @inheritDoc
	 */
	public function getOpenApiPaths(): array
	{
		return [
			'/v1/admin/pages' => [
				'get' => [
					'summary' => 'Mapa administrace',
					'description' => 'Stránky administrace, na které smí účet agenta, a u nich formuláře '
						. 'a akce, které jde přes API použít. Odsud se vychází — jméno presenteru a formuláře '
						. 'z téhle odpovědi se dosazuje do ostatních cest. „identity" říká, za koho brána jedná; '
						. 's obchodníkem (merchantId) jsou to stránky a oprávnění jeho administrátorského účtu. '
						. 'Bez parametrů krátký přehled (jen jména formulářů, seznamů a akcí); celou stránku vrátí presenter, '
						. 'hledání q, celou mapu detail=1.',
					'parameters' => [
						self::merchantParam(),
						['name' => 'q', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'],
							'description' => 'Hledání ve všech stránkách: celé stránky, kde se najdou všechna slova (název, skupina, formuláře, seznamy, akce i popisy; bez ohledu na diakritiku), např. kontakt.'],
						['name' => 'presenter', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'],
							'description' => 'Celá jedna stránka mapy s cestami a popisy, např. Web:Admin:Setting.'],
						['name' => 'detail', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['0', '1']],
							'description' => '1 = celá mapa se vším (velká — u modelu radši presenter nebo q).'],
					],
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
						self::merchantParam(),
						[
							'name' => 'id',
							'in' => 'query',
							'required' => false,
							'description' => 'Id upravovaného záznamu. Vynechá se jen u formuláře, který umí '
								. 'i zakládat (v /v1/admin/pages má "create": true) — pak se vrátí schéma prázdného '
								. 'formuláře a v "createNote" výhrada, kterou je potřeba uživateli přetlumočit.',
							'schema' => ['type' => 'string'],
						],
						['name' => 'item', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'],
							'description' => 'Id položky u formuláře jedné položky záznamu (v /v1/admin/pages má „items") — např. položka objednávky. '
								. 'Bez něj se vrátí seznam položek záznamu.'],
						['name' => 'parent', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'],
							'description' => 'Id nadřazeného záznamu u zakládání pod ním (v /v1/admin/pages má „createParent") — např. akce u nového kupónu.'],
						['name' => 'tab', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'],
							'description' => 'Záložka formuláře, který ji má (kategorie: id typu stromu z categoryTypeGrid) — jen u schématu nového záznamu; u úpravy ji brána vezme ze záznamu.'],
						['name' => 'fields', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'],
							'description' => 'Jen vybraná pole: jména oddělená čárkou, * = cokoli (fields=prices-*-price,name-cs). Formulář produktu má přes '
								. 'tisíc polí — čti jen ta, která potřebuješ; odpověď řekne ve "fieldsFilter", kolik vybralo z kolika.'],
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
						self::merchantParam(),
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
									'item' => ['type' => 'string', 'description' => 'Id položky u formuláře jedné položky záznamu; brána ověří, že k záznamu patří.'],
									'parent' => ['type' => 'string', 'description' => 'Id nadřazeného záznamu u zakládání pod ním (kupón akce: id akce).'],
									'tab' => ['type' => 'string', 'description' => 'Záložka nového záznamu u formuláře, který ji má (kategorie: id typu stromu). U úpravy ji brána vezme ze záznamu, u založení i z nadřazeného (ancestor).'],
									'create' => ['type' => 'boolean', 'description' => 'true = zakládá se nový záznam. '
										. 'Zakládání se nepozná z toho, že id v databázi není — bez "create": true je '
										. 'neznámé id chyba, ne pokyn k založení.'],
									'values' => [
										'type' => 'object',
										'description' => 'Jméno pole ze schématu => nová hodnota. Jen to, co se mění.',
										'additionalProperties' => true,
									],
									'files' => [
										'type' => 'object',
										'description' => 'Pole typu soubor (ve schématu mají „upload") => {"name": "<název>", "content": "<obsah v base64>"}. '
											. 'Nový soubor nahradí ten dnešní; brána ho nahrává, jen když to e-shop zapnul (maxUploadBytes).',
										'additionalProperties' => [
											'type' => 'object',
											'properties' => ['name' => ['type' => 'string'], 'content' => ['type' => 'string', 'format' => 'byte']],
										],
									],
									'dryRun' => ['type' => 'boolean', 'description' => 'true = jen validace a diff, nic se nezapíše.'],
									'previewToken' => ['type' => 'string', 'description' => 'Token z předchozího dryRun; u ostrého zápisu povinný.'],
									'actor' => ['type' => 'string', 'description' => 'Jméno člověka, za kterého se zapisuje; jde do auditu jen jako popisek.'],
									'merchantId' => self::merchantProperty(),
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
						'403' => ['description' => 'Role nesmí měnit některé z poslaných polí, nebo administrace záznam neotevřela (účet ho nevidí)'],
						'409' => ['description' => 'Chybí previewToken, vypršel, nebo se záznam mezitím změnil'],
						'422' => ['description' => 'Formulář neprošel validací, nebo uložení administrace odmítla'],
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
						self::merchantParam(),
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
									'file' => [
										'type' => 'object',
										'description' => 'Soubor pro akci, která ho bere (v /v1/admin/pages má „file"), například fotka k produktu: '
											. '{"name": "<název>", "content": "<obsah v base64>"}.',
										'properties' => ['name' => ['type' => 'string'], 'content' => ['type' => 'string', 'format' => 'byte']],
									],
									'actor' => ['type' => 'string', 'description' => 'Jméno člověka, za kterého se akce spouští; jde do auditu jen jako popisek.'],
									'merchantId' => self::merchantProperty(),
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
						'403' => ['description' => 'Role nemá na té stránce plný přístup, nebo administrace záznam neotevřela (účet ho nevidí)'],
						'409' => ['description' => 'Chybí previewToken, vypršel, nebo se záznam mezitím změnil'],
						'422' => ['description' => 'Administrace akci odmítla (flash zpráva chyba/varování) — nic se neprovedlo'],
					],
				],
			],
			'/v1/admin/delete/{presenter}/{grid}' => [
				'post' => [
					'summary' => 'Smazání záznamů z gridu administrace',
					'description' => 'Maže tlačítkem „Smazat" nad vybranými řádky, tedy tak, jak maže člověk: platí podmínky '
						. 'projektu i úklid po smazání. Vždy dvoufázově: "dryRun": true vypíše, co zmizí, a vydá previewToken; '
						. 'ostrý běh se stejnými „ids" chce token i "confirm": true. Co administrace nesmaže (záznam je '
						. 'použitý jinde, projekt to nedovolí), vrátí odpověď v „remaining" — nikdy to nehlásí jako smazané.',
					'parameters' => [
						self::pathParam('presenter', 'Jméno presenteru, například Eshop:Admin:Producer.'),
						self::pathParam('grid', 'Jméno gridu podle /v1/admin/pages (klíč „delete").'),
						self::merchantParam(),
					],
					'requestBody' => [
						'required' => true,
						'content' => ['application/json' => [
							'schema' => [
								'type' => 'object',
								'required' => ['ids'],
								'properties' => [
									'ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Id mazaných záznamů (z gridu).'],
									'id' => ['type' => 'string', 'description' => 'Id záznamu, ke kterému seznam patří (v /v1/admin/pages „requiresId": true) — např. slider u slidů.'],
									'tab' => ['type' => 'string', 'description' => 'Záložka seznamu, který ji má („tabs": true) — např. typ menu nebo kategorií. Stejná jako při čtení gridu.'],
									'dryRun' => ['type' => 'boolean'],
									'previewToken' => ['type' => 'string'],
									'confirm' => ['type' => 'boolean', 'description' => 'U ostrého běhu povinné true.'],
									'actor' => ['type' => 'string', 'description' => 'Jméno člověka, za kterého se maže; jde do auditu jen jako popisek.'],
									'merchantId' => self::merchantProperty(),
								],
							],
							'example' => ['ids' => ['a1b2c3d4'], 'dryRun' => true],
						]],
					],
					'responses' => [
						'200' => [
							'description' => 'Náhled (dryRun) nebo provedené smazání',
							'content' => ['application/json' => ['example' => [
								'dryRun' => false,
								'deleted' => 1,
								'deletedIds' => ['a1b2c3d4'],
								'remaining' => [],
								'complete' => true,
								'records' => ['count' => 1, 'sample' => [['id' => 'a1b2c3d4', 'name' => 'Mamut']], 'truncated' => false],
							]]],
						],
						'400' => ['description' => 'Chybí "confirm": true u ostrého běhu'],
						'403' => ['description' => 'Role nemá na té stránce plný přístup'],
						'404' => ['description' => 'Některé id v gridu není (neexistuje, nebo ho účet nevidí), nebo grid mazání nemá'],
						'409' => ['description' => 'Chybí previewToken, vypršel, nebo ostrý běh maže jiné záznamy než náhled'],
						'422' => ['description' => 'Administrace nesmazala ani jeden záznam'],
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
						self::pathParam('presenter', 'Jméno presenteru, například Eshop:Admin:Customer.'),
						self::pathParam('grid', 'Jméno gridu, například grid.'),
						self::merchantParam(),
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
						'403' => ['description' => 'Účet agenta nesmí na stránku seznamu, nebo ji administrace odmítla otevřít'],
						'404' => ['description' => 'Seznam brána číst neumí'],
					],
				],
			],
			'/v1/admin/bulk/{presenter}/{grid}' => [
				'get' => [
					'summary' => 'Co umí hromadná úprava gridu',
					'description' => 'Vstupy hromadné úpravy i s režimy, které u nich administrace nabízí („add" přidá '
						. 'k tomu, co záznam má, „remove" odebere, „replace" původní vazby zahodí a nastaví poslané, „set" '
						. 'nastaví obyčejné pole), filtry, podle kterých jde vybrat cíle, a práh, od kterého ostrý zápis chce '
						. 'potvrzení. Co role bez „Plného přístupu" (777) nesmí, se v seznamu vůbec neobjeví.',
					'parameters' => [
						self::pathParam('presenter', 'Jméno presenteru, například Eshop:Admin:Customer.'),
						self::pathParam('grid', 'Jméno gridu, například customers.'),
						self::merchantParam(),
					],
					'responses' => ['200' => ['description' => 'OK']],
				],
				'post' => [
					'summary' => 'Hromadná úprava (dvoufázová)',
					'description' => 'Cíle se zadají buď výčtem „ids", nebo filtrem gridu „filter" — jedno z toho, '
						. 'ne obojí. Nejdřív zavolej s "dryRun": true: vrátí se počet cílů, ukázka záznamů, seznam '
						. 'změn a previewToken. To ukaž uživateli a teprve pak zapiš s "dryRun": false a tím tokenem. '
						. 'U filtru se množina spočítá znovu i při zápisu; když se mezitím změnila, je odpověď 409. '
						. 'Totéž, když se cílům mezitím změnila pole, která se mění (třeba je někdo upravil v administraci). '
						. 'Je-li cílů víc než "confirmFrom" ze schématu, chce ostrý zápis navíc "confirm": true. '
						. 'Režim musí být jeden z „modes" vstupu ve schématu — přidat a odebrat jednu položku jde jen '
						. 'u vztahu N:N, jinde se posílá „replace" s výsledným seznamem.',
					'parameters' => [
						self::pathParam('presenter', 'Jméno presenteru, například Eshop:Admin:Customer.'),
						self::pathParam('grid', 'Jméno gridu, například customers.'),
						self::merchantParam(),
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
										'description' => 'Jméno vstupu => {režim: hodnota}. Režimy: „add", „remove" a „replace" '
											. 'u vztahů N:N (ceníky, seznamy viditelnosti), jen „replace" u ostatních výběrů více '
											. 'položek, „set" u obyčejných polí (sleva, fiktivní ceník) — tam se dá poslat i holá hodnota.',
										'additionalProperties' => true,
									],
									'dryRun' => ['type' => 'boolean', 'description' => 'true = jen náhled, nic se nezapíše.'],
									'previewToken' => ['type' => 'string', 'description' => 'Token z předchozího dryRun; u ostrého zápisu povinný.'],
									'confirm' => ['type' => 'boolean', 'description' => 'Povinné true, když náhled vrátil "requiresConfirm": true.'],
									'actor' => ['type' => 'string', 'description' => 'Jméno člověka, za kterého se zapisuje; jde do auditu jen jako popisek.'],
									'merchantId' => self::merchantProperty(),
								],
							],
							'examples' => [
								'přidej ceník dvěma zákazníkům' => ['value' => [
									'ids' => ['Z002462', 'Z002463'],
									'values' => ['pricelists' => ['add' => ['id-ceniku']]],
									'dryRun' => true,
									'actor' => 'Jan Novák',
								]],
								'odeber ceník dvěma zákazníkům' => ['value' => [
									'ids' => ['Z002462', 'Z002463'],
									'values' => ['pricelists' => ['remove' => ['id-ceniku']]],
									'dryRun' => true,
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
						'400' => ['description' => 'Neznámý filtr, filtr bez výsledku, pole, které hromadná úprava nenabízí, režim, který pole nemá, nebo chybějící confirm'],
						'403' => ['description' => 'Role nemá na té stránce plný přístup (777)'],
						'404' => ['description' => 'Některé z „ids" v seznamu není (neexistuje, nebo ho účet nevidí)'],
						'409' => ['description' => 'Chybí previewToken, vypršel, nebo se cílová množina či její měněná pole mezitím změnily'],
						'422' => ['description' => 'Hodnota mimo číselník, nebo hromadnou úpravu administrace odmítla'],
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
	 * Obchodník, za kterého se jedná — parametr dotazu. Kolego ho dosazuje sám (nebo posílá hlavičku
	 * X-Doryo-Za); model ho nemá vymýšlet ani měnit.
	 * @return array<string, mixed>
	 */
	private static function merchantParam(): array
	{
		return [
			'name' => MerchantAdministratorResolver::PARAMETER,
			'in' => 'query',
			'required' => false,
			'description' => self::MERCHANT_DESCRIPTION,
			'schema' => ['type' => 'string'],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function merchantProperty(): array
	{
		return ['type' => 'string', 'description' => self::MERCHANT_DESCRIPTION];
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
