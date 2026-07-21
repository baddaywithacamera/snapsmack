<?php
// SNAPSMACK_EOF_HEADER: this file MUST end with // ===== SNAPSMACK EOF =====
/**
 * PHOTOFRI.DAY — DB + settings + config helpers. Standalone: no dependency
 * on a CMS install.
 */

function pfd_config(): array {
    static $c = null;
    if ($c === null) {
        $path = dirname(__DIR__) . '/config.php';
        $c = is_file($path) ? (require $path) : [];
        if (!is_array($c)) $c = [];
    }
    return $c;
}

function pfd_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $c = pfd_config();
        $pdo = new PDO(
            'mysql:host=' . ($c['db_host'] ?? '127.0.0.1')
                . ';dbname=' . ($c['db_name'] ?? 'photofri_day') . ';charset=utf8mb4',
            $c['db_user'] ?? 'root',
            $c['db_pass'] ?? '',
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
    return $pdo;
}

/** Public base URL, trailing slash. */
function pfd_base(): string {
    $c = pfd_config();
    return 'https://' . ($c['domain'] ?? 'photofri.day') . '/';
}

function pfd_actor_url(): string { return pfd_base() . 'actor'; }
function pfd_inbox_url(): string { return pfd_base() . 'inbox'; }
function pfd_key_id():   string { return pfd_actor_url() . '#main-key'; }

function pfd_setting(string $k, ?string $default = null): ?string {
    try {
        $s = pfd_db()->prepare("SELECT v FROM pfd_settings WHERE k = ? LIMIT 1");
        $s->execute([$k]);
        $v = $s->fetchColumn();
        return $v === false ? $default : (string)$v;
    } catch (Throwable $e) { return $default; }
}

function pfd_set(string $k, string $v): void {
    pfd_db()->prepare(
        "INSERT INTO pfd_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)"
    )->execute([$k, $v]);
}

/** Bounded inbound diagnostic ring — verb/actor/object/outcome, newest kept. */
function pfd_log(string $verb, string $actor, ?string $obj, string $outcome): void {
    try {
        pfd_db()->prepare(
            "INSERT INTO pfd_inbox_log (verb, actor_url, object_ref, outcome) VALUES (?,?,?,?)"
        )->execute([
            substr($verb, 0, 40), substr($actor, 0, 500),
            $obj !== null ? substr($obj, 0, 500) : null, substr($outcome, 0, 190),
        ]);
        pfd_db()->exec(
            "DELETE FROM pfd_inbox_log WHERE id < (
                SELECT * FROM (SELECT MAX(id) - 1000 FROM pfd_inbox_log) AS t
            )"
        );
    } catch (Throwable $e) { /* never fatal */ }
}

/**
 * Load schema.sql on first boot (idempotent, guarded by a flag). Deploy can also
 * pre-load it (`mysql < schema.sql`); this makes a bare CT self-heal on first hit.
 */
function pfd_ensure_schema(): void {
    try { if (pfd_setting('schema_ready', '') === '1') return; }
    catch (Throwable $e) { /* pfd_settings not created yet — fall through */ }
    $sql = @file_get_contents(dirname(__DIR__) . '/schema.sql');
    if ($sql !== false && $sql !== '') {
        foreach (explode(';', $sql) as $stmt) {
            $stmt = trim(preg_replace('/^\s*--.*$/m', '', (string)$stmt));
            if ($stmt === '') continue;
            try { pfd_db()->exec($stmt); } catch (Throwable $e) { /* ignore */ }
        }
    }
    try { pfd_set('schema_ready', '1'); } catch (Throwable $e) { /* ignore */ }
}
// ===== SNAPSMACK EOF =====
