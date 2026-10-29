<?php

namespace OCA\Timesheet\Service;

use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Http\Client\IClientService;

class HolidayService {
	private string $appName;
	private IAppData $appData;
	private IClientService $clientService;

	public function __construct(string $appName, IAppData $appData, IClientService $clientService) {
		$this->appName = $appName;
		$this->appData = $appData;
		$this->clientService = $clientService;
	}

	public function getHolidays(int $year, string $state): array {
		$stateCode = $this->normalizeState($state);

		$cacheFolderName = 'holidays';
		$cacheFileName = $year . '_' . $stateCode . '.json';

		// Create the cache folder when it is missing.
		try {
			$cacheFolder = $this->appData->getFolder($cacheFolderName);
		} catch (NotFoundException $missingFolder) {
			$cacheFolder = $this->appData->newFolder($cacheFolderName);
		}

		// Reuse a recent cache entry for this year and state.
		if ($cacheFolder->fileExists($cacheFileName)) {
			try {
				$cacheFile = $cacheFolder->getFile($cacheFileName);
				$modifiedAt = $cacheFile->getMTime(); // Cache file modification time.
				if (time() - $modifiedAt < 15768000) { // Six months in seconds.
					$cachedHolidays = json_decode($cacheFile->getContent(), true);
					if (is_array($cachedHolidays)) {
						return $cachedHolidays;
					}
				} else {
					// Delete expired entries before requesting fresh data.
					$cacheFile->delete();
				}
			} catch (\Throwable $cacheError) {
				// Request fresh data when the cache cannot be read.
			}
		}

		return $this->loadAndCacheFromApi($cacheFolder, $cacheFileName, $year, $stateCode);
	}

	private function loadAndCacheFromApi($folder, string $cacheKey, int $year, string $stateCode): array {
		$url = 'https://get.api-feiertage.de?years=' . urlencode($year) . '&states=' . urlencode($stateCode);
		try {
			$response = $this->clientService->newClient()->get($url);
			if ($response->getStatusCode() !== 200) {
				throw new \RuntimeException('Feiertage-API antwortete mit HTTP ' . $response->getStatusCode());
			}
			$responseData = json_decode($response->getBody(), true);
			if (!is_array($responseData) || !isset($responseData['feiertage']) || !is_array($responseData['feiertage'])) {
				throw new \RuntimeException('Unerwartete Antwortstruktur der Feiertage-API');
			}
		} catch (\Throwable $apiError) {
			throw new \RuntimeException('Feiertage-API Fehler: ' . $apiError->getMessage() . ' | URL: ' . $url);
		}

		// Include public holidays marked for the requested state.
		$holidays = [];
		foreach ($responseData['feiertage'] as $holiday) {
			$stateKey = strtolower($stateCode);
			if (!isset($holiday['date']) || !isset($holiday[$stateKey]) || $holiday[$stateKey] !== "1") {
				continue;
			}
			$holidays[$holiday['date']] = $holiday['fname'] ?? 'Feiertag';
		}

		try {
			$cacheJson = json_encode($holidays, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
			$folder->newFile($cacheKey)->putContent($cacheJson);
		} catch (\Throwable) {
			// Cache writes are optional.
		}

		return $holidays;
	}

	/** Checks reminder eligibility without changing the existing holiday lookup behavior. */
	public function isSupportedState(string $state): bool {
		return in_array($this->normalizeState($state), [
			'bw', 'by', 'be', 'bb', 'hb', 'hh', 'he', 'mv', 'ni', 'nw', 'rp', 'sl', 'sn', 'st', 'sh', 'th',
		], true);
	}

	private function normalizeState(string $state): string {
		$stateCodes = [
			'Baden-Württemberg'      => 'bw',
			'Baden Württemberg'      => 'bw',
			'Baden Wuerttemberg'     => 'bw',
			'Bayern'                 => 'by',
			'Berlin'                 => 'be',
			'Brandenburg'            => 'bb',
			'Bremen'                 => 'hb',
			'Hamburg'                => 'hh',
			'Hessen'                 => 'he',
			'Mecklenburg-Vorpommern' => 'mv',
			'Mecklenburg Vorpommern' => 'mv',
			'Niedersachsen'          => 'ni',
			'Nordrhein-Westfalen'    => 'nw',
			'Nordrhein Westfalen'    => 'nw',
			'NRW'                    => 'nw',
			'Rheinland-Pfalz'        => 'rp',
			'Rheinland Pfalz'        => 'rp',
			'Saarland'               => 'sl',
			'Sachsen'                => 'sn',
			'Sachsen-Anhalt'         => 'st',
			'Sachsen Anhalt'         => 'st',
			'Schleswig-Holstein'     => 'sh',
			'Schleswig Holstein'     => 'sh',
			'Thüringen'              => 'th',
		];

		$state = trim($state);

		// Accept an existing two-letter state code.
		if (preg_match('/^[A-Z]{2}$/', $state)) {
			return strtolower($state);
		}

		return $stateCodes[$state] ?? strtolower($state);
	}
}
