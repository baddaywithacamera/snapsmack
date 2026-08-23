<?php
/**
 * SNAPSMACK — photoblogs.fyi DIRECTORY API (hub side)  [0.7.547]
 *
 * Public JSON endpoint that receives directory listing opt-ins from spoke blogs
 * (posted by core/photoblogs-directory.php :: pbdir_submit). A new listing is
 * stored as 'pending' and shows on the public directory only after the hub admin
 * approves it — mirroring fediverse.info's model and the for-admins promise that
 * "new members' first entries are reviewed by a human." Re-submitting an already
 * approved listing keeps it active and just refreshes the details.
 *
 *   POST /directory-api.php?action=register   {site_url, handle, name, ...}
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
            samples      TEXT NULL,
            state        ENUM('pending','active','hidden','removed') NOT NULL DEFAULT 'pending',
            submitted_at DATETIME NULL,
            updated_at   DATETIME NULL,
            KEY state_idx (state)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
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

if ($action === 'remove') {
    $pdo->prepare("UPDATE snap_directory_listings SET state='removed', updated_at=NOW() WHERE site_url=?")
        ->execute([$site_url]);
    pbdir_api_out(['ok' => true, 'state' => 'removed']);
}
if ($action !== 'register') pbdir_api_out(['error' => 'unknown action'], 404);

$name   = mb_substr(trim((string)($in['name'] ?? $host)), 0, 120);
$handle = mb_substr(trim((string)($in['handle'] ?? '')), 0, 120);
$desc   = mb_substr(trim((string)($in['description'] ?? '')), 0, 500);
$avatar = filter_var(trim((string)($in['avatar_url'] ?? '')), FILTER_VALIDATE_URL) ?: '';

$topics = [];
if (isset($in['topics']) && is_array($in['topics'])) {
    foreach ($in['topics'] as $t) { $t = trim((string)$t); if ($t !== '') $topics[] = mb_substr($t, 0, 40); }
}
$topics = array_slice($topics, 0, 12);

$samples = [];
if (isset($in['samples']) && is_array($in['samples'])) {
    foreach ($in['samples'] as $s) { $s = filter_var(trim((string)$s), FILTER_VALIDATE_URL); if ($s) $samples[] = $s; }
}
$samples = array_slice($samples, 0, 6);

// Keep an already-approved listing active on re-submit; new ones start pending.
$stmt = $pdo->prepare("SELECT state FROM snap_directory_listings WHERE site_url=?");
$stmt->execute([$site_url]);
$prev  = $stmt->fetchColumn();
$state = ($prev === 'active') ? 'active' : 'pending';

$pdo->prepare(
    "INSERT INTO snap_directory_listings
        (site_url, host, handle, name, description, topics, avatar_url, samples, state, submitted_at, updated_at)
     VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())
     ON DUPLICATE KEY UPDATE
        host=VALUES(host), handle=VALUES(handle), name=VALUES(name), description=VALUES(description),
        topics=VALUES(topics), avatar_url=VALUES(avatar_url), samples=VALUES(samples),
        state=VALUES(state), updated_at=NOW()"
)->execute([$site_url, $host, $handle, $name, $desc, json_encode($topics), $avatar, json_encode($samples), $state]);

pbdir_api_out(['ok' => true, 'state' => $state]);
// ===== SNAPSMACK EOF =====
