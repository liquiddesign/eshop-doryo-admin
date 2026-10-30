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
	) {
	}

	public function getEndpoint(): string
	{
		return \sprintf('/v1/admin/forms/%s/%s', $this->presenter, $this->form);
	}
}
