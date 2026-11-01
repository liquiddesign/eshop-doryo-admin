<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

use Admin\Controls\IMenuFactory;
use Nette\Utils\Strings;

/**
 * Mapa administrace: co v ní vůbec je a co z toho smí identita požadavku (servisní účet agenta,
 * nebo administrátor obchodníka).
 *
 * Bere se z `config/admin.neon` přes tutéž továrnu, která staví menu pro člověka, takže se
 * seznam nemá jak rozejít s tím, co je v administraci vidět. U stránek, které brána umí měnit,
 * se rovnou uvedou jejich formuláře a akce i s cestou, kam se pro ně zajít.
 */
final class PageMap
{
	/**
	 * @param array<\DoryoAdmin\Gateway\PageDescriber> $describers Projektové doplňky řádků stránek
	 */
	public function __construct(
		private IMenuFactory $menuFactory,
		private PresenterRunner $runner,
		private GatewayConfig $config,
		private array $describers = [],
	) {
	}

	/** Klíče řádku stránky, které vypisuje brána sama; co přidají projektové doplňky, je v přehledu jen jménem (`dalsi`). */
	private const KLICE = ['group', 'label', 'presenter', 'link', 'access', 'forms', 'grids', 'actions', 'bulk', 'delete'];

	/**
	 * Mapa administrace (0.8, Kolego 1. 11. 2026): celá mapa MC Profi má 65 kB a model z ní dostával jen začátek — 15 stránek
	 * z 38, Kontakty a sítě ani Mikrotexty mezi nimi nebyly, a Kolego pak tvrdilo, že kontaktní stránku změnit nejde.
	 *
	 * - bez parametrů krátký přehled: u stránky jen jména formulářů, seznamů, akcí, hromadných úprav a mazání,
	 * - `presenter` = celá jedna stránka, `q` = celé stránky, kde se najdou všechna slova (název, skupina, formuláře, seznamy,
	 *   akce i jejich popisy, bez ohledu na diakritiku), `detail` = celá mapa jako do 0.7.
	 *
	 * @return array<string, mixed>
	 */
	public function build(bool $detail = true, ?string $q = null, ?string $jenStranka = null): array
	{
		$items = [];
		$listed = [];

		foreach ($this->menuFactory->create()->getItems() as $group) {
			foreach ($group->items ?: [$group] as $item) {
				if ($item->link === null) {
					continue;
				}

				$page = $this->describe($group === $item ? null : $group->label, $item->label, self::presenterName($item->link), $item->link);

				if ($page === null) {
					continue;
				}

				$items[] = $page;
				$listed[$page['presenter']] = true;
			}
		}

		// Stránky z whitelistu, které v menu nejsou (fotky produktů jsou v administraci jen jako odkaz z produktu):
		// jinak by je model neměl jak najít, i když na ně identita smí.
		foreach ($this->config->getPresenters() as $presenter => $first) {
			if (isset($listed[$presenter])) {
				continue;
			}

			$page = $this->describe('Mimo menu', $first['label'], $presenter, ':' . $presenter . ':' . $first['action']);

			if ($page !== null) {
				$items[] = $page;
			}
		}

		$vyber = null;

		if ($jenStranka !== null && $jenStranka !== '') {
			$items = \array_values(\array_filter($items, static fn (array $page): bool => $page['presenter'] === $jenStranka));
			$vyber = $items ? \sprintf('Jen stránka %s.', $jenStranka) : \sprintf('Stránku %s mapa nezná, nebo na ni účet nesmí — jména jsou v krátkém přehledu.', $jenStranka);
		} elseif ($q !== null && \trim($q) !== '') {
			$slova = \array_filter(\preg_split('~\s+~', self::hledatelne($q)) ?: []);
			$items = \array_values(\array_filter($items, static function (array $page) use ($slova): bool {
				$text = self::hledatelne((string) \json_encode($page, \JSON_UNESCAPED_UNICODE));

				foreach ($slova as $slovo) {
					if (!\str_contains($text, $slovo)) {
						return false;
					}
				}

				return true;
			}));
			$vyber = $items
				? \sprintf('Stránky, kde se najde „%s" (název, formuláře, seznamy, akce i popisy).', \trim($q))
				: \sprintf('„%s" se nenašlo v žádné stránce — zkus jiné slovo, nebo projdi krátký přehled (bez parametrů).', \trim($q));
		} elseif (!$detail) {
			$items = \array_map([self::class, 'prehled'], $items);
		}

		return [
			'identity' => [
				'administrator' => Identities::label($this->runner->getIdentity()),
				'note' => 'Za tenhle účet administrace brána jedná: platí jeho role a co v administraci vidí. '
					. 'Do auditu jde on; „actor" z těla je jen popisek.',
			],
			'version' => GatewayConfig::version(),
			'items' => $items,
			'znacky' => $this->znacky(),
			'note' => ($vyber !== null ? $vyber . ' ' : '')
				. (!$detail && $vyber === null
					? 'Krátký přehled: u stránky jen jména formulářů (forms), seznamů (grids), akcí (actions), hromadných úprav (bulk) '
						. 'a mazání (delete). Celou stránku s cestami a popisy vrátí ?presenter=<presenter>, hledání ve všech stránkách '
						. '?q=<slova> (třeba q=kontakt), celou mapu ?detail=1. Když něco v přehledu nevidíš, hledej dřív, než řekneš, že to nejde. '
					: '')
				. 'Vypisují se jen stránky, na které smí účet z „identity". Měnit jde to, co má vyplněné „forms", '
				. '„actions" nebo „bulk", mazat, co má „delete", číst jde, co má „grids"; další klíče u stránky jsou cesty projektu. U ostatních '
				. 'stránek brána jen ví, že existují. Formulář s „create": true umí i zakládat nový záznam — přečti si '
				. 'u něj „createNote".',
		];
	}

