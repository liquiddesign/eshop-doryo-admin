<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

/**
 * Jeden seznam (grid) administrace, který smí Doryo číst.
 *
 * Whitelist kvůli popisu, ne kvůli oprávnění: to drží role účtu agenta. Model potřebuje vědět,
 * co v seznamu je a k čemu je („reporty obchodníků čekající na schválení"), a to z gridu samotného
 * nevyčte — ten zná jen hlavičky sloupců.
 */
final class GridTarget
{
	/**
	 * @param string $grid Jméno komponenty gridu na presenteru (`grid`, `customers`)
	 * @param string $action Akce presenteru, na které grid žije
	 * @param string|null $parameter Parametr akce se záznamem, ke kterému seznam patří (`sale` u produktů akce);
	 *     null = seznam stojí sám
	 * @param array<string, string> $filterNotes Ruční popis filtrů, které v gridu nemají popisek
	 * @param string|null $tabParameter Persistentní parametr presenteru se záložkou, podle které grid filtruje
	 *     (`tab` u menu: patička levá/pravá…); null = seznam záložky nemá
	 */
	public function __construct(
		public readonly string $presenter,
		public readonly string $grid,
		public readonly string $action,
		public readonly string $label,
		public readonly string $description,
		public readonly array $filterNotes,
		public readonly ?string $parameter = null,
		public readonly ?string $tabParameter = null,
	) {
	}

	public function getEndpoint(): string
	{
		return \sprintf('/v1/admin/grids/%s/%s', $this->presenter, $this->grid);
	}

	/**
	 * Resource pro oprávnění tak, jak ho čte administrace.
	 */
	public function getResource(): string
	{
		return ':' . $this->presenter . ':' . $this->action;
	}
}
