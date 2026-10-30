<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

use Admin\DB\ChangelogRepository;
use DoryoApi\Http\ApiException;
use Nette\Application\Responses\JsonResponse;
use StORM\DIConnection;
use StORM\Entity;

/**
 * Brána nad signály administrace (`handle*`): storno objednávky, přijetí, dokončení, otevření.
 *
 * Na rozdíl od formulářů tady není co introspektovat — `handle*` metody nenesou popis parametrů
 * ani významu. Seznam je proto ruční ({@see GatewayConfig}) a u každé akce je ručně psaný popis
 * a příznak „destruktivní". Spouští se pak normální cestou, signálem na presenteru, takže platí
 * logika i logování administrace.
 */
final class ActionGateway
{
	public function __construct(
		private PresenterRunner $runner,
		private PreviewToken $previewToken,
		private ChangelogRepository $changelogRepository,
		private DIConnection $connection,
		private Uploads $uploads,
	) {
	}

	/**
	 * @param array<mixed> $body
	 * @return array<string, mixed>
	 */
	public function run(ActionTarget $target, array $body): array
	{
		$params = self::readParams($target, $body);
		$dryRun = (bool) ($body['dryRun'] ?? false);

		if ($target->file === null && isset($body['file'])) {
			throw ApiException::badRequest(\sprintf('Akce „%s" soubor nebere.', $target->label));
		}

		if ($target->file !== null && !isset($body['file'])) {
			throw ApiException::badRequest(\sprintf('Akce „%s" nahrává soubor — v těle chybí "file": {"name", "content" (base64)}.', $target->label));
		}

		$upload = $target->file !== null ? $this->uploads->read($body['file'], $target->label, $target->fileImage) : null;
		// token se váže i na obsah souboru: ostrý běh s jiným souborem, než ukázal náhled, je 409
		$signed = $upload !== null ? $params + ['file' => 'file:' . $upload['file']['sha256']] : $params;
		$id = $target->idParam !== null ? (string) $params[$target->idParam] : '';
		$resource = ':' . $target->presenter . ':' . $target->action;

		return $this->runner->withAdministrator(function () use ($target, $body, $params, $signed, $upload, $dryRun, $id, $resource): array {
			$this->runner->assertManager($resource, $target->label);

			if ($dryRun) {
				// Náhled otevře stránku akce s jejími parametry, jen bez signálu: administrace tím
				// sama řekne, jestli identita na záznam smí — For Baby `OrderPresenter::startup()`
				// obchodníkovi cizí objednávku odmítne dřív, než by signál vůbec běžel. Bez toho by
				// náhled ukázal záznam, na který ostrý běh nesmí, a token by se vydal zbytečně.
				$this->runner->open($target->presenter, ['action' => $target->action] + $params);
			}

			$before = $this->describe($target, $id);
			$scope = 'action:' . $target->presenter . '/' . $target->signal;

			if ($dryRun) {
				$preview = $this->previewToken->issue($scope, $id, $signed, $before);

				return ($upload !== null ? ['file' => $upload['file']] : []) + [
					'dryRun' => true,
					'action' => $target->signal,
					'label' => $target->label,
					'destructive' => $target->destructive,
					'record' => $before,
					'requiresConfirm' => $target->destructive,
					'customerEmail' => $target->customerEmail,
					'previewToken' => $preview['token'],
					'expiresAt' => $preview['expiresAt'],
					'note' => $target->destructive
						? 'Akce je nevratná. Ostrý běh chce vedle previewTokenu i "confirm": true.'
						: 'Ostrý běh pošli s previewTokenem z téhle odpovědi.',
				];
			}

			if ($target->destructive && ($body['confirm'] ?? null) !== true) {
				throw ApiException::badRequest(\sprintf(
					'Akce „%s" je označená jako destruktivní, ostrý běh proto vyžaduje v těle "confirm": true.',
					$target->label,
				));
			}

			$this->previewToken->verify(
				\is_string($body['previewToken'] ?? null) ? $body['previewToken'] : null,
				$scope,
				$id,
				$signed,
				$before,
			);

			$run = $this->runner->run(
				$target->presenter,
				['action' => $target->action, 'do' => $target->signal] + $params,
				[],
				$upload !== null ? [(string) $target->file => $upload['upload']] : [],
			);

			// Levior: tady kontrola chyběla a akce vracela „executed": true i tehdy, když ji
			// administrace odmítla — signály odmítnutí hlásí flash zprávou a přesměrováním, ne
			// výjimkou (For Baby `handleCancelOrder`: „Objednávku se nepodařilo stornovat" jako error,
			// nepovolené storno jako warning). Stejně jako u formulářů je to 422 a do auditu nic nejde.
			$refusals = $run->getRefusals();

			if ($refusals) {
				throw new ApiException(422, 'Akce odmítnuta', \sprintf(
					'Administrace akci „%s" neprovedla: %s',
					$target->label,
					\implode(' ', \array_column($refusals, 'message')),
				));
			}

			$this->changelogRepository->createOne([
				'user' => $this->runner->auditUser(PresenterRunner::actor($body)),
				'entity' => $target->presenter,
				'objectId' => $id !== '' ? $id : null,
				'type' => 'signal:' . $target->signal,
			]);

			$result = [
				'dryRun' => false,
				'executed' => true,
				'action' => $target->signal,
				'label' => $target->label,
				'before' => $before,
				'after' => $this->describe($target, $id),
				'flashes' => $run->flashes,
			];

			if ($upload !== null) {
				$result['file'] = $upload['file'];
			}

			// signály dropzone odpovídají JSONem (název uloženého souboru, náhled) — model ho potřebuje třeba
			// k dalšímu kroku „nastav jako hlavní"
			if ($run->response instanceof JsonResponse) {
				$result['response'] = $run->response->getPayload();
			}

			return $result;
		});
	}

