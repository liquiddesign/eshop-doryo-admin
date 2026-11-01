<?php

declare(strict_types=1);

namespace DoryoAdmin\Gateway;

use DoryoApi\Http\ApiException;
use Nette\Http\FileUpload;
use Nette\Utils\Strings;

/**
 * Soubory, které brána nahrává za člověka: obsah přichází v JSON těle jako base64 (`{"name", "content"}`)
 * a do administrace jde jako `FileUpload` nad dočasným souborem — stejně, jako by ho poslal prohlížeč.
 *
 * Nahrávání je volitelné (`maxUploadBytes`, výchozí 0 = vypnuto), protože dřív pole souborů brána jen
 * držela, a whitelist, který s tím nepočítal, se nesmí rozšířit potichu. Náhled soubor popíše (název,
 * velikost, typ podle obsahu, rozměry obrázku, otisk) a token se váže na otisk — ostrý zápis s jiným
 * souborem je 409.
 */
final class Uploads
{
	/** @var list<string> dočasné soubory tohoto požadavku; mažou se na konci, ať v tempu nezůstávají */
	private array $temporary = [];

	/**
	 * @param string|null $userDir Složka souborů e-shopu (`userfiles`) — odsud jde vzít soubor, který už v e-shopu je
	 *     (`{"eshop": "/userfiles/…"}`), bez stahování přes web; null = jen soubory v těle
	 */
	public function __construct(private int $maxBytes, private ?string $userDir = null)
	{
	}

	public function __destruct()
	{
		$this->cleanup();
	}

	public function isEnabled(): bool
	{
		return $this->maxBytes > 0;
	}

	/**
	 * Soubor z těla: `{"name": "logo.png", "content": "<base64>"}` → upload a popis pro náhled.
	 * @return array{upload: \Nette\Http\FileUpload, file: array<string, mixed>}
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function read(mixed $spec, string $label, bool $image): array
	{
		if (!$this->isEnabled()) {
			throw new ApiException(403, 'Nahrávání je vypnuté', 'Nahrávání souborů má brána na tomhle e-shopu vypnuté '
				. '(doryoAdmin: maxUploadBytes). Soubor tedy nahrát nejde, ostatní pole ano.');
		}

		if (\is_array($spec) && \is_string($spec['eshop'] ?? null)) {
			[$name, $data] = $this->zEshopu($spec['eshop'], $label);
		} elseif (!\is_array($spec) || !\is_string($spec['name'] ?? null) || !\is_string($spec['content'] ?? null)) {
			throw ApiException::badRequest(\sprintf(
				'Soubor pro „%s" se posílá jako objekt {"name": "<název souboru>", "content": "<obsah v base64>"}, '
				. 'nebo soubor, který už v e-shopu je: {"eshop": "<adresa nebo cesta /userfiles/…>"}.',
				$label,
			));
		} else {
			$name = Strings::trim($spec['name']);
			$data = \base64_decode($spec['content'], true);
		}

		if ($name === '' || $data === false || $data === '') {
			throw ApiException::badRequest(\sprintf('Soubor pro „%s" nemá název, nebo jeho obsah není platné base64.', $label));
		}

		if (\strlen($data) > $this->maxBytes) {
			throw new ApiException(413, 'Soubor je moc velký', \sprintf(
				'%s má %s, brána bere nejvýš %s.',
				$name,
				self::size(\strlen($data)),
				self::size($this->maxBytes),
			));
		}

		$path = \tempnam(\sys_get_temp_dir(), 'doryo-admin-');

		if ($path === false || \file_put_contents($path, $data) !== \strlen($data)) {
			throw new ApiException(500, 'Chyba serveru', 'Soubor se nepodařilo uložit do dočasné složky. Nahlas to vývojářům.');
		}

		$this->temporary[] = $path;
		$upload = new FileUpload([
			'name' => $name,
			'full_path' => $name,
			'size' => \strlen($data),
			'tmp_name' => $path,
			'error' => \UPLOAD_ERR_OK,
		]);

		if ($image && !$upload->isImage()) {
			throw new ApiException(422, 'Není to obrázek', \sprintf(
				'„%s" bere jen obrázek (JPEG, PNG, GIF, WebP); %s je podle obsahu %s.',
				$label,
				$name,
				$upload->getContentType() ?? 'neznámý typ',
			));
		}

		$file = [
			'name' => $upload->getSanitizedName(),
			'size' => \strlen($data),
			'sizeLabel' => self::size(\strlen($data)),
			'contentType' => $upload->getContentType(),
			'sha256' => \hash('sha256', $data),
		];

		$dimensions = $upload->isImage() ? $upload->getImageSize() : null;

		if ($dimensions) {
			$file['width'] = $dimensions[0];
			$file['height'] = $dimensions[1];
		}

		return ['upload' => $upload, 'file' => $file];
	}

	/**
	 * Soubor, který už v e-shopu je (fotka produktu, logo), přímo z disku — Kolego na testovací kopii za VPN obrázek z webu
	 * stáhnout neumí (1. 11. 2026). Bere adresu webu i cestu; jen soubor uvnitř složky souborů e-shopu (`userDir`).
	 * @return array{0: string, 1: string}
	 * @throws \DoryoApi\Http\ApiException
	 */
	private function zEshopu(string $kde, string $label): array
	{
		$koren = $this->userDir !== null ? \realpath($this->userDir) : false;

		if ($koren === false) {
			throw new ApiException(422, 'Soubory e-shopu nejsou k dispozici', 'Brána na tomhle e-shopu nezná složku souborů '
				. '(doryoAdmin: userFilesDir) — pošli soubor v těle jako {"name", "content"}.');
		}

		$cesta = \rawurldecode((string) (\parse_url(Strings::trim($kde), \PHP_URL_PATH) ?? ''));
		$cesta = \ltrim($cesta, '/');
		$slozka = \basename($koren) . '/';

		if (\str_starts_with($cesta, $slozka)) {
			$cesta = \substr($cesta, \strlen($slozka));
		}

		$plna = $cesta !== '' ? \realpath($koren . '/' . $cesta) : false;

		if ($plna === false || !\str_starts_with($plna, $koren . \DIRECTORY_SEPARATOR) || !\is_file($plna) || !\is_readable($plna)) {
			throw new ApiException(404, 'Soubor v e-shopu není', \sprintf(
				'Pro „%s" jsem v souborech e-shopu nenašel %s — beru jen soubory ze složky %s (adresa obrázku z webu nebo cesta /%s…).',
				$label,
				$kde,
				\basename($koren),
				$slozka,
			));
		}

		$data = (string) \file_get_contents($plna);

		return [\basename($plna), $data];
	}

	public function cleanup(): void
	{
		foreach ($this->temporary as $path) {
			if (\is_file($path)) {
				@\unlink($path);
			}
		}

		$this->temporary = [];
	}

	private static function size(int $bytes): string
	{
		return $bytes >= 1048576 ? \sprintf('%.1f MB', $bytes / 1048576) : \sprintf('%d kB', (int) \ceil($bytes / 1024));
	}
}
