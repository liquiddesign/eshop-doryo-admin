<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

use DoryoApi\Http\ApiException;
use StORM\DIConnection;
use StORM\Entity;

/**
 * Položka, která patří k záznamu stránky: položka objednávky na detailu objednávky.
 *
 * Administrace takové položky edituje komponentou s id položky ve jménu (`detailOrderItemForm-<id>`
 * je `Multiplier`) a signálem s id v parametru (`handleToggleDeleteOrderItem($itemId)`) — a ani jedno
 * si nekontroluje, že položka k objednávce na stránce opravdu patří. Člověk se k cizí položce
 * nedostane, protože ji stránka nevykreslí; přes API by stačilo poslat jiné id. Brána proto
 * příslušnost ověří sama (`parent` = sloupec s id záznamu stránky, i přes vztah: `package.fk_order`)
 * a stejným dotazem vypíše, jaké položky záznam má.
 */
final class ChildItem
{
	/**
	 * @param string $param Jméno parametru s id položky (v těle `item`, v názvu formuláře `{item}`)
	 * @param class-string<\StORM\Entity> $entity Entita položky
	 * @param string $parent Sloupec položky s id záznamu stránky, i přes vztah (`package.fk_order`)
	 * @param string $label Vlastnost položky, podle které ji člověk pozná, i přes vztah (`cartItem.productName`)
	 */
	public function __construct(
		public readonly string $param,
		public readonly string $entity,
		public readonly string $parent,
		public readonly string $label,
	) {
	}

	/**
	 * Z položky whitelistu `item: { param, entity, parent, label }`; prázdná = formulář či akce položky nemá.
	 * @param array<string, mixed>|null $config
	 */
	public static function fromConfig(?array $config): ?self
	{
		if (!$config) {
			return null;
		}

		foreach (['entity', 'parent'] as $key) {
			if (!isset($config[$key]) || !\is_string($config[$key]) || $config[$key] === '') {
				throw new \Nette\DI\InvalidConfigurationException("doryoAdmin: u položky (item) chybí „{$key}\".");
			}
		}

		return new self(
			param: (string) ($config['param'] ?? 'item'),
			entity: (string) $config['entity'],
			parent: (string) $config['parent'],
			label: (string) ($config['label'] ?? 'uuid'),
		);
	}

	/**
	 * Patří položka k záznamu? Jinak 404 — se stejnou větou pro neexistující i cizí položku.
	 * @return array{id: string, label: string|null}
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function assertBelongs(DIConnection $connection, string $itemId, string $parentId): array
	{
		$repository = $connection->findRepository($this->entity);
		$pk = $repository->getStructure()->getPK()->getName();
		$entity = $repository->many()->where('this.' . $pk, $itemId)->where($this->parent, $parentId)->first();

		if (!$entity instanceof Entity) {
			throw ApiException::notFound(\sprintf(
				'Položka %s k záznamu %s nepatří, nebo neexistuje. Položky záznamu vypíše schéma bez „%s".',
				$itemId,
				$parentId,
				$this->param,
			));
		}

		return ['id' => $itemId, 'label' => self::path($entity, $this->label)];
	}

	/**
	 * Položky záznamu, ať model ví, jaká id poslat.
	 * @return list<array{id: string, label: string|null}>
	 */
	public function list(DIConnection $connection, string $parentId): array
	{
		$items = [];

		foreach ($connection->findRepository($this->entity)->many()->where($this->parent, $parentId)->toArray() as $id => $entity) {
			$items[] = ['id' => (string) $id, 'label' => self::path($entity, $this->label)];
		}

		return $items;
	}

	/**
	 * Jméno komponenty s dosazeným id položky (`detailOrderItemForm-{item}` → `detailOrderItemForm-<id>`).
	 */
	public function component(string $template, string $itemId): string
	{
		return \str_replace('{' . $this->param . '}', $itemId, $template);
	}

	private static function path(Entity $entity, string $path): ?string
	{
		$value = $entity;

		foreach (\explode('.', $path) as $property) {
			try {
				$value = $value instanceof Entity ? $value->{$property} : null;
			} catch (\Throwable) {
				return null;
			}
		}

		return \is_scalar($value) ? (string) $value : null;
	}
}
