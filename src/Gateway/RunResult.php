<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

use Nette\Application\Response;
use Nette\Application\UI\Presenter;
use Nette\Utils\Arrays;

/**
 * Výsledek jednoho průchodu presenterem: samotný presenter (kvůli komponentám), jeho odpověď
 * a flash zprávy, které by jinak zůstaly v session pro další požadavek.
 */
final class RunResult
{
	/** Typy flash zpráv, kterými administrace hlásí, že něco neudělala. */
	public const REFUSAL_TYPES = ['error', 'danger', 'warning'];

	/**
	 * @param array<array{type: string, message: string}> $flashes
	 */
	public function __construct(
		public readonly Presenter $presenter,
		public readonly ?Response $response,
		public readonly array $flashes,
	) {
	}

	/**
	 * Flash zprávy, kterými administrace požadavek odmítla.
	 *
	 * Administrace odmítnutí většinou nehází jako chybu, ale hlásí flash zprávou a přesměruje —
	 * „Produkt nebyl nalezen", „Objednávku nelze stornovat", „Nemáte oprávnění k tomuto zákazníkovi".
	 * Formulář pak vypadá odeslaný a bez chyb a signál proběhlý, jenže se nic nestalo. Brána proto
	 * každou takovou zprávu bere jako odmítnutí.
	 * @return array<array{type: string, message: string}>
	 */
	public function getRefusals(): array
	{
		return \array_values(\array_filter(
			$this->flashes,
			static fn (array $flash): bool => Arrays::contains(self::REFUSAL_TYPES, $flash['type']),
		));
	}
}
