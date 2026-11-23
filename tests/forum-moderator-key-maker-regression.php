<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// SS-1510: per-person keys are one-time, hashed, revocable and moderation-only.

$root = dirname(__DIR__);
$failures = [];
$assertions = 0;
$check = static function (string $what, bool $ok) use (&$failures, &$assertions): void {
    $assertions++;
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if (!$ok) $failures[] = $what;
};

define('FORUM_MOD_KEY', 'mod_owner_test_secret');
require_once $root . '/projects/forum-server/api/forum/moderator-keys.php';
require_once $root . '/projects/forum-server/api/forum/mod-log.php';

echo "SS-1510: per-person moderator key maker\n\n";

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    "CREATE TABLE ss_forum_moderator_keys (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        key_id TEXT NOT NULL UNIQUE,
        label TEXT NOT NULL,
        secret_hash TEXT NOT NULL,
        revoked_at TEXT NULL,
        last_used_at TEXT NULL,
        last_used_ip TEXT NOT NULL DEFAULT ''
    )"
);
$keyId = '0123456789abcdef';
$secret = str_repeat('a1', 32);
$pdo->prepare("INSERT INTO ss_forum_moderator_keys (key_id,label,secret_hash) VALUES (?,?,?)")
    ->execute([$keyId, 'Jane — weekend moderator', forum_moderator_key_hash($secret)]);

$check('a valid 256-bit per-person key authenticates',
    is_array($actor = forum_authenticate_moderator_key($pdo, 'modp_' . $keyId . '_' . $secret)));
$check('the actor is moderation-only and carries a stable key identity',
    ($actor['role'] ?? 0) === 2
    && ($actor['auth_kind'] ?? '') === 'moderator_key'
    && ($actor['moderator_key_id'] ?? '') === $keyId
    && !isset($actor['id']));
$check('use updates bounded last-used evidence',
    (string)$pdo->query("SELECT last_used_at FROM ss_forum_moderator_keys")->fetchColumn() !== '');
$pdo->exec(
    "CREATE TABLE ss_forum_mod_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        actor_install_id INTEGER NULL,
        actor_domain TEXT NOT NULL,
        actor_is_god INTEGER NOT NULL,
        action TEXT NOT NULL,
        target_type TEXT NOT NULL,
        target_id INTEGER NULL,
        detail TEXT NOT NULL
    )"
);
$check('a per-person moderation action is attributable without inventing an install',
    forum_log_mod_action($pdo, $actor, 'thread.pinlock', 'thread', 42, 'pinned=1') === true);
$audit = $pdo->query("SELECT actor_install_id, actor_domain, actor_is_god FROM ss_forum_mod_log")->fetch(PDO::FETCH_ASSOC);
$check('the audit row names the person and key ID without calling it the owner key',
    $audit['actor_install_id'] === null
    && str_contains((string)$audit['actor_domain'], 'Jane — weekend moderator#' . $keyId)
    && (int)$audit['actor_is_god'] === 0);
$check('a wrong secret fails',
    forum_authenticate_moderator_key($pdo, 'modp_' . $keyId . '_' . str_repeat('b2', 32)) === null);
$check('a malformed token fails', forum_authenticate_moderator_key($pdo, 'modp_bad') === null);
$pdo->exec("UPDATE ss_forum_moderator_keys SET revoked_at = CURRENT_TIMESTAMP");
$check('revocation takes effect on the next request',
    forum_authenticate_moderator_key($pdo, 'modp_' . $keyId . '_' . $secret) === null);

$api = (string)file_get_contents($root . '/projects/forum-server/api/forum/index.php');
$ui = (string)file_get_contents($root . '/smack-central/sc-forum.php');
$schema = (string)file_get_contents($root . '/projects/forum-server/forum-schema.sql');
$migration = (string)file_get_contents($root . '/projects/forum-server/forum-schema-v7-migration.sql');
$scSchema = (string)file_get_contents($root . '/smack-central/schemas/sc-forum-canonical.sql');
$route = static function (string $source, string $start, string $next): string {
    $at = strpos($source, $start);
    if ($at === false) return '';
    $end = strpos($source, $next, $at + strlen($start));
    return $end === false ? substr($source, $at) : substr($source, $at, $end - $at);
};

$check('the API loads the dedicated key authenticator',
    str_contains($api, "require_once __DIR__ . '/moderator-keys.php'"));
$check('ordinary authentication rejects moderator keys unless a route opts in',
    str_contains($api, 'function require_auth(bool $allow_moderator_key = false)')
    && str_contains($api, "api_error(403, 'MODERATOR_SCOPE'"));
$check('read and moderation routes opt in',
    substr_count($api, 'require_auth(true)') >= 13);
$check('posting, replying, reacting, board and install administration do not opt in',
    !str_contains($route($api, '// POST /threads', '// PATCH /threads/{id}'), 'require_auth(true)')
    && !str_contains($route($api, '// POST /threads/{id}/react', '// POST /replies/{id}/react'), 'require_auth(true)')
    && !str_contains($route($api, '// POST /categories', '// PATCH /categories/{id}'), 'require_auth(true)')
    && !str_contains($route($api, '// GET /installs', '// PATCH /installs/{id}/role'), 'require_auth(true)'));
$check('/me tells clients the exact narrow capability',
    str_contains($api, "['post' => false, 'moderate' => true, 'administer' => false]"));

$check('the owner UI generates 256 random bits and stores only an HMAC digest',
    str_contains($ui, '$secret = bin2hex(random_bytes(32));')
    && str_contains($ui, "hash_hmac('sha256', \$secret, \$pepper)")
    && !preg_match('/\bsecret\b\s+(?:TEXT|VARCHAR|CHAR)/i', $schema));
$check('the complete key is shown from response memory and never stored in the session',
    str_contains($ui, "'token' => 'modp_' . \$key_id . '_' . \$secret")
    && !str_contains($ui, "\$_SESSION['sc_issued_moderator_key']"));
$check('issue and revoke both require the session CSRF token',
    substr_count($ui, "hash_equals(\$forum_key_csrf") === 2
    && substr_count($ui, 'name="csrf"') === 2);
$check('issue and revoke both append audit events',
    str_contains($ui, "'moderator_key.issue'")
    && str_contains($ui, "'moderator_key.revoke'")
    && !preg_match('/sc_forum_log_key_event\([^;]+\$secret/s', $ui));
$check('the list selects metadata but never the digest',
    str_contains($ui, 'SELECT id, key_id, label, created_at, revoked_at, last_used_at')
    && !str_contains($ui, 'SELECT id, key_id, label, secret_hash'));

foreach ([$schema, $migration] as $i => $sql) {
    $check(($i ? 'migration' : 'canonical schema') . ' creates hashed, revocable key storage',
        str_contains($sql, 'CREATE TABLE IF NOT EXISTS ss_forum_moderator_keys')
        && str_contains($sql, 'secret_hash')
        && str_contains($sql, 'revoked_at'));
}
$check('Smack Central Schema Manager can see and create the key table',
    str_contains($scSchema, 'CREATE TABLE IF NOT EXISTS `ss_forum_moderator_keys`'));
$check('the global owner key is labelled break-glass and not for sharing',
    str_contains((string)file_get_contents($root . '/projects/forum-server/README.md'), 'owner-only break-glass key'));

echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1510 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $failure) fwrite(STDERR, "  - {$failure}\n");
    exit(1);
}
echo "SS-1510 PASS — {$assertions} checks cover authentication, revocation, fixed scope,\n";
echo "one-time display, hashed storage, CSRF protection and audit events.\n";

// ===== SNAPSMACK EOF =====
