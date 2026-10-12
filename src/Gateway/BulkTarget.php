<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

/**
 * Jeden grid administrace, jehož „Hromadnou úpravu" smí agent použít.
 *
 * Stejně jako u formulářů je to konfigurace, ne kód. Navíc proti formuláři nese entitu (bez ní
 * se nedá poznat, které pole je vztah N:N, a tedy jestli se dá přidávat) a práh, nad kterým chce
 * ostrý zápis potvrzení — hromadná úprava sáhne na desítky záznamů najednou, takže „kolik jich to
 * je" je ta nejdůležitější informace, kterou má uživatel před odsouhlasením vidět.
 */
final class BulkTarget
{
	/**
	 * @param string $grid Jméno komponenty gridu na presenteru (`customers`)
	 * @param string $form Jméno detailového formuláře, ze kterého si hromadná úprava půjčuje vstupy
	 * @param string $action Akce presenteru s hromadnou úpravou, vždycky `bulkEdit`
	 * @param class-string<\StORM\Entity> $entity Entita řádků gridu
	 * @param int $confirmFrom Od kolika cílů chce ostrý zápis navíc `"confirm": true`
	 * @param array<string, string> $filterNotes Ruční popis filtrů, které v gridu nemají popisek
	 */
	public function __construct(
		public readonly string $presenter,
		public readonly string $grid,
		public readonly string $form,
		public readonly string $action,
		public readonly string $label,
		public readonly string $entity,
		public readonly int $confirmFrom,
		public readonly array $filterNotes,
	) {
	}

	public function getEndpoint(): string
	{
		return \sprintf('/v1/admin/bulk/%s/%s', $this->presenter, $this->grid);
	}

	/**
	 * Resource pro oprávnění tak, jak ho čte administrace (`BackendPresenter::getAction(true)`).
	 */
	public function getResource(): string
	{
		return ':' . $this->presenter . ':' . $this->action;
	}
}
