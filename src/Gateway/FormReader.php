<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

use Nette\Forms\Control;
use Nette\Forms\Controls\BaseControl;
use Nette\Forms\Controls\Button;
use Nette\Forms\Controls\Checkbox;
use Nette\Forms\Controls\ChoiceControl;
use Nette\Forms\Controls\HiddenField;
use Nette\Forms\Controls\MultiChoiceControl;
use Nette\Forms\Controls\TextArea;
use Nette\Forms\Controls\TextInput;
use Nette\Forms\Controls\UploadControl;
use Nette\Forms\Form;
use Nette\Utils\Arrays;
use Nette\Utils\Strings;

/**
 * Čte z formuláře administrace to, co model potřebuje vědět, aby ho uměl vyplnit: jméno pole,
 * typ, český popisek, nápovědu, povinnost, položky selectů, jestli je pole pro tuhle roli jen
 * ke čtení, a aktuální hodnotu záznamu.
 *
 * Nic se nekopíruje ani nepřepisuje ručně — popisky jsou ty, které vidí člověk v administraci,
 * takže se nemají jak rozejít, když někdo formulář změní.
 */
final class FormReader
{
	/** Nad tolik položek se vypisuje jen jejich počet — celý číselník by odpověď utopil. */
	public const MAX_ITEMS = 200;

	/**
	 * Popis polí a skupin formuláře.
	 * @param list<string> $hidden Pole, která se nevydávají (viz {@see FormTarget::$hidden})
	 * @return array{groups: array<array<string, mixed>>, fields: array<array<string, mixed>>}
	 */
	public static function describe(Form $form, array $hidden = []): array
	{
		$fields = [];

		foreach (self::controls($form, $hidden) as $name => $control) {
			$fields[] = self::describeControl($name, $control);
		}

		$groups = [];

		foreach ($form->getGroups() as $key => $group) {
			$label = $group->getOption('label');
			$names = [];

			foreach ($group->getControls() as $control) {
				if (!$control instanceof BaseControl || self::skip($control) || Arrays::contains($hidden, self::name($control))) {
					continue;
				}

				$names[] = self::name($control);
			}

			if (!$names) {
				continue;
			}

			$groups[] = [
				'label' => $label !== null ? (string) $label : (\is_string($key) ? $key : null),
				'fields' => $names,
			];
		}

		return ['groups' => $groups, 'fields' => $fields];
	}

	/**
	 * Aktuální hodnoty všech polí, normalizované tak, aby se daly porovnávat i podepsat.
	 *
	 * Pole se procházejí přes `getControls()` (rekurzivně i kontejnery), ne přes
	 * `getComponents(true, …)`: component-model 3.2 má `getComponents()` bez parametrů a hloubku
	 * drží jen kvůli zpětné kompatibilitě.
	 * @return array<string, mixed>
	 */
	public static function values(Form $form): array
	{
		$values = [];

		foreach ($form->getControls() as $control) {
			if (!$control instanceof BaseControl || $control instanceof Button) {
				continue;
			}

			$values[self::name($control)] = self::normalize($control->getValue());
		}

		return $values;
	}

	/**
	 * Pole, která model smí nastavovat. Tlačítka a skrytá pole ne — ta si formulář řeší sám;
	 * ani pole, která konfigurace schovává (`hidden`).
	 * @param list<string> $hidden
	 * @return array<string, \Nette\Forms\Controls\BaseControl>
	 */
	public static function controls(Form $form, array $hidden = []): array
	{
		$controls = [];

		foreach ($form->getControls() as $control) {
			if (!$control instanceof BaseControl || self::skip($control) || Arrays::contains($hidden, self::name($control))) {
				continue;
			}

			$controls[self::name($control)] = $control;
		}

		return $controls;
	}

	/**
	 * Jméno pole tak, jak ho vidí model: cesta komponentou, takže i pole v kontejneru
	 * (`page-url-cs`) má jednoznačné jméno a dá se podle něj komponenta zase najít.
	 */
	public static function name(BaseControl $control): string
	{
		return $control->lookupPath(Form::class) ?? (string) $control->getName();
	}

	public static function label(BaseControl $control): string
	{
		$label = \strip_tags((string) $control->getCaption());

		return $label !== '' ? $label : self::name($control);
	}

	/**
	 * Popisek hodnoty u polí s číselníkem — model i uživatel čtou „Velkoobchod B", ne uuid.
	 */
	public static function valueLabel(BaseControl $control, mixed $value): mixed
	{
		$items = self::items($control);

		if ($items === null) {
			return null;
		}

		if (\is_array($value)) {
			return \array_values(\array_map(static fn ($key): string => $items[$key] ?? (string) $key, $value));
		}

		return $value === null ? null : ($items[$value] ?? (string) $value);
	}

