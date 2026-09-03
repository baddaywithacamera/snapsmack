<?php
/**
 * SNAPSMACK — photoblogs.fyi DIRECTORY API (hub side)  [0.7.559]
 *
 * Public JSON endpoint that receives directory listing opt-ins from spoke blogs
 * (posted by core/photoblogs-directory.php :: pbdir_submit).
 *
 * SECAUDIT 051: this endpoint has no login (any blog may opt in, and site_url is
 * public), so it must NOT trust the POST body. A forged POST for someone else's
 * site_url could otherwise deface a live listing or delist a site. Instead, the
 * hub VERIFIES domain ownership by calling the site back at /directory-verify.php
 * and reads the card straight from there — so the only thing a register/remove
 * can do is make the hub re-read that site's OWN truth:
 *   - register: honoured only if the site itself reports listed:true; the card is
 *     built from the site's data, never from the POST body.
 *   - remove:   honoured only if the site itself reports listed:false (or the
 *     verify endpoint is gone). A stranger cannot delist a site that still lists.
 * A brand-new listing lands 'pending' (human-reviewed first entry); a re-submit
 * from an already-approved site refreshes in place and stays active.
 *
 *   POST /directory-api.php?action=register   {site_url}
 *   POST /directory-api.php?action=remove     {site_url}
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

require_once __DIR__ . '/core/constants.php';
require_once __DIR__ . '/core/db.php';   // provides $pdo

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function pbdir_api_out($arr, int $code = 200): void {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_SLASHES);
    exit;
}

function pbdir_ensure_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS snap_directory_listings (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            site_url     VARCHAR(255) NOT NULL UNIQUE,
            host         VARCHAR(255) NOT NULL DEFAULT '',
            handle       VARCHAR(160) NOT NULL DEFAULT '',
            name         VARCHAR(160) NOT NULL DEFAULT '',
            description  VARCHAR(600) NOT NULL DEFAULT '',
            topics       TEXT NULL,
            avatar_url   VARCHAR(500) NOT NULL DEFAULT '',
            feed_url     VARCHAR(500) NOT NULL DEFAULT '',
            last_post_at DATETIME NULL,
            last_checked_at DATETIME NULL,
            last_success_at DATETIME NULL,
            feed_status  VARCHAR(20) NOT NULL DEFAULT 'unknown',
            feed_failures INT UNSIGNED NOT NULL DEFAULT 0,
            feed_etag VARCHAR(255) NOT NULL DEFAULT '',
            feed_last_modified VARCHAR(255) NOT NULL DEFAULT '',
            post_count INT UNSIGNED NULL,
            samples      TEXT NULL,
            state        ENUM('pending','active','hidden','removed') NOT NULL DEFAULT 'pending',
            submitted_at DATETIME NULL,
            updated_at   DATETIME NULL,
            KEY state_idx (state)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    foreach ([
        "ADD COLUMN IF NOT EXISTS feed_url VARCHAR(500) NOT NULL DEFAULT '' AFTER avatar_url",
        "ADD COLUMN IF NOT EXISTS last_post_at DATETIME NULL AFTER feed_url",
        "ADD COLUMN IF NOT EXISTS last_checked_at DATETIME NULL AFTER last_post_at",
        "ADD COLUMN IF NOT EXISTS last_success_at DATETIME NULL AFTER last_checked_at",
        "ADD COLUMN IF NOT EXISTS feed_status VARCHAR(20) NOT NULL DEFAULT 'unknown' AFTER last_success_at",
        "ADD COLUMN IF NOT EXISTS feed_failures INT UNSIGNED NOT NULL DEFAULT 0 AFTER feed_status",
        "ADD COLUMN IF NOT EXISTS feed_etag VARCHAR(255) NOT NULL DEFAULT '' AFTER feed_failures",
        "ADD COLUMN IF NOT EXISTS feed_last_modified VARCHAR(255) NOT NULL DEFAULT '' AFTER feed_etag",
        "ADD COLUMN IF NOT EXISTS post_count INT UNSIGNED NULL AFTER feed_last_modified",
    ] as $alter) {
        $pdo->exec("ALTER TABLE snap_directory_listings {$alter}");
    }
}

/**
 * Media URLs render inside a CSS url('...') on the public page, so reject any
 * value carrying a quote/paren/space (CSS breakout / tracking beacon) and require
 * a plain http(s) URL. Returns '' if unsafe.
 */
function pbdir_clean_media_url(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    if (strpbrk($url, "'\"()<>\\ \t\r\n") !== false) return '';
    if (!preg_match('~^https?://~i', $url)) return '';
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
}

/**
 * Fetch a site's /directory-verify.php with SSRF protection: pin the vetted
 * public IP, refuse redirects, https/http only, cap the body. Returns the decoded
 * array only when the endpoint proves it belongs to $site_url, else null.
 */
