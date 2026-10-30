<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

/**
 * Jeden grid administrace, ze kterého smí agent mazat.
 *
 * Maže se tlačítkem „Smazat" nad vybranými řádky (`addButtonDeleteSelected`), tedy tou cestou,
 * kterou maže člověk: platí projektové podmínky (systémové stránky, objednané produkty) i úklid
 * v `onDelete` (fotky produktu, soubory). Grid bez toho tlačítka brána mazat neumí — mazání
 * jednoho řádku ikonou koše má v gridu jen pořadové číslo sloupce, ne jméno, na které by šlo
 * spolehlivě ukázat.
 */
final class DeleteTarget
{
	/**
	 * @param string $grid Jméno komponenty gridu na presenteru (`grid`, `productGrid`)
	 * @param string $action Akce presenteru, na které grid žije
	 * @param class-string<\StORM\Entity> $entity Entita řádků gridu — podle ní brána popíše, co maže,
	 *     a po smazání ověří, co opravdu zmizelo
	 * @param string $description Co smazání znamená, pro model a kartu („produkt zmizí z webu i z feedů")
	 */
	public function __construct(
		public readonly string $presenter,
		public readonly string $grid,
		public readonly string $action,
		public readonly string $label,
		public readonly string $entity,
		public readonly string $description,
	) {
	}

	public function getEndpoint(): string
	{
		return \sprintf('/v1/admin/delete/%s/%s', $this->presenter, $this->grid);
	}

	/**
	 * Resource pro oprávnění tak, jak ho čte administrace.
	 */
	public function getResource(): string
	{
		return ':' . $this->presenter . ':' . $this->action;
	}
}
