<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

/**
 * Jeden signál administrace (`handle*`), který smí agent spustit.
 *
 * Signály musí být ruční seznam: `handle*` metody nenesou popis parametrů ani významu, model
 * si je nemá jak odvodit. Proto je u každého ručně psaný popis a příznak „destruktivní".
 */
final class ActionTarget
{
	/**
	 * @param array<string> $params Jména parametrů signálu v pořadí, v jakém je bere `handle*`
	 * @param class-string<\StORM\Entity>|null $entity Entita, které se signál týká — kvůli
	 *     kontrole existence a popisu záznamu v náhledu
	 */
	public function __construct(
		public readonly string $presenter,
		public readonly string $signal,
		public readonly string $action,
		public readonly string $label,
		public readonly array $params,
		public readonly bool $destructive,
		public readonly ?string $entity,
		public readonly ?string $idParam,
		public readonly bool $customerEmail = false,
	) {
	}

	public function getEndpoint(): string
	{
		return \sprintf('/v1/admin/actions/%s/%s', $this->presenter, $this->signal);
	}
}
