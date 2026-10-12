<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

use Admin\Controls\AdminGrid;
use DoryoApi\Http\ApiException;
use Grid\Column;
use Grid\Datagrid;
use Nette\Forms\Controls\BaseControl;
use Nette\Forms\Controls\Button;
use Nette\Forms\Controls\HiddenField;
use Nette\Forms\Controls\SelectBox;
use Nette\Forms\Form;
use Nette\Utils\Arrays;
use Nette\Utils\Strings;
use StORM\Entity;

/**
 * Co se dá z gridu administrace vyčíst: filtry tak, jak je nabízí člověku, a řádky tak, jak je vidí.
 *
 * Sdílí to čtení seznamů ({@see GridGateway}) i hromadná úprava ({@see BulkGateway}) — obojí stojí
 * na tom, že filtr, který grid nezná, je chyba, ne tiché „bez filtru".
 */
final class GridReader
{
	/** Buňka může obsahovat celý popis nebo HTML seznam; do odpovědi pro model jde jen začátek. */
	private const CELL_MAX_LENGTH = 300;

	/**
	 * Filtry gridu tak, jak je nabízí člověku. Popisek některých filtrů v Levioru chybí (grid je
	 * staví jen s placeholderem), proto záloha z konfigurace.
	 * @param array<string, string> $notes Ruční popis filtrů podle jména
	 * @return array<array<string, mixed>>
	 */
	public static function describeFilters(AdminGrid $grid, array $notes): array
	{
		$filters = [];

		foreach (self::filterControls($grid) as $name => $control) {
			$field = FormReader::describeControl($name, $control);
			unset($field['value'], $field['valueLabel'], $field['required'], $field['readOnly']);
			// popisek filtru grid často nemá — staví ho jen s placeholderem („IČ, více lze oddělit…")
			// nebo s promptem selectu („- Doručovací země -"). Model potřebuje vědět, co filtr dělá,
			// takže se sáhne na obojí a teprve pak na ruční popis z konfigurace.
			$placeholder = $control->getControlPrototype()->getAttribute('placeholder');
			$prompt = $control instanceof SelectBox ? $control->getPrompt() : false;
			$note = $notes[$name] ?? match (true) {
				$placeholder !== null => \strip_tags((string) $placeholder),
				\is_string($prompt) => Strings::trim(\strip_tags($prompt), " \t\n\r-"),
				default => null,
			};

			if ($field['label'] === $name && $note !== null) {
				$field['label'] = $note;
			}

			if ($note !== null) {
				$field['note'] = $note;
			}

			$filters[] = $field;
		}

		return $filters;
	}

	/**
	 * Filtr, který grid nezná, by se tiše ignoroval — čtení by vrátilo nefiltrovaný seznam, který
	 * vypadá jako odfiltrovaný, a hromadná úprava by zapisovala do všech záznamů. Proto 400.
	 * @param array<string, mixed> $filter
	 * @throws \DoryoApi\Http\ApiException
	 */
	public static function assertFilters(AdminGrid $grid, array $filter, string $endpoint): void
	{
		$known = \array_keys(self::filterControls($grid));
		$unknown = \array_diff(\array_keys($filter), $known);

		if (!$unknown) {
			return;
		}

		throw ApiException::badRequest(\sprintf(
			'Grid nezná filtry %s. Zná tyhle: %s. Seznam i s popisem vrátí GET %s.',
			\implode(', ', $unknown),
			$known ? \implode(', ', $known) : '(žádné)',
			$endpoint,
		));
	}

	/**
	 * Sloupce, které nesou data: bez výběrových zaškrtávátek, bez editovatelných vstupů a bez
	 * sloupců bez hlavičky (tlačítka „upravit", „smazat").
	 * @return array<string, \Grid\Column> Hlavička => sloupec
	 */
	public static function dataColumns(Datagrid $grid): array
	{
		$property = new \ReflectionProperty(Datagrid::class, 'columns');
		/** @var array<\Grid\Column> $columns */
		$columns = $property->getValue($grid);
		$out = [];

		foreach ($columns as $column) {
			if (!$column instanceof Column || $column->getContainer() !== null) {
				continue;
			}

			try {
				$head = (string) $column->getTableHead();
				$label = self::text($head);
			} catch (\TypeError) {
				// výběrový sloupec hlavičku nemá a getTableHead() pak místo slibovaného řetězce vrací null
				continue;
			}

			if ($label === '') {
				continue;
			}

			// sloupce pro jednotlivé jazyky mají stejnou hlavičku („Překlad") a liší se jen vlajkou
			if (\preg_match('~<img[^>]*\balt=[\'"]([a-z]{2})[\'"]~i', $head, $flag)) {
				$label .= ' (' . $flag[1] . ')';
			}

			$key = $label;

			for ($i = 2; isset($out[$key]); $i++) {
				$key = $label . ' ' . $i;
			}

			$out[$key] = $column;
		}

		return $out;
	}

