<?php
/**
 * SMACK CENTRAL - SMACKATTACK Public API
 *
 * Network-wide distributed ban reputation system for SnapSmack.
 * Called by opted-in SnapSmack blogs — NOT the SC admin interface.
 *
 * Routes (all require Authorization: Bearer {api_key}):
 *   POST ?route=register       — Register a new site (no auth required)
 *   POST ?route=report         — Submit ban report(s)
 *   POST ?route=allow          — Submit allow vote
 *   GET  ?route=scores/delta   — Pull score updates since timestamp
 *   POST ?route=heartbeat      — Keep registration alive, update post count
 *   POST ?route=optout         — Permanently opt out
 */

require_once __DIR__ . '/sc-config.php';
require_once __DIR__ . '/sc-db.php';
require_once __DIR__ . '/sc-enemy-scoring.php';

// ── Bootstrap ─────────────────────────────────────────────────────────────────

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$pdo   = sc_enemy_db();
$route = trim($_GET['route'] ?? '', '/');

// ── Auth ──────────────────────────────────────────────────────────────────────

/**
 * Authenticate via Bearer token. Returns the site row or exits with 401.
 * Registration route is exempt.
 */
function ste_auth(PDO $pdo): array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $m)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Missing or invalid Authorization header.']);
        exit;
    }
    $key  = strtolower($m[1]);
    $site = $pdo->prepare("SELECT * FROM ste_sites WHERE api_key = ? AND status != 'opted_out'");
    $site->execute([$key]);
    $row = $site->fetch();
    if (!$row) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unknown or inactive API key.']);
        exit;
    }
    // Update last_seen
    $pdo->prepare("UPDATE ste_sites SET last_seen_at = NOW() WHERE id = ?")->execute([$row['id']]);
    return $row;
}

