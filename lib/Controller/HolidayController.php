<?php

namespace OCA\Timesheet\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCA\Timesheet\Service\HolidayService;

/**
 * Serves the public holiday lookup endpoint.
 */
class HolidayController extends Controller {
	private HolidayService $holidayService;

	public function __construct(string $appName, IRequest $request, HolidayService $holidayService) {
		parent::__construct($appName, $request);
		$this->holidayService = $holidayService;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getHolidays(int $year, string $state): JSONResponse {
		try {
			$holidays = $this->holidayService->getHolidays($year, $state);
			// Return the holiday lookup result.
			return new JSONResponse($holidays);
		} catch (\Throwable $exception) {
			// Include the lookup failure in the HTTP 500 response.
			return new JSONResponse(
				[
					'error'  => 'Feiertage konnten nicht abgerufen werden.',
					'detail' => $exception->getMessage(), // Diagnostic detail.
				],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}
}
