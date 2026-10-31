<?php

declare(strict_types=1);

namespace DoryoAdmin\Latte;

use Latte\Compiler\Nodes\Php\Expression\ArrayNode;
use Latte\Compiler\Nodes\StatementNode;
use Latte\Compiler\PrintContext;
use Latte\Compiler\Tag;

/**
 * `n:kolego` a `n:kolego-pole` — atribut do počáteční značky prvku, jako `n:class`. Hodnotu skládá {@see Znacky}.
 */
final class KolegoNode extends StatementNode
{
	public ArrayNode $args;

	public bool $pole = false;

	public static function create(Tag $tag): static
	{
		$tag->expectArguments();
		$node = new static();
		$node->pole = $tag->name === 'kolego-pole';
		$node->args = $tag->parser->parseArguments();

		return $node;
	}

	public function print(PrintContext $context): string
	{
		return $this->pole
			? $context->format('echo \DoryoAdmin\Latte\Znacky::atribut(\'data-kolego-pole\', (string) (%node)[0]) %line;', $this->args, $this->position)
			: $context->format('echo \DoryoAdmin\Latte\Znacky::atribut(\'data-kolego\', \DoryoAdmin\Latte\Znacky::hodnota(...%node)) %line;', $this->args, $this->position);
	}

	public function &getIterator(): \Generator
	{
		yield $this->args;
	}
}