	/**
	 * Identifikace záznamu, kterého se akce týká — aby uživatel v náhledu viděl, co přesně
	 * se chystá, a aby se na to dal navázat podpis náhledu.
	 * @return array<string, mixed>
	 */
	private function describe(ActionTarget $target, string $id): array
	{
		if ($target->entity === null || $id === '') {
			return [];
		}

		/** @var \StORM\Entity|null $entity */
		$entity = $this->connection->findRepository($target->entity)->one($id);

		if ($entity === null) {
			throw ApiException::notFound(\sprintf('Záznam s id %s neexistuje.', $id));
		}

		$record = ['id' => $id];

		foreach (['code', 'name'] as $property) {
			$value = self::value($entity, $property);

			if ($value === null) {
				continue;
			}

			$record[$property] = $value;
		}

		if (\method_exists($entity, 'getState')) {
			$record['state'] = (string) $entity->getState();
		}

		return $record;
	}

	private static function value(Entity $entity, string $property): ?string
	{
		try {
			$value = $entity->getValue($property);
		} catch (\Throwable) {
			return null;
		}

		return \is_scalar($value) ? (string) $value : null;
	}

	/**
	 * @param array<mixed> $body
	 * @return array<string, string>
	 */
	private static function readParams(ActionTarget $target, array $body): array
	{
		$given = $body['params'] ?? [];

		if (!\is_array($given)) {
			throw ApiException::badRequest('Parametr "params" musí být objekt.');
		}

		$params = [];

		foreach ($target->params as $name) {
			if (!isset($given[$name]) || !\is_scalar($given[$name]) || (string) $given[$name] === '') {
				throw ApiException::badRequest(\sprintf(
					'Akce „%s" potřebuje parametr „%s". Očekává tyhle: %s.',
					$target->label,
					$name,
					\implode(', ', $target->params),
				));
			}

			$params[$name] = (string) $given[$name];
		}

		$unknown = \array_diff(\array_keys($given), $target->params);

		if ($unknown) {
			throw ApiException::badRequest(\sprintf(
				'Akce „%s" nezná parametry %s. Očekává tyhle: %s.',
				$target->label,
				\implode(', ', $unknown),
				\implode(', ', $target->params),
			));
		}

		return $params;
	}
}
