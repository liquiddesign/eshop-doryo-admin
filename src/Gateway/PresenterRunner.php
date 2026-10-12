<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

use Admin\Administrator;
use Admin\Authorizator;
use Admin\DB\AdministratorRepository;
use DoryoApi\Http\ApiException;
use Nette\Application\AbortException;
use Nette\Application\BadRequestException;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\UI\Presenter;
use Nette\DI\Container;
use Nette\Http\Helpers;
use Nette\Http\Request as HttpRequest;
use Nette\Security\IIdentity;
use Nette\Utils\Strings;
use StORM\DIConnection;
use StORM\Entity;

/**
 * Spouští presentery administrace normální cestou Nette, jen bez prohlížeče.
 *
 * Celý smysl brány je, že se nic nekopíruje: `Presenter::run()` projde `checkRequirements()`
 * (přihlášení a oprávnění), `startup()`, akci, signály i formuláře, takže platí skutečná
 * validace, skutečná oprávnění a skutečná `onSuccess` logika — včetně té, která je ve vendoru
 * a projekt ji nemá jak duplikovat.
 *
 * Tři věci se musí obejít, protože požadavek nepřišel z prohlížeče:
 *
 * - **kanonizace** porovnává adresu s tím, co by vygeneroval router, a hned by odpověděla
 *   přesměrováním na adresu administrace; vypíná se {@see Presenter::$autoCanonicalize},
 * - **render** není k ničemu a jen by stál čas, tak se z `onRender` vyskočí `AbortException`,
 *   kterou si `run()` odchytí sám,
 * - **entity v parametrech akce** normálně dosazuje router (`Pages::mapParameters`); tady se
 *   dosadí stejně, jen ručně,
 * - **CSRF**: Nette bere POST bez same-site cookie `_nss` jako útok a odpoví přesměrováním.
 *   Ochrana proti cizímu webu tady nedává smysl (požadavek nechodí z prohlížeče a chrání ho
 *   Bearer token), tak presenter dostane HTTP požadavek s tou cookie. Jinou ochranu admin
 *   formuláře nemají, `addProtection()` se v administraci nepoužívá.
 */
final class PresenterRunner
{
	/** Jméno služby administrátora; není autowirovaná, protože shop má i zákaznického uživatele. */
	private const ADMINISTRATOR_SERVICE = 'admin.administrator';

	/** Identita účtu agenta; mapa stránek se na ni ptá u každé položky menu, tak se čte jen jednou. */
	private ?IIdentity $identity = null;

	public function __construct(
		private IPresenterFactory $presenterFactory,
		private Container $container,
		private AdministratorRepository $administratorRepository,
		private Authorizator $authorizator,
		private DIConnection $connection,
		private GatewayConfig $config,
	) {
	}

	/**
	 * Přihlásí účet agenta, pustí nad ním práci a zase ho odhlásí.
	 *
	 * Identita se nastavuje programově: požadavek od Doryo nemá cookie, ale `checkRequirements()`
	 * chce přihlášeného administrátora a jeho roli. Kdo se ptal, se z Kolega zatím nedozvíme,
	 * takže oprávnění drží role tohohle účtu a jméno žadatele je jen do auditu.
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function withAdministrator(callable $callback): mixed
	{
		$administrator = $this->getAdministrator();
		$administrator->login($this->getIdentity());

		try {
			return $callback();
		} finally {
			$administrator->logout(true);
		}
	}

	/**
	 * Smí role účtu agenta na tuhle stránku? Bez privilegia jde o čtení, se `777` o úpravy —
	 * přesně jak to čte administrace.
	 */
	public function isAllowed(string $resource, ?string $privilege = null): bool
	{
		$role = $this->getIdentity()->getRoles()[0] ?? null;

		return $role !== null && $this->authorizator->isAllowed($role, $resource, $privilege);
	}