	/**
	 * Hodnota v tvaru, který jde porovnat i podepsat: bez objektů a bez závislosti na pořadí.
	 */
	public static function normalize(mixed $value): mixed
	{
		if ($value instanceof \DateTimeInterface) {
			return $value->format('Y-m-d H:i:s');
		}

		if (\is_array($value)) {
			$value = \array_values(\array_map(static fn ($item): string => \is_scalar($item) ? (string) $item : '', $value));
			\sort($value);

			return $value;
		}

		if (\is_bool($value) || $value === null) {
			return $value;
		}

		return \is_scalar($value) ? (string) $value : null;
	}

	/**
	 * Popis jednoho pole: jméno, popisek, typ, povinnost, jestli je jen ke čtení, hodnota a číselník.
	 * Používá to i hromadná úprava, která popisuje vstupy z kontejneru `values` bulk formuláře.
	 * @return array<string, mixed>
	 */
	public static function describeControl(string $name, BaseControl $control): array
	{
		$info = $control->getControlPrototype()->getAttribute('data-info');
		$items = self::items($control);

		$field = [
			'name' => $name,
			'label' => self::label($control),
			'type' => self::type($control),
			'required' => $control->isRequired(),
			'readOnly' => $control->isDisabled(),
			'value' => self::normalize($control->getValue()),
		];

		if ($info !== null) {
			$field['info'] = \strip_tags(\str_replace(['<br>', '<br/>', '<br />'], ' ', (string) $info));
		}

		if ($control->isDisabled()) {
			// z formuláře nejde poznat proč: `setDisabled()` používá administrace jak na pole,
			// která u existujícího záznamu měnit nejde, tak na ta, která vypíná chybějící 777
			$field['readOnlyReason'] = 'Pole je ve formuláři vypnuté: buď se u existujícího záznamu měnit nedá '
				. '(třeba přihlašovací e-mail), nebo ho pro tuhle roli vypíná chybějící oprávnění '
				. '„Plný přístup" (777) na téhle stránce.';
		}

		if ($control instanceof UploadControl) {
			$field['readOnly'] = true;
			$field['readOnlyReason'] = 'Nahrávání souborů brána v první fázi neumí.';
		}

		if ($items === null) {
			return $field;
		}

		if (\count($items) > self::MAX_ITEMS) {
			$field['itemCount'] = \count($items);
			$field['itemsNote'] = \sprintf(
				'Číselník má %d položek, proto se nevypisuje. Posílej id; dohledat se dají v administraci '
					. 'na odpovídající stránce, u produktů přes /v1/products a u zákazníků přes /v1/customers.',
				\count($items),
			);

			return $field;
		}

		$field['items'] = $items;
		$field['valueLabel'] = self::valueLabel($control, $control->getValue());

		return $field;
	}

	/**
	 * Číselník pole, nebo null, když pole žádný nemá.
	 * @return array<string, string>|null
	 */
	private static function items(BaseControl $control): ?array
	{
		if (!$control instanceof ChoiceControl && !$control instanceof MultiChoiceControl) {
			return null;
		}

		$items = [];

		foreach ($control->getItems() as $key => $label) {
			$items[(string) $key] = \is_array($label) ? (string) $key : \strip_tags((string) $label);
		}

		return $items;
	}

	private static function type(BaseControl $control): string
	{
		return match (true) {
			$control instanceof Checkbox => 'checkbox',
			$control instanceof MultiChoiceControl => 'multiselect',
			$control instanceof ChoiceControl => 'select',
			$control instanceof UploadControl => 'file',
			$control instanceof TextArea => 'textarea',
			$control instanceof TextInput => (string) ($control->getControlPrototype()->getAttribute('type') ?? 'text'),
			default => Strings::lower((new \ReflectionClass($control))->getShortName()),
		};
	}

	/**
	 * Skryté pole (`uuid`, `_defaults`), tlačítka a přepínač jazyka u překládaných polí do schématu
	 * nepatří: nejsou to údaje záznamu, jen technika formuláře. Do zápisu se pošlou stejně, jako je
	 * posílá prohlížeč.
	 */
	private static function skip(Control $control): bool
	{
		return $control instanceof Button
			|| $control instanceof HiddenField
			|| ($control instanceof BaseControl && $control->getName() === \Forms\Form::MUTATION_SELECTOR_NAME);
	}
}
