<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

use Admin\Administrator;
use Admin\Authorizator;
use DoryoApi\Http\ApiException;
use DoryoApi\Http\Query;
use Nette\Application\AbortException;
use Nette\Application\BadRequestException;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\UI\Presenter;
use Nette\DI\Container;
use Nette\Http\Helpers;
use Nette\Http\IRequest;
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
 * Čtyři věci se musí obejít, protože požadavek nepřišel z prohlížeče:
 *
 * - **kanonizace** porovnává adresu s tím, co by vygeneroval router, a hned by odpověděla
 *   přesměrováním na adresu administrace; vypíná se {@see Presenter::$autoCanonicalize},
 * - **render** není k ničemu a jen by stál čas, tak se z `onRender` vyskočí `AbortException`,
 *   kterou si `run()` odchytí sám,
 * - **entity v parametrech akce** normálně dosazuje router (`Pages::mapParameters`); tady se
 *   dosadí stejně, jen ručně,
 * - **CSRF**: Nette bere odeslaný formulář i signál (`handle*`, od Nette 3.2 implicitně
 *   `#[Requires(sameOrigin: true)]`) bez same-site původu jako útok a tiše přesměruje
 *   (`detectedCsrf()`). Ochrana proti cizímu webu tady nedává smysl (požadavek nechodí
 *   z prohlížeče a chrání ho Bearer token), tak presenter dostane HTTP požadavek, který
 *   same-site je — viz {@see self::swapHttpRequest()}. Jinou ochranu admin formuláře nemají,
 *   `addProtection()` se v administraci nepoužívá.
 */
final class PresenterRunner
{
	/** Za koho brána v tomhle požadavku jedná; nastaví {@see self::identify()}. */
	private ?IIdentity $identity = null;

	/**
	 * @param \Admin\Administrator $administrator Uživatel administrace (služba `admin.administrator`); není
	 *     autowirovaný, protože shop má i zákaznického uživatele — předává ho DI rozšíření balíku
	 */
	public function __construct(
		private IPresenterFactory $presenterFactory,
		private Container $container,
		private Administrator $administrator,
		private IdentityResolver $identityResolver,
		private Authorizator $authorizator,
		private DIConnection $connection,
		private GatewayConfig $config,
	) {
	}

	/**
	 * Určí, za koho brána v tomhle požadavku jedná ({@see IdentityResolver}). Volá se jako první
	 * věc v každé obsluze — dřív, než se cokoli spustí, a dřív, než by šlo cokoli zapsat.
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function identify(Query $query): void
	{
		$this->identity = $this->identityResolver->resolve($query);
	}

	/**
	 * Identita nastavená zvenku, bez požadavku API — pro projektový kód, který gateways volá sám.
	 */
	public function useIdentity(IIdentity $identity): void
	{
		$this->identity = $identity;
	}

