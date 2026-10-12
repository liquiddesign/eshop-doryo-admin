<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

use DoryoApi\Http\Query;
use Nette\Security\IIdentity;

/**
 * Kdo v administraci jedná: administrátor, pod kterým brána pro tenhle požadavek pustí presentery.
 *
 * Na identitě stojí všechno, co administrace rozhoduje sama — role (na které stránky smí, 555/777),
 * pole vypnutá ve formulářích a filtry dat podle přihlášeného (ve For Baby `MerchantAdminContext`).
 * Brána proto oprávnění nevymýšlí, jen vybere, za koho se přihlásí; audit (`admin_changelog`) pak
 * nese tohohle administrátora.
 *
 * Výchozí je servisní účet ({@see ServiceAccountResolver}), s `merchantAdministrators: true`
 * administrátor obchodníka ({@see MerchantAdministratorResolver}). Projekt může dát vlastní
 * implementaci přes `doryoAdmin: identityResolver:`.
 */
interface IdentityResolver
{
	/**
	 * Identita pro aktuální požadavek. Volá se jednou na požadavek, dřív než brána cokoli spustí.
	 *
	 * Parametry, které implementace čte z dotazu, musí číst přes `$query` — balík eshop-doryo-api
	 * jinak bere neznámý parametr jako překlep a odpoví 400.
	 * @throws \DoryoApi\Http\ApiException 400/403/503, když za požadavek nejde nikoho přihlásit
	 */
	public function resolve(Query $query): IIdentity;
}
