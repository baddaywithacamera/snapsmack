<?php
/**
 * SMACK CENTRAL - SMACK THE ENEMY Scoring Engine
 *
 * Site weight calculation, score recomputation, time decay, colour mapping,
 * velocity anomaly detection, and coordination cluster detection.
 * Included by sc-enemy-api.php — never called directly.
 */


// ── Constants ────────────────────────────────────────────────────────────────

// Score → colour thresholds (from spec)
const STE_COLOUR_BLACK  = 10.0;
const STE_COLOUR_RED    = 6.0;
const STE_COLOUR_ORANGE = 3.0;
const STE_COLOUR_YELLOW = 1.0;

// Decay half-life in months
const STE_DECAY_HALFLIFE_MONTHS = 6.0;

// Velocity limit: reports per hour before quarantine kicks in
const STE_VELOCITY_LIMIT = 20;

// Coordination window: minutes and minimum sites
const STE_COORD_WINDOW_MINUTES = 10;
const STE_COORD_SITE_THRESHOLD = 5;

// Allow vote weight by threshold setting
const STE_ALLOW_MULTIPLIERS = [
    'yellow' => 1.0,
    'orange' => 0.75,
    'red'    => 0.5,
    'black'  => 0.2,
    'never'  => 0.2,
];

// Semantic set propagation multiplier (Tier 1 → Tier 2 linked fingerprints)
const STE_SET_PROPAGATION_WEIGHT = 0.6;


// ── Site Weight ───────────────────────────────────────────────────────────────

/**
 * Compute the current weight for a site.
 *
 * weight = post_count_factor × age_factor × approval_ratio_factor
 *
 * post_count_factor: logarithmic curve — 1 post ≈ 0.12, 500+ ≈ 1.0
 * age_factor:        linear ramp over 90 days of participation
 * approval_ratio:    reduced when reports are frequently overridden
 */
function ste_site_weight(array $site): float {
    if ($site['weight_suspended'] || $site['status'] !== 'active') {
        return 0.0;
    }

    // Post count factor: log10(posts + 1) / 2.5, capped at 1.0
    $post_factor = min(1.0, log10(max(1, (int)$site['post_count']) + 1) / 2.5);

    // Age factor: days since registration / 90, capped at 1.0
    $days_old    = max(0, (time() - strtotime($site['registered_at'])) / 86400);
    $age_factor  = min(1.0, $days_old / 90.0);

    // Approval ratio factor: penalise sites whose reports are often overridden
    $override_rate   = (float)($site['override_rate'] ?? 0.0);
    $approval_factor = max(0.1, 1.0 - $override_rate);

    return round($post_factor * $age_factor * $approval_factor, 4);
}


// ── Time Decay ────────────────────────────────────────────────────────────────

/**
 * Decay multiplier for a contribution made at $timestamp.
 * Half-life of STE_DECAY_HALFLIFE_MONTHS months.
 */
function ste_decay(string $timestamp): float {
    $months = (time() - strtotime($timestamp)) / (86400 * 30.44);
    return pow(0.5, $months / STE_DECAY_HALFLIFE_MONTHS);
}


// ── Colour Mapping ────────────────────────────────────────────────────────────

function ste_score_to_colour(float $score): string {
    if ($score >= STE_COLOUR_BLACK)  return 'black';
    if ($score >= STE_COLOUR_RED)    return 'red';
    if ($score >= STE_COLOUR_ORANGE) return 'orange';
    if ($score >= STE_COLOUR_YELLOW) return 'yellow';
    return 'green';
}


// ── Score Recomputation ───────────────────────────────────────────────────────

/**
 * Recompute the reputation score for a fingerprint from all its
 * non-quarantined reports and allow votes, applying time decay.
 *
 * score = Σ(report_weight × decay) − Σ(allow_weight × decay)
 *
 * Updates ste_fingerprints and ste_score_cache.
 */
