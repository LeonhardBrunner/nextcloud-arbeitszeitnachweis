<?php

declare(strict_types=1);

namespace OCA\Timesheet\Controller;

use OC\ForbiddenException;
use OCA\Timesheet\Service\ChangeHistoryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class ChangeHistoryController extends Controller {
	public function __construct(string $appName, IRequest $request, private ChangeHistoryService $history) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): DataResponse {
		try {
			return new DataResponse($this->history->list($this->request->getParams()));
		} catch (ForbiddenException) {
			return new DataResponse(['error' => 'Forbidden'], 403);
		} catch (\InvalidArgumentException) {
			return new DataResponse(['error' => 'Invalid history filter'], 400);
		} catch (\Throwable) {
			return new DataResponse(['error' => 'The change history could not be loaded. Please try again.'], 500);
		}
	}
}
