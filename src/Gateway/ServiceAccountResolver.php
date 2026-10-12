<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

use Admin\DB\AdministratorRepository;
use DoryoApi\Http\ApiException;
use DoryoApi\Http\Query;
use Nette\Http\IRequest;
use Nette\Security\IIdentity;

/**
 * Výchozí identita: jeden servisní účet (`doryoAdmin: administratorLogin:`), pod kterým brána
 * jede pro všechny. Co smí, drží jeho role v Administrace → Role; kdo se ptal, je jen popisek
 * v auditu (`actor` z těla).
 *
 * Požadavek za konkrétního obchodníka tady schválně končí 403: servisní účet má skoro vždycky
 * širší práva než obchodník, a kdyby brána obchodníka potichu přehlédla, zapisoval by s nimi.
 */
final class ServiceAccountResolver implements IdentityResolver
{
	/** Identita se čte jednou: mapa stránek se na ni ptá u každé položky menu. */
	private ?IIdentity $identity = null;

	/**
	 * @param string $login Login účtu agenta (`doryoAdmin: administratorLogin:`)
	 * @param \Admin\DB\AdministratorRepository<\Admin\DB\Administrator> $administratorRepository
	 */
	public function __construct(
		private string $login,
		private AdministratorRepository $administratorRepository,
		private IRequest $httpRequest,
	) {
	}

	/**
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function resolve(Query $query): IIdentity
	{
		$merchantId = MerchantAdministratorResolver::requestedMerchant($query, $this->httpRequest);

		if ($merchantId !== null) {
			throw new ApiException(403, 'Za obchodníka brána nejedná', \sprintf(
				'Požadavek je za obchodníka %s, ale brána jede jen pod servisním účtem „%s" '
					. '(doryoAdmin: merchantAdministrators: false). Ten má širší práva než obchodník, takže se za '
					. 'obchodníka nic nespustí. Zapíná se to v konfiguraci brány; obchodník pak potřebuje '
					. 'v Administrace → Administrátoři vlastní účet s přiřazeným obchodníkem.',
				$merchantId,
				$this->login,
			));
		}

		return $this->account();
	}

	/**
	 * Servisní účet bez ohledu na požadavek — pro resolver obchodníků, když požadavek obchodníka nenese.
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function account(): IIdentity
	{
		if ($this->identity !== null) {
			return $this->identity;
		}

		$identity = $this->administratorRepository->getByAccountLogin($this->login);

		if (!$identity instanceof IIdentity) {
			throw new ApiException(503, 'Účet agenta neexistuje', \sprintf(
				'V administraci není administrátor s účtem „%s". Založ ho v Administrace → Administrátoři, '
					. 'přiřaď mu roli a v Administrace → Role mu nastav, na které stránky smí.',
				$this->login,
			));
		}

		Identities::assertActive($identity, 'Účet agenta');

		return $this->identity = $identity;
	}

	public function getLogin(): string
	{
		return $this->login;
	}
}
