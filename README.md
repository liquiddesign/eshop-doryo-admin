# eshop-doryo-admin

Zápis do administrace e-shopu pro [Doryo / Kolego](https://lqd.kolego.cz). Rozšiřuje balík
[`liquiddesign/eshop-doryo-api`](https://github.com/liquiddesign/eshop-doryo-api) (čtecí API)
o cesty `/v1/admin/…`, přes které agent čte seznamy administrace, vyplňuje její formuláře,
spouští její akce, dělá hromadné úpravy a maže z gridů — se stejnými kontrolami a stejnými následky, jako kdyby
to člověk naklikal v `/admin`.

**Nic se nekopíruje.** Brána spouští presentery administrace normální cestou Nette
(`IPresenterFactory::createPresenter()` a `Presenter::run()`), jen bez prohlížeče. Platí tedy
skutečná oprávnění (`checkRequirements()`), skutečná validace a skutečná `onSuccess` logika —
i ta ve vendoru, kterou projekt nemá jak duplikovat. Když někdo formulář změní, brána ho popíše
a vyplní podle nové podoby.

**Zápis je vždycky dvoufázový:** náhled (`"dryRun": true`) vrátí, co se změní, a podepsaný
`previewToken`; ostrý zápis bez tokenu neprojde. Agent tak nemůže zapsat nic jiného, než co předtím
ukázal uživateli.

Cílí na novou generaci knihoven: PHP ≥ 8.2, nette/application 3.2, nette/forms 3.2,
nette/http 3.3–3.4, liquiddesign/admin 2.1, grid 1.0, forms 3.0. Vznikl z „brány administrace"
projektu Levior (tam běží nad starší generací); první konzument je For Baby.

## Instalace

```bash
composer require liquiddesign/eshop-doryo-admin
```

```neon
extensions:
    doryoApi: DoryoApi\Bridges\DoryoApiDI
    doryoAdmin: DoryoAdmin\Bridges\DoryoAdminDI

doryoAdmin:
    administratorLogin: doryo
```

Služby si balík zaregistruje sám a cesty `/v1/admin/…` **sám přidá do API** balíku
eshop-doryo-api: v `beforeCompile()` připíše službu `@doryoAdmin.endpoint` do endpointů routeru
a `openapi.json`, jako by ji projekt uvedl v `doryoApi: endpoints:`. Rozšíření eshop-doryo-api se
hledá podle třídy, na jeho klíči v `extensions:` nezáleží. Kdo to chce mít v configu výslovně,
nastaví `registerEndpoint: false` a přidá ji sám:

```neon
doryoApi:
    endpoints:
        - @doryoAdmin.endpoint
```

(Když ji projekt uvede sám a `registerEndpoint` nechá zapnutý, podruhé se nepřidá.)

Předpoklady, které už splňuje každý shop s čtecím API: `doryoApi` má token a požadavek na
`DoryoApi:Api` projde bránou na front (`frontAccess.exclude`). Brána potřebuje rozšíření
administrace `Admin\Bridges\AdminDI` — uživatele administrace (`Admin\Administrator`) si najde podle
typu.

## Konfigurace

| klíč | výchozí | k čemu |
| --- | --- | --- |
| `administratorLogin` | `doryo` | login administrátorského účtu, pod kterým brána jede (servisní účet) |
| `agentName` | `Kolego` | jméno agenta do auditu: `<administrátor> via Kolego (<actor>)` |
| `previewTokenLifetime` | `600` | platnost náhledového tokenu v sekundách |
| `bulkConfirmFrom` | `50` | od kolika cílů chce hromadná úprava `"confirm": true`; dá se přebít u gridu |
| `merchantAdministrators` | `false` | požadavek za obchodníka jede pod jeho administrátorským účtem, viz [Identita](#identita-kdo-zapisuje) |
| `merchantColumn` | `fk_merchant` | sloupec tabulky administrátorů s vazbou na obchodníka |
| `emailAdministrators` | `false` | požadavek s hlavičkou `X-Doryo-Email` jede pod administrátorem s tímtéž loginem, viz [Identita](#identita-kdo-zapisuje) |
| `administratorEmails` | `[]` | párování e-mail → login účtu administrace, kde login není e-mail |
| `identityResolver` | `null` | vlastní `DoryoAdmin\Gateway\IdentityResolver` projektu (přebíjí `merchantAdministrators`) |
| `administratorService` | `null` | jméno služby `Admin\Administrator`, když jich je víc |
| `registerEndpoint` | `true` | cesty se samy přidají do `doryoApi: endpoints:` |
| `describers` | `[]` | služby `DoryoAdmin\Gateway\PageDescriber`, viz [Rozšíření](#rozšíření-z-projektu) |
| `forms`, `actions`, `grids`, `bulk` | `[]` | whitelist — co brána umí popsat a spustit |

Whitelist je **konfigurace, ne kód** — další stránka se přidá řádkem v NEONu. Oprávnění se jím
neřeší (to drží role účtu v Administrace → Role), whitelist jen říká „tohle brána umí popsat".
Položky mají pevné klíče, překlep spadne už při kompilaci kontejneru. Popisy jsou texty pro model;
procento se v nich píše `%%` (Nette jinak `%…%` bere jako parametr) a text nesmí začínat `@`.

| položka | klíče (výchozí) |
| --- | --- |
| `forms` | `presenter`*, `parameter`* (parametr akce se záznamem), `form` (`form`), `action` (`edit`), `label`, `description`, `create` (`false`), `createAction` (`new`), `entity` (kvůli zakládání), `createNote`, `hidden` (pole, která brána nevydá ani nezmění), `customerEmail` (`false`) |
| `actions` | `presenter`*, `signal`* (bez `handle`), `action` (`default`), `label`, `params` (jména parametrů signálu), `destructive` (`false`), `entity`, `idParam`, `customerEmail` (`false`) |
| `grids` | `presenter`*, `grid` (`grid`), `action` (`default`), `label`, `description`, `filters` (ruční popis filtrů bez popisku), `parameter` (seznam patří k záznamu), `tabParameter` (persistentní parametr se záložkou) |
| `bulk` | `presenter`*, `entity`*, `grid` (`grid`), `form` (`form`, detailový formulář, ze kterého si hromadná úprava půjčuje vstupy), `action` (`bulkEdit`), `label`, `bulkConfirmFrom`, `filters` |
| `delete` | `presenter`*, `entity`* (entita řádků — podle ní brána popíše a po smazání ověří, co zmizelo), `grid` (`grid`), `action` (`default`), `label`, `description` (co smazání znamená), `parameter` (seznam patří k záznamu), `tabParameter` (persistentní parametr se záložkou) |

\* povinné

`customerEmail: true` u formuláře nebo akce říká, že uložení či spuštění pošle e-mail zákazníkovi (změna stavu objednávky,
storno, odkaz na nové heslo). Brána ho vrací v náhledu i v mapě stránek a Kolego to na kartě řekne nahoře.

### Příklad: For Baby

```neon
# config/doryo-admin.neon
extensions:
    doryoAdmin: DoryoAdmin\Bridges\DoryoAdminDI

doryoAdmin:
    administratorLogin: doryo
    # obchodník z Kolega (X-Doryo-Za / merchantId) jede pod svým administrátorem (admin_administrator.fk_merchant),
    # takže platí role „Obchodník" a MerchantAdminContext — vidí a mění jen své zákazníky a jejich objednávky
    merchantAdministrators: true
    forms:
        -
            presenter: 'Eshop:Admin:Customer'
            form: form
            action: edit
            parameter: customer
            entity: Eshop\DB\Customer
            label: 'Zákazník'
            description: 'Detail zákazníka: firma, kontakty, ceníky, obchodníci, platební podmínky.'
    grids:
        -
            presenter: 'Eshop:Admin:Customer'
            grid: customers
            label: 'Zákazníci'
            description: 'Fakturační organizace tak, jak je ukazuje administrace (pobočky jsou pod nimi). Údaje jednoho zákazníka čti radši z formuláře zákazníka nebo z /v1/customers.'
    actions:
        -
            presenter: 'Eshop:Admin:Order'
            signal: cancelOrder
            params: [orderId]
            idParam: orderId
            entity: Eshop\DB\Order
            label: 'Stornovat objednávku'
            destructive: true
```

## Účet a role

Brána jede pod administrátorským účtem (`administratorLogin`). Bez něj vrací 503 s návodem.
Zakládá se klikáním, žádná migrace:

1. Administrace → Role: nová role, třeba `Kolego (API)`.
2. V gridu role „Přístup" (555) u stránek, které má agent číst, a „Plný přístup" (777) u stránek,
   kde má měnit. Začni s 555 všude a 777 přidávej.
3. Administrace → Administrátoři: administrátor s účtem `doryo`, dlouhé náhodné heslo (nikdo se
   jím nepřihlašuje) a touhle rolí.

Bez „Plného přístupu" se agentovi pole chovají stejně jako člověku: co administrace bez 777 vypíná
(`setDisabled(!$this->isManager)`), je ve schématu `readOnly: true` a pokus o změnu je 403.
**Akce a hromadné úpravy chtějí 777 vždycky** — `checkRequirements()` u signálů a u akce `bulkEdit`
kontroluje jen přístup na stránku (v administraci je ochranou, že tlačítko vidí jen oprávněný),
přes API je to málo.

Pozor: `PermissionRepository::isAllowed()` porovnává resource jako prefix, takže
`:Eshop:Admin:Customer:*` pustí i `:Eshop:Admin:CustomerLead:*`. Je to chování administrace.

## Identita (kdo zapisuje)

Na začátku každé obsluhy se určí, za koho brána jedná (`DoryoAdmin\Gateway\IdentityResolver`,
vrací `Nette\Security\IIdentity`). Pod touhle identitou se presentery pouštějí, platí její role
a do auditu jde ona. Mapa stránek ji vrací v `identity`.

- **Servisní účet** (výchozí): všechno pod `administratorLogin`. Požadavek, který nese obchodníka,
  je tady **403** — servisní účet má širší práva a obchodník by s nimi potichu zapisoval.
- **Administrátor obchodníka** (`merchantAdministrators: true`): obchodníka nese hlavička
  `X-Doryo-Za` (Kolego ji posílá jen s platným tokenem; když přijde víckrát, platí první hodnota),
  parametr `?merchantId=` nebo `"merchantId"` v těle zápisu. Brána se přihlásí jako administrátor,
  kterému je obchodník v administraci přiřazený (`admin_administrator.<merchantColumn>`, existence
  sloupce se ověřuje). Platí jeho role i filtry, které projekt staví na přihlášeném (For Baby
  `MerchantAdminContext`: jen zákazníci obchodníka a jeho podřízených, cizí detail objednávky
  odmítne). Bez obchodníka jede požadavek pod servisním účtem.
  - obchodník bez administrátorského účtu → 403 s návodem (založit mu ho v Administrace →
    Administrátoři s přiřazeným obchodníkem a rolí),
  - obchodník s víc účty → 403 (brána neví, čí role platí),
  - vypnutý účet → 403, neznámý obchodník → 404,
  - obchodník poslaný na víc místech a pokaždé jiný → 400.
- **Člověk podle e-mailu** (`emailAdministrators: true`): hlavičku `X-Doryo-Email` posílá Kolego jen za člověka,
  jehož e-mail potvrdil Google, a jen s platným tokenem. Brána se přihlásí jako administrátor, jehož účet má login
  rovný tomu e-mailu (nebo login z `administratorEmails`). Platí jeho role a do auditu jde on. Bez hlavičky (agent,
  automatizace) jede požadavek dál výchozí cestou — servisní účet, případně administrátor obchodníka.
  - člověk bez účtu v administraci → 403 s návodem (servisní účet nepoužije — má širší práva),
  - vypnutý účet → 403.
- **Vlastní resolver**: `identityResolver: App\Doryo\MyResolver`.

Audit (`admin_changelog`): `user` = `<login administrátora> via Kolego (<actor>)`, `entity` = presenter,
`objectId` = id záznamu, `type` = `form:<akce>`, `signal:<signál>`, `bulk:<grid>:<počet>` nebo `delete:<grid>:<počet smazaných>`.
`actor` z těla vyplňuje model — je to jen popisek, oprávnění na něm nestojí.

Náhledový token je vázaný i na identitu: náhled, který viděl jeden obchodník, nezapíše jiný ani
servisní účet.

## Cesty

Všechny pod prefixem a tokenem čtecího API (`https://<shop>/doryo-api/v1/admin/…`,
`Authorization: Bearer <token>`). Chyby jsou česky v `application/problem+json`. Popis pro model je
v `openapi.json` (schémata těla inline, Kolego `$ref` v těle neresolvuje).

| metoda a cesta | k čemu |
| --- | --- |
| `GET /v1/admin/pages` | mapa administrace z menu (`admin: menu:`) omezená na to, kam identita smí; u stránek formuláře, akce, gridy a hromadné úpravy z whitelistu |
| `GET /v1/admin/forms/{presenter}/{form}?id=` | schéma polí (typ, popisek, nápověda, povinnost, číselníky, `readOnly`) a hodnoty záznamu; bez `id` schéma prázdného formuláře pro založení |
| `POST /v1/admin/forms/{presenter}/{form}` | náhled (diff + token) nebo ostrý zápis; tělo bez `id` = náhled založení |
| `POST /v1/admin/actions/{presenter}/{signal}` | akce (signál `handle*`); destruktivní chtějí `"confirm": true` |
| `GET /v1/admin/grids/{presenter}/{grid}` | stránka seznamu: text buněk pod hlavičkami, `filter` (JSON), `page`, `limit` (≤ 100), `order`, `id`, `tab` |
| `GET /v1/admin/bulk/{presenter}/{grid}` | co umí hromadná úprava: vstupy s režimy, filtry, práh potvrzení |
| `POST /v1/admin/bulk/{presenter}/{grid}` | hromadná úprava nad `ids` nebo `filter`; náhled a pak ostrý zápis |
| `POST /v1/admin/delete/{presenter}/{grid}` | smazání `ids` tlačítkem „Smazat" gridu; náhled, pak ostrý běh s tokenem a `"confirm": true` |

Všechny cesty berou `merchantId` (viz [Identita](#identita-kdo-zapisuje)).

### Formulář: náhled → token → zápis

```http
POST /doryo-api/v1/admin/forms/Eshop:Admin:Customer/form
{"id": "a1b2c3", "values": {"phone": "+420 601 000 111"}, "dryRun": true, "actor": "Jan Novák"}
```

```json
{
  "dryRun": true, "create": false, "id": "a1b2c3", "valid": true,
  "previewToken": "eyJ2Ijoi….9f3c", "expiresAt": "2026-10-12T14:32:00+02:00",
  "diff": [{"field": "phone", "label": "Telefon", "from": "+420 602 111 222", "to": "+420 601 000 111"}],
  "errors": [],
  "warnings": ["Pole „Ceníky\" jsou v tomhle formuláři jen ke čtení; pokus o jejich změnu skončí chybou 403."]
}
```

Diff agent ukáže uživateli a zapíše totéž s `"dryRun": false` a tokenem:

```http
POST /doryo-api/v1/admin/forms/Eshop:Admin:Customer/form
{"id": "a1b2c3", "values": {"phone": "+420 601 000 111"}, "dryRun": false, "previewToken": "eyJ2Ijoi….9f3c"}
```

```json
{"dryRun": false, "written": true, "id": "a1b2c3", "diff": [...], "flashes": [{"type": "success", "message": "Uloženo"}]}
```

- Posílají se jen pole, která se mění; brána formulář pošle celý, jak by ho poslal prohlížeč
  (včetně názvů nahraných obrázků, ty zůstávají).
- Náhled s neplatnou hodnotou je 200 s `"valid": false` a `errors` po polích, bez tokenu.
- Jiné hodnoty než v náhledu, změněný záznam, vypršelý token nebo token jiné identity = **409**,
  zopakovat náhled.
- **Zakládání** (`create: true` ve whitelistu): náhled bez `id` vygeneruje id a vrátí ho; ostrý
  zápis pošle to id, `"create": true` a token. Neznámé id bez `"create": true` je chyba, ne pokyn
  k založení. `createNote` z konfigurace se vrací ve schématu i náhledu a model ho má uživateli
  přetlumočit.

### Akce

```http
POST /doryo-api/v1/admin/actions/Eshop:Admin:Order/cancelOrder
{"params": {"orderId": "…"}, "dryRun": true}
```

Náhled vrátí `record` (id, kód, stav), `requiresConfirm` a token. Ostrý běh: totéž s
`"dryRun": false`, tokenem a u destruktivní akce `"confirm": true`; odpověď nese `before`, `after`
a `flashes`. Náhled otevře stránku akce s jejími parametry (bez signálu), takže záznam, na který
identita nesmí, odmítne administrace už tady.

### Seznamy (gridy)

```http
GET /doryo-api/v1/admin/grids/Eshop:Admin:Customer/customers?filter={"search":"Novák"}&page=1&limit=20
```

Vrací `total`, `pageCount`, `hasMore`, `columns`, `rows` (`id`, `cells` — text buněk bez HTML,
nejvýš 300 znaků), `filters` a `orderable`. Neznámý filtr je 400 — nefiltrovaný seznam by vypadal
jako odfiltrovaný. Stačí „Přístup" (555).

### Hromadná úprava

`GET` vrátí vstupy a u každého `modes` — přesně to, co u něj nabízí hromadná úprava
v administraci (liquiddesign/admin 2.1):

| režim | kde | co udělá |
| --- | --- | --- |
| `add` | vztah N:N (ceníky, seznamy viditelnosti) | přidá k tomu, co záznam má |
| `remove` | vztah N:N | odebere poslané položky |
| `replace` | výběr více položek | původní vazby zahodí a nastaví poslané (`[]` = smazat všechny) |
| `set` | ostatní pole | nastaví hodnotu všem cílům (lze poslat i holou hodnotu) |

```http
POST /doryo-api/v1/admin/bulk/Eshop:Admin:Customer/customers
{"ids": ["a1", "b2"], "values": {"pricelists": {"add": ["<id ceníku>"]}}, "dryRun": true}
```

Cíle jsou `ids`, nebo `filter` (filtry gridu) — jedno z toho. Náhled vrátí `targets` (počet,
ukázka 20 záznamů), `changes`, `requiresConfirm` a token. Token je vázaný na otisk cílové množiny,
změny **i stav cílů v měněných polích** — když filtr mezitím vrací jiné záznamy nebo cílům někdo
změnil ceníky, je zápis 409. Nad `bulkConfirmFrom` cílů chce ostrý zápis `"confirm": true`.
Ostrý zápis posílá vždycky výčet id (`bulkType = selected`), i když byl zadaný filtr — admin by
filtr vyhodnotil znovu a zapsal do množiny, kterou nikdo neschválil. Výčet `ids` se ověřuje proti
zdroji gridu, tedy proti tomu, co identita vidí; cizí nebo neexistující id je 404.

### Mazání

```http
POST /doryo-api/v1/admin/delete/Eshop:Admin:Producer/grid
{"ids": ["a1"], "dryRun": true}
```

Maže se tlačítkem „Smazat" nad vybranými řádky (`AdminGrid::addButtonDeleteSelected()`) — brána
odešle formulář gridu s `__selector` a `deleteAll` jako prohlížeč, takže platí podmínka projektu
(systémové stránky), úklid v `onDelete` (fotky a soubory produktu) i omezení zdroje gridu. Grid bez
toho tlačítka (jen koš u řádku) brána mazat neumí: akce řádku má v gridu jen pořadové číslo.

Seznam, který patří k záznamu nebo má záložky, bere v těle `id` a `tab` stejně jako jeho čtení
(`GET /v1/admin/grids/…`). Náhled vrátí `records` (počet, ukázka 20 záznamů) a token vázaný na otisk
množiny i na `id` a `tab`; ostrý běh chce
token i `"confirm": true` vždy, ne až od prahu. Smazání chce na stránce „Plný přístup" (777).
`addButtonDeleteSelected` hlásí „Provedeno" i tehdy, když část záznamů nesmazal (cizí klíč, podmínka)
— brána proto po běhu zjistí, co v tabulce zůstalo: `deleted`, `deletedIds`, `remaining`, `complete`.
Nesmaže-li administrace nic, je to 422.

### Chybové stavy

| kód | kdy |
| --- | --- |
| 400 | vadný vstup: neznámé pole, filtr nebo režim, chybějící `confirm`, `add`/`remove` s prázdným seznamem, obchodník poslaný dvakrát jinak |
| 403 | role nesmí (stránka, 777, pole jen ke čtení), administrace stránku neotevřela (záznam identita nevidí), obchodník bez účtu, požadavek za obchodníka bez `merchantAdministrators` |
| 404 | neznámý formulář/akce/grid ve whitelistu, neexistující záznam nebo obchodník |
| 409 | chybí / vypršel / nesedí `previewToken`, záznam nebo cíle se od náhledu změnily |
| 422 | formulář neprošel validací, hodnota mimo číselník, **administrace zápis nebo akci odmítla** (flash zpráva typu chyba / varování) |
| 503 | chybí účet agenta, API nemá token, shop nemá sloupec obchodníka |

## Bezpečnost

- **Oprávnění drží administrace**, ne brána: role identity v Administrace → Role, pole vypínaná
  podle role, filtry dat podle přihlášeného. Brána nic z toho neobchází ani nedomýšlí.
- **777 pro akce a hromadné úpravy** navíc nad tím, co kontroluje administrace.
- **Token místo potvrzení v klientovi.** Kolego potvrzuje zápis jednou za sezení a tělo neukazuje,
  takže souhlas s nástrojem je bianko šek. Bezpečnost proto stojí v API: bez tokenu z náhledu se
  nezapíše nic a s tokenem jen přesně to, co náhled ukázal, nad stejným stavem a za stejnou identitu.
  Token je bezstavový HMAC podepsaný tokenem API.
- **Odmítnutí není úspěch.** Administrace odmítnutí většinou hlásí flash zprávou a přesměrováním
  („Objednávku nelze stornovat"), ne výjimkou. Brána každou flash zprávu typu error / danger /
  warning po zápisu bere jako 422 a do auditu nic nepíše; při otevírání stránky jako 403.
- **CSRF**: Nette 3.2 bere formulář i signál bez same-site původu jako útok a tiše přesměruje.
  Požadavek nechodí z prohlížeče a chrání ho Bearer token, takže brána po dobu průchodu presenterem
  vymění v DI kontejneru HTTP požadavek za same-site kopii (hlavička `Sec-Fetch-Site: same-origin`
  i cookie `_nss`) s tělem formuláře a parametry v adrese; po průchodu vrací původní. Kdyby presenter
  kopii nedostal, je to 500, ne tichý neúspěch.
- **Audit** nese skutečného administrátora, viz [Identita](#identita-kdo-zapisuje).
- Log API (`eshop-doryo-api`) nese u zápisu jen velikost těla, nikdy obsah.

## Rozšíření z projektu

- **Vlastní cesty** (importy, kontroly, vlastní domény) do brány nepatří — projekt je přidá
  standardně přes `doryoApi: endpoints:` (viz README eshop-doryo-api).
- **`PageDescriber`** — aby o nich model věděl, projekt je připíše k řádku stránky v mapě:

  ```php
  final class ImportsDescriber implements DoryoAdmin\Gateway\PageDescriber
  {
      public function describe(string $presenter, array $row): array
      {
          if ($presenter === 'Eshop:Admin:Import') {
              $row['imports'] = ['status' => 'GET /v1/fb/imports — poslední běh každého importu'];
          }

          return $row;
      }
  }
  ```

  ```neon
  doryoAdmin:
      describers: [App\DoryoApi\ImportsDescriber]
  ```

  Volá se jen u stránek, na které identita smí; vlastní klíče nesmí kolidovat s `forms`, `actions`,
  `bulk`, `grids`.
- **Vlastní `IdentityResolver`** přes `identityResolver:`.
- Gateways jdou volat i z projektového kódu: `PresenterRunner::useIdentity()` místo `identify()`.

## Omezení

- **Upload souborů** brána neumí (pole typu `file` jsou `readOnly`); u uloženého obrázku nebo
  souboru se při zápisu pošle jeho současný název, takže zůstává.
- **Mazání** jen z gridů s tlačítkem „Smazat" nad výběrem; koš u jednoho řádku (`addColumnActionDelete`
  bez `addButtonDeleteSelected`) a inline editace v gridech (`addColumnInput*`, „Uložit vše") cesty nemají.
- Pole s datem: posílá se jako `Y-m-d H:i:s`; formát je potřeba ověřit, až takový formulář přibude.
- Selecty plněné AJAXem mají v číselníku jen počet položek — posílá se id.
- Signály se do whitelistu píšou ručně: `handle*` nenese popis parametrů ani významu.
- Přihlášení identity nastartuje session, odpověď tak nese `Set-Cookie`. Po požadavku se odhlašuje.
- Sloupce gridu se čtou reflexí `Grid\Datagrid::$columns` (getter chybí i v liquiddesign/grid 1.0) —
  při aktualizaci gridu zkontrolovat.
- Stav cílů hromadné úpravy se hlídá jen u polí, která jsou sloupcem nebo vztahem N:N tabulky
  entity; vstupy z kontejnerů a jazykové mutace v otisku nejsou.
- Akce bez kontroly přístupu v presenteru (signál, který si záznam neověří) projde s rolí, která
  má na stránce 777 — stejně jako v administraci, kde jde signál zavolat odkazem.

## Vývoj

```bash
composer install
composer check-code   # phpcs (liquiddesign/codestyle) + phpstan level 6
```
