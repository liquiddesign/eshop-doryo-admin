<?php

declare(strict_types=1);

namespace DoryoAdmin\Latte;

use DoryoAdmin\Gateway\GatewayConfig;

/**
 * Značky pro rozšíření Kolego v šablonách webu (Kolego nad e-shopem, doryo docs/156).
 *
 * Makro `n:kolego` označí prvek, který odpovídá záznamu administrace: `data-kolego="<druh>:<id>"`. Druh je značka
 * formuláře z whitelistu brány (`forms: - znacka: produkt`), takže rozšíření ví, kterým formulářem se prvek mění.
 * Atribut nic neodemyká — co kdo smí, rozhoduje brána a role účtu v administraci; zákazník vidí jen data atribut.
 *
 *     <article n:kolego="$product">              → data-kolego="produkt:<pk>"   (druh podle entity)
 *     <div n:kolego="'slide', $slide->getPK()">  → data-kolego="slide:<pk>"     (druh výslovně)
 *     <span n:kolego-pole="name">                → data-kolego-pole="name"      (které pole prvek ukazuje, pro náhled změny)
 */
final class Znacky
{
	private static ?GatewayConfig $config = null;

	public static function nastav(GatewayConfig $config): void
	{
		self::$config = $config;
	}

	/**
	 * Hodnota `data-kolego`, nebo null, když prvek značku mít nemá (entita bez formuláře se značkou, prázdné id).
	 */
	public static function hodnota(mixed $co, mixed $id = null): ?string
	{
		if (\is_object($co)) {
			$form = self::$config?->getZnackaEntity($co);
			$pk = \method_exists($co, 'getPK') ? $co->getPK() : null;

			return $form !== null && \is_scalar($pk) && (string) $pk !== '' ? $form->znacka . ':' . $pk : null;
		}

		if (!\is_string($co) || !\preg_match('/^[a-z][a-z0-9-]{0,30}$/', $co) || !\is_scalar($id) || (string) $id === '') {
			return null;
		}

		return $co . ':' . $id;
	}

	/**
	 * Celý atribut s mezerou na začátku (tvar, jaký Latte vkládá do počáteční značky), nebo prázdný řetězec.
	 */
	public static function atribut(string $jmeno, ?string $hodnota): string
	{
		return $hodnota === null || $hodnota === '' ? '' : ' ' . $jmeno . '="' . \htmlspecialchars($hodnota, \ENT_QUOTES | \ENT_HTML5 | \ENT_SUBSTITUTE, 'UTF-8') . '"';
	}
}