	/**
	 * Řádek krátkého přehledu: kde stránka je a co na ní jde, jen jmény.
	 * @param array<string, mixed> $page
	 * @return array<string, mixed>
	 */
	private static function prehled(array $page): array
	{
		$row = ['group' => $page['group'], 'label' => $page['label'], 'presenter' => $page['presenter'], 'access' => $page['access']];

		foreach (['forms' => 'form', 'grids' => 'grid', 'actions' => 'action', 'bulk' => 'grid', 'delete' => 'grid'] as $klic => $jmeno) {
			if (empty($page[$klic]) || !\is_array($page[$klic])) {
				continue;
			}

			$row[$klic] = \array_values(\array_map(
				static fn (array $x): string => (string) ($x['label'] ?? $x[$jmeno] ?? ''),
				$page[$klic],
			));
		}

		$dalsi = \array_values(\array_diff(\array_keys($page), self::KLICE));

		if ($dalsi) {
			$row['dalsi'] = $dalsi;
		}

		return $row;
	}

	/** Text k hledání: malá písmena bez diakritiky. */
	private static function hledatelne(string $s): string
	{
		return Strings::lower(Strings::toAscii($s));
	}

	/**
	 * Značky na webu (Kolego nad e-shopem): druh z `data-kolego="<druh>:<id>"` → formulář, kterým se záznam mění, a pole
	 * do bubliny u prvku. Jen formuláře, na které identita smí — ostatní prvky rozšíření nechá bez rámečku.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function znacky(): array
	{
		$out = [];

		foreach ($this->config->getZnacky() as $druh => $form) {
			if (!$this->runner->isAllowed(':' . $form->presenter . ':' . $form->action)) {
				continue;
			}

			$out[$druh] = [
				'label' => $form->label,
				'form' => $form->getEndpoint(),
				'parameter' => $form->parameter,
				'bublina' => $form->bublina,
				'customerEmail' => $form->customerEmail,
			];
		}

		return $out;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function describe(?string $group, string $label, string $presenter, string $link): ?array
	{
		if (!$this->runner->isAllowed($link)) {
			return null;
		}

		$page = $this->config->getPage($presenter);

		$row = [
			'group' => $group,
			'label' => $label,
			'presenter' => $presenter,
			'link' => $link,
			'access' => $this->runner->isAllowed($link, '777') ? 'write' : 'read',
		];

		foreach ($page['forms'] as $form) {
			$described = [
				'form' => $form->form,
				'label' => $form->label,
				'description' => $form->description,
				'schema' => 'GET ' . $form->getEndpoint() . '?id=<id záznamu>',
				'write' => 'POST ' . $form->getEndpoint(),
				'create' => $form->create,
			];

			if ($form->customerEmail) {
				$described['customerEmail'] = true;
			}

			if ($form->item !== null) {
				// formulář jedné položky záznamu: seznam položek vrátí schéma bez item
				$described['schema'] = 'GET ' . $form->getEndpoint() . '?id=<id záznamu>&item=<id položky>';
				$described['items'] = 'GET ' . $form->getEndpoint() . '?id=<id záznamu>';
			}

			if ($form->createParameter !== null) {
				$described['createParent'] = $form->createParameter;
			}

			if ($form->create) {
				$described['createSchema'] = 'GET ' . $form->getEndpoint() . ($form->createParameter !== null ? '?parent=<id nadřazeného záznamu>' : '');

				if ($form->createNote !== '') {
					$described['createNote'] = $form->createNote;
				}
			}

			$row['forms'][] = $described;
		}

		foreach ($page['bulks'] as $bulk) {
			$row['bulk'][] = [
				'grid' => $bulk->grid,
				'label' => $bulk->label,
				'schema' => 'GET ' . $bulk->getEndpoint(),
				'write' => 'POST ' . $bulk->getEndpoint(),
				'confirmFrom' => $bulk->confirmFrom,
			];
		}

		foreach ($page['grids'] as $grid) {
			$row['grids'][] = [
				'grid' => $grid->grid,
				'label' => $grid->label,
				'description' => $grid->description,
				'read' => 'GET ' . $grid->getEndpoint() . '?' . ($grid->parameter !== null ? 'id=<id záznamu>&' : '') . 'filter={"<filtr>":"<hodnota>"}&page=1&limit=20',
				'requiresId' => $grid->parameter !== null,
				'tabs' => $grid->tabParameter !== null,
			];
		}

		foreach ($page['actions'] as $action) {
			$row['actions'][] = [
				'action' => $action->signal,
				'label' => $action->label,
				'destructive' => $action->destructive,
				'params' => $action->params,
				'endpoint' => 'POST ' . $action->getEndpoint(),
			] + ($action->customerEmail ? ['customerEmail' => true] : []) + ($action->file !== null ? ['file' => ['image' => $action->fileImage]] : [])
				+ ($action->cache ? ['cache' => true] : []);
		}

		foreach ($page['deletes'] as $delete) {
			$row['delete'][] = [
				'grid' => $delete->grid,
				'label' => $delete->label,
				'description' => $delete->description,
				'endpoint' => 'POST ' . $delete->getEndpoint(),
				'destructive' => true,
				'requiresId' => $delete->parameter !== null,
				'tabs' => $delete->tabParameter !== null,
			];
		}

		foreach ($this->describers as $describer) {
			$row = $describer->describe($presenter, $row);
		}

		return $row;
	}

	/**
	 * Z odkazu v menu (`:Eshop:Admin:Customer:default`) jméno presenteru.
	 */
	private static function presenterName(string $link): string
	{
		$parts = \explode(':', \ltrim($link, ':'));
		\array_pop($parts);

		return \implode(':', $parts);
	}
}
