<?php
/**
 * SNAPSMACK - Database Schema Manager
 *
 * Diffs the live snap_* database against snapsmack_canonical.sql and can
 * apply missing tables and columns via ALTER TABLE / CREATE TABLE.
 *
 * Adds missing tables and columns, and corrects wrong column types or
 * nullability via MODIFY COLUMN. The automatic sync never drops anything.
 * A separate "Schema Drift" report surfaces tables/columns that exist in the
 * live DB but NOT in canonical, and a password + 2FA gated "Prune Debris"
 * action can remove them. Existing row data is never modified by the sync.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once 'core/auth-smack.php';
require_once 'core/schema-sync.php';   // snap_schema_drift() + snap_schema_prune() — reverse-diff debris detection
require_once 'core/reauth.php';        // step-up auth (password + 2FA) for the destructive prune

$page_title = 'Database Schema';

// ── Type/nullable helpers ─────────────────────────────────────────────────────

/**
 * Normalize a type string for comparison.
 * Accepts either a raw COLUMN_TYPE value ('varchar(500)') or a full
 * canonical column-def line ('`title` text COLLATE ... NOT NULL').
 * Returns lowercase, whitespace-collapsed type token.
 * Strips MySQL 5.x integer display widths (e.g. int(11) → int) because
 * MySQL 8+ omits them in INFORMATION_SCHEMA; preserves tinyint(1).
 */
function snap_normalize_col_type(string $input): string {
    // Strip leading `col_name` if present (full def line passed in)
    $s = preg_replace('/^`\w+`\s+/i', '', trim($input));
    if (preg_match('/^(\w+(?:\s*\([^)]+\))?(?:\s+UNSIGNED|\s+SIGNED)?)/i', $s, $m)) {
        $t = strtolower(preg_replace('/\s+/', ' ', trim($m[1])));
    } else {
        $t = strtolower(strtok(trim($s), ' ') ?: $s);
    }
    // Strip integer display widths (except tinyint(1) — used as boolean marker)
    if ($t !== 'tinyint(1)') {
        $t = preg_replace(
            '/^(bigint|int|mediumint|smallint|tinyint)\(\d+\)(\s+unsigned)?$/',
            '$1$2', $t
        );
        $t = trim($t);
    }
    return $t;
}

/** Extract type token from a canonical column def line. */
function snap_col_type(string $col_def): string {
    return snap_normalize_col_type($col_def);
}

/** Return true if the column is nullable (no NOT NULL clause). */
function snap_col_nullable(string $col_def): bool {
    return !preg_match('/\bNOT\s+NULL\b/i', $col_def);
}

/**
 * Returns true if two normalised type strings are platform-equivalent.
 * MariaDB stores JSON as LONGTEXT internally; INFORMATION_SCHEMA reports
 * 'longtext' for JSON columns. Treating them as equivalent prevents phantom
 * wrong-type alerts on MariaDB installs.
 */
if (!function_exists('snap_types_equivalent')) {
    // Provided by core/schema-sync.php; guarded so this page still works if that
    // engine file is ever absent. Identical definition.
    function snap_types_equivalent(string $a, string $b): bool {
        if ($a === $b) return true;
        static $json_compat = ['json', 'longtext'];
        return in_array($a, $json_compat, true) && in_array($b, $json_compat, true);
    }
}

// ── Schema parser ─────────────────────────────────────────────────────────────
// Parse canonical SQL into ['table_name' => ['columns' => [...], 'col_meta' => [...], 'sql' => '...']]
// Mirrors sc-schema.php logic exactly.

function snap_schema_parse(string $path): array|false {
    if (!file_exists($path)) return false;
    $sql = file_get_contents($path);
    $sql = str_replace("\r\n", "\n", $sql);
    $sql = preg_replace('/--[^\n]*/', '', $sql);

    $tables     = [];
    $statements = preg_split('/;\s*\n/', $sql);

    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if (!preg_match(
            '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\((.+)\)\s*ENGINE\s*=[^;]*/si',
            $stmt, $m
        )) continue;

        $name = $m[1];
        $body = $m[2];
        $full = $stmt . ';';

        $columns  = [];
        $col_meta = [];
        foreach (explode("\n", $body) as $raw_line) {
            $line = trim($raw_line, " \t,");
            if (preg_match(
                '/^`?(\w+)`?\s+((?:INT|BIGINT|TINYINT|SMALLINT|MEDIUMINT|'
                . 'VARCHAR|CHAR|TEXT|MEDIUMTEXT|LONGTEXT|'
                . 'DECIMAL|FLOAT|DOUBLE|'
                . 'TIMESTAMP|DATETIME|DATE|TIME|YEAR|'
                . 'ENUM|SET|JSON|BLOB|MEDIUMBLOB|LONGBLOB)\b.*)/i',
                $line, $cm
            )) {
                $columns[$cm[1]]  = $line;
                $col_meta[$cm[1]] = [
                    'type'     => snap_col_type($line),
                    'nullable' => snap_col_nullable($line),
                ];
            }
        }

        $tables[$name] = ['columns' => $columns, 'col_meta' => $col_meta, 'sql' => $full];
    }

    return $tables;
}