function ste_json(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function ste_body(): array {
    $raw = file_get_contents('php://input');
    return $raw ? (json_decode($raw, true) ?? []) : [];
}

// ── Rate Limiting ─────────────────────────────────────────────────────────────
// 100 requests/minute per IP. Simple token bucket via transient DB tracking.

function ste_rate_limit(): void {
    $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $limit = 100;
    // File-based rate limit with exclusive locking to prevent race conditions
    // where concurrent requests all read the old count and all pass the check.
    $file = sys_get_temp_dir() . '/ste_' . md5($ip) . '.json';
    $fp   = fopen($file, 'c+');
    if (!$fp) return; // Can't open file — fail open rather than block all requests
    flock($fp, LOCK_EX);
    $raw  = stream_get_contents($fp);
    $data = $raw ? (json_decode($raw, true) ?? []) : [];
    if (empty($data['window']) || time() - $data['window'] > 60) {
        $data = ['count' => 0, 'window' => time()];
    }
    $data['count']++;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
    if ($data['count'] > $limit) {
        ste_json(['ok' => false, 'error' => 'Rate limit exceeded. Max 100 requests/minute.'], 429);
    }
}

ste_rate_limit();

// ── Router ────────────────────────────────────────────────────────────────────

// ── POST register ─────────────────────────────────────────────────────────────
if ($route === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $body     = ste_body();
    $site_url = trim($body['site_url'] ?? '');
    $posts    = max(0, (int)($body['post_count'] ?? 0));

    if (!$site_url || !filter_var($site_url, FILTER_VALIDATE_URL)) {
        ste_json(['ok' => false, 'error' => 'Valid site_url required.'], 400);
    }

    // Normalise URL — strip trailing slash
    $site_url = rtrim($site_url, '/');

    // Check if already registered
    $existing = $pdo->prepare("SELECT id, status FROM ste_sites WHERE site_url = ?");
    $existing->execute([$site_url]);
    $row = $existing->fetch();
    if ($row) {
        if ($row['status'] === 'opted_out') {
            ste_json(['ok' => false, 'error' => 'This site has opted out and cannot re-register.'], 403);
        }
        ste_json(['ok' => false, 'error' => 'This site is already registered.'], 409);
    }

    // Generate API key
    $api_key = bin2hex(random_bytes(32));

    $pdo->prepare("
        INSERT INTO ste_sites (site_url, api_key, post_count)
        VALUES (?, ?, ?)
    ")->execute([$site_url, $api_key, $posts]);

    $site_id = (int)$pdo->lastInsertId();

    ste_json([
        'ok'      => true,
        'site_id' => $site_id,
        'api_key' => $api_key,
        'message' => 'Welcome to SMACKATTACK. Store your api_key securely.',
    ]);
}


// ── POST report ───────────────────────────────────────────────────────────────
if ($route === 'report' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $site    = ste_auth($pdo);
    $body    = ste_body();
    $reports = $body['reports'] ?? [];
    $posts   = (int)($body['post_count'] ?? $site['post_count']);

    if (empty($reports) || !is_array($reports)) {
        ste_json(['ok' => false, 'error' => 'reports[] array required.'], 400);
    }

    // Update post count for weight calc
    if ($posts !== (int)$site['post_count']) {
        $pdo->prepare("UPDATE ste_sites SET post_count = ? WHERE id = ?")->execute([$posts, $site['id']]);
        $site['post_count'] = $posts;
    }

    $site_weight  = ste_site_weight($site);
    $velocity_hit = ste_velocity_exceeded($pdo, $site['id']);
    $accepted     = 0;
    $quarantined  = 0;
    $affected_fps = [];

    foreach (array_slice($reports, 0, 500) as $rep) {
        $ban_type  = $rep['ban_type']  ?? '';
        $ban_value = strtolower(trim($rep['ban_value'] ?? ''));
        $rep_at    = $rep['reported_at'] ?? date('Y-m-d H:i:s');

        if (!in_array($ban_type, ['fingerprint', 'ip', 'email_hash'], true)) continue;
        if (!preg_match('/^[a-f0-9]{64}$/', $ban_value)) continue;

        $fp_id = ste_get_or_create_fingerprint($pdo, $ban_type, $ban_value);
        $is_quarantined = $velocity_hit ? 1 : 0;

        // Check coordination if not already quarantined
        if (!$is_quarantined) {
            $is_coord = ste_check_coordination($pdo, $fp_id, $site['id']);
            if ($is_coord) $is_quarantined = 1;
        }

        // INSERT or UPDATE (one strike per site per fingerprint)
        $pdo->prepare("
            INSERT INTO ste_reports
                (site_id, fingerprint_id, reported_at, site_weight_at_report, is_quarantined)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                reported_at           = VALUES(reported_at),
                site_weight_at_report = VALUES(site_weight_at_report),
                is_quarantined        = VALUES(is_quarantined)
        ")->execute([$site['id'], $fp_id, $rep_at, $site_weight, $is_quarantined]);

        // Update report_count on fingerprint (only non-quarantined new inserts)
        if (!$is_quarantined) {
            $pdo->prepare("
                UPDATE ste_fingerprints
                SET report_count = (
                    SELECT COUNT(DISTINCT site_id) FROM ste_reports
                    WHERE fingerprint_id = ? AND is_quarantined = 0
                )
                WHERE id = ?
            ")->execute([$fp_id, $fp_id]);
            $affected_fps[] = $fp_id;
            $accepted++;
        } else {
            $quarantined++;
        }
    }

    // Update site report count and recompute scores
    $pdo->prepare("UPDATE ste_sites SET report_count = report_count + ? WHERE id = ?")
        ->execute([$accepted, $site['id']]);

    foreach (array_unique($affected_fps) as $fp_id) {
        ste_recompute_score($pdo, $fp_id);
    }

    // ── Store style vector (GOBSMACKED — Tier 3) ──────────────────────────────
    // The blog extracts writing style features at ban time. Only the numeric
    // vector arrives here — raw comment text never leaves the blog.
    $raw_vector = $body['style_vector'] ?? null;
    if (
        $raw_vector !== null
        && is_array($raw_vector)
        && count($raw_vector) === 25
        && count($affected_fps) > 0
    ) {
        // Validate: all elements must be numeric floats in [0, 1]
        $valid_vector = true;
        foreach ($raw_vector as $v) {
            if (!is_numeric($v) || $v < 0 || $v > 1) { $valid_vector = false; break; }
        }

        if ($valid_vector) {
            $vector_json  = json_encode(array_map('floatval', $raw_vector));
            $expires_date = date('Y-m-d', strtotime('+365 days'));
            $upsert_vec   = $pdo->prepare("
                INSERT INTO ste_style_vectors
                    (fingerprint_id, site_id, vector, word_count, comment_count, expires_at)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    vector        = VALUES(vector),
                    word_count    = VALUES(word_count),
                    comment_count = VALUES(comment_count),
                    recorded_at   = CURRENT_TIMESTAMP,
                    expires_at    = VALUES(expires_at)
            ");

            // Associate the vector with the first accepted fingerprint in this report batch.
            // A single report call typically covers one commenter.
            $fp_id_for_vec = $affected_fps[0];
            $upsert_vec->execute([
                $fp_id_for_vec,
                $site['id'],
                $vector_json,
                (int)($body['style_word_count']    ?? 0),
                (int)($body['style_comment_count'] ?? 0),
                $expires_date,
            ]);

            // Expire old vectors while we're here (opportunistic cleanup)
            $pdo->exec("DELETE FROM ste_style_vectors WHERE expires_at < CURDATE()");
        }
    }

    ste_json([
        'ok'          => true,
        'accepted'    => $accepted,
        'quarantined' => $quarantined,
        'site_weight' => $site_weight,
    ]);
}


// ── POST allow ────────────────────────────────────────────────────────────────
if ($route === 'allow' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $site      = ste_auth($pdo);
    $body      = ste_body();
    $ban_type  = $body['ban_type']      ?? '';
    $ban_value = strtolower(trim($body['ban_value'] ?? ''));
    $threshold = $body['site_threshold'] ?? 'red';

    if (!in_array($ban_type, ['fingerprint', 'ip', 'email_hash'], true)) {
        ste_json(['ok' => false, 'error' => 'Invalid ban_type.'], 400);
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $ban_value)) {
        ste_json(['ok' => false, 'error' => 'ban_value must be a 64-char SHA-256 hex string.'], 400);
    }
    if (!array_key_exists($threshold, STE_ALLOW_MULTIPLIERS)) {
        $threshold = 'red';
    }

    $site_weight = ste_site_weight($site);
    $fp_id       = ste_get_or_create_fingerprint($pdo, $ban_type, $ban_value);

    // Upsert allow vote (one per site per fingerprint)
    $pdo->prepare("
        INSERT INTO ste_allow_votes
            (site_id, fingerprint_id, voted_at, site_threshold, site_weight_at_vote)
        VALUES (?, ?, NOW(), ?, ?)
        ON DUPLICATE KEY UPDATE
            voted_at            = NOW(),
            site_threshold      = VALUES(site_threshold),
            site_weight_at_vote = VALUES(site_weight_at_vote)
    ")->execute([$site['id'], $fp_id, $threshold, $site_weight]);

    // Update allow count on fingerprint
    $pdo->prepare("
        UPDATE ste_fingerprints
        SET allow_count = (SELECT COUNT(*) FROM ste_allow_votes WHERE fingerprint_id = ?)
        WHERE id = ?
    ")->execute([$fp_id, $fp_id]);

    // Update site allow count
    $pdo->prepare("UPDATE ste_sites SET allow_count = allow_count + 1 WHERE id = ?")
        ->execute([$site['id']]);

    // Refresh this site's override rate
    ste_refresh_override_rate($pdo, $site['id']);

    $new_score = ste_recompute_score($pdo, $fp_id);

    ste_json([
        'ok'        => true,
        'new_score' => $new_score,
        'colour'    => ste_score_to_colour($new_score),
    ]);
}


// ── GET scores/delta ──────────────────────────────────────────────────────────
if ($route === 'scores/delta' && $_SERVER['REQUEST_METHOD'] === 'GET') {

    $site  = ste_auth($pdo);
    $since = trim($_GET['since'] ?? '');

    // Validate and sanitise timestamp
    if ($since && !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $since)) {
        $since = '';
    }

    if ($since) {
        $stmt = $pdo->prepare("
            SELECT f.ban_type, f.ban_value, c.score, c.colour_level, c.computed_at
            FROM ste_score_cache c
            JOIN ste_fingerprints f ON f.id = c.fingerprint_id
            WHERE c.computed_at > ?
            ORDER BY c.computed_at ASC
            LIMIT 5000
        ");
        $stmt->execute([$since]);
    } else {
        // No cursor — return full current green-or-above set (excludes pure green for bandwidth)
        $stmt = $pdo->query("
            SELECT f.ban_type, f.ban_value, c.score, c.colour_level, c.computed_at
            FROM ste_score_cache c
            JOIN ste_fingerprints f ON f.id = c.fingerprint_id
            WHERE c.colour_level != 'green'
            ORDER BY c.score DESC
            LIMIT 10000
        ");
    }

    $scores = $stmt->fetchAll();

    ste_json([
        'ok'         => true,
        'scores'     => $scores,
        'count'      => count($scores),
        'next_sync'  => date('Y-m-d H:i:s', strtotime('+1 hour')),
    ]);
}


// ── POST heartbeat ────────────────────────────────────────────────────────────
if ($route === 'heartbeat' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $site  = ste_auth($pdo);
    $body  = ste_body();
    $posts = (int)($body['post_count'] ?? $site['post_count']);

    $pdo->prepare("
        UPDATE ste_sites SET post_count = ?, last_seen_at = NOW() WHERE id = ?
    ")->execute([$posts, $site['id']]);

    // Refresh override rate on heartbeat (periodic maintenance)
    ste_refresh_override_rate($pdo, $site['id']);

    // Reload site to get fresh weight
    $site = $pdo->prepare("SELECT * FROM ste_sites WHERE id = ?")->execute([$site['id']])
                 ?: $site;
    $stmt = $pdo->prepare("SELECT * FROM ste_sites WHERE id = ?");
    $stmt->execute([$site['id']]);
    $site = $stmt->fetch() ?: $site;

    ste_json([
        'ok'          => true,
        'site_weight' => ste_site_weight($site),
        'post_count'  => $posts,
    ]);
}


// ── POST optout ───────────────────────────────────────────────────────────────
if ($route === 'optout' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $site = ste_auth($pdo);

    // Invalidate key and mark opted out
    $new_key = 'revoked_' . bin2hex(random_bytes(28)); // keeps UNIQUE constraint valid
    $pdo->prepare("
        UPDATE ste_sites SET status = 'opted_out', api_key = ? WHERE id = ?
    ")->execute([$new_key, $site['id']]);

    $token = bin2hex(random_bytes(16)); // opt-out confirmation token for admin records

    ste_json([
        'ok'               => true,
        'confirmation_token' => $token,
        'message'          => 'Opted out. Your API key has been revoked. Historical contributions are retained per the SMACKATTACK privacy policy.',
    ]);
}


// ── 404 ───────────────────────────────────────────────────────────────────────
ste_json(['ok' => false, 'error' => 'Unknown route: ' . htmlspecialchars($route)], 404);
