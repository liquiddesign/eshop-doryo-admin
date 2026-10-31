<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

/**
 * Jeden formulář administrace, který smí agent číst a měnit. Vzniká z konfigurace, ne z kódu —
 * další stránka se přidává řádkem v sekci `doryoAdmin: forms:`.
 */
final class FormTarget
{
	/**
	 * @param bool $create Smí se přes tenhle formulář i zakládat, nebo jen editovat?
	 * @param string $createAction Akce presenteru, na které formulář zakládá (obvykle `new`)
	 * @param class-string<\StORM\Entity>|null $entity Entita záznamu — po zápisu se přes ni ověří,
	 *     že založený záznam opravdu vznikl
	 * @param string $createNote Výhrada k zakládání, kterou má model uživateli přetlumočit dřív,
	 *     než záznam založí; vrací se ve schématu i v mapě stránek
	 * @param list<string> $hidden Pole, která brána nevydá ani nepřijme (tajné klíče, hesla) — formulář
	 *     je při zápisu dostane se současnou hodnotou, takže se nepřepíšou
	 * @param string|null $createParameter Parametr zakládací akce s nadřazeným záznamem (`discount` u kupónu akce);
	 *     v těle `parent`. Null = zakládací akce žádný parametr nemá
	 * @param \DoryoAdmin\Gateway\ChildItem|null $item Formulář jedné položky záznamu stránky — jméno formuláře
	 *     nese `{<param>}` (`detailOrderItemForm-{item}`), id položky jde v těle a brána ověří, že k záznamu patří
	 * @param string|null $znacka Druh značky na webu (`produkt`, `slide`…): šablona e-shopu označí záznam makrem
	 *     `n:kolego` a rozšíření Kolego podle druhu pozná, kterým formulářem se mění (Kolego nad e-shopem)
	 * @param list<string> $bublina Pole, která rozšíření ukáže v bublině u prvku na webu; ostatní jsou za „Celý formulář"
	 * @param array<string, list<string>> $popisky Popisky polí bez vlastního popisku: vzor jména s `{id}` → [předpona, entita]
	 *     (`'prices-{id}-price' => ['Cena bez DPH', Pricelist::class]` → „Cena bez DPH · KZ – Denbraven"), viz {@see Popisky}
	 * @param string|null $tabParameter Persistentní parametr presenteru se záložkou (`tab` — u kategorií typ stromu); administrace
	 *     z něj při uložení bere, kam záznam patří, takže ho brána musí poslat jako prohlížeč
	 * @param string|null $tabColumn Sloupec záznamu (`entity`) s hodnotou záložky — u úpravy se vezme odtud (`type`)
	 * @param string|null $tabFromField Pole formuláře s nadřazeným záznamem, z jehož `tabColumn` se vezme záložka u založení (`ancestor`)
	 */
	public function __construct(
		public readonly string $presenter,
		public readonly string $form,
		public readonly string $action,
		public readonly string $parameter,
		public readonly string $label,
		public readonly string $description,
		public readonly bool $create = false,
		public readonly string $createAction = 'new',
		public readonly ?string $entity = null,
		public readonly string $createNote = '',
		public readonly array $hidden = [],
		public readonly bool $customerEmail = false,
		public readonly ?string $createParameter = null,
		public readonly ?ChildItem $item = null,
		public readonly ?string $znacka = null,
		public readonly array $bublina = [],
		public readonly array $popisky = [],
		public readonly ?string $tabParameter = null,
		public readonly ?string $tabColumn = null,
		public readonly ?string $tabFromField = null,
	) {
	}

	public function getEndpoint(): string
	{
		return \sprintf('/v1/admin/forms/%s/%s', $this->presenter, $this->form);
	}
}
