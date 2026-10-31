<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

use Admin\Controls\IMenuFactory;

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

	/**
	 * @return array<string, mixed>
	 */
	public function build(): array
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

		return [
			'identity' => [
				'administrator' => Identities::label($this->runner->getIdentity()),
				'note' => 'Za tenhle účet administrace brána jedná: platí jeho role a co v administraci vidí. '
					. 'Do auditu jde on; „actor" z těla je jen popisek.',
			],
			'version' => GatewayConfig::version(),
			'items' => $items,
			'note' => 'Vypisují se jen stránky, na které smí účet z „identity". Měnit jde to, co má vyplněné „forms", '
				. '„actions" nebo „bulk", mazat, co má „delete", číst jde, co má „grids"; další klíče u stránky jsou cesty projektu. U ostatních '
				. 'stránek brána jen ví, že existují. Formulář s „create": true umí i zakládat nový záznam — přečti si '
				. 'u něj „createNote".',
		];
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
			] + ($action->customerEmail ? ['customerEmail' => true] : []) + ($action->file !== null ? ['file' => ['image' => $action->fileImage]] : []);
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
