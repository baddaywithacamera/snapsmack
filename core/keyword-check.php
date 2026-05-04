<?php
/**
 * SNAPSMACK - Keyword Checking
 *
 * Provides functions to check comments against banned keywords and phrases.
 * Uses PDO for database access.
 */

/**
 * Check if comment text contains any banned keywords
 *
 * @param PDO $pdo Database connection
 * @param string $comment_text
 * @return array ['matched' => bool, 'matches' => [keywords matched], 'severity' => 'flag'|'reject']
 */
function check_keywords(PDO $pdo, string $comment_text): array {
	$result = ['matched' => false, 'matches' => [], 'severity' => 'flag'];

	// Fetch all keywords
	$stmt = $pdo->prepare("SELECT keyword, match_type, severity FROM snap_keywords ORDER BY severity DESC");
	$stmt->execute();
	$keywords = $stmt->fetchAll(PDO::FETCH_ASSOC);

	foreach ($keywords as $row) {
		$keyword = $row['keyword'];
		$match_type = $row['match_type'];
		$severity = $row['severity'];
		$found = false;

		if ($match_type === 'exact') {
			// Exact word match (case-insensitive)
			$pattern = '\b' . preg_quote($keyword) . '\b';
			$found = (bool) preg_match("/$pattern/i", $comment_text);
		} elseif ($match_type === 'substring') {
			// Substring match
			$found = (stripos($comment_text, $keyword) !== false);
		} elseif ($match_type === 'regex') {
			// Regex match
			@$found = (bool) preg_match($keyword, $comment_text);
		}

		if ($found) {
			$result['matched'] = true;
			$result['matches'][] = $keyword;

			// If any match is 'reject', set severity to reject
			if ($severity === 'reject') {
				$result['severity'] = 'reject';
			}
		}
	}

	return $result;
}

/**
 * Add a banned keyword/phrase
 *
 * @param PDO $pdo Database connection
 * @param string $keyword
 * @param string $match_type 'exact', 'substring', or 'regex'
 * @param string $severity 'flag' or 'reject'
 * @param string $reason Optional reason
 * @param string $added_by Optional username
 * @return bool
 */
function add_keyword(PDO $pdo, string $keyword, string $match_type = 'substring', string $severity = 'flag', string $reason = '', string $added_by = ''): bool {
	if (empty($keyword)) {
		return false;
	}

	$stmt = $pdo->prepare("INSERT IGNORE INTO snap_keywords (keyword, match_type, severity, reason, added_by) VALUES (?, ?, ?, ?, ?)");
	return $stmt->execute([$keyword, $match_type, $severity, $reason ?: null, $added_by ?: null]);
}

/**
 * Remove a banned keyword/phrase
 *
 * @param PDO $pdo Database connection
 * @param string $keyword
 * @return bool
 */
function remove_keyword(PDO $pdo, string $keyword): bool {
	$stmt = $pdo->prepare("DELETE FROM snap_keywords WHERE keyword = ?");
	return $stmt->execute([$keyword]);
}

/**
 * Get all banned keywords
 *
 * @param PDO $pdo Database connection
 * @return array
 */
function get_all_keywords(PDO $pdo): array {
	$stmt = $pdo->prepare("SELECT id, keyword, match_type, severity, reason, added_at, added_by FROM snap_keywords ORDER BY added_at DESC");
	$stmt->execute();
	return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
// EOF
