<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

/**
 * Rozšiřovací bod mapy administrace: projekt k řádku stránky připíše, co o ní ví jen on.
 *
 * Mapa stránek je to, odkud model vychází („co smíš v administraci dělat"). Když projekt k některé
 * stránce nabízí vlastní cestu — v Levioru třeba stav importů z K2 a opětovný import jednoho
 * zákazníka —, musí se o ní model dozvědět právě tady, jinak ji nenajde. Vlastní cesta se přidává
 * přes `doryoApi: endpoints:`, popis k ní sem; služby se registrují v `doryoAdmin: describers:`.
 *
 * Describer se volá jen u stránek, na které identita požadavku smí (mapa ostatní vůbec nevypíše).
 */
interface PageDescriber
{
	/**
	 * Doplní nebo upraví řádek stránky. Řádek nese aspoň `presenter`, `link`, `label` a `access`
	 * (`read` / `write`); vlastní klíče volí projekt tak, aby nekolidovaly s klíči brány
	 * (`forms`, `actions`, `bulk`, `grids`).
	 * @param string $presenter Jméno presenteru stránky, například `Eshop:Admin:Customer`
	 * @param array<string, mixed> $row Řádek tak, jak ho postavila brána
	 * @return array<string, mixed> Řádek, který se vydá
	 */
	public function describe(string $presenter, array $row): array;
}
