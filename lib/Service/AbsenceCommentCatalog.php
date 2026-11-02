<?php

declare(strict_types=1);

namespace OCA\Timesheet\Service;

use Normalizer;
use RuntimeException;

/** Shares localized absence labels between the interface and reminder checks. */
class AbsenceCommentCatalog {

	/** @var array<string,array{vacation:string,timeOffInLieu:string}> */
	private array $termsByLanguage = [];
	/** @var array<string,true> */
	private array $acceptedComments = [];

	public function __construct() {
		$catalogFile = __DIR__ . '/absence-comment-terms.json';
		$contents = file_get_contents($catalogFile);
		if ($contents === false) {
			throw new RuntimeException('The absence comment catalog could not be read.');
		}
		$catalog = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($catalog) || !isset($catalog['en'], $catalog['de'])) {
			throw new RuntimeException('The absence comment catalog must include English and German terms.');
		}

		foreach ($catalog as $language => $terms) {
			if (!is_string($language) || !is_array($terms)
				|| !$this->isValidTerm($terms['vacation'] ?? null)
				|| !$this->isValidTerm($terms['timeOffInLieu'] ?? null)) {
				throw new RuntimeException('The absence comment catalog contains invalid terms.');
			}
			$language = $this->normalizeLanguage($language);
			$this->termsByLanguage[$language] = [
				'vacation' => $terms['vacation'],
				'timeOffInLieu' => $terms['timeOffInLieu'],
			];
			foreach ([$terms['vacation'], $terms['timeOffInLieu']] as $term) {
				$this->acceptedComments[$this->normalizeComment($term)] = true;
				// Uppercase forms also preserve case pairs such as Turkish dotless i.
				$this->acceptedComments[$this->normalizeComment(mb_strtoupper($term, 'UTF-8'))] = true;
			}
		}
	}

	/** @return array{vacation:string,timeOffInLieu:string} */
	public function getTerms(string $language): array {
		$language = $this->normalizeLanguage($language);
		$baseLanguage = explode('_', $language, 2)[0];
		if (isset($this->termsByLanguage[$language])) {
			return $this->termsByLanguage[$language];
		}
		if (isset($this->termsByLanguage[$baseLanguage])) {
			return $this->termsByLanguage[$baseLanguage];
		}
		// Some Nextcloud languages are offered only with a region, such as et_EE.
		foreach ($this->termsByLanguage as $knownLanguage => $terms) {
			if (explode('_', $knownLanguage, 2)[0] === $baseLanguage) {
				return $terms;
			}
		}
		return $this->termsByLanguage['en'];
	}

	public function isExemptComment(?string $comment): bool {
		// Recognition is independent of the reader's language and later account changes.
		return isset($this->acceptedComments[$this->normalizeComment($comment ?? '')]);
	}

	private function isValidTerm(mixed $term): bool {
		return is_string($term) && mb_check_encoding($term, 'UTF-8') && $this->normalizeComment($term) !== '';
	}

	private function normalizeLanguage(string $language): string {
		return strtolower(str_replace('-', '_', trim($language)));
	}

	private function normalizeComment(string $comment): string {
		if (!mb_check_encoding($comment, 'UTF-8')) {
			return '';
		}
		$trimmedComment = preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $comment);
		$normalizedComment = Normalizer::normalize($trimmedComment ?? '', Normalizer::FORM_C);
		return mb_convert_case($normalizedComment === false ? '' : $normalizedComment, MB_CASE_FOLD, 'UTF-8');
	}
}
