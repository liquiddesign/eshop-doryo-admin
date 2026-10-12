<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

use Admin\Controls\AdminGrid;
use DoryoApi\Http\ApiException;
use Nette\Utils\Arrays;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Nette\Utils\Strings;

/**
 * Čtení seznamů administrace: reporty ke schválení, slevové akce, emailingy, zákazníci…
 *
 * Grid se otevře normální cestou presenteru s filtry, stránkou a řazením v parametrech — tedy
 * přesně to, co by člověk viděl ve výpisu, i s tím, co grid do sloupců sám dopočítá. Řádky se
 * vydávají jako text buněk pod hlavičkami sloupců, bez HTML.
 *
 * Odpovědi drží na uzdě dvě věci: stránka má nejvýš {@see MAX_PAGE_SIZE} řádků a buňka nejvýš
 * 300 znaků. Kdo chce víc, stránkuje nebo filtruje — vysypat devět tisíc zákazníků do kontextu
 * modelu nemá smysl.
 */
final class GridGateway
{
	public const DEFAULT_PAGE_SIZE = 20;

	private const MAX_PAGE_SIZE = 100;

	public function __construct(private PresenterRunner $runner)
	{
	}

	/**
	 * @param array<string, mixed> $filter
	 * @return array<string, mixed>
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function read(GridTarget $target, array $filter, int $page, int $pageSize, ?string $order, ?string $id = null, ?string $tab = null): array
	{
		if ($target->tabParameter === null && $tab !== null) {
			throw ApiException::badRequest(\sprintf('Seznam „%s" záložky nemá, parametr „tab" nebere.', $target->label));
		}

		if ($target->parameter !== null && ($id === null || $id === '')) {
			throw ApiException::badRequest(\sprintf('Seznam „%s" patří ke konkrétnímu záznamu — chybí parametr „id".', $target->label));
		}

		if ($target->parameter === null && $id !== null) {
			throw ApiException::badRequest(\sprintf('Seznam „%s" stojí sám, parametr „id" nebere.', $target->label));
		}

		$page = \max(1, $page);
		$pageSize = \min(self::MAX_PAGE_SIZE, \max(1, $pageSize));

		return $this->runner->withAdministrator(function () use ($target, $filter, $page, $pageSize, $order, $id, $tab): array {
			if (!$this->runner->isAllowed($target->getResource())) {
				throw ApiException::forbidden(\sprintf(
					'Účet Doryo nesmí na stránku „%s". Oprávnění se nastavují v Administrace → Role.',
					$target->label,
				));
			}

			$params = ['action' => $target->action, $target->grid . '-page' => $page, $target->grid . '-onpage' => $pageSize];

			if ($order !== null) {
				$params[$target->grid . '-order'] = $order;
			}

			if ($target->tabParameter !== null && $tab !== null) {
				$params[$target->tabParameter] = $tab;
			}

			if ($target->parameter !== null) {
				// záznam dosadí PresenterRunner (id → entita) stejně jako router, neexistující id je 404
				$params[$target->parameter] = $id;
			}

			foreach ($filter as $name => $value) {
				$params[$target->grid . '-' . $name] = $value;
			}

			$run = $this->runner->run($target->presenter, $params);
			$grid = $run->presenter->getComponent($target->grid, false);

			if (!$grid instanceof AdminGrid) {
				throw ApiException::notFound(\sprintf('Komponenta %s na presenteru %s není grid administrace.', $target->grid, $target->presenter));
			}

			GridReader::assertFilters($grid, $filter, $target->getEndpoint());

			$columns = GridReader::dataColumns($grid);
			$orderable = GridReader::orderable($columns);

			if ($order !== null) {
				$expression = Strings::before($order, '-', -1) ?? $order;

				if (!Arrays::contains(\array_column($orderable, 'order'), $expression) || !\preg_match('~-(ASC|DESC)$~', $order)) {
					throw ApiException::badRequest(\sprintf(
						'Řazení „%s" grid nezná. Tvar je „<výraz>-ASC" nebo „<výraz>-DESC", výrazy: %s.',
						$order,
						$orderable ? \implode(', ', \array_column($orderable, 'order')) : '(grid řadit neumí)',
					));
				}
			}

			$total = $grid->getFilteredSource()->count();
			$rows = [];

			foreach ($grid->getItemsOnPage() as $item) {
				$rows[] = GridReader::row($grid, $item, $columns);
			}

			$pageCount = (int) \ceil($total / $pageSize);

			return [
				'grid' => ['presenter' => $target->presenter, 'grid' => $target->grid, 'label' => $target->label, 'description' => $target->description, 'id' => $id, 'tab' => $tab],
				'filter' => (object) $filter,
				'order' => $order,
				'page' => $page,
				'pageSize' => $pageSize,
				'total' => $total,
				'pageCount' => $pageCount,
				'hasMore' => $page < $pageCount,
				'columns' => \array_keys($columns),
				'rows' => $rows,
				'filters' => GridReader::describeFilters($grid, $target->filterNotes),
				'orderable' => $orderable,
				'note' => 'Buňky jsou text tak, jak je grid ukazuje člověku (dlouhé zkrácené na 300 znaků). '
					. 'Filtruj parametrem „filter" (JSON objekt jméno filtru → hodnota), stránkuj „page" a „limit" '
					. '(nejvýš ' . self::MAX_PAGE_SIZE . '). „total" je počet po filtru — když je velký, radši zúž filtr, '
					. 'než abys stránkoval všechno.',
			];
		});
	}

	/**
	 * Filtr z query: JSON objekt, protože jména filtrů jsou per grid a do pevného seznamu parametrů
	 * se nevejdou.
	 * @return array<string, mixed>
	 * @throws \DoryoApi\Http\ApiException
	 */
	public static function parseFilter(?string $raw): array
	{
		if ($raw === null || $raw === '') {
			return [];
		}

		try {
			$filter = Json::decode($raw, Json::FORCE_ARRAY);
		} catch (JsonException) {
			throw ApiException::badRequest('Parametr „filter" musí být JSON objekt, například {"search":"Novák"}.');
		}

		if (!\is_array($filter) || \array_is_list($filter) && $filter !== []) {
			throw ApiException::badRequest('Parametr „filter" musí být JSON objekt (jméno filtru → hodnota), ne pole.');
		}

		foreach ($filter as $name => $value) {
			if (!\is_string($name) || !(\is_scalar($value) || (\is_array($value) && \array_is_list($value)))) {
				throw ApiException::badRequest(\sprintf('Hodnota filtru „%s" musí být text, číslo nebo seznam.', (string) $name));
			}
		}

		return $filter;
	}
}