	/**
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function getIdentity(): IIdentity
	{
		return $this->identity ?? throw new ApiException(500, 'Chyba serveru', 'Brána neví, za koho jedná '
			. '(chybí PresenterRunner::identify()). Nahlas to vývojářům.');
	}

	/**
	 * Klíč identity, na který se váže náhledový token: náhled jednoho administrátora nesmí
	 * zapsat jiný.
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function getIdentityKey(): string
	{
		return (string) $this->getIdentity()->getId();
	}

	/**
	 * Za koho brána jedná, do hlášek: „účet „doryo"", „účet „novak@shop.cz"".
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function describeIdentity(): string
	{
		return \sprintf('účet „%s"', Identities::label($this->getIdentity()));
	}

	/**
	 * Kdo zápis udělal, do `admin_changelog.user`: skutečný administrátor, přes koho (agent)
	 * a za koho podle těla. `actor` vyplňuje model, takže je to jen popisek, oprávnění na něm
	 * nestojí. Sloupec má 255 znaků.
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function auditUser(?string $actor): string
	{
		$actor = $actor !== null ? Strings::trim($actor) : '';
		$user = \sprintf('%s via %s', Identities::label($this->getIdentity()), $this->config->getAgentName());

		if ($actor !== '') {
			$user .= \sprintf(' (%s)', $actor);
		}

		return Strings::substring($user, 0, 255);
	}

	/**
	 * Přihlásí identitu požadavku, pustí nad ní práci a zase ji odhlásí.
	 *
	 * Identita se nastavuje programově: požadavek od agenta nemá cookie, ale `checkRequirements()`
	 * chce přihlášeného administrátora a jeho roli.
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function withAdministrator(callable $callback): mixed
	{
		$this->administrator->login($this->getIdentity());

		try {
			return $callback();
		} finally {
			$this->administrator->logout(true);
		}
	}

	/**
	 * Smí identita požadavku na tuhle stránku? Bez privilegia jde o čtení, se `777` o úpravy —
	 * přesně jak to čte administrace. Ptá se přímo autorizátoru, bez přihlášení, protože mapa
	 * stránek se ptá u každé položky menu.
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function isAllowed(string $resource, ?string $privilege = null): bool
	{
		// administrátor má jednu roli, ale IIdentity jich slibuje víc — stačí, když smí kterákoli
		foreach ($this->getIdentity()->getRoles() as $role) {
			if (\is_scalar($role) && $this->authorizator->isAllowed((string) $role, $resource, $privilege)) {
				return true;
			}
		}

		return false;
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
			'%s nemá na %s „Plný přístup" (777), takže akci „%s" spustit nesmí. '
				. 'Oprávnění se nastavují v Administrace → Role.',
			Strings::firstUpper($this->describeIdentity()),
			$resource,
			$label,
		));
	}

	/**
	 * Jeden průchod presenterem.
	 *
	 * Po dobu průchodu je v kontejneru místo HTTP požadavku API jeho same-site kopie s tělem
	 * formuláře a parametry v adrese ({@see self::swapHttpRequest()}); po průchodu se vrací původní.
	 * @param array<string, mixed> $params Parametry požadavku (`action`, `do`, parametry akce)
	 * @param array<string, mixed> $post Tělo POSTu ve tvaru, v jakém by ho poslal prohlížeč
	 * @param array<string, mixed> $files Nahrávané soubory (`FileUpload`) podle HTML jmen polí, jako `$_FILES` po Nette
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function run(string $presenterName, array $params, array $post = [], array $files = []): RunResult
	{
		$method = $post || $files ? 'POST' : 'GET';
		[$copy, $originals] = $this->swapHttpRequest($method, $params, $post, $files);

		try {
			$presenter = $this->presenterFactory->createPresenter($presenterName);

			if (!$presenter instanceof Presenter) {
				throw new ApiException(500, 'Chyba serveru', "Presenter $presenterName není UI presenter.");
			}

			// Bez téhle kopie by Nette odeslaný formulář i signál tiše přesměrovalo a brána by
			// hlásila úspěch u něčeho, co se neprovedlo. Presenter si požadavek bere z kontejneru
			// při vzniku, takže kdyby ho dostal odjinud (vlastní továrna, sdílená instance), musí
			// to spadnout tady, ne potichu.
			if ($presenter->getHttpRequest() !== $copy) {
				throw new ApiException(500, 'Chyba serveru', \sprintf(
					'Presenter %s nedostal HTTP požadavek brány (vzniká mimo DI kontejner?), zápis by '
						. 'administrace odmítla jako cizí. Nahlas to vývojářům.',
					$presenterName,
				));
			}

			$presenter->autoCanonicalize = false;
			$presenter->onRender[] = static function (): void {
				throw new AbortException();
			};

			// formulář bere odeslaná data z aplikačního požadavku (`Request::getFiles()`), signál jako dropzone
			// z HTTP požadavku (`getFile()`) — soubory proto jdou do obou
			$request = new Request($presenterName, $method, $this->mapEntities($presenterName, $params), $post, $files);

			try {
				$response = $presenter->run($request);
			} catch (BadRequestException $e) {
				// checkRequirements() hlásí chybějící oprávnění právě takhle; ostatní BadRequest
				// (neznámá akce, chybějící parametr) je opravdu 404 a nemá se tvářit jako oprávnění
				if ($e->getHttpCode() === 403 || \str_contains($e->getMessage(), 'Not allowed')) {
					throw new ApiException(403, 'Přístup odepřen', \sprintf(
						'%s na %s nesmí: %s. Oprávnění se nastavují v Administrace → Role.',
						Strings::firstUpper($this->describeIdentity()),
						$presenterName,
						$e->getMessage(),
					));
				}

				throw ApiException::notFound(\sprintf('%s odmítl požadavek: %s', $presenterName, $e->getMessage()));
			}

			return new RunResult($presenter, $response, self::takeFlashes($presenter));
		} finally {
			$this->restoreHttpRequest($originals);
		}
	}

	/**
	 * Průchod, který má jen otevřít stránku (schéma formuláře, výpis gridu, náhled). Když ji
	 * administrace odmítne flash zprávou typu chyba nebo varování — ve For Baby třeba „Nemáte
	 * oprávnění k tomuto zákazníkovi" a přesměrování na přehled —, je to 403 s její hláškou.
	 * Jinak by brána popsala prázdný formulář nebo seznam, jako by se nic nestalo.
	 * @param array<string, mixed> $params
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function open(string $presenterName, array $params): RunResult
	{
		$run = $this->run($presenterName, $params);
		$refusals = $run->getRefusals();

		if ($refusals) {
			throw new ApiException(403, 'Administrace stránku neotevřela', \sprintf(
				'%s odmítl stránku otevřít: %s',
				$presenterName,
				\implode(' ', \array_column($refusals, 'message')),
			));
		}

		return $run;
	}

	/**
	 * Jméno člověka z těla požadavku (`actor`), jen jako popisek do auditu.
	 * @param array<mixed> $body
	 */
	public static function actor(array $body): ?string
	{
		return isset($body['actor']) && \is_scalar($body['actor']) ? (string) $body['actor'] : null;
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
	 * Vymění v kontejneru HTTP požadavek za same-site kopii, ze které presenter vznikne.
	 *
	 * Historie: dřív se formuláři volalo `allowCrossOrigin()` z `onStartup`, jenže Nette pouští
	 * `onStartup` před `startup()` presenteru: formulář tak vznikl dřív, než si presenter ve
	 * `startup()` zaregistroval háčky (Levior `MenuPresenter` přidává pole obrázků hlavičky),
	 * a zápis spadl. Pak se presenteru reflexí podstrkoval požadavek s cookie — v Nette 3.2 je
	 * `Presenter::$httpRequest` `private readonly`, takže to nejde, a same-origin se navíc hlídá
	 * i u signálů (`AccessPolicy`), ne jen u formulářů. Proto se požadavek mění dřív, než
	 * presenter vznikne: `injectPrimary()` si ho vezme z kontejneru jako kteroukoli službu.
	 *
	 * Kopie nese obojí, podle čeho Nette same-site pozná: hlavičku `Sec-Fetch-Site: same-origin`
	 * (nette/http 3.4 se ptá nejdřív na ni) i cookie `_nss` (záloha pro prohlížeče bez Sec-Fetch,
	 * na ni se ptá nette/http 3.3). Navíc nese tělo formuláře a parametry v adrese tak, jak by je
	 * poslal prohlížeč — administrace je místy čte přímo z HTTP požadavku (hromadná úprava
	 * `copyRawValues`, řazení položek, náhledy), ne z požadavku aplikace.
	 *
	 * `addService()` po `removeService()` kontroluje typ proti továrně služby (`Nette\Http\Request`),
	 * proto je kopie tatáž třída. Služby, které vzniknou během průchodu a HTTP požadavek si vezmou,
	 * dostanou kopii natrvalo; liší se od originálu jen tím, co je popsané výš.
	 * @param array<string, mixed> $params
	 * @param array<string, mixed> $post
	 * @param array<string, mixed> $files
	 * @return array{0: \Nette\Http\Request, 1: array<string, object>} kopie a původní služby podle jména
	 * @throws \DoryoApi\Http\ApiException
	 */
	private function swapHttpRequest(string $method, array $params, array $post, array $files = []): array
	{
		/** @var \Nette\Http\IRequest $original */
		$original = $this->container->getByType(IRequest::class);
		$copy = self::sameSiteCopy($original, $method, $params, $post, $files);
		$originals = [];

		foreach ($this->container->findByType(IRequest::class) as $name) {
			// jiné služby téhož typu (pokud nějaké jsou) nejsou to, co dostane presenter
			if ($this->container->getService($name) !== $original) {
				continue;
			}

			if (!\is_a($copy, $this->container->getServiceType($name))) {
				throw new ApiException(500, 'Chyba serveru', \sprintf(
					'Služba HTTP požadavku „%s" má typ %s, brána umí podstrčit jen %s. Nahlas to vývojářům.',
					$name,
					$this->container->getServiceType($name),
					HttpRequest::class,
				));
			}

			$originals[$name] = $original;
			$this->container->removeService($name);
			$this->container->addService($name, $copy);
		}

		return [$copy, $originals];
	}

	/**
	 * @param array<string, object> $originals
	 */
	private function restoreHttpRequest(array $originals): void
	{
		foreach ($originals as $name => $original) {
			$this->container->removeService($name);
			$this->container->addService($name, $original);
		}
	}

	/**
	 * @param array<string, mixed> $params
	 * @param array<string, mixed> $post
	 * @param array<string, mixed> $files
	 */
	private static function sameSiteCopy(IRequest $original, string $method, array $params, array $post, array $files = []): HttpRequest
	{
		$headers = \array_change_key_case($original->getHeaders());
		unset($headers['content-type'], $headers['content-length']);
		$headers = ['sec-fetch-site' => 'same-origin'] + $headers;

		if ($method === 'POST') {
			$headers['content-type'] = $files ? 'multipart/form-data' : 'application/x-www-form-urlencoded';
		}

		$query = \array_filter($params, static fn ($value): bool => \is_scalar($value) || \is_array($value));

		return new HttpRequest(
			$original->getUrl()->withQuery($query),
			$post,
			$files,
			[Helpers::StrictCookieName => '1'] + $original->getCookies(),
			$headers,
			$method,
			$original->getRemoteAddress(),
			// getRemoteHost() by dělal reverzní DNS dotaz; kopie si ho dopočítá, až ho někdo bude chtít
			null,
			// tělo API požadavku (JSON) není to, co by poslal prohlížeč s formulářem
			static fn (): ?string => null,
		);
	}

	/**
	 * Flash zprávy, které by jinak čekaly v session na další požadavek. Po vybrání se mažou,
	 * ať se nepřilepí k dalšímu volání.
	 *
	 * Bere se celá sekce, ne jen klíč `flash`: presenter ukládá pod `flash`, komponenta, která
	 * volá `flashMessage()` sama na sobě (grid, vložený formulář), pod `<komponenta>-flash`.
	 * @return array<array{type: string, message: string}>
	 */
	private static function takeFlashes(Presenter $presenter): array
	{
		if (!$presenter->hasFlashSession()) {
			return [];
		}

		$section = $presenter->getFlashSession();
		$flashes = [];

		foreach ($section as $key => $messages) {
			if (!\is_string($key) || ($key !== 'flash' && !\str_ends_with($key, '-flash')) || !\is_iterable($messages)) {
				continue;
			}

			foreach ($messages as $flash) {
				if (!$flash instanceof \stdClass) {
					continue;
				}

				$flashes[] = [
					'type' => (string) ($flash->type ?? 'info'),
					'message' => \strip_tags((string) ($flash->message ?? '')),
				];
			}
		}

		$section->remove();

		return $flashes;
	}
}