// ── Live schema reader ─────────────────────────────────────────────────────────
// Returns ['table_name' => ['col1', 'col2', ...], ...]

function snap_schema_live(PDO $pdo): array {
    try {
        $db_name = $pdo->query("SELECT DATABASE()")->fetchColumn();
        $stmt    = $pdo->prepare("
            SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ?
            ORDER BY TABLE_NAME ASC, ORDINAL_POSITION ASC
        ");
        $stmt->execute([$db_name]);
        $schema = [];
        foreach ($stmt->fetchAll() as $row) {
            $schema[$row['TABLE_NAME']][$row['COLUMN_NAME']] = [
                'type'     => snap_normalize_col_type($row['COLUMN_TYPE']),
                'nullable' => ($row['IS_NULLABLE'] === 'YES'),
            ];
        }
        return $schema;
    } catch (Exception $e) {
        return [];
    }
}

// ── Diff ──────────────────────────────────────────────────────────────────────

function snap_schema_diff(array $canonical, array $live): array {
    $missing_tables  = [];
    $missing_columns = [];
    $wrong_type      = [];
    $ok_tables       = [];

    foreach ($canonical as $table => $def) {
        if (!isset($live[$table])) {
            $missing_tables[$table] = $def['sql'];
        } else {
            $live_cols           = $live[$table];
            $missing_in_table    = [];
            $wrong_type_in_table = [];
            $ok_cols             = [];
            foreach ($def['columns'] as $col => $col_def) {
                if (!isset($live_cols[$col])) {
                    $missing_in_table[$col] = $col_def;
                } else {
                    $can_type     = $def['col_meta'][$col]['type']     ?? '';
                    $can_nullable = $def['col_meta'][$col]['nullable']  ?? true;
                    $live_type    = $live_cols[$col]['type'];
                    $live_nullable= $live_cols[$col]['nullable'];
                    if (!snap_types_equivalent($live_type, $can_type) || $live_nullable !== $can_nullable) {
                        $wrong_type_in_table[$col] = [
                            'def'          => $col_def,
                            'live_type'    => $live_type,
                            'live_nullable'=> $live_nullable,
                            'can_type'     => $can_type,
                            'can_nullable' => $can_nullable,
                        ];
                    } else {
                        $ok_cols[] = $col;
                    }
                }
            }
            if ($missing_in_table)    $missing_columns[$table] = $missing_in_table;
            if ($wrong_type_in_table) $wrong_type[$table]      = $wrong_type_in_table;
            $ok_tables[$table] = $ok_cols;
        }
    }

    return compact('missing_tables', 'missing_columns', 'wrong_type', 'ok_tables');
}

// ── PHP reference audit ───────────────────────────────────────────────────────
// Scans PHP files on disk for snap_* SQL references and returns any that are
// absent from the canonical schema. Same logic as tools/check-schema.php.

function snap_schema_audit_php(string $root, array $canonical_tables): array {
    $skip    = ['smack-central', 'vendor', 'node_modules', '.git'];
    $pattern =
        '/\b(?:FROM|JOIN|INTO|UPDATE|ALTER\s+TABLE|TRUNCATE(?:\s+TABLE)?|' .
        'CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|DROP\s+TABLE(?:\s+IF\s+EXISTS)?)' .
        '\s+[`"]?(snap_[a-z_]+)[`"]?/i';

    $refs = [];
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                function ($file, $key, $it) use ($skip) {
                    if ($it->hasChildren()) {
                        return !in_array($file->getFilename(), $skip, true);
                    }
                    return $file->getExtension() === 'php';
                }
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            $src = @file_get_contents($file->getPathname());
            if ($src === false) continue;
            preg_match_all($pattern, $src, $m);
            foreach ($m[1] as $t) {
                $refs[strtolower($t)] = true;
            }
        }
    } catch (Exception $e) {
        // Scan failed — return empty; caller treats as inconclusive
        return [];
    }

    $missing = [];
    foreach (array_keys($refs) as $table) {
        if (!isset($canonical_tables[$table])) {
            $missing[] = $table;
        }
    }
    sort($missing);
    return $missing;
}