	/**
	 * Signály mění data, ale `checkRequirements()` u nich kontroluje jen přístup na stránku —
	 * v administraci je totiž ochranou to, že tlačítko vidí jen ten, kdo na stránku smí.
	 * Přes API je to málo, tak se u akcí vyžaduje rovnou „Plný přístup".
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function assertManager(string $resource, string $label): void
	{
		if ($this->isAllowed($resource, '777')) {
			return;
		}

		throw new ApiException(403, 'Přístup odepřen', \sprintf(
			'Účet „%s" nemá na %s „Plný přístup" (777), takže akci „%s" spustit nesmí. '
				. 'Oprávnění se nastavují v Administrace → Role.',
			$this->config->getAdministratorLogin(),
			$resource,
			$label,
		));
	}

	/**
	 * Jeden průchod presenterem.
	 * @param array<string, mixed> $params Parametry požadavku (`action`, `do`, parametry akce)
	 * @param array<string, mixed> $post Tělo POSTu ve tvaru, v jakém by ho poslal prohlížeč
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function run(string $presenterName, array $params, array $post = []): RunResult
	{
		$presenter = $this->presenterFactory->createPresenter($presenterName);

		if (!$presenter instanceof Presenter) {
			throw new ApiException(500, 'Chyba serveru', "Presenter $presenterName není UI presenter.");
		}

		$presenter->autoCanonicalize = false;
		$presenter->onRender[] = static function (): void {
			throw new AbortException();
		};

		if ($post) {
			self::markSameSite($presenter);
		}

		$request = new Request($presenterName, $post ? 'POST' : 'GET', $this->mapEntities($presenterName, $params), $post);

		try {
			$response = $presenter->run($request);
		} catch (BadRequestException $e) {
			// checkRequirements() hlásí chybějící oprávnění právě takhle; ostatní BadRequest
			// (neznámá akce, chybějící parametr) je opravdu 404 a nemá se tvářit jako oprávnění
			if ($e->getHttpCode() === 403 || \str_contains($e->getMessage(), 'Not allowed')) {
				throw new ApiException(403, 'Přístup odepřen', \sprintf(
					'Účet „%s" na %s nesmí: %s. Oprávnění se nastavují v Administrace → Role.',
					$this->config->getAdministratorLogin(),
					$presenterName,
					$e->getMessage(),
				));
			}

			throw ApiException::notFound(\sprintf('%s odmítl požadavek: %s', $presenterName, $e->getMessage()));
		}

		return new RunResult($presenter, $response, self::takeFlashes($presenter));
	}

	/**
	 * @throws \DoryoApi\Http\ApiException
	 */
	private function getIdentity(): IIdentity
	{
		if ($this->identity !== null) {
			return $this->identity;
		}

		$login = $this->config->getAdministratorLogin();
		$identity = $this->administratorRepository->getByAccountLogin($login);

		if (!$identity instanceof IIdentity) {
			throw new ApiException(503, 'Účet pro Doryo neexistuje', \sprintf(
				'V administraci není administrátor s účtem „%s". Založ ho v Administrace → Administrátoři, '
					. 'přiřaď mu roli a v Administrace → Role mu nastav, na které stránky smí.',
				$login,
			));
		}

		return $this->identity = $identity;
	}

	private function getAdministrator(): Administrator
	{
		/** @var \Admin\Administrator $administrator */
		$administrator = $this->container->getService(self::ADMINISTRATOR_SERVICE);

		return $administrator;
	}

	/**
	 * Dosadí do parametrů akce entity místo id — normálně to dělá router přes `Pages::mapParameters`,
	 * ale brána cestu nesestavuje, takže si to musí udělat sama.
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 * @throws \DoryoApi\Http\ApiException
	 */
	private function mapEntities(string $presenterName, array $params): array
	{
		$class = $this->presenterFactory->getPresenterClass($presenterName);
		$action = Strings::firstUpper((string) ($params['action'] ?? 'default'));
		$method = \method_exists($class, "action$action") ? "action$action" : (\method_exists($class, "render$action") ? "render$action" : null);

		if ($method === null) {
			return $params;
		}

		foreach ((new \ReflectionMethod($class, $method))->getParameters() as $parameter) {
			$type = $parameter->getType();
			$name = $parameter->getName();

			if (!$type instanceof \ReflectionNamedType || !\is_subclass_of($type->getName(), Entity::class) || !isset($params[$name]) || !\is_string($params[$name])) {
				continue;
			}

			$entity = $this->connection->findRepository($type->getName())->one($params[$name]);

			if ($entity === null) {
				throw ApiException::notFound(\sprintf('Záznam %s s id %s neexistuje.', $type->getName(), $params[$name]));
			}

			$params[$name] = $entity;
		}

		return $params;
	}

	/**
	 * Dřív se formuláři volalo `allowCrossOrigin()` z `onStartup`, jenže Nette pouští `onStartup`
	 * před `startup()` presenteru: formulář tak vznikl dřív, než si presenter ve `startup()`
	 * zaregistroval háčky (Levior `MenuPresenter` přidává pole obrázků hlavičky), a zápis spadl.
	 * Cookie na požadavku nechá komponenty vzniknout v normálním pořadí.
	 */
	private static function markSameSite(Presenter $presenter): void
	{
		$property = new \ReflectionProperty(Presenter::class, 'httpRequest');
		/** @var \Nette\Http\IRequest $original */
		$original = $property->getValue($presenter);

		$property->setValue($presenter, new HttpRequest(
			$original->getUrl(),
			$original->getPost(),
			$original->getFiles(),
			[Helpers::StrictCookieName => '1'] + $original->getCookies(),
			$original->getHeaders(),
			$original->getMethod(),
			$original->getRemoteAddress(),
			$original->getRemoteHost(),
			static fn (): ?string => $original->getRawBody(),
		));
	}

	/**
	 * Flash zprávy, které by jinak čekaly v session na další požadavek. Po vybrání se mažou,
	 * ať se nepřilepí k dalšímu volání.
	 * @return array<array{type: string, message: string}>
	 */
	private static function takeFlashes(Presenter $presenter): array
	{
		if (!$presenter->hasFlashSession()) {
			return [];
		}

		$section = $presenter->getFlashSession();
		$flashes = [];

		foreach ((array) $section->get('flash') as $flash) {
			$flashes[] = [
				'type' => (string) ($flash->type ?? 'info'),
				'message' => (string) ($flash->message ?? ''),
			];
		}

		$section->remove();

		return $flashes;
	}
}
