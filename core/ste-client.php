<?php
/**
 * SNAPSMACK - SMACK THE ENEMY Client
 *
 * HTTP client for the SMACK THE ENEMY network reputation API.
 * All communication with the central server goes through these functions.
 * Register once; all subsequent calls use the stored api_key Bearer token.
 */

define('STE_API_URL', 'https://snapsmack.ca/smack-central/sc-enemy-api.php');

// Score thresholds — must match the server-side constants in sc-enemy-scoring.php
define('STE_SCORE_YELLOW', 1.0);
define('STE_SCORE_ORANGE', 3.0);
define('STE_SCORE_RED',    6.0);
define('STE_SCORE_BLACK', 10.0);

// Colour level ordering for threshold comparison
const STE_COLOUR_ORDER = ['green' => 0, 'yellow' => 1, 'orange' => 2, 'red' => 3, 'black' => 4];

// ── Internal HTTP helper ──────────────────────────────────────────────────────

/**
 * POST or GET to the STE API. Returns decoded JSON array or ['ok'=>false,'error'=>...].
 */
function _ste_request(string $method, string $route, array $body = [], string $api_key = ''): array {
    $url = STE_API_URL . '?route=' . urlencode($route);

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($api_key !== '') {
        $headers[] = 'Authorization: Bearer ' . $api_key;
    }

    $opts = [
        'http' => [
            'method'        => strtoupper($method),
            'header'        => implode("\r\n", $headers),
            'content'       => $method !== 'GET' ? json_encode($body) : '',
            'timeout'       => 8,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ];

    $ctx  = stream_context_create($opts);
    $raw  = @file_get_contents($url, false, $ctx);

    if ($raw === false) {
        return ['ok' => false, 'error' => 'Could not reach the SMACK THE ENEMY server.'];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Invalid response from server.'];
    }

    return $decoded;
}

// ── Public API ────────────────────────────────────────────────────────────────

/**
 * Register this site with SMACK THE ENEMY.
 * Idempotent — re-registering the same site URL returns the existing key.
 * Returns ['ok'=>true,'api_key'=>'...'] or ['ok'=>false,'error'=>'...'].
 */
function ste_client_register(string $site_url, string $display_name, int $post_count): array {
    $res = _ste_request('POST', 'register', [
        'site_url'     => $site_url,
        'display_name' => $display_name,
        'post_count'   => $post_count,
        'ss_version'   => defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : '0',
    ]);

    if (!isset($res['api_key'])) {
        return ['ok' => false, 'error' => $res['error'] ?? 'Registration failed.'];
    }

    return ['ok' => true, 'api_key' => $res['api_key']];
}

/**
 * Report one or more ban hashes to the network.
 * $bans = [['ban_type'=>'ip','ban_value'=>'sha256hex'], ...]  (max 500)
 * Values must be raw strings — this function hashes them.
 */
function ste_client_report(string $api_key, array $bans): array {
    if (empty($bans) || $api_key === '') return ['ok' => false, 'error' => 'No key or bans.'];

    $payload = [];
    foreach ($bans as $b) {
        if (empty($b['ban_type']) || empty($b['ban_value'])) continue;
        $payload[] = [
            'ban_type'  => $b['ban_type'],
            'ban_hash'  => hash('sha256', strtolower(trim($b['ban_value']))),
        ];
    }

    if (empty($payload)) return ['ok' => false, 'error' => 'No valid bans to report.'];

    return _ste_request('POST', 'report', ['bans' => $payload], $api_key);
}

/**
 * Submit an allow vote — this commenter was wrongly flagged on this site.
 * Reduces the fingerprint's network score.
 */
function ste_client_allow(string $api_key, string $ban_type, string $ban_value): array {
    if ($api_key === '') return ['ok' => false, 'error' => 'No API key.'];

    return _ste_request('POST', 'allow', [
        'ban_type' => $ban_type,
        'ban_hash' => hash('sha256', strtolower(trim($ban_value))),
    ], $api_key);
}

/**
 * Fetch score updates since the last cursor timestamp.
 * Stores new scores in snap_ste_scores and advances the cursor in snap_settings.
 * Pass $cursor = '' to get the full non-green set (first sync).
 * Returns number of scores updated, or false on failure.
 */
function ste_client_fetch_delta(PDO $pdo, string $api_key, string $cursor = ''): int|false {
    if ($api_key === '') return false;

    $params = $cursor !== '' ? ['since' => $cursor] : [];
    $url    = STE_API_URL . '?route=scores/delta' . ($cursor !== '' ? '&since=' . urlencode($cursor) : '');

    // Build GET request manually since _ste_request handles body, not query params
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $api_key,
    ];
    $opts = [
        'http' => ['method' => 'GET', 'header' => implode("\r\n", $headers), 'timeout' => 10, 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ];
    $raw = @file_get_contents($url, false, stream_context_create($opts));
    if ($raw === false) return false;

    $res = json_decode($raw, true);
    if (!is_array($res) || empty($res['scores'])) return 0;

    $stmt = $pdo->prepare("
        INSERT INTO snap_ste_scores (ban_type, ban_hash, score, colour_level, last_updated)
        VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            score        = VALUES(score),
            colour_level = VALUES(colour_level),
            last_updated = NOW()
    ");

    $count = 0;
    foreach ($res['scores'] as $row) {
        if (empty($row['ban_type']) || empty($row['ban_hash'])) continue;
        $stmt->execute([
            $row['ban_type'],
            $row['ban_hash'],
            (float)($row['score'] ?? 0),
            $row['colour_level'] ?? 'green',
        ]);
        $count++;
    }

    // Advance cursor
    $new_cursor = $res['as_of'] ?? date('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('ste_scores_cursor', ?)
                   ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")
        ->execute([$new_cursor]);

    return $count;
}

/**
 * Send a heartbeat to keep registration alive and update post count.
 */
function ste_client_heartbeat(string $api_key, int $post_count): bool {
    if ($api_key === '') return false;
    $res = _ste_request('POST', 'heartbeat', ['post_count' => $post_count], $api_key);
    return !empty($res['ok']);
}

// ── Score lookup helpers ──────────────────────────────────────────────────────

/**
 * Look up the locally-cached colour level for a raw value.
 * Returns 'green' if not found (assume clean until proven otherwise).
 */
function ste_get_colour(PDO $pdo, string $ban_type, string $ban_value): string {
    $hash = hash('sha256', strtolower(trim($ban_value)));
    $row  = $pdo->prepare("SELECT colour_level FROM snap_ste_scores WHERE ban_type = ? AND ban_hash = ? LIMIT 1");
    $row->execute([$ban_type, $hash]);
    return $row->fetchColumn() ?: 'green';
}

/**
 * Get the worst (highest) colour level from multiple ban types for a single submitter.
 * Pass an array of ['ban_type'=>..., 'ban_value'=>...] pairs.
 */
function ste_worst_colour(PDO $pdo, array $checks): string {
    $worst = 0;
    $level = 'green';
    foreach ($checks as $c) {
        if (empty($c['ban_type']) || empty($c['ban_value'])) continue;
        $col  = ste_get_colour($pdo, $c['ban_type'], $c['ban_value']);
        $rank = STE_COLOUR_ORDER[$col] ?? 0;
        if ($rank > $worst) {
            $worst = $rank;
            $level = $col;
        }
    }
    return $level;
}

/**
 * Return true if $colour_level meets or exceeds the configured auto-ban threshold.
 * Threshold options: 'never', 'black', 'red', 'orange', 'yellow'.
 */
function ste_exceeds_threshold(string $colour_level, string $threshold): bool {
    if ($threshold === 'never' || $threshold === '') return false;
    $order = STE_COLOUR_ORDER;
    return ($order[$colour_level] ?? 0) >= ($order[$threshold] ?? 99);
}