// ── DDL builder ───────────────────────────────────────────────────────────────

function snap_schema_build_ddl(array $diff): array {
    $stmts = [];

    foreach ($diff['missing_tables'] as $table => $create_sql) {
        $stmts[] = $create_sql;
    }

    foreach ($diff['missing_columns'] as $table => $cols) {
        foreach ($cols as $col => $def) {
            $def_clean = preg_replace('/\s+COMMENT\s+\'[^\']*\'/i', '', $def);
            $type_part = trim(preg_replace('/^`?' . preg_quote($col, '/') . '`?\s+/i', '', $def_clean));
            $stmts[]   = "ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$type_part};";
        }
    }

    foreach ($diff['wrong_type'] ?? [] as $table => $cols) {
        foreach ($cols as $col => $info) {
            $def_clean = preg_replace('/\s+COMMENT\s+\'[^\']*\'/i', '', $info['def']);
            $type_part = trim(preg_replace('/^`?' . preg_quote($col, '/') . '`?\s+/i', '', $def_clean));
            $stmts[]   = "ALTER TABLE `{$table}` MODIFY COLUMN `{$col}` {$type_part};";
        }
    }

    return $stmts;
}

// ── POST: Apply ───────────────────────────────────────────────────────────────

$apply_results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['apply_schema'])) {
    $canonical_path = __DIR__ . '/database/schema/snapsmack_canonical.sql';
    $canonical      = snap_schema_parse($canonical_path);

    if ($canonical !== false) {
        try {
            $live  = snap_schema_live($pdo);
            $diff  = snap_schema_diff($canonical, $live);
            $stmts = snap_schema_build_ddl($diff);

            if (empty($stmts)) {
                $apply_results = [['ok', 'Schema is already up to date. Nothing to apply.']];
            } else {
                foreach ($stmts as $stmt) {
                    try {
                        $pdo->exec($stmt);
                        $apply_results[] = ['ok', $stmt];
                    } catch (PDOException $e) {
                        $apply_results[] = ['err', $stmt . ' — ' . $e->getMessage()];
                    }
                }
            }
        } catch (Exception $e) {
            $apply_results = [['err', 'Error: ' . $e->getMessage()]];
        }
    } else {
        $apply_results = [['err', 'Canonical schema file not found.']];
    }

    // PRG: store results in session and redirect
    session_start();
    $_SESSION['snap_schema_apply'] = $apply_results;
    header('Location: smack-schema.php?applied=1');
    exit;
}

// ── POST: Prune schema debris (DESTRUCTIVE) ────────────────────────────────────
// Drops live tables/columns that are NOT in canonical. Step-up gated: requires
// the admin's password AND a valid 2FA code (no password-only path; an unenrolled
// admin is told to set up 2FA) — see [[feedback_stepup_auth_pass_plus_2fa]].
// snap_schema_prune() re-verifies drift at execution time and validates every
// identifier, so nothing canonical can ever be dropped.
$prune_results = [];
$prune_error   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['prune_schema'])) {
    $ra = reauth_verify($pdo, (string)($_POST['reauth_password'] ?? ''), (string)($_POST['reauth_totp'] ?? ''));
    if (!$ra['ok']) {
        $prune_error = $ra['error'];
    } else {
        try {
            $d   = snap_schema_drift($pdo);
            $res = snap_schema_prune($pdo, $d['extra_tables'], $d['extra_columns']);
            if (session_status() === PHP_SESSION_NONE) session_start();
            $_SESSION['snap_schema_prune'] = $res;
            header('Location: smack-schema.php?pruned=1');
            exit;
        } catch (Throwable $e) {
            $prune_error = 'Prune failed: ' . $e->getMessage();
        }
    }
}

// Retrieve apply results after redirect
if (!empty($_GET['applied'])) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['snap_schema_apply'])) {
        $apply_results = $_SESSION['snap_schema_apply'];
        unset($_SESSION['snap_schema_apply']);
    }
}

