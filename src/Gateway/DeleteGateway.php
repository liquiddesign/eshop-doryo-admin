<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

use Admin\Controls\AdminGrid;
use Admin\DB\ChangelogRepository;
use DoryoApi\Http\ApiException;
use Nette\Application\UI\Presenter;
use Nette\Forms\Controls\SubmitButton;
use StORM\DIConnection;

/**
 * Brána nad mazáním z gridu administrace.
 *
 * Maže se jen dvoufázově a vždycky s potvrzením: náhled vypíše, co zmizí, a vydá token vázaný na
 * přesně tu množinu; ostrý běh chce token i `"confirm": true`. Pak se odešle formulář gridu
 * s vybranými řádky a tlačítkem „Smazat" — stejný POST, jaký pošle prohlížeč, takže rozhoduje
 * administrace: projektová podmínka (`condition`), úklid v `onDelete` i omezení zdroje gridu.
 *
 * `addButtonDeleteSelected` hlásí „Provedeno" i tehdy, když část záznamů nesmazal (cizí klíč,
 * podmínka projektu) — jen k tomu přidá varování. Brána proto po běhu sama zjistí, co v tabulce
 * zůstalo, a vrátí to jmenovitě; co se smazat nepovedlo, nikdy nehlásí jako smazané.
 */
final class DeleteGateway
{
	/** Tlačítko „Smazat" nad vybranými řádky, jak ho staví `AdminGrid::addButtonDeleteSelected()`. */
	private const SUBMIT = 'deleteAll';

	public function __construct(
		private PresenterRunner $runner,
		private PreviewToken $previewToken,
		private ChangelogRepository $changelogRepository,
		private DIConnection $connection,
	) {
	}

	/**
	 * @param array<mixed> $body
	 * @return array<string, mixed>
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function submit(DeleteTarget $target, array $body): array
	{
		$ids = GridTargets::readIds($body['ids'] ?? null);
		$dryRun = (bool) ($body['dryRun'] ?? false);
		$params = self::params($target, $body);

		return $this->runner->withAdministrator(function () use ($target, $body, $ids, $dryRun, $params): array {
			// mazání je v administraci tlačítko nad gridem; přístup na stránku kontroluje presenter,
			// „Plný přístup" je ochranou jen tím, že tlačítko jinak není vidět — přes API je to málo
			$this->runner->assertManager($target->getResource(), $target->label);

			$run = $this->runner->open($target->presenter, $params);
			$grid = self::grid($run->presenter, $target);
			GridTargets::assertVisible($grid, $ids);

			$records = GridTargets::sample($this->connection, $target->entity, $ids);
			$scope = 'delete:' . $target->presenter . '/' . $target->grid;
			$fingerprint = GridTargets::fingerprint($ids);
			$state = ['count' => \count($ids)];

			if ($dryRun) {
				$preview = $this->previewToken->issue($scope, $fingerprint, $params, $state);

				return [
					'dryRun' => true,
					'label' => $target->label,
					'description' => $target->description,
					'records' => $records,
					'destructive' => true,
					'requiresConfirm' => true,
					'previewToken' => $preview['token'],
					'expiresAt' => $preview['expiresAt'],
					'note' => 'Smazání je nevratné. Ukaž uživateli, co zmizí, a ostrý běh pošli se stejnými „ids", '
						. 's previewTokenem z téhle odpovědi a s "confirm": true.',
				];
			}

			if (($body['confirm'] ?? null) !== true) {
				throw ApiException::badRequest('Smazání je nevratné, ostrý běh proto vyžaduje v těle "confirm": true '
					. '— a před ním náhled s "dryRun": true, který uživatel viděl.');
			}

			$this->previewToken->verify(
				\is_string($body['previewToken'] ?? null) ? $body['previewToken'] : null,
				$scope,
				$fingerprint,
				$params,
				$state,
				'Ostrý běh maže jiné záznamy, než ukázal náhled. Zopakuj dotaz s "dryRun": true se stejnými „ids" '
					. 'a ukaž uživateli, co zmizí.',
			);

			return $this->delete($target, $params, $ids, $records, PresenterRunner::actor($body));
		});
	}

	/**
	 * Ostrý běh: POST na formulář gridu s vybranými řádky a tlačítkem „Smazat".
	 * @param array<string, string> $params Parametry stránky gridu (akce, záznam, záložka) — stejné jako v náhledu
	 * @param array<string> $ids
	 * @param array<string, mixed> $records
	 * @return array<string, mixed>
	 * @throws \DoryoApi\Http\ApiException
	 */
	private function delete(DeleteTarget $target, array $params, array $ids, array $records, ?string $actor): array
	{
		$run = $this->runner->run(
			$target->presenter,
			$params + ['do' => $target->grid . '-form-submit'],
			// zaškrtávátko řádku nese jako hodnotu id; `getSelectedIds()` bere hodnoty pole `__selector`
			['__selector' => \array_combine($ids, $ids), self::SUBMIT => 'Smazat'],
		);

		$remaining = GridTargets::existing($this->connection, $target->entity, $ids);
		$deleted = \array_values(\array_diff($ids, $remaining));

		if ($deleted) {
			$this->changelogRepository->createOne([
				'user' => $this->runner->auditUser($actor),
				'entity' => $target->presenter,
				'objectId' => \count($deleted) === 1 ? $deleted[0] : null,
				'type' => \sprintf('delete:%s:%d', $target->grid, \count($deleted)),
			]);
		}

		$refusals = \array_column($run->getRefusals(), 'message');

		if (!$deleted) {
			throw new ApiException(422, 'Nic se nesmazalo', \sprintf(
				'Administrace z „%s" nesmazala ani jeden záznam%s. Bývá to tím, že je záznam použitý jinde '
					. '(objednávka, vazba) nebo ho projekt mazat nedovolí.',
				$target->label,
				$refusals ? ': ' . \implode(' ', $refusals) : '',
			));
		}

		return [
			'dryRun' => false,
			'deleted' => \count($deleted),
			'deletedIds' => $deleted,
			'remaining' => $remaining,
			'records' => $records,
			'complete' => $remaining === [],
			'flashes' => $run->flashes,
			'note' => $remaining === []
				? 'Smazáno všechno, co ukázal náhled.'
				: \sprintf(
					'Smazáno %d z %d. Tyhle záznamy administrace nesmazala a zůstaly: %s%s',
					\count($deleted),
					\count($ids),
					\implode(', ', $remaining),
					$refusals ? ' (' . \implode(' ', $refusals) . ')' : '.',
				),
		];
	}

