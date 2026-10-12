<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

use Admin\Controls\IMenuFactory;
use Admin\Controls\MenuItem;

/**
 * Mapa administrace: co v ní vůbec je a co z toho smí účet agenta.
 *
 * Bere se z `config/admin.neon` přes tutéž továrnu, která staví menu pro člověka, takže se
 * seznam nemá jak rozejít s tím, co je v administraci vidět. U stránek, které brána umí měnit,
 * se rovnou uvedou jejich formuláře a akce i s cestou, kam se pro ně zajít.
 */
final class PageMap
{
	public function __construct(
		private IMenuFactory $menuFactory,
		private PresenterRunner $runner,
		private GatewayConfig $config,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function build(): array
	{
		$items = [];

		foreach ($this->menuFactory->create()->getItems() as $group) {
			foreach ($group->items ?: [$group] as $item) {
				$page = $this->describe($group, $item);

				if ($page === null) {
					continue;
				}

				$items[] = $page;
			}
		}

		return [
			'items' => $items,
			'note' => 'Vypisují se jen stránky, na které smí účet Doryo. Měnit jde zatím to, co má '
				. 'vyplněné „forms", „actions", „bulk" nebo „k2Imports", číst jde, co má „grids"; u ostatních stránek brána zatím jen ví, že existují. '
				. 'Formulář s „create": true umí i zakládat nový záznam — přečti si u něj „createNote".',
		];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function describe(MenuItem $group, MenuItem $item): ?array
	{
		if ($item->link === null || !$this->runner->isAllowed($item->link)) {
			return null;
		}

		$presenter = self::presenterName($item->link);
		$page = $this->config->getPage($presenter);

		$row = [
			'group' => $group === $item ? null : $group->label,
			'label' => $item->label,
			'presenter' => $presenter,
			'link' => $item->link,
			'access' => $this->runner->isAllowed($item->link, '777') ? 'write' : 'read',
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

			if ($form->create) {
				$described['createSchema'] = 'GET ' . $form->getEndpoint();

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
			];
		}

		if ($presenter === K2ImportGateway::PRESENTER) {
			$row['k2Imports'] = [
				'status' => 'GET /v1/admin/k2/imports — poslední běh každého importu; ?target=<kód> historie jednoho zákazníka nebo produktu',
				'reimport' => 'POST /v1/admin/k2/reimport/{customer|product} — znovu naimportovat jeden záznam z K2',
				'prices' => 'GET /v1/admin/k2/prices?customer=<id|kód|IČ>&product=<kód> — proč zákazník vidí na produktu tuhle cenu a jestli se ceny z K2 nahrály',
				'destructive' => true,
				'note' => 'Reimport přepíše záznam hodnotami z K2 a u zákazníka umí založit účty (e-mail s heslem na zákazníka) '
					. 'i účty smazat. Vždy nejdřív náhled a výslovné „ano" uživatele v konverzaci.',
			];
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
