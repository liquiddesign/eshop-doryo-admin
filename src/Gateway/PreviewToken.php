<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

use DoryoApi\Config;
use DoryoApi\Http\ApiException;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Nette\Utils\Strings;

/**
 * Podpis náhledu, bez kterého se nedá zapsat.
 *
 * Kolego má potvrzení per nástroj, ne per volání, a v potvrzovacím okně se obsah těla vůbec
 * neukáže — odsouhlasení zápisu je tedy z pohledu uživatele bianko šek na všechna další zápisová
 * volání v sezení. Bezpečnost proto musí být tady: agent nejdřív pošle `dryRun`, dostane diff
 * a k němu podepsaný token, a ostrý zápis bez toho tokenu neprojde. Nemůže tak zapsat nic jiného,
 * než co uživateli předtím ukázal.
 *
 * Token je bezstavový (HMAC, žádná tabulka) a váže se i na stav záznamu — když ho mezitím někdo
 * změnil, náhled už neplatí a musí se zopakovat. Tím se řeší i souběh.
 *
 * Tajemství je API token z {@see Config}: kdo ho má, může volat API tak jako tak, takže vlastní
 * konfigurační položka by nic nepřidala, jen by šla zapomenout nastavit.
 */
final class PreviewToken
{
	private const VERSION = 'pt1';

	public function __construct(private Config $config, private GatewayConfig $gatewayConfig)
	{
	}

	/**
	 * @param array<string, mixed> $changes Co se má zapsat (normalizované hodnoty)
	 * @param array<string, mixed> $state Stav záznamu, ze kterého náhled vznikl
	 * @return array{token: string, expiresAt: string}
	 */
	public function issue(string $scope, string $id, array $changes, array $state): array
	{
		$expires = \time() + $this->gatewayConfig->getPreviewTokenLifetime();

		$payload = [
			'v' => self::VERSION,
			's' => $scope,
			'i' => $id,
			'c' => self::hash($changes),
			'r' => self::hash($state),
			'e' => $expires,
		];

		$body = self::encode($payload);

		return [
			'token' => $body . '.' . $this->sign($body),
			'expiresAt' => (new \DateTimeImmutable('@' . $expires))->setTimezone(new \DateTimeZone($this->config->getTimezone()))->format('c'),
		];
	}

	/**
	 * Ostrý zápis: token musí sedět na tentýž záznam, tytéž změny a tentýž výchozí stav.
	 * @param array<string, mixed> $changes
	 * @param array<string, mixed> $state
	 * @param string|null $idMismatch Vysvětlení pro případ, kdy „záznam" není jeden řádek; u hromadné úpravy je otiskem celé cílové množiny
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function verify(?string $token, string $scope, string $id, array $changes, array $state, ?string $idMismatch = null): void
	{
		if ($token === null || $token === '') {
			throw new ApiException(409, 'Chybí náhled', 'Ostrý zápis potřebuje previewToken. Zavolej nejdřív totéž '
				. 's "dryRun": true, ukaž uživateli vrácený diff a token z odpovědi pošli sem.');
		}

		$parts = \explode('.', $token, 2);

		if (\count($parts) !== 2 || !\hash_equals($this->sign($parts[0]), $parts[1])) {
			throw new ApiException(409, 'Neplatný náhled', 'previewToken není platný. Zopakuj dotaz s "dryRun": true.');
		}

		try {
			/** @var array<string, mixed> $payload */
			$payload = Json::decode(self::decode($parts[0]), Json::FORCE_ARRAY);
		} catch (JsonException) {
			throw new ApiException(409, 'Neplatný náhled', 'previewToken se nepodařilo přečíst. Zopakuj dotaz s "dryRun": true.');
		}

		if (($payload['v'] ?? null) !== self::VERSION || ($payload['s'] ?? null) !== $scope) {
			throw new ApiException(409, 'Náhled patří jinam', 'previewToken byl vystavený na jinou akci. '
				. 'Zopakuj dotaz s "dryRun": true.');
		}

		if (($payload['i'] ?? null) !== $id) {
			throw new ApiException(409, $idMismatch !== null ? 'Výběr se mezitím změnil' : 'Náhled patří jinam', $idMismatch
				?? 'previewToken byl vystavený na jiný záznam. Zopakuj dotaz s "dryRun": true.');
		}

		if ((int) ($payload['e'] ?? 0) < \time()) {
			throw new ApiException(409, 'Náhled vypršel', \sprintf(
				'previewToken platí %d minut a ten tvůj už vypršel. Zopakuj dotaz s "dryRun": true.',
				(int) \round($this->gatewayConfig->getPreviewTokenLifetime() / 60),
			));
		}

		if (($payload['c'] ?? null) !== self::hash($changes)) {
			throw new ApiException(409, 'Jiné hodnoty než v náhledu', 'Tělo požadavku nese jiné změny, než na které byl '
				. 'previewToken vystavený. Zapsat jde jen to, co uživatel viděl v náhledu.');
		}

		if (($payload['r'] ?? null) !== self::hash($state)) {
			throw new ApiException(409, 'Záznam se mezitím změnil', 'Od náhledu se záznam změnil — mohl ho upravit někdo '
				. 'v administraci. Zopakuj dotaz s "dryRun": true, ukaž nový diff a zapiš znovu.');
		}
	}

	private function sign(string $body): string
	{
		$secret = $this->config->getToken();

		if ($secret === null) {
			throw new ApiException(503, 'API není nastavené', 'Doryo API nemá token, bez něj se náhled nedá podepsat.');
		}

		return Strings::substring(\hash_hmac('sha256', $body, $secret), 0, 32);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private static function hash(array $values): string
	{
		\ksort($values);

		return Strings::substring(\hash('sha256', \serialize($values)), 0, 32);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function encode(array $payload): string
	{
		return \rtrim(\strtr(\base64_encode(Json::encode($payload)), '+/', '-_'), '=');
	}

	private static function decode(string $value): string
	{
		return (string) \base64_decode(\strtr($value, '-_', '+/'), true);
	}
}
