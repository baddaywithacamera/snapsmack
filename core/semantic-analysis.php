<?php
/**
 * SNAPSMACK - Semantic Analysis
 *
 * Provides TF-IDF vectorization and cosine similarity for detecting
 * comments from the same author across different fingerprints.
 * Uses PDO for database access.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


/**
 * Store comment text and compute TF-IDF vector
 *
 * @param PDO $pdo Database connection
 * @param int $comment_id
 * @param string $fingerprint_hash
 * @param string $comment_text
 * @return bool
 */
function store_comment_text(PDO $pdo, int $comment_id, string $fingerprint_hash, string $comment_text): bool {
	$tokens = tokenize($comment_text);
	$vector = compute_tfidf($tokens);
	$vector_json = json_encode($vector);

	$stmt = $pdo->prepare("INSERT INTO snap_comments_semantic (comment_id, fingerprint_hash, comment_text, tfidf_vector) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE comment_text = ?, tfidf_vector = ?");
	return $stmt->execute([$comment_id, $fingerprint_hash, $comment_text, $vector_json, $comment_text, $vector_json]);
}

/**
 * Tokenize comment text into words (simple lowercase split)
 *
 * @param string $text
 * @return array
 */
function tokenize(string $text): array {
	$text = strtolower($text);
	// Remove non-alphanumeric except spaces
	$text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
	// Split on whitespace, remove empty
	$tokens = array_filter(explode(' ', $text));
	return array_values($tokens);
}

/**
 * Compute simple TF-IDF vector from tokens
 * For efficiency, we use term frequency only (not full TF-IDF with IDF lookup)
 * Returns normalized vector of top 50 terms by frequency
 *
 * @param array $tokens
 * @return array
 */
function compute_tfidf(array $tokens): array {
	if (empty($tokens)) {
		return [];
	}

	// Count term frequencies
	$tf = array_count_values($tokens);

	// Sort by frequency, take top 50
	arsort($tf);
	$tf = array_slice($tf, 0, 50, true);

	// Normalize: divide by sum
	$sum = array_sum($tf);
	if ($sum === 0) {
		return [];
	}

	foreach ($tf as &$freq) {
		$freq = $freq / $sum;
	}

	return $tf;
}

/**
 * Find fingerprints with semantically similar comments
 *
 * @param PDO $pdo Database connection
 * @param string $fingerprint_hash
 * @param float $threshold Minimum cosine similarity (0-1), default 0.55
 * @return array Array of ['fingerprint' => hash, 'similarity' => score, 'comment_count' => n]
 */
function find_similar_fingerprints(PDO $pdo, string $fingerprint_hash, float $threshold = 0.55): array {
	// Get the average TF-IDF vector for this fingerprint
	$stmt = $pdo->prepare("SELECT GROUP_CONCAT(tfidf_vector) as vectors FROM snap_comments_semantic WHERE fingerprint_hash = ? LIMIT 100");
	$stmt->execute([$fingerprint_hash]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);

	if (!$row || !$row['vectors']) {
		return [];
	}

	// Average the vectors
	$vectors = explode(',', $row['vectors']);
	$avg_vector = average_vectors($vectors);

	if (empty($avg_vector)) {
		return [];
	}

	// Get all other fingerprints with comment count
	$stmt = $pdo->prepare("SELECT fingerprint_hash, COUNT(*) as cnt FROM snap_comments_semantic WHERE fingerprint_hash != ? GROUP BY fingerprint_hash ORDER BY cnt DESC LIMIT 50");
	$stmt->execute([$fingerprint_hash]);
	$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

	$similar = [];

	foreach ($results as $row) {
		$other_hash = $row['fingerprint_hash'];

		// Get average vector for this fingerprint
		$stmt2 = $pdo->prepare("SELECT GROUP_CONCAT(tfidf_vector) as vectors FROM snap_comments_semantic WHERE fingerprint_hash = ? LIMIT 100");
		$stmt2->execute([$other_hash]);
		$row2 = $stmt2->fetch(PDO::FETCH_ASSOC);

		if ($row2 && $row2['vectors']) {
			$vectors2 = explode(',', $row2['vectors']);
			$other_vector = average_vectors($vectors2);

			if (!empty($other_vector)) {
				$similarity = cosine_similarity($avg_vector, $other_vector);

				if ($similarity >= $threshold) {
					$similar[] = [
						'fingerprint' => $other_hash,
						'similarity' => round($similarity, 2),
						'comment_count' => $row['cnt']
					];
				}
			}
		}
	}

	// Sort by similarity descending
	usort($similar, function($a, $b) {
		return $b['similarity'] <=> $a['similarity'];
	});

	return array_slice($similar, 0, 20); // Return top 20
}

/**
 * Average multiple TF-IDF vectors
 *
 * @param array $vector_jsons Array of JSON vector strings
 * @return array
 */
function average_vectors(array $vector_jsons): array {
	$combined = [];
	$count = 0;

	foreach ($vector_jsons as $json) {
		if (empty($json)) continue;

		$vec = json_decode($json, true);
		if (!is_array($vec)) continue;

		foreach ($vec as $term => $freq) {
			if (!isset($combined[$term])) {
				$combined[$term] = 0;
			}
			$combined[$term] += $freq;
		}
		$count++;
	}

	if ($count === 0 || empty($combined)) {
		return [];
	}

	// Average
	foreach ($combined as &$val) {
		$val = $val / $count;
	}

	return $combined;
}

/**
 * Calculate cosine similarity between two vectors
 *
 * @param array $v1
 * @param array $v2
 * @return float 0-1
 */
function cosine_similarity(array $v1, array $v2): float {
	if (empty($v1) || empty($v2)) {
		return 0.0;
	}

	$dot_product = 0;
	$norm1 = 0;
	$norm2 = 0;

	// Get all unique terms
	$all_terms = array_unique(array_merge(array_keys($v1), array_keys($v2)));

	foreach ($all_terms as $term) {
		$val1 = $v1[$term] ?? 0;
		$val2 = $v2[$term] ?? 0;

		$dot_product += $val1 * $val2;
		$norm1 += $val1 * $val1;
		$norm2 += $val2 * $val2;
	}

	if ($norm1 === 0 || $norm2 === 0) {
		return 0.0;
	}

	return $dot_product / (sqrt($norm1) * sqrt($norm2));
}
// ===== SNAPSMACK EOF =====
