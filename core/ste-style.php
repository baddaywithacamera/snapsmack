<?php
/**
 * SNAPSMACK - GOBSMACKED: Stylometric Feature Extractor
 *
 * Extracts a 25-dimension writing style vector from raw comment text.
 * Used at ban/report time to build a numeric signature of how someone writes.
 * Raw text is never transmitted — only the vector.
 *
 * Entry point: ste_style_extract(array $comment_texts): ?array
 * Returns a 25-element float array, or null if text is under the minimum threshold.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// ── Constants ─────────────────────────────────────────────────────────────────

define('STE_STYLE_MIN_WORDS',   30);    // minimum word count to produce a vector
define('STE_STYLE_DIMENSIONS',  25);    // vector length
define('STE_STYLE_VERSION',      1);    // increment if vector format changes

// High-frequency English function words — subconscious, hard to fake
const STE_FUNCTION_WORDS = [
    'the','a','an','and','but','or','nor','if','as','at','by','for',
    'from','in','of','on','to','up','with','about','after','before',
    'into','out','over','so','than','until','when','where','while',
];

// Tracked individual words for per-feature slots (indices 14–24)
const STE_TRACKED_WORDS = [
    'the','and','but','or','i','you','it','that','this','just','really',
];

// Common contractions for contraction ratio
const STE_CONTRACTIONS = [
    "i'm","i've","i'd","i'll","you're","you've","you'd","you'll",
    "he's","she's","it's","we're","we've","we'd","we'll",
    "they're","they've","they'd","they'll","that's","what's",
    "don't","doesn't","didn't","can't","couldn't","won't","wouldn't",
    "isn't","aren't","wasn't","weren't","haven't","hasn't","hadn't",
    "there's","here's","let's","who's","how's","that'd","should've",
    "could've","would've","might've","must've",
];

// Approximate full forms for the contractions above (one-to-one mapping isn't needed;
// we just count a selection of definitive full-form pairs to estimate ratio)
const STE_FULL_FORMS = [
    'do not','does not','did not','cannot','could not','will not',
    'would not','is not','are not','was not','were not',
    'have not','has not','had not','i am','i have','i will',
    'they are','we are','you are','there is',
];

// ── Main entry point ──────────────────────────────────────────────────────────

/**
 * Extract a stylometric vector from one or more comment texts.
 *
 * @param  string[] $comment_texts  Raw comment bodies (HTML stripped by caller or here)
 * @return float[]|null             25-element float array, or null if text is too short
 */
