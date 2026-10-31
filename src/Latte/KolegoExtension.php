<?php

declare(strict_types=1);

namespace DoryoAdmin\Latte;

use DoryoAdmin\Gateway\GatewayConfig;
use Latte\Extension;

/**
 * Latte rozšíření s makry `n:kolego` a `n:kolego-pole` (značky pro rozšíření Kolego). Zapojuje ho DoryoAdminDI do každé
 * LatteFactory aplikace; projekt bez automatického zapojení (`latte: false`) ho přidá sám.
 */
final class KolegoExtension extends Extension
{
	public function __construct(GatewayConfig $config)
	{
		Znacky::nastav($config);
	}

	/**
	 * @return array<string, callable>
	 */
	public function getTags(): array
	{
		return [
			'n:kolego' => KolegoNode::create(...),
			'n:kolego-pole' => KolegoNode::create(...),
		];
	}
}
