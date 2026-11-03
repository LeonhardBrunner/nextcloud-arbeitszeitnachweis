<?php

declare(strict_types=1);

namespace OCA\Timesheet\Service;

use OCP\IL10N;

/** Reads the bundled release texts; account state and delivery stay outside this class. */
class UpdateNotesCatalog {
	private ?array $releases = null;

	public function __construct(private ?string $catalogPath = null) {
	}

	public static function isVersion(mixed $version): bool {
		return is_string($version) && preg_match('/\A\d+\.\d+\.\d+\z/', $version) === 1;
	}

	public function contains(string $version): bool {
		return isset($this->all()[$version]);
	}

	/** @return array<int,array{version:string,sections:array}> */
	public function between(string $floorVersion, ?string $afterVersion, string $throughVersion): array {
		return array_values(array_filter($this->all(), static fn(array $release): bool =>
			version_compare($release['version'], $floorVersion, '>=')
			&& ($afterVersion === null || version_compare($release['version'], $afterVersion, '>'))
			&& version_compare($release['version'], $throughVersion, '<=')
		));
	}

	public function translate(array $releases, IL10N $translator): array {
		return array_map(static fn(array $release): array => [
			'version' => $release['version'],
			'sections' => array_map(static fn(array $section): array => [
				'title' => (string)$translator->t($section['title']),
				'items' => array_map(static fn(string $message): string => (string)$translator->t($message), $section['items']),
			], $release['sections']),
		], $releases);
	}

	private function all(): array {
		if ($this->releases !== null) {
			return $this->releases;
		}
		$path = $this->catalogPath ?? __DIR__ . '/../../appinfo/release-notes.json';
		$content = file_get_contents($path);
		if ($content === false) {
			throw new \RuntimeException('The update notes catalog could not be read.');
		}
		$catalog = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($catalog) || !isset($catalog['releases']) || !is_array($catalog['releases']) || !array_is_list($catalog['releases'])) {
			throw new \UnexpectedValueException('Invalid update notes catalog.');
		}
		$releases = [];
		foreach ($catalog['releases'] as $release) {
			if (!is_array($release) || !self::isVersion($release['version'] ?? null) || isset($releases[$release['version']])
				|| !is_array($release['sections'] ?? null) || !array_is_list($release['sections']) || $release['sections'] === []) {
				throw new \UnexpectedValueException('Invalid release in update notes catalog.');
			}
			foreach ($release['sections'] as $section) {
				if (!is_array($section) || !is_string($section['title'] ?? null) || trim($section['title']) === ''
					|| !is_array($section['items'] ?? null) || !array_is_list($section['items']) || $section['items'] === []) {
					throw new \UnexpectedValueException('Invalid update notes section.');
				}
				foreach ($section['items'] as $message) {
					if (!is_string($message) || trim($message) === '') {
						throw new \UnexpectedValueException('Invalid update note.');
					}
				}
			}
			$releases[$release['version']] = $release;
		}
		uksort($releases, static fn(string $first, string $second): int => version_compare($first, $second));
		return $this->releases = $releases;
	}
}