function ste_style_extract(array $comment_texts): ?array {
    // Strip HTML, normalise whitespace, join
    $texts = array_map(function(string $t): string {
        return preg_replace('/\s+/', ' ', trim(strip_tags($t)));
    }, $comment_texts);

    $full_text = implode(' ', array_filter($texts));
    if ($full_text === '') return null;

    // ── Tokenise ─────────────────────────────────────────────────────────────

    $words      = _ste_words($full_text);
    $word_count = count($words);

    if ($word_count < STE_STYLE_MIN_WORDS) return null;

    $sentences  = _ste_sentences($full_text);
    $sent_count = max(1, count($sentences));
    $lower_text = mb_strtolower($full_text);
    $lower_words = array_map('mb_strtolower', $words);

    // ── Feature extraction ────────────────────────────────────────────────────

    // 0. doc_len — log-scaled word count
    $doc_len = min(1.0, log($word_count + 1) / log(500));

    // 1. avg_word_len — average word character length
    $char_lengths = array_map('mb_strlen', $words);
    $avg_word_len = min(1.0, (array_sum($char_lengths) / $word_count) / 10.0);

    // 2. ttr — type-token ratio (vocabulary richness)
    $ttr = count(array_unique($lower_words)) / $word_count;

    // 3. func_word_ratio — function words / total words
    $func_count = 0;
    $func_set   = array_flip(STE_FUNCTION_WORDS);
    foreach ($lower_words as $w) {
        if (isset($func_set[$w])) $func_count++;
    }
    $func_word_ratio = $func_count / $word_count;

    // 4. excl_rate — exclamation marks per sentence
    $excl_count = substr_count($full_text, '!');
    $excl_rate  = min(1.0, $excl_count / $sent_count);

    // 5. quest_rate — question marks per sentence
    $quest_count = substr_count($full_text, '?');
    $quest_rate  = min(1.0, $quest_count / $sent_count);

    // 6. comma_per_word — commas per word (×10 scaled)
    $comma_count    = substr_count($full_text, ',');
    $comma_per_word = min(1.0, ($comma_count / $word_count) * 10.0);

    // 7. ellipsis_rate — ellipsis per sentence
    $ellipsis_count = substr_count($full_text, '...');
    $ellipsis_rate  = min(1.0, $ellipsis_count / $sent_count);

    // 8. caps_i — proportion of I/i tokens written as capital I
    $cap_i_count   = 0;
    $lower_i_count = 0;
    foreach ($words as $w) {
        if ($w === 'I') $cap_i_count++;
        elseif ($w === 'i') $lower_i_count++;
    }
    $total_i = $cap_i_count + $lower_i_count;
    $caps_i  = $total_i > 0 ? $cap_i_count / $total_i : 0.5; // default 0.5 if no I/i used

    // 9. all_caps_ratio — fully-uppercase words of 2+ chars
    $all_caps = 0;
    foreach ($words as $w) {
        if (mb_strlen($w) >= 2 && $w === mb_strtoupper($w) && ctype_alpha($w)) $all_caps++;
    }
    $all_caps_ratio = min(1.0, $all_caps / $word_count);

    // 10. digit_ratio — numeric tokens / total words
    $digit_tokens = 0;
    foreach ($words as $w) {
        if (is_numeric($w)) $digit_tokens++;
    }
    $digit_ratio = min(1.0, $digit_tokens / $word_count);

    // 11. contraction_ratio — contractions vs approximate full forms
    $contraction_count = 0;
    foreach (STE_CONTRACTIONS as $c) {
        $contraction_count += substr_count($lower_text, $c);
    }
    $full_form_count = 0;
    foreach (STE_FULL_FORMS as $f) {
        $full_form_count += substr_count($lower_text, $f);
    }
    $total_forms       = $contraction_count + $full_form_count;
    $contraction_ratio = $total_forms > 0 ? $contraction_count / $total_forms : 0.5;

    // 12. avg_sent_len — average words per sentence (normalised by 30)
    $sent_lengths = array_map(function(string $s): int {
        return count(_ste_words($s));
    }, $sentences);
    $avg_sent_len = min(1.0, (array_sum($sent_lengths) / $sent_count) / 30.0);

    // 13. sent_len_var — std deviation of sentence lengths (normalised by 20)
    $mean_len = array_sum($sent_lengths) / $sent_count;
    $variance = array_sum(array_map(fn($l) => ($l - $mean_len) ** 2, $sent_lengths)) / $sent_count;
    $sent_len_var = min(1.0, sqrt($variance) / 20.0);

    // 14–24. Individual tracked word frequencies (normalised, ×20 scaled)
    $tracked = [];
    $tracked_set = [];
    foreach (STE_TRACKED_WORDS as $tw) {
        $tracked_set[$tw] = 0;
    }
    foreach ($lower_words as $w) {
        if (isset($tracked_set[$w])) $tracked_set[$w]++;
    }
    foreach (STE_TRACKED_WORDS as $tw) {
        $tracked[] = min(1.0, ($tracked_set[$tw] / $word_count) * 20.0);
    }

    // ── Assemble vector ───────────────────────────────────────────────────────

    $vector = array_merge(
        [
            round($doc_len,           4),
            round($avg_word_len,      4),
            round($ttr,               4),
            round($func_word_ratio,   4),
            round($excl_rate,         4),
            round($quest_rate,        4),
            round($comma_per_word,    4),
            round($ellipsis_rate,     4),
            round($caps_i,            4),
            round($all_caps_ratio,    4),
            round($digit_ratio,       4),
            round($contraction_ratio, 4),
            round($avg_sent_len,      4),
            round($sent_len_var,      4),
        ],
        array_map(fn($v) => round($v, 4), $tracked)
    );

    return $vector; // 25 elements
}

// ── Similarity ────────────────────────────────────────────────────────────────

/**
 * Cosine similarity between two style vectors.
 * Returns float in [0.0, 1.0]. Returns 0.0 if either vector is zero-magnitude.
 */
function ste_style_similarity(array $a, array $b): float {
    if (count($a) !== count($b) || count($a) === 0) return 0.0;

    $dot  = 0.0;
    $magA = 0.0;
    $magB = 0.0;

    for ($i = 0; $i < count($a); $i++) {
        $dot  += $a[$i] * $b[$i];
        $magA += $a[$i] * $a[$i];
        $magB += $b[$i] * $b[$i];
    }

    $denom = sqrt($magA) * sqrt($magB);
    return $denom > 0 ? round($dot / $denom, 4) : 0.0;
}

/**
 * Confidence label for a similarity score.
 */
function ste_style_confidence(float $sim): string {
    if ($sim >= 0.95) return 'STRONG MATCH';
    if ($sim >= 0.90) return 'LIKELY MATCH';
    if ($sim >= 0.80) return 'POSSIBLE MATCH';
    return 'NO MATCH';
}

// ── Internal helpers ──────────────────────────────────────────────────────────

/**
 * Tokenise text into words (alpha + numeric tokens, strip punctuation).
 */
function _ste_words(string $text): array {
    preg_match_all("/[\w']+/u", $text, $m);
    return array_filter($m[0], fn($w) => mb_strlen($w) > 0);
}

/**
 * Split text into sentences on . ! ? (naïve but good enough for comments).
 */
function _ste_sentences(string $text): array {
    $parts = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    return array_filter($parts, fn($s) => trim($s) !== '');
}
// ===== SNAPSMACK EOF =====
