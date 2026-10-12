<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

use Admin\DB\Administrator;
use DoryoApi\Http\ApiException;
use Nette\Security\IIdentity;
use Security\DB\Account;

/**
 * Drobnosti nad identitou administrátora, které potřebují resolvery i runner: čitelné jméno
 * do hlášek a auditu a kontrola, že účet není v administraci vypnutý.
 */
final class Identities
{
	/**
	 * Jméno administrátora tak, jak ho zná administrace: login účtu, jinak celé jméno, jinak id.
	 */
	public static function label(IIdentity $identity): string
	{
		$account = self::account($identity);

		if ($account !== null && $account->login !== '') {
			return $account->login;
		}

		if ($identity instanceof Administrator && $identity->fullName !== null && $identity->fullName !== '') {
			return $identity->fullName;
		}

		return (string) $identity->getId();
	}

	/**
	 * Účet, přes který se administrátor přihlašuje. `Admin\DB\Administrator::$account` je typovaná
	 * vlastnost bez výchozí hodnoty: nastaví ji jen `getByAccountLogin()` / `getByAccount()` nebo
	 * `setAccount()`, jinak čtení hází `Error`. Identita dohledaná jinou cestou (podle obchodníka)
	 * ho proto mít nemusí.
	 */
	public static function account(IIdentity $identity): ?Account
	{
		if (!$identity instanceof Administrator) {
			return null;
		}

		try {
			return $identity->getAccount();
		} catch (\Error) {
			return null;
		}
	}

	/**
	 * Vypnutý účet by administrace pustila jen do `checkRequirements()`, kde ho odhlásí
	 * a přesměruje na přihlášení (`BackendPresenter::verifyAccountActive()`). Přes API to má být
	 * srozumitelná 403 hned na začátku, ne přesměrování uprostřed zápisu.
	 * @throws \DoryoApi\Http\ApiException
	 */
	public static function assertActive(IIdentity $identity, string $who): void
	{
		$account = self::account($identity);

		if ($account === null || $account->isActive()) {
			return;
		}

		throw new ApiException(403, 'Účet je vypnutý', \sprintf(
			'%s má v administraci účet „%s" vypnutý (neaktivní nebo mimo platnost), takže za něj brána nic nespustí. '
				. 'Účet se zapíná v Administrace → Administrátoři.',
			$who,
			$account->login,
		));
	}
}
