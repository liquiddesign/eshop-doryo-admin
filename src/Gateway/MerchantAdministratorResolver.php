<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

use Admin\DB\Administrator;
use Admin\DB\AdministratorRepository;
use DoryoApi\Codebooks;
use DoryoApi\Http\ApiException;
use DoryoApi\Http\Query;
use Eshop\DB\Merchant;
use Nette\Http\IRequest;
use Nette\Security\IIdentity;
use Nette\Utils\Strings;
use Security\DB\Account;
use StORM\DIConnection;
use StORM\Entity;

/**
 * Identita obchodníka: požadavek, který nese obchodníka, jede pod administrátorem, kterému je
 * v administraci tenhle obchodník přiřazený (`admin_administrator.fk_merchant`).
 *
 * Proč ne servisní účet s filtrem: administrace už umí, co obchodník smí — jeho role omezuje
 * stránky (555/777) a projekt podle přihlášeného filtruje data (ve For Baby `MerchantAdminContext`
 * zúží zákazníky, objednávky i faktury na jeho a jeho podřízených a cizí detail odmítne). Když se
 * brána přihlásí jako on, platí to všechno beze změny a audit nese skutečného administrátora.
 *
 * Obchodníka nese hlavička `X-Doryo-Za` (Kolego ji posílá jen s platným tokenem) nebo parametr
 * `merchantId` — v dotazu, nebo v těle zápisu. Bez obchodníka jede požadavek pod servisním účtem.
 */
final class MerchantAdministratorResolver implements IdentityResolver
{
	public const HEADER = 'X-Doryo-Za';

	public const PARAMETER = 'merchantId';

	/** @var array<string, \Nette\Security\IIdentity> */
	private array $resolved = [];

	/**
	 * @param string $column Sloupec tabulky administrátorů s vazbou na obchodníka
	 * @param \Admin\DB\AdministratorRepository<\Admin\DB\Administrator> $administratorRepository
	 */
	public function __construct(
		private ServiceAccountResolver $serviceAccount,
		private string $column,
		private AdministratorRepository $administratorRepository,
		private Codebooks $codebooks,
		private DIConnection $connection,
		private IRequest $httpRequest,
	) {
	}

	/**
	 * Obchodník, za kterého požadavek jedná, nebo null.
	 *
	 * Hlavička může přijít víckrát a proxy ji pak spojí čárkou — platí první hodnota. Když požadavek
	 * nese obchodníka na víc místech a pokaždé jiného, je to 400: tipovat, za koho se zapisuje,
	 * brána nesmí.
	 * @throws \DoryoApi\Http\ApiException
	 */
	public static function requestedMerchant(Query $query, IRequest $httpRequest): ?string
	{
		$header = $httpRequest->getHeader(self::HEADER);
		$fromHeader = $header !== null ? Strings::trim(\explode(',', $header)[0]) : '';
		$fromQuery = Strings::trim((string) $query->string(self::PARAMETER));
		$body = $query->getBody();
		$fromBody = isset($body[self::PARAMETER]) && \is_scalar($body[self::PARAMETER]) ? Strings::trim((string) $body[self::PARAMETER]) : '';

		$given = \array_values(\array_unique(\array_filter(
			[$fromHeader, $fromQuery, $fromBody],
			static fn (string $value): bool => $value !== '',
		)));

		if (\count($given) > 1) {
			throw ApiException::badRequest(\sprintf(
				'Požadavek nese obchodníka víckrát a pokaždé jiného (%s) — v hlavičce %s, v parametru nebo v těle „%s". '
					. 'Pošli ho jen jednou.',
				\implode(', ', $given),
				self::HEADER,
				self::PARAMETER,
			));
		}

		return $given[0] ?? null;
	}

	/**
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function resolve(Query $query): IIdentity
	{
		$merchantId = self::requestedMerchant($query, $this->httpRequest);

		if ($merchantId === null) {
			return $this->serviceAccount->account();
		}

		return $this->resolved[$merchantId] ??= $this->forMerchant($merchantId);
	}

	/**
	 * @throws \DoryoApi\Http\ApiException
	 */
	private function forMerchant(string $merchantId): IIdentity
	{
		$table = $this->administratorRepository->getStructure()->getTable()->getName();

		// Vazbu administrátor → obchodník nemá základní admin, přidává ji projekt (For Baby
		// App\Admin\DB\Administrator::$merchant). Na shopu bez ní se dotaz nemá na co ptát.
		if (!$this->codebooks->hasColumn($table, $this->column)) {
			throw new ApiException(503, 'Obchodníci v administraci nejsou', \sprintf(
				'Tabulka %s nemá sloupec %s, takže brána neumí najít administrátora obchodníka. Buď shop '
					. 'administrátory obchodníků nevede (pak vypni doryoAdmin: merchantAdministrators), nebo je vazba '
					. 'v jiném sloupci (doryoAdmin: merchantColumn).',
				$table,
				$this->column,
			));
		}

		$merchant = $this->connection->findRepository(Merchant::class)->one($merchantId);

		if (!$merchant instanceof Entity) {
			throw ApiException::notFound(\sprintf('Obchodník %s neexistuje.', $merchantId));
		}

		$name = self::merchantName($merchant);

		/** @var array<\Admin\DB\Administrator> $administrators */
		$administrators = \array_values($this->administratorRepository->many()->where('this.' . $this->column, $merchantId)->toArray());

		if (!$administrators) {
			throw new ApiException(403, 'Obchodník nemá účet v administraci', \sprintf(
				'Obchodník %s (%s) nemá v administraci vlastní účet, takže za něj brána nic nespustí — servisní účet '
					. 'má širší práva než on. Založ mu v Administrace → Administrátoři administrátora s přiřazeným '
					. 'obchodníkem a rolí, jakou má mít (stejně jako pro přihlášení do administrace).',
				$name,
				$merchantId,
			));
		}

		if (\count($administrators) > 1) {
			throw new ApiException(403, 'Obchodník má víc účtů v administraci', \sprintf(
				'Obchodníkovi %s (%s) je přiřazených %d administrátorů a brána neví, čí role má platit. '
					. 'Nech mu v Administrace → Administrátoři jen jednoho.',
				$name,
				$merchantId,
				\count($administrators),
			));
		}

		$administrator = $administrators[0];
		self::attachAccount($administrator);
		Identities::assertActive($administrator, \sprintf('Obchodník %s', $name));

		return $administrator;
	}

	/**
	 * Účet administrátora. Bez něj by `getAccount()` hodilo `Error` všude, kde ho administrace čte
	 * (`AdminFormFactory` s `setLogging()` do auditu zapisuje jeho login), a nešlo by ověřit, že účet
	 * není vypnutý. Administrátor bez účtu se do administrace nepřihlásí, přes API ale projde jeho role.
	 */
	private static function attachAccount(Administrator $administrator): void
	{
		$account = $administrator->accounts->first();

		if (!$account instanceof Account) {
			return;
		}

		$administrator->setAccount($account);
	}

	private static function merchantName(Entity $merchant): string
	{
		foreach (['fullname', 'name', 'code'] as $property) {
			try {
				$value = $merchant->getValue($property);
			} catch (\Throwable) {
				continue;
			}

			if (\is_scalar($value) && (string) $value !== '') {
				return (string) $value;
			}
		}

		return (string) $merchant->getPK();
	}
}