function ste_recompute_score(PDO $pdo, int $fingerprint_id): float {

    // Sum report contributions (non-quarantined only)
    $reports = $pdo->prepare("
        SELECT site_weight_at_report, reported_at
        FROM ste_reports
        WHERE fingerprint_id = ? AND is_quarantined = 0
    ");
    $reports->execute([$fingerprint_id]);

    $score = 0.0;
    foreach ($reports->fetchAll() as $r) {
        $score += (float)$r['site_weight_at_report'] * ste_decay($r['reported_at']);
    }

    // Subtract allow vote contributions
    $allows = $pdo->prepare("
        SELECT site_weight_at_vote, site_threshold, voted_at
        FROM ste_allow_votes
        WHERE fingerprint_id = ?
    ");
    $allows->execute([$fingerprint_id]);

    foreach ($allows->fetchAll() as $a) {
        $multiplier = STE_ALLOW_MULTIPLIERS[$a['site_threshold']] ?? 0.2;
        $score -= (float)$a['site_weight_at_vote'] * $multiplier * ste_decay($a['voted_at']);
    }

    $score  = max(0.0, round($score, 4));
    $colour = ste_score_to_colour($score);
    $now    = date('Y-m-d H:i:s');

    // Update fingerprint table
    $pdo->prepare("
        UPDATE ste_fingerprints
        SET score = ?, colour_level = ?, last_score_update = ?
        WHERE id = ?
    ")->execute([$score, $colour, $now, $fingerprint_id]);

    // Upsert score cache (used by delta push)
    $pdo->prepare("
        INSERT INTO ste_score_cache (fingerprint_id, score, colour_level, computed_at)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE score = VALUES(score), colour_level = VALUES(colour_level), computed_at = VALUES(computed_at)
    ")->execute([$fingerprint_id, $score, $colour, $now]);

    return $score;
}


// ── Fingerprint Upsert ────────────────────────────────────────────────────────

/**
 * Get or create a fingerprint row. Returns the row id.
 */
function ste_get_or_create_fingerprint(PDO $pdo, string $ban_type, string $ban_value): int {
    $row = $pdo->prepare("SELECT id FROM ste_fingerprints WHERE ban_type = ? AND ban_value = ?");
    $row->execute([$ban_type, $ban_value]);
    $existing = $row->fetchColumn();
    if ($existing) return (int)$existing;

    $pdo->prepare("
        INSERT INTO ste_fingerprints (ban_type, ban_value)
        VALUES (?, ?)
    ")->execute([$ban_type, $ban_value]);

    return (int)$pdo->lastInsertId();
}


// ── Velocity Check ────────────────────────────────────────────────────────────

/**
 * Returns true if the site has exceeded the per-hour report limit.
 * Also flags the site if it has, setting weight_suspended.
 */
function ste_velocity_exceeded(PDO $pdo, int $site_id): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM ste_reports
        WHERE site_id = ? AND reported_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute([$site_id]);
    $count = (int)$stmt->fetchColumn();

    if ($count >= STE_VELOCITY_LIMIT) {
        // Check if already flagged — if second offence in 7 days, suspend
        $prev = $pdo->prepare("
            SELECT COUNT(*) FROM ste_reports
            WHERE site_id = ? AND is_quarantined = 1
              AND reported_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $prev->execute([$site_id]);
        if ((int)$prev->fetchColumn() > 0) {
            $pdo->prepare("UPDATE ste_sites SET weight_suspended = 1 WHERE id = ?")
                ->execute([$site_id]);
        }
        return true;
    }

    return false;
}


// ── Coordination Detection ────────────────────────────────────────────────────

/**
 * Checks whether the current report is part of a coordination cluster.
 * A cluster is: ≥5 sites reporting the same fingerprint within 10 minutes,
 * where those sites have no prior co-reporting history.
 *
 * If a cluster is detected, quarantines the new report's weight contribution
 * and records the cluster.
 */
function ste_check_coordination(PDO $pdo, int $fingerprint_id, int $site_id): bool {

    // Count distinct sites that reported this fingerprint in the last 10 minutes
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT site_id) FROM ste_reports
        WHERE fingerprint_id = ?
          AND reported_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
          AND site_id != ?
    ");
    $stmt->execute([$fingerprint_id, STE_COORD_WINDOW_MINUTES, $site_id]);
    $recent_sites = (int)$stmt->fetchColumn();

    if ($recent_sites < STE_COORD_SITE_THRESHOLD - 1) {
        return false; // Not enough to form a cluster
    }

    // Check if any of those sites have ever co-reported with the current site before
    $co_report = $pdo->prepare("
        SELECT COUNT(*) FROM ste_reports r1
        JOIN ste_reports r2 ON r1.fingerprint_id = r2.fingerprint_id
        WHERE r1.site_id = ? AND r2.site_id IN (
            SELECT DISTINCT site_id FROM ste_reports
            WHERE fingerprint_id = ?
              AND reported_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
              AND site_id != ?
        )
        AND r1.reported_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
    ");
    $co_report->execute([
        $site_id,
        $fingerprint_id,
        STE_COORD_WINDOW_MINUTES,
        $site_id,
        STE_COORD_WINDOW_MINUTES,
    ]);

    if ((int)$co_report->fetchColumn() > 0) {
        return false; // These sites have co-reported before — probably legitimate
    }

    // Record the coordination cluster
    $pdo->prepare("
        INSERT INTO ste_coordination_clusters (fingerprint_id, site_count, window_minutes)
        VALUES (?, ?, ?)
    ")->execute([$fingerprint_id, $recent_sites + 1, STE_COORD_WINDOW_MINUTES]);

    return true;
}


// ── Override Rate Refresh ─────────────────────────────────────────────────────

/**
 * Recomputes override_rate for a site.
 * override_rate = allow votes from OTHER sites on fingerprints this site reported
 *                 ÷ total reports from this site.
 *
 * Called periodically (e.g., on heartbeat) to keep the weight fresh.
 */
function ste_refresh_override_rate(PDO $pdo, int $site_id): void {
    $total = $pdo->prepare("SELECT COUNT(*) FROM ste_reports WHERE site_id = ?");
    $total->execute([$site_id]);
    $total_count = (int)$total->fetchColumn();

    if ($total_count === 0) return;

    $overridden = $pdo->prepare("
        SELECT COUNT(DISTINCT r.fingerprint_id)
        FROM ste_reports r
        JOIN ste_allow_votes av ON av.fingerprint_id = r.fingerprint_id
        WHERE r.site_id = ? AND av.site_id != ?
    ");
    $overridden->execute([$site_id, $site_id]);
    $overridden_count = (int)$overridden->fetchColumn();

    $rate = round($overridden_count / $total_count, 4);
    $pdo->prepare("UPDATE ste_sites SET override_rate = ? WHERE id = ?")->execute([$rate, $site_id]);
}
