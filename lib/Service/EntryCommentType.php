<?php

declare(strict_types=1);

namespace OCA\Timesheet\Service;

enum EntryCommentType: string {
	case Vacation = 'vacation';
	case Sick = 'sick';
	case Appointment = 'appointment';
	case Custom = 'custom';

	public function isAbsence(): bool {
		return $this !== self::Custom;
	}
}
