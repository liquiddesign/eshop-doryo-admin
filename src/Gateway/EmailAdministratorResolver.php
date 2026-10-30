<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

use Admin\DB\AdministratorRepository;
use DoryoApi\Http\ApiException;
use DoryoApi\Http\Query;
use Nette\Http\IRequest;
use Nette\Security\IIdentity;
use Nette\Utils\Strings;

/**
 * Identita člověka: požadavek s hlavičkou `X-Doryo-Email` jede pod administrátorem, jehož účet má
 * login rovný tomu e-mailu (nebo login, se kterým ho spáruje `administratorEmails` v konfiguraci).
 *
 * Kolego hlavičku posílá jen za člověka, jehož e-mail potvrdil Google, a jen s platným tokenem API;
 * agent a automatizace ji nemají a jedou dál výchozí cestou (servisní účet, případně administrátor
 * obchodníka). Člověk bez účtu v administraci je 403 s návodem — servisní účet má širší práva, než
 * by měl on, a audit by nesl někoho jiného.
 */
final class EmailAdministratorResolver implements IdentityResolver
{
	public const HEADER = 'X-Doryo-Email';

	/** @var array<string, \Nette\Security\IIdentity> */
	private array $resolved = [];

	/** @var array<string, string> e-mail malými písmeny → login */
	private array $emails = [];

	/**
	 * @param array<string, string> $emails Párování e-mail → login účtu administrace, kde login není e-mail
	 * @param \Admin\DB\AdministratorRepository<\Admin\DB\Administrator> $administratorRepository
	 */
	public function __construct(
		private IdentityResolver $fallback,
		array $emails,
		private AdministratorRepository $administratorRepository,
		private IRequest $httpRequest,
	) {
		foreach ($emails as $email => $login) {
			$this->emails[Strings::lower(Strings::trim((string) $email))] = (string) $login;
		}
	}

	/**
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function resolve(Query $query): IIdentity
	{
		$email = self::requestedEmail($this->httpRequest);

		if ($email === null) {
			return $this->fallback->resolve($query);
		}

		return $this->resolved[$email] ??= $this->forEmail($email);
	}

	/**
	 * E-mail z hlavičky malými písmeny, nebo null. Přijde-li hlavička víckrát, platí první hodnota.
	 */
	public static function requestedEmail(IRequest $httpRequest): ?string
	{
		$header = $httpRequest->getHeader(self::HEADER);

		if ($header === null) {
			return null;
		}

		$email = Strings::lower(Strings::trim(\explode(',', $header)[0]));

		return $email !== '' ? $email : null;
	}

	/**
	 * @throws \DoryoApi\Http\ApiException
	 */
	private function forEmail(string $email): IIdentity
	{
		$login = $this->emails[$email] ?? $email;
		$identity = $this->administratorRepository->getByAccountLogin($login);

		if (!$identity instanceof IIdentity) {
			throw new ApiException(403, 'Člověk nemá účet v administraci', \sprintf(
				'Za %s brána nic nespustí: v administraci e-shopu nemá účet (hledala login „%s"). Založ mu '
					. 'v Administrace → Administrátoři administrátora s účtem %s a rolí, jakou má mít, nebo ho '
					. 'v konfiguraci brány spáruj s jeho loginem (doryoAdmin: administratorEmails).',
				$email,
				$login,
				$email,
			));
		}

		Identities::assertActive($identity, \sprintf('Účet %s', $login));

		return $identity;
	}
}
