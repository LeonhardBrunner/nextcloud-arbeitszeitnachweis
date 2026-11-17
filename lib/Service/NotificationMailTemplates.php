<?php

namespace OCA\Timesheet\Service;

/** Validates editable notification text while keeping generated findings in each message. */
class NotificationMailTemplates {
	public const MAX_LENGTH = 4000;

	public static function read(mixed $stored, array $types): array {
		$templates = [];
		foreach ($types as $type) {
			$translations = is_array($stored) ? ($stored[$type] ?? null) : null;
			if (!is_array($translations)) {
				continue;
			}
			foreach ($translations as $language => $text) {
				$body = self::normalizeBody($text);
				if (self::isLanguage($language) && $body !== null) {
					$templates[$type][$language] = $body;
				}
			}
		}
		return $templates;
	}

	/** A patch changes only the supplied language and type. Null restores the built-in text. */
	public static function merge(array $templates, mixed $submitted, array $types): array {
		if (!is_array($submitted)) {
			throw new \InvalidArgumentException('Invalid reminder mail templates');
		}
		foreach ($submitted as $type => $translations) {
			if (!in_array($type, $types, true) || !is_array($translations)) {
				throw new \InvalidArgumentException('Invalid reminder mail template recipient');
			}
			foreach ($translations as $language => $text) {
				if (!self::isLanguage($language)) {
					throw new \InvalidArgumentException('Invalid reminder mail template language');
				}
				if ($text === null) {
					unset($templates[$type][$language]);
					continue;
				}
				$body = self::normalizeBody($text);
				if ($body === null) {
					throw new \InvalidArgumentException('Invalid reminder mail template body');
				}
				$templates[$type][$language] = $body;
			}
			if (($templates[$type] ?? []) === []) {
				unset($templates[$type]);
			}
		}
		foreach ($templates as &$translations) {
			ksort($translations);
		}
		unset($translations);
		ksort($templates);
		return $templates;
	}

	private static function isLanguage(mixed $language): bool {
		return is_string($language) && preg_match('/\A[a-z]{2,3}(?:[-_][a-zA-Z0-9]{2,8}){0,2}(?:@[a-zA-Z0-9]{2,20})?\z/', $language) === 1;
	}

	private static function normalizeBody(mixed $text): ?string {
		if (!is_string($text) || preg_match('//u', $text) !== 1) {
			return null;
		}
		$text = str_replace(["\r\n", "\r"], "\n", $text);
		if (mb_strlen($text, 'UTF-8') > self::MAX_LENGTH || substr_count($text, '{entries}') !== 1
			|| preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1) {
			return null;
		}
		return $text;
	}
}