// Retrieve prune results after redirect
if (!empty($_GET['pruned'])) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['snap_schema_prune'])) {
        $prune_results = $_SESSION['snap_schema_prune'];
        unset($_SESSION['snap_schema_prune']);
    }
}

// ── Build diff for display ────────────────────────────────────────────────────

$canonical_path = __DIR__ . '/database/schema/snapsmack_canonical.sql';
$canonical      = snap_schema_parse($canonical_path);
$diff           = null;
$parse_error    = null;
$total_missing  = 0;

$php_audit_missing = [];
$schema_drift      = ['extra_tables' => [], 'extra_columns' => []];

if ($canonical === false) {
    $parse_error = 'Canonical schema file not found at database/schema/snapsmack_canonical.sql';
} else {
    try {
        $live          = snap_schema_live($pdo);
        $diff          = snap_schema_diff($canonical, $live);
        $total_missing = count($diff['missing_tables'])
                       + array_sum(array_map('count', $diff['missing_columns']))
                       + array_sum(array_map('count', $diff['wrong_type'] ?? []));
    } catch (Exception $e) {
        $parse_error = 'Could not read live database: ' . $e->getMessage();
    }

    // PHP reference audit — compare source files against canonical table list
    $canonical_table_keys = array_combine(
        array_map('strtolower', array_keys($canonical)),
        array_fill(0, count($canonical), true)
    );
    $php_audit_missing = snap_schema_audit_php(__DIR__, $canonical_table_keys);

    // Reverse diff — tables/columns in the live DB but absent from canonical
    // (debris the additive-only sync never removes). Read-only.
    try {
        $schema_drift = snap_schema_drift($pdo);
    } catch (Throwable $e) {
        $schema_drift = ['extra_tables' => [], 'extra_columns' => []];
    }
}

require_once 'core/admin-header.php';
require 'core/sidebar.php';
?>