	/**
	 * Řádek tak, jak ho vidí člověk: text každé buňky bez HTML.
	 * @param array<string, \Grid\Column> $columns
	 * @return array{id: string|null, cells: array<string, string|null>}
	 */
	public static function row(Datagrid $grid, object $item, array $columns): array
	{
		$cells = [];

		foreach ($columns as $label => $column) {
			try {
				$html = (string) $column->renderCell($item);
				$text = self::text($html);

				if ($text === '' && \preg_match('~<(input|textarea|select)\b~i', $html)) {
					$text = self::controlValue($html);
				}
			} catch (\Throwable) {
				// buňka, kterou grid mimo prohlížeč vyrenderovat neumí, nemá shodit celý seznam
				$text = null;
			}

			$cells[$label] = $text === '' ? null : ($text !== null && Strings::length($text) > self::CELL_MAX_LENGTH ? Strings::truncate($text, self::CELL_MAX_LENGTH) : $text);
		}

		return ['id' => self::id($grid, $item), 'cells' => $cells];
	}

	/**
	 * Řaditelné sloupce: jejich hodnota pro parametr `order` je „<výraz>-ASC" nebo „<výraz>-DESC".
	 * @param array<string, \Grid\Column> $columns
	 * @return array<array{column: string, order: string}>
	 */
	public static function orderable(array $columns): array
	{
		$out = [];

		foreach ($columns as $label => $column) {
			if (!$column->isOrdable() || $column->getOrderExpression() === null) {
				continue;
			}

			$out[] = ['column' => $label, 'order' => $column->getOrderExpression()];
		}

		return $out;
	}

	/**
	 * @return array<string, \Nette\Forms\Controls\BaseControl>
	 */
	private static function filterControls(AdminGrid $grid): array
	{
		$form = $grid->getFilterForm();
		$controls = [];

		if (!$form instanceof Form) {
			return $controls;
		}

		foreach ($form->getComponents(true, BaseControl::class) as $control) {
			if (!$control instanceof BaseControl || $control instanceof Button || $control instanceof HiddenField) {
				continue;
			}

			$controls[FormReader::name($control)] = $control;
		}

		return $controls;
	}

	private static function id(Datagrid $grid, object $item): ?string
	{
		if ($item instanceof Entity) {
			return (string) $item->getPK();
		}

		$name = \str_contains($grid->getSourceIdName(), '.') ? \explode('.', $grid->getSourceIdName())[1] : $grid->getSourceIdName();

		return isset($item->$name) && \is_scalar($item->$name) ? (string) $item->$name : null;
	}

	/**
	 * Hodnota editovatelného pole v buňce (grid s úpravami přímo v řádku, třeba překlady mikrotextů):
	 * text vstupu je v atributu `value`, takže by po odstranění HTML zmizel.
	 */
	private static function controlValue(string $html): string
	{
		$document = new \DOMDocument();
		$previous = \libxml_use_internal_errors(true);
		$document->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>');
		\libxml_clear_errors();
		\libxml_use_internal_errors($previous);
		$values = [];

		foreach ($document->getElementsByTagName('input') as $input) {
			$type = Strings::lower($input->getAttribute('type') ?: 'text');

			if (Arrays::contains(['hidden', 'submit', 'button'], $type)) {
				continue;
			}

			$values[] = Arrays::contains(['checkbox', 'radio'], $type)
				? ($input->hasAttribute('checked') ? 'ano' : 'ne')
				: $input->getAttribute('value');
		}

		foreach ($document->getElementsByTagName('textarea') as $textarea) {
			$values[] = $textarea->textContent;
		}

		foreach ($document->getElementsByTagName('select') as $select) {
			foreach ($select->getElementsByTagName('option') as $option) {
				if ($option->hasAttribute('selected')) {
					$values[] = $option->textContent;
				}
			}
		}

		return Strings::trim(\implode(', ', \array_filter($values, static fn (string $value): bool => $value !== '')));
	}

	private static function text(string $html): string
	{
		$text = \html_entity_decode(\strip_tags(Strings::replace($html, '~<br\s*/?>|</(p|div|li)>~i', ' ')), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

		return Strings::trim(Strings::replace($text, '~\s+~u', ' '));
	}
}
