<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

use Nette\Application\Response;
use Nette\Application\UI\Presenter;

/**
 * Výsledek jednoho průchodu presenterem: samotný presenter (kvůli komponentám), jeho odpověď
 * a flash zprávy, které by jinak zůstaly v session pro další požadavek.
 */
final class RunResult
{
	/**
	 * @param array<array{type: string, message: string}> $flashes
	 */
	public function __construct(
		public readonly Presenter $presenter,
		public readonly ?Response $response,
		public readonly array $flashes,
	) {
	}
}
