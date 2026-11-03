<?php

declare(strict_types=1);

namespace OCA\Timesheet\Controller;

use OCA\Timesheet\Service\UpdateNotesService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class UpdateNotesController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private UpdateNotesService $notesService,
		private IL10N $translator,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function load(mixed $mode = 'automatic'): DataResponse {
		return $this->forCurrentUser(function (string $userId) use ($mode): array {
			if (!in_array($mode, ['automatic', 'manual'], true)) {
				throw new \InvalidArgumentException('Invalid update notes mode.');
			}
			return $this->notesService->open($userId, $mode, $this->translator);
		});
	}

	#[NoAdminRequired]
	public function shown(mixed $throughVersion = null): DataResponse {
		return $this->forCurrentUser(function (string $userId) use ($throughVersion): array {
			if (!is_string($throughVersion)) {
				throw new \InvalidArgumentException('An update notes version is required.');
			}
			return $this->notesService->markShown($userId, $throughVersion);
		});
	}

	private function forCurrentUser(callable $operation): DataResponse {
		$userId = $this->userSession->getUser()?->getUID();
		try {
			if ($userId === null || !$this->notesService->canReceive($userId)) {
				return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
			}
			return new DataResponse($operation($userId), Http::STATUS_OK);
		} catch (\InvalidArgumentException) {
			return new DataResponse(['message' => 'Invalid update notes request'], Http::STATUS_BAD_REQUEST);
		} catch (\DomainException) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		} catch (\Throwable $error) {
			$this->logger->warning('Update notes could not be processed.', ['userId' => $userId, 'exception' => $error]);
			return new DataResponse(['message' => 'Update notes could not be processed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
