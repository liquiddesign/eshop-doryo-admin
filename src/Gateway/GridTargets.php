<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

use Admin\Controls\AdminGrid;
use DoryoApi\Http\ApiException;
use Grid\Datagrid;
use Nette\Utils\Strings;
use StORM\DIConnection;
use StORM\Entity;

/**
 * Cílové záznamy zápisu nad gridem: výčet id z těla, kontrola, že je identita v gridu vidí,
 * otisk množiny pro náhledový token a ukázka pro člověka.
 *
 * Sdílí to hromadná úprava ({@see BulkGateway}) i mazání ({@see DeleteGateway}) — obojí sahá na
 * víc záznamů najednou a obojí musí zapsat přesně tu množinu, kterou uživatel v náhledu viděl.
 */
final class GridTargets
{
	/** Kolik cílů se vypíše jmenovitě; zbytek je jen počet, jinak by odpověď u filtru utekla. */
	public const SAMPLE = 20;

	/**
	 * @return array<string>
	 * @throws \DoryoApi\Http\ApiException
	 */
	public static function readIds(mixed $ids): array
	{
		if (!\is_array($ids) || !$ids || !\array_is_list($ids)) {
			throw ApiException::badRequest('Parametr "ids" musí být neprázdné pole id záznamů.');
		}

		$result = [];

		foreach ($ids as $id) {
			if (!\is_scalar($id) || (string) $id === '') {
				throw ApiException::badRequest('Parametr "ids" smí obsahovat jen id záznamů.');
			}

			$result[] = (string) $id;
		}

		return \array_values(\array_unique($result));
	}

	/**
	 * Jsou všechna poslaná id v gridu — tedy v tom, co identita v administraci vidí? Neexistující
	 * nebo cizí se nedá tiše přeskočit: uživatel by dostal „hotovo" u záznamů, na které se nesáhlo.
	 *
	 * Kontroluje se proti zdroji gridu, ne proti repozitáři: projekt v něm omezuje, co kdo vidí
	 * (For Baby obchodníkovi jen jeho zákazníky). Admin 2.1 sám u vztahů N:N sahá přímo přes
	 * `RelationCollection` na poslaná id a zdroj gridu neřeší, takže bez téhle kontroly by šlo
	 * obchodníkovi přidat ceník i cizímu zákazníkovi.
	 * @param array<string> $ids
	 * @return array<string>
	 * @throws \DoryoApi\Http\ApiException
	 */
	public static function assertVisible(AdminGrid $grid, array $ids): array
	{
		$missing = \array_values(\array_diff($ids, self::visible($grid, $ids)));

		if ($missing) {
			throw ApiException::notFound(\sprintf(
				'Tahle id v seznamu nejsou — neexistují, nebo je účet, za který brána jedná, v administraci nevidí: %s.',
				\implode(', ', $missing),
			));
		}

		return $ids;
	}

	/**
	 * Která z id zdroj gridu vrací.
	 * @param array<string> $ids
	 * @return array<string>
	 */
	public static function visible(AdminGrid $grid, array $ids): array
	{
		$source = $grid->getSource();
		$idName = $grid->getSourceIdName();
		$column = \str_contains($idName, '.') ? $idName : $source->getPrefix() . $idName;

		return \array_map('\strval', $source->where($column, $ids)->toArrayOf($idName));
	}

	/**
	 * Id tak, jak je grid nese v zaškrtávátkách řádků. AdminGrid se většinou zakládá s `encodeId`
	 * (id jako `bin2hex`) a `getSelectedIds()` poslané hodnoty dekóduje — holé id by se po dekódování
	 * nenašlo a administrace by řádek tiše přeskočila. Callback je chráněný a getter nemá, proto reflexe.
	 * @param array<string> $ids
	 * @return array<string>
	 */
	public static function encodeIds(Datagrid $grid, array $ids): array
	{
		$encode = (new \ReflectionProperty(Datagrid::class, 'encodeIdCallback'))->getValue($grid);

		if (!\is_callable($encode)) {
			return $ids;
		}

		return \array_map(static fn (string $id): string => (string) $encode($id), $ids);
	}

	/**
	 * Otisk cílové množiny. Na pořadí nezáleží, na obsahu ano — token se tím váže na přesně ty
	 * záznamy, které uživatel v náhledu viděl.
	 * @param array<string> $ids
	 */
	public static function fingerprint(array $ids): string
	{
		\sort($ids);

		return Strings::substring(\hash('sha256', \implode('|', $ids)), 0, 32);
	}

	/**
	 * Kolik cílů to je a pár z nich jmenovitě, ať uživatel v náhledu čte firmy a produkty, a ne uuid.
	 * @param class-string<\StORM\Entity> $entity
	 * @param array<string> $ids
	 * @return array{count: int, sample: array<array<string, string>>, truncated: bool}
	 */
	public static function sample(DIConnection $connection, string $entity, array $ids): array
	{
		$sample = [];
		$repository = $connection->findRepository($entity);
		$pk = $repository->getStructure()->getPK()->getName();

		foreach ($repository->many()->where('this.' . $pk, \array_slice($ids, 0, self::SAMPLE))->toArray() as $id => $record) {
			$row = ['id' => (string) $id];

			foreach (['code', 'company', 'name', 'fullname'] as $property) {
				$value = self::property($record, $property);

				if ($value === null || $value === '') {
					continue;
				}

				$row[$property] = $value;

				break;
			}

			$sample[] = $row;
		}

		return [
			'count' => \count($ids),
			'sample' => $sample,
			'truncated' => \count($ids) > self::SAMPLE,
		];
	}

	/**
	 * Která z id v tabulce entity ještě jsou — po mazání podle toho brána pozná, co administrace
	 * opravdu smazala (`addButtonDeleteSelected` hlásí „Provedeno" i tehdy, když část nesmazala).
	 * @param class-string<\StORM\Entity> $entity
	 * @param array<string> $ids
	 * @return array<string>
	 */
	public static function existing(DIConnection $connection, string $entity, array $ids): array
	{
		$repository = $connection->findRepository($entity);
		$pk = $repository->getStructure()->getPK()->getName();

		return \array_map('\strval', \array_keys($repository->many()->where('this.' . $pk, $ids)->toArray()));
	}

	public static function property(Entity $entity, string $property): ?string
	{
		try {
			$value = $entity->getValue($property);
		} catch (\Throwable) {
			return null;
		}

		return \is_scalar($value) ? (string) $value : null;
	}
}
