<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

use StORM\DIConnection;

/**
 * Popisky polí, která ve formuláři žádný nemají (Kolego, karta zápisu 31. 10. 2026).
 *
 * Pole pod ceníkem, seznamem viditelnosti nebo skladem skládá formulář z id záznamu (`prices-<ceník>-price`) a popisek
 * nemají — administrace je kreslí v mřížce se jménem ceníku. Brána pak vydávala jako popisek jméno pole a karta v Kolego
 * ukazovala 254 řádků `prices-23_RL_4-price`. Whitelist formuláře proto smí říct, jak se takové pole jmenuje:
 *
 *     popisky:
 *         'prices-{id}-price': ['Cena bez DPH', Eshop\DB\Pricelist]
 *         'visibility-list_{id}-recommended': ['Doporučený', Eshop\DB\VisibilityList]
 *
 * → „Cena bez DPH · KZ – Denbraven". `{id}` je id záznamu entity (jméno z jejího `name`, jinak `code`, jinak id); bez entity
 * zůstane id. Platí jen pro pole bez vlastního popisku — popisek z formuláře vyhrává. Jména se čtou jednou za entitu a požadavek.
 */
final class Popisky
{
	/** @var array<class-string|string, array<string, string>> entita → id → jméno */
	private array $jmena = [];

	public function __construct(private DIConnection $connection)
	{
	}

	/**
	 * Popisek pole: vlastní popisek formuláře, nebo složený podle `popisky` (jinak beze změny).
	 */
	public function popisek(FormTarget $target, string $name, string $label): string
	{
		if ($label !== $name || !$target->popisky) {
			return $label;
		}

		foreach ($target->popisky as $vzor => $popis) {
			$re = '~^' . \str_replace('\{id\}', '(.+?)', \preg_quote((string) $vzor, '~')) . '$~';

			if (!\preg_match($re, $name, $m)) {
				continue;
			}

			$predpona = (string) ($popis[0] ?? $name);
			$id = (string) ($m[1] ?? '');

			if ($id === '') {
				return $predpona;
			}

			$jmeno = isset($popis[1]) ? ($this->jmenaEntity((string) $popis[1])[$id] ?? null) : null;

			return $predpona . ' · ' . ($jmeno !== null && $jmeno !== '' ? $jmeno : $id);
		}

		return $label;
	}

	/**
	 * Pole schématu nebo položky rozdílu s popisky (`label` podle jména pole v `name`, nebo `field`).
	 * @param list<array<string, mixed>> $pole
	 * @return list<array<string, mixed>>
	 */
	public function pole(FormTarget $target, array $pole): array
	{
		if (!$target->popisky) {
			return $pole;
		}

		foreach ($pole as $i => $p) {
			$jmeno = (string) ($p['name'] ?? $p['field'] ?? '');
			$pole[$i]['label'] = $this->popisek($target, $jmeno, (string) ($p['label'] ?? $jmeno));
		}

		return $pole;
	}

	/**
	 * Jména záznamů entity (id → jméno), jednou za požadavek. Entitu, kterou nejde přečíst, nechá prázdnou.
	 * @return array<string, string>
	 */
	private function jmenaEntity(string $entita): array
	{
		if (isset($this->jmena[$entita])) {
			return $this->jmena[$entita];
		}

		$out = [];

		try {
			foreach ($this->connection->findRepository($entita)->many() as $zaznam) {
				$jmeno = '';

				foreach (['name', 'code'] as $sloupec) {
					try {
						$hodnota = $zaznam->{$sloupec};
					} catch (\Throwable) {
						$hodnota = null;
					}

					if (\is_scalar($hodnota) && (string) $hodnota !== '') {
						$jmeno = \strip_tags((string) $hodnota);

						break;
					}
				}

				$out[(string) $zaznam->getPK()] = $jmeno;
			}
		} catch (\Throwable) {
			$out = [];
		}

		return $this->jmena[$entita] = $out;
	}
}
