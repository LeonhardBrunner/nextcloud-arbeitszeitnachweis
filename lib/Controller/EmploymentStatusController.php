<?php

declare(strict_types=1);

namespace OCA\Timesheet\Controller;

use OCA\Timesheet\Db\EmploymentStatusStore;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use OCP\PreConditionNotMetException;
use OCA\Timesheet\Service\HrService;
use Psr\Log\LoggerInterface;

class EmploymentStatusController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private EmploymentStatusStore $statusStore,
		private IUserSession $userSession,
		private HrService $hrService,
		private LoggerInterface $logger,
		private IGroupManager $groupManager,
	) {
		parent::__construct($appName, $request);
	}

	/** Only ordinary employees may change their own freelancer classification. */
	#[NoAdminRequired]
	public function setFreelancerMode(mixed $freelancerMode = null, mixed $statusRevision = null): DataResponse {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null || $userId === '' || $this->hrService->isHr($userId) || $this->groupManager->isAdmin($userId)) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}
		if (!is_bool($freelancerMode) || !$this->isRevision($statusRevision)) {
			return new DataResponse(['message' => 'Invalid employment status request'], Http::STATUS_BAD_REQUEST);
		}
		return $this->save($userId, fn() => $this->statusStore->setFreelancerMode($userId, $freelancerMode, $statusRevision));
	}

	private function save(string $userId, callable $update): DataResponse {
		try {
			try {
				$status = $update();
			} catch (PreConditionNotMetException | LockedException) {
				return new DataResponse([
					'code' => 'employment_status_conflict',
					...$this->statusStore->getStatus($userId)->toPublicArray(),
				], Http::STATUS_CONFLICT);
			}
			return new DataResponse($status->toPublicArray(), Http::STATUS_OK);
		} catch (\Throwable $saveError) {
			$this->logger->error('Employment status could not be saved.', ['userId' => $userId, 'exception' => $saveError]);
			return new DataResponse(['message' => 'Saving employment status failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	private function isRevision(mixed $revision): bool {
		return is_string($revision) && preg_match('/\A[0-9a-f]{64}\z/i', $revision) === 1;
	}
}