	/**
	 * Parametry stránky gridu: akce, záznam, ke kterému seznam patří (`id` z těla), a záložka (`tab`).
	 * @param array<mixed> $body
	 * @return array<string, string>
	 * @throws \DoryoApi\Http\ApiException
	 */
	private static function params(DeleteTarget $target, array $body): array
	{
		$id = isset($body['id']) && \is_scalar($body['id']) && (string) $body['id'] !== '' ? (string) $body['id'] : null;
		$tab = isset($body['tab']) && \is_scalar($body['tab']) && (string) $body['tab'] !== '' ? (string) $body['tab'] : null;

		if ($target->parameter !== null && $id === null) {
			throw ApiException::badRequest(\sprintf('Seznam „%s" patří ke konkrétnímu záznamu — v těle chybí „id".', $target->label));
		}

		if ($target->parameter === null && $id !== null) {
			throw ApiException::badRequest(\sprintf('Seznam „%s" stojí sám, „id" nebere — mazané záznamy patří do „ids".', $target->label));
		}

		if ($target->tabParameter === null && $tab !== null) {
			throw ApiException::badRequest(\sprintf('Seznam „%s" záložky nemá, „tab" nebere.', $target->label));
		}

		$params = ['action' => $target->action];

		if ($target->parameter !== null) {
			// záznam dosadí PresenterRunner (id → entita) stejně jako router, neexistující id je 404
			$params[$target->parameter] = (string) $id;
		}

		if ($target->tabParameter !== null && $tab !== null) {
			$params[$target->tabParameter] = $tab;
		}

		return $params;
	}

	/**
	 * @throws \DoryoApi\Http\ApiException
	 */
	private static function grid(Presenter $presenter, DeleteTarget $target): AdminGrid
	{
		$grid = $presenter->getComponent($target->grid, false);

		if (!$grid instanceof AdminGrid) {
			throw ApiException::notFound(\sprintf('Komponenta %s na presenteru %s není grid administrace.', $target->grid, $target->presenter));
		}

		if (!$grid->getForm()->getComponent(self::SUBMIT, false) instanceof SubmitButton) {
			throw ApiException::notFound(\sprintf(
				'Grid %s nemá tlačítko „Smazat" nad vybranými řádky (addButtonDeleteSelected), brána z něj mazat neumí.',
				$target->grid,
			));
		}

		return $grid;
	}
}