<style>
.schema-wrap       { max-width: 960px; padding: 0 24px 60px; }
.schema-status-bar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 20px;
    background: var(--admin-box-bg, #1a1a1a);
    border: 1px solid var(--admin-border, #333);
    border-radius: 6px;
    margin-bottom: 24px;
}
.schema-status-label { font-size: 0.8rem; letter-spacing: 1px; text-transform: uppercase; opacity: 0.5; }
.schema-status-ok    { color: #4ec994; font-weight: 700; }
.schema-status-warn  { color: #e2b714; font-weight: 700; }
.schema-status-err   { color: #e45735; font-weight: 700; }

.schema-table {
    background: var(--admin-box-bg, #1a1a1a);
    border: 1px solid var(--admin-border, #333);
    border-radius: 6px;
    overflow: hidden;
    margin-bottom: 12px;
}
.schema-table-head {
    display: grid;
    grid-template-columns: 220px 1fr 120px;
    align-items: center;
    padding: 10px 18px;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    background: rgba(0,0,0,0.15);
    font-size: 0.78rem;
}
.schema-table-name      { font-family: monospace; color: var(--admin-text, #ccc); }
.schema-table-name--bad { color: #e45735; }
.schema-cols            { display: flex; flex-wrap: wrap; gap: 4px 6px; padding: 10px 18px; }
.schema-col             { font-size: 0.65rem; font-family: monospace; padding: 2px 7px; border-radius: 3px; }
.schema-col--ok         { background: rgba(255,255,255,.06); color: #777; }
.schema-col--missing    { background: rgba(226,183,20,.2); color: #e2b714; font-weight: 700; }
.schema-col--wrong-type { background: rgba(232,125,53,.2); color: #e87d35; font-weight: 700; }
.schema-tag             { font-size: 0.65rem; font-weight: 700; text-transform: uppercase;
                          letter-spacing: 0.5px; padding: 2px 8px; border-radius: 3px; justify-self: end; }
.schema-tag--ok         { background: rgba(78,201,148,.12); color: #4ec994; }
.schema-tag--missing    { background: rgba(228,87,53,.15); color: #e45735; }
.schema-tag--warn       { background: rgba(226,183,20,.15); color: #e2b714; }

.schema-log {
    padding: 14px 18px;
    border-top: 1px solid var(--admin-border, #333);
    font-family: monospace; font-size: 0.78rem; line-height: 1.9;
    background: rgba(0,0,0,0.2);
}
.schema-log-ok  { color: #4ec994; }
.schema-log-err { color: #e45735; }

.schema-apply-bar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 18px;
    border-top: 1px solid var(--admin-border, #333);
    background: rgba(0,0,0,0.1);
    gap: 12px;
}
.schema-apply-note { font-size: 0.78rem; opacity: 0.6; }
</style>

<div class="main-content">
<div class="schema-wrap">

<h2 class="page-heading">Database Schema</h2>
<p style="font-size:0.85rem; opacity:0.6; margin-bottom:24px; max-width:640px;">
    Diffs the live database against
    <code>database/schema/snapsmack_canonical.sql</code>.
    Missing tables are created in full. Missing columns are added with
    <code>ALTER TABLE … ADD COLUMN</code>. Existing data is never modified.
</p>

<?php if ($parse_error): ?>
<div class="schema-table">
    <div class="schema-table-head">
        <span class="schema-table-name--bad"><?php echo htmlspecialchars($parse_error); ?></span>
    </div>
</div>

<?php else: ?>

<!-- Status summary bar -->
<div class="schema-status-bar">
    <span class="schema-status-label">Status</span>
    <?php if ($total_missing === 0): ?>
        <span class="schema-status-ok">✓ Schema in sync — nothing to apply</span>
    <?php else: ?>
        <span class="schema-status-warn">
            <?php
            $s_mt = count($diff['missing_tables']);
            $s_mc = array_sum(array_map('count', $diff['missing_columns']));
            $s_wt = array_sum(array_map('count', $diff['wrong_type'] ?? []));
            $parts = [];
            if ($s_mt) $parts[] = "{$s_mt} missing table" . ($s_mt !== 1 ? 's' : '');
            if ($s_mc) $parts[] = "{$s_mc} missing column" . ($s_mc !== 1 ? 's' : '');
            if ($s_wt) $parts[] = "{$s_wt} wrong type" . ($s_wt !== 1 ? 's' : '');
            echo implode(', ', $parts) . ' — apply to fix';
            ?>
        </span>
    <?php endif; ?>
</div>

<!-- Apply log (shown after applying) -->
<?php if (!empty($apply_results)): ?>
<div class="schema-table" style="margin-bottom:24px;">
    <div class="schema-log">
        <?php foreach ($apply_results as [$level, $msg]): ?>
        <div class="schema-log-<?php echo $level === 'ok' ? 'ok' : 'err'; ?>">
            <?php echo $level === 'ok' ? '✓' : '✗'; ?>
            <?php echo htmlspecialchars($msg); ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- PHP Reference Audit -->
<h3 style="font-size:0.8rem; letter-spacing:1px; text-transform:uppercase; opacity:0.5; margin:28px 0 10px;">
    PHP Reference Audit
</h3>
<p style="font-size:0.82rem; opacity:0.5; margin-bottom:14px; max-width:600px;">
    Tables referenced in PHP source that are absent from the canonical schema.
    Any gap here would cause the SC release packager to abort the build.
</p>
<div class="schema-status-bar" style="margin-bottom:24px;">
    <span class="schema-status-label">PHP → Schema</span>
    <?php if (empty($php_audit_missing)): ?>
        <span class="schema-status-ok">✓ All PHP-referenced tables present in canonical schema</span>
    <?php else: ?>
        <span class="schema-status-err">
            <?php echo count($php_audit_missing); ?> table<?php echo count($php_audit_missing) !== 1 ? 's' : ''; ?>
            referenced in PHP but missing from canonical schema
        </span>
    <?php endif; ?>
</div>
<?php if (!empty($php_audit_missing)): ?>
<div class="schema-table" style="margin-bottom:24px;">
    <div class="schema-cols" style="padding:14px 18px;">
        <?php foreach ($php_audit_missing as $t): ?>
        <span class="schema-col schema-col--missing"><?php echo htmlspecialchars($t); ?></span>
        <?php endforeach; ?>
    </div>
    <div style="padding:10px 18px 14px; font-size:0.78rem; opacity:0.55; border-top:1px solid rgba(255,255,255,0.05);">
        Fix: add each table to <code>database/schema/snapsmack_canonical.sql</code>
        and create a <code>migrations/migrate-*.sql</code> with IF NOT EXISTS DDL.
    </div>
</div>
<?php endif; ?>

<!-- ── Schema Drift (DB-side debris not in canonical) ─────────────────────── -->
<?php
$drift_tables = $schema_drift['extra_tables']  ?? [];
$drift_cols   = $schema_drift['extra_columns'] ?? [];
$drift_col_n  = array_sum(array_map('count', $drift_cols));
$drift_total  = count($drift_tables) + $drift_col_n;
?>
<h3 style="font-size:0.8rem; letter-spacing:1px; text-transform:uppercase; opacity:0.5; margin:28px 0 10px;">
    Schema Drift — Debris Not in Canonical
</h3>
<p style="font-size:0.82rem; opacity:0.5; margin-bottom:14px; max-width:640px;">
    The sync only ever ADDs and corrects what canonical requires — it never removes.
    This is the reverse view: tables and columns present in the live database but
    absent from canonical (leftovers from dropped features or old migrations).
    Detection is read-only. Removing them is destructive and requires your
    password and 2FA.
</p>

<?php if (!empty($prune_results)): ?>
<div class="schema-table" style="margin-bottom:16px;">
    <div class="schema-log">
        <?php
        foreach ($prune_results['dropped_tables']  as $t) echo '<div class="schema-log-ok">✓ dropped table `' . htmlspecialchars($t) . '`</div>';
        foreach ($prune_results['dropped_columns'] as $c) echo '<div class="schema-log-ok">✓ dropped column `' . htmlspecialchars($c) . '`</div>';
        foreach ($prune_results['skipped']         as $s) echo '<div class="schema-log-ok" style="opacity:.55;">– skipped ' . htmlspecialchars($s) . '</div>';
        foreach ($prune_results['errors']          as $e) echo '<div class="schema-log-err">✗ ' . htmlspecialchars($e) . '</div>';
        if (empty($prune_results['dropped_tables']) && empty($prune_results['dropped_columns'])
            && empty($prune_results['skipped']) && empty($prune_results['errors'])) {
            echo '<div class="schema-log-ok">Nothing to prune.</div>';
        }
        ?>
    </div>
</div>
<?php endif; ?>

<div class="schema-status-bar"<?php echo $drift_total ? '' : ' style="margin-bottom:24px;"'; ?>>
    <span class="schema-status-label">DB → Canonical</span>
    <?php if ($drift_total === 0): ?>
        <span class="schema-status-ok">✓ No debris — every live table &amp; column exists in canonical</span>
    <?php else: ?>
        <span class="schema-status-warn">
            <?php
            $dp = [];
            if ($drift_tables) $dp[] = count($drift_tables) . ' extra table' . (count($drift_tables) !== 1 ? 's' : '');
            if ($drift_col_n)  $dp[] = $drift_col_n . ' extra column' . ($drift_col_n !== 1 ? 's' : '');
            echo implode(', ', $dp) . ' in the DB but not in canonical';
            ?>
        </span>
    <?php endif; ?>
</div>

<?php if ($drift_total > 0): ?>
<div class="schema-table" style="margin-bottom:24px;">
    <?php foreach ($drift_tables as $t): ?>
    <div class="schema-table-head">
        <span class="schema-table-name schema-table-name--bad">`<?php echo htmlspecialchars($t); ?>`</span>
        <span></span>
        <span class="schema-tag schema-tag--missing">Extra table</span>
    </div>
    <?php endforeach; ?>
    <?php foreach ($drift_cols as $t => $cols): ?>
    <div class="schema-table-head">
        <span class="schema-table-name">`<?php echo htmlspecialchars($t); ?>`</span>
        <span></span>
        <span class="schema-tag schema-tag--warn"><?php echo count($cols); ?> extra column<?php echo count($cols) !== 1 ? 's' : ''; ?></span>
    </div>
    <div class="schema-cols">
        <?php foreach ($cols as $c): ?>
        <span class="schema-col schema-col--wrong-type"><?php echo htmlspecialchars($c); ?></span>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <div class="schema-apply-bar">
        <div class="schema-apply-note" style="color:#e45735;">
            Pruning permanently DROPs the items above — irreversible, so back up first.
            Requires your password and 2FA.
        </div>
        <form method="POST"
              onsubmit="return confirm('Permanently DROP the listed tables/columns? This cannot be undone.');"
              style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:0;">
            <?php if (function_exists('csrf_field')) csrf_field(); ?>
            <input type="hidden" name="prune_schema" value="1">
            <input type="password" name="reauth_password" placeholder="Password"
                   autocomplete="current-password" required style="padding:7px 10px;">
            <input type="text" name="reauth_totp" placeholder="2FA code" inputmode="numeric"
                   autocomplete="one-time-code" maxlength="10" required style="padding:7px 10px; width:110px;">
            <button type="submit" class="btn-smack btn-danger">Prune Debris</button>
        </form>
    </div>
    <?php if ($prune_error): ?>
    <div class="schema-log schema-log-err" style="border-top:1px solid var(--admin-border,#333);">
        ✗ <?php echo htmlspecialchars($prune_error); ?>
    </div>
    <?php endif; ?>
</div>
<?php elseif ($prune_error): ?>
<div class="schema-status-bar" style="margin-bottom:24px;">
    <span class="schema-status-err">✗ <?php echo htmlspecialchars($prune_error); ?></span>
</div>
<?php endif; ?>

<h3 style="font-size:0.8rem; letter-spacing:1px; text-transform:uppercase; opacity:0.5; margin:28px 0 10px;">
    Live Database vs Canonical Schema
</h3>

<!-- Table-by-table diff -->
<?php foreach ($canonical as $table => $tdef):
    $is_missing   = isset($diff['missing_tables'][$table]);
    $missing_cols = $diff['missing_columns'][$table] ?? [];
    $wrong_cols   = $diff['wrong_type'][$table] ?? [];
    $ok_cols      = $diff['ok_tables'][$table] ?? [];
?>
<div class="schema-table">
    <div class="schema-table-head">
        <span class="schema-table-name <?php echo $is_missing ? 'schema-table-name--bad' : ''; ?>">
            `<?php echo htmlspecialchars($table); ?>`
        </span>
        <span></span>
        <?php if ($is_missing): ?>
            <span class="schema-tag schema-tag--missing">Missing table</span>
        <?php elseif ($missing_cols || $wrong_cols): ?>
            <span class="schema-tag schema-tag--warn">
                <?php
                $n_issues = count($missing_cols) + count($wrong_cols);
                echo $n_issues . ' issue' . ($n_issues !== 1 ? 's' : '');
                ?>
            </span>
        <?php else: ?>
            <span class="schema-tag schema-tag--ok">✓ OK</span>
        <?php endif; ?>
    </div>

    <div class="schema-cols">
        <?php if ($is_missing): ?>
            <?php foreach ($tdef['columns'] as $col => $def): ?>
            <span class="schema-col schema-col--missing"
                  title="<?php echo htmlspecialchars($def); ?>">
                <?php echo htmlspecialchars($col); ?>
            </span>
            <?php endforeach; ?>
        <?php else: ?>
            <?php foreach ($ok_cols as $col): ?>
            <span class="schema-col schema-col--ok"><?php echo htmlspecialchars($col); ?></span>
            <?php endforeach; ?>
            <?php foreach (array_keys($missing_cols) as $col): ?>
            <span class="schema-col schema-col--missing"
                  title="<?php echo htmlspecialchars($missing_cols[$col]); ?>">
                <?php echo htmlspecialchars($col); ?>
            </span>
            <?php endforeach; ?>
            <?php foreach ($wrong_cols as $col => $info): ?>
            <span class="schema-col schema-col--wrong-type"
                  title="live: <?php echo htmlspecialchars($info['live_type'] . ($info['live_nullable'] ? '' : ' NOT NULL')); ?>  →  needs: <?php echo htmlspecialchars($info['can_type'] . ($info['can_nullable'] ? '' : ' NOT NULL')); ?>">
                <?php echo htmlspecialchars($col); ?>
            </span>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<!-- Apply button -->
<?php if ($total_missing > 0): ?>
<div class="schema-apply-bar" style="margin-top:24px; background:var(--admin-box-bg,#1a1a1a); border:1px solid var(--admin-border,#333); border-radius:6px;">
    <div class="schema-apply-note">
        Highlighted columns and tables will be created. No existing data will be modified.
    </div>
    <form method="POST" onsubmit="return confirm('Apply schema changes to the live database?');">
        <input type="hidden" name="apply_schema" value="1">
        <button type="submit" class="btn-smack">Apply Schema</button>
    </form>
</div>
<?php endif; ?>

<?php endif; // end parse_error check ?>

</div><!-- /.schema-wrap -->
</div><!-- /.main-content -->

<?php require_once 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