function pbdir_verify_site(string $site_url): ?array {
    if (!filter_var($site_url, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $site_url)) return null;
    $verify_url = $site_url . '/directory-verify.php';
    $p    = parse_url($verify_url);
    $host = (string)($p['host'] ?? '');
    if ($host === '') return null;
    if (in_array(strtolower($host), ['localhost', 'ip6-localhost', 'ip6-loopback', '::1', '0.0.0.0'], true)) return null;
    $port = (int)($p['port'] ?? (strtolower((string)($p['scheme'] ?? 'http')) === 'https' ? 443 : 80));
    $ip   = filter_var($host, FILTER_VALIDATE_IP) ? $host : (string)@gethostbyname($host);
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;

    $ch = curl_init($verify_url);
    curl_setopt_array($ch, [
        CURLOPT_RESOLVE        => ["{$host}:{$port}:{$ip}"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'SnapSmack-DirectoryVerify/1.0',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200) return null;

    $v = json_decode((string)substr((string)$body, 0, 20000), true);
    if (!is_array($v)) return null;

    // The endpoint must declare the SAME site_url it was fetched under.
    $claimed = filter_var(rtrim(trim((string)($v['site_url'] ?? '')), '/'), FILTER_VALIDATE_URL);
    if ($claimed !== $site_url) return null;
    return $v;
}

pbdir_ensure_table($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') pbdir_api_out(['error' => 'POST only'], 405);

$action = $_GET['action'] ?? '';
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) pbdir_api_out(['error' => 'bad json'], 400);

$site_url = filter_var(rtrim(trim((string)($in['site_url'] ?? '')), '/'), FILTER_VALIDATE_URL);
if (!$site_url || !preg_match('~^https?://~i', (string)$site_url)) {
    pbdir_api_out(['error' => 'valid site_url required'], 400);
}
$host = (string)parse_url($site_url, PHP_URL_HOST);

// ── REMOVE ────────────────────────────────────────────────────────────────
// Honour only if the site itself no longer reports as listed (or its verify
// endpoint is gone). This stops a stranger delisting a site that still lists.
if ($action === 'remove') {
    $v = pbdir_verify_site($site_url);
    if (is_array($v) && !empty($v['listed'])) {
        pbdir_api_out(['error' => 'site still reports itself as listed — refusing to remove'], 409);
    }
    $pdo->prepare("UPDATE snap_directory_listings SET state='removed', updated_at=NOW() WHERE site_url=?")
        ->execute([$site_url]);
    pbdir_api_out(['ok' => true, 'state' => 'removed']);
}
if ($action !== 'register') pbdir_api_out(['error' => 'unknown action'], 404);

// ── REGISTER ──────────────────────────────────────────────────────────────
// Verify domain ownership and read the card FROM THE SITE — never the POST body.
$v = pbdir_verify_site($site_url);
if ($v === null) {
    pbdir_api_out(['error' => 'could not verify site — /directory-verify.php must be reachable and match this site_url'], 400);
}
if (empty($v['listed'])) {
    pbdir_api_out(['error' => 'this site is not opted in to the directory'], 403);
}

$name   = mb_substr(trim((string)($v['name'] ?? $host)), 0, 120);
$handle = mb_substr(trim((string)($v['handle'] ?? '')), 0, 120);
$desc   = mb_substr(trim((string)($v['description'] ?? '')), 0, 500);
$avatar = pbdir_clean_media_url((string)($v['avatar_url'] ?? ''));
$feed   = rtrim((string)$site_url, '/') . '/rss.php';

$topics = [];
if (isset($v['topics']) && is_array($v['topics'])) {
    foreach ($v['topics'] as $t) { $t = trim((string)$t); if ($t !== '') $topics[] = mb_substr($t, 0, 40); }
}
$topics = array_slice($topics, 0, 12);

$samples = [];
if (isset($v['samples']) && is_array($v['samples'])) {
    foreach ($v['samples'] as $s) {
        $s = pbdir_clean_media_url((string)$s);
        if ($s !== '') $samples[] = $s;
    }
}
$samples = array_slice($samples, 0, 6);

// Card content is now proven to come from the site, so an already-approved
// listing may refresh in place and stay active; a first-time listing waits in
// 'pending' for the one-time human review of a new member.
$stmt = $pdo->prepare("SELECT state FROM snap_directory_listings WHERE site_url=?");
$stmt->execute([$site_url]);
$prev  = $stmt->fetchColumn();
$state = ($prev === 'active') ? 'active' : 'pending';

$pdo->prepare(
    "INSERT INTO snap_directory_listings
        (site_url, host, handle, name, description, topics, avatar_url, feed_url, samples, state, submitted_at, updated_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
     ON DUPLICATE KEY UPDATE
        host=VALUES(host), handle=VALUES(handle), name=VALUES(name), description=VALUES(description),
        topics=VALUES(topics), avatar_url=VALUES(avatar_url), feed_url=VALUES(feed_url), samples=VALUES(samples),
        state=VALUES(state), updated_at=NOW()"
)->execute([$site_url, $host, $handle, $name, $desc, json_encode($topics), $avatar, $feed, json_encode($samples), $state]);

pbdir_api_out(['ok' => true, 'state' => $state]);
// ===== SNAPSMACK EOF =====
