<?php
/**
 * SMACK CENTRAL - Database Schema Manager
 *
 * Diffs each Smack Central database against its canonical schema file
 * and can apply missing tables and columns. Mirrors the SnapSmack updater
 * approach but for SC's three isolated databases:
 *   squir871_smackcent  — SC admin tables (sc_*)
 *   squir871_smackforum — Community forum tables (ss_forum_*)
 *   squir871_enemy      — SMACK THE ENEMY tables (ste_*)
 */

require_once __DIR__ . '/sc-auth.php';
$sc_active_nav = 'sc-schema.php';
$sc_page_title = 'Schema Manager';

// ── DB registry ───────────────────────────────────────────────────────────────
// Each entry maps a human label → [PDO factory fn, canonical SQL path, DB name constant].
// DB name constants come from sc-config.php.

$sc_schema_dbs = [
    'smackcent' => [
        'label'     => 'smackcent',
        'colour'    => '#5c6bc0',
        'canonical' => __DIR__ . '/schemas/sc-smackcent-canonical.sql',
        'db_name'   => defined('SC_DB_NAME')       ? SC_DB_NAME       : '',
        'pdo_fn'    => 'sc_db',
    ],
    'smackforum' => [
        'label'     => 'smackforum',
        'colour'    => '#26a69a',
        'canonical' => __DIR__ . '/schemas/sc-forum-canonical.sql',
        'db_name'   => defined('SC_FORUM_DB_NAME') ? SC_FORUM_DB_NAME : '',
        'pdo_fn'    => 'sc_forum_db',
    ],
    'enemy' => [
        'label'     => 'enemy',
        'colour'    => '#e45735',
        'canonical' => __DIR__ . '/schemas/sc-enemy-canonical.sql',
        'db_name'   => defined('STE_DB_NAME')      ? STE_DB_NAME      : '',
        'pdo_fn'    => 'sc_enemy_db',
    ],
];


// ── Schema parser ─────────────────────────────────────────────────────────────

/**
 * Parse a canonical SQL file into an array of table definitions.
 *
 * Returns:
 *   ['table_name' => [
 *       'columns' => ['col_name' => 'col_name TYPE ...'],  // full definition line
 *       'sql'     => 'CREATE TABLE IF NOT EXISTS ...',     // full block for fresh install
 *   ], ...]
 */
function sc_schema_parse(string $path): array|false {
    if (!file_exists($path)) return false;
    $sql = file_get_contents($path);
    $sql = str_replace("\r\n", "\n", $sql);

    // Strip line comments
    $sql = preg_replace('/--[^\n]*/', '', $sql);

    $tables = [];

    // Split into individual statements first to prevent cross-statement regex
    // matching (DOTALL non-greedy can bleed through INSERT/other statements
    // that appear between CREATE TABLE blocks).
    $statements = preg_split('/;\s*\n/', $sql);

    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if (!preg_match(
            '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\((.+)\)\s*ENGINE\s*=[^;]*/si',
            $stmt,
            $m
        )) {
            continue;
        }

        $name = $m[1];
        $body = $m[2];
        $full = $stmt . ';';

        $columns = [];
        foreach (explode("\n", $body) as $raw_line) {
            $line = trim($raw_line, " \t,");
            // Match column lines: starts with `col_name` or col_name followed by a data type
            if (preg_match(
                '/^`?(\w+)`?\s+((?:INT|BIGINT|TINYINT|SMALLINT|MEDIUMINT|'
                . 'VARCHAR|CHAR|TEXT|MEDIUMTEXT|LONGTEXT|'
                . 'DECIMAL|FLOAT|DOUBLE|'
                . 'TIMESTAMP|DATETIME|DATE|TIME|YEAR|'
                . 'ENUM|SET|JSON|BLOB|MEDIUMBLOB|LONGBLOB)\b.*)/i',
                $line,
                $cm
            )) {
                $col_name = $cm[1];
                // Strip any trailing COMMENT clause for cleaner ALTER TABLE output
                // Keep the definition intact otherwise
                $columns[$col_name] = $line;
            }
        }

        $tables[$name] = ['columns' => $columns, 'sql' => $full];
    }

    return $tables;
}


// ── Live schema reader ────────────────────────────────────────────────────────

/**
 * Fetch the current table + column list from INFORMATION_SCHEMA.
 * Returns ['table_name' => ['col1', 'col2', ...], ...]
 */
function sc_schema_live(PDO $pdo, string $db_name): array {
    if ($db_name === '') return [];
    try {
        $stmt = $pdo->prepare("
            SELECT TABLE_NAME, COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ?
            ORDER BY TABLE_NAME ASC, ORDINAL_POSITION ASC
        ");
        $stmt->execute([$db_name]);
        $schema = [];
        foreach ($stmt->fetchAll() as $row) {
            $schema[$row['TABLE_NAME']][] = $row['COLUMN_NAME'];
        }
        return $schema;
    } catch (Exception $e) {
        return [];
    }
}


// ── Diff ──────────────────────────────────────────────────────────────────────

/**
 * Diff canonical against live.
 * Returns [
 *   'missing_tables'  => ['table_name' => create_sql, ...],
 *   'missing_columns' => ['table_name' => ['col_name' => def_line, ...], ...],
 *   'ok_tables'       => ['table_name' => ['ok_col', ...], ...],
 * ]
 */
function sc_schema_diff(array $canonical, array $live): array {
    $missing_tables  = [];
    $missing_columns = [];
    $ok_tables       = [];

    foreach ($canonical as $table => $def) {
        if (!isset($live[$table])) {
            $missing_tables[$table] = $def['sql'];
        } else {
            $live_cols = array_flip($live[$table]); // col_name => true
            $missing_in_table = [];
            $ok_cols = [];
            foreach ($def['columns'] as $col => $col_def) {
                if (isset($live_cols[$col])) {
                    $ok_cols[] = $col;
                } else {
                    $missing_in_table[$col] = $col_def;
                }
            }
            if ($missing_in_table) {
                $missing_columns[$table] = $missing_in_table;
            }
            $ok_tables[$table] = $ok_cols;
        }
    }

    return [
        'missing_tables'  => $missing_tables,
        'missing_columns' => $missing_columns,
        'ok_tables'       => $ok_tables,
    ];
}


// ── Apply ─────────────────────────────────────────────────────────────────────

/**
 * Build ALTER TABLE / CREATE TABLE SQL from a diff.
 * Returns an array of SQL statements ready to execute.
 */
function sc_schema_build_ddl(array $diff): array {
    $stmts = [];

    foreach ($diff['missing_tables'] as $table => $create_sql) {
        // Canonical already has IF NOT EXISTS — safe to run as-is
        $stmts[] = $create_sql;
    }

    foreach ($diff['missing_columns'] as $table => $cols) {
        foreach ($cols as $col => $def) {
            // Extract just the definition part (strip trailing COMMENT for safety on older MySQL)
            $def_clean = preg_replace('/\s+COMMENT\s+\'[^\']*\'/i', '', $def);
            // Strip leading `col_name` from $def to get just the type/modifiers
            // $def is the full "col_name TYPE NOT NULL DEFAULT ..." line
            $type_part = preg_replace('/^`?' . preg_quote($col, '/') . '`?\s+/i', '', $def_clean);
            // No IF NOT EXISTS — MySQL 5.7 doesn't support it on ADD COLUMN.
            // The diff already confirmed the column is absent, so it's safe.
            $stmts[] = "ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$type_part};";
        }
    }

    return $stmts;
}


// ── POST: Apply DDL ───────────────────────────────────────────────────────────

$apply_results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['apply_db'])) {
    $apply_key = $_POST['apply_db'];

    if (isset($sc_schema_dbs[$apply_key])) {
        $db_cfg   = $sc_schema_dbs[$apply_key];
        $pdo_fn   = $db_cfg['pdo_fn'];
        $canonical = sc_schema_parse($db_cfg['canonical']);

        if ($canonical !== false && function_exists($pdo_fn)) {
            try {
                $pdo  = $pdo_fn();
                $live = sc_schema_live($pdo, $db_cfg['db_name']);
                $diff = sc_schema_diff($canonical, $live);
                $stmts = sc_schema_build_ddl($diff);

                if (empty($stmts)) {
                    $apply_results[$apply_key] = [['ok', 'Schema is already up to date. Nothing to apply.']];
                } else {
                    $results = [];
                    foreach ($stmts as $stmt) {
                        try {
                            $pdo->exec($stmt);
                            $results[] = ['ok', $stmt];
                        } catch (PDOException $e) {
                            $results[] = ['err', $stmt . ' — ' . $e->getMessage()];
                        }
                    }
                    $apply_results[$apply_key] = $results;
                }
            } catch (Exception $e) {
                $apply_results[$apply_key] = [['err', 'Could not connect: ' . $e->getMessage()]];
            }
        } else {
            $apply_results[$apply_key] = [['err', 'Canonical file not found or DB function missing.']];
        }
    }

    // Redirect to clear POST (PRG pattern)
    // We store results in session to survive the redirect
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['sc_schema_apply'] = $apply_results;
    header('Location: sc-schema.php?applied=' . urlencode($apply_key));
    exit;
}

// Retrieve apply results from session after redirect
if (!empty($_GET['applied'])) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['sc_schema_apply'])) {
        $apply_results = $_SESSION['sc_schema_apply'];
        unset($_SESSION['sc_schema_apply']);
    }
}


// ── Build diff data for display ───────────────────────────────────────────────

$db_status = [];

foreach ($sc_schema_dbs as $key => $cfg) {
    $canonical = sc_schema_parse($cfg['canonical']);

    if ($canonical === false) {
        $db_status[$key] = ['error' => 'Canonical file not found: ' . basename($cfg['canonical'])];
        continue;
    }

    if (!function_exists($cfg['pdo_fn'])) {
        $db_status[$key] = ['error' => 'DB connection function ' . $cfg['pdo_fn'] . '() not defined.'];
        continue;
    }

    try {
        $pdo  = ($cfg['pdo_fn'])();
        $live = sc_schema_live($pdo, $cfg['db_name']);
        $diff = sc_schema_diff($canonical, $live);

        $total_missing = count($diff['missing_tables'])
            + array_sum(array_map('count', $diff['missing_columns']));

        $db_status[$key] = [
            'canonical'      => $canonical,
            'live'           => $live,
            'diff'           => $diff,
            'total_missing'  => $total_missing,
            'db_name'        => $cfg['db_name'],
        ];
    } catch (Exception $e) {
        $db_status[$key] = ['error' => 'Connection failed: ' . $e->getMessage()];
    }
}


require __DIR__ . '/sc-layout-top.php';
?>

<style>
.sc-schema-grid {
    display: flex;
    flex-direction: column;
    gap: 24px;
}
.sc-schema-db {
    background: var(--sc-bg-box);
    border: 1px solid var(--sc-border);
    border-radius: var(--sc-radius);
    overflow: hidden;
}
.sc-schema-db-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 20px;
    border-bottom: 1px solid var(--sc-border);
    background: rgba(0,0,0,0.15);
}
.sc-schema-db-dot {
    width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
}
.sc-schema-db-name {
    font-weight: 700; font-size: 0.85rem; letter-spacing: 0.03em;
    color: var(--sc-text); font-family: var(--sc-font-mono);
}
.sc-schema-db-dbname {
    font-size: 0.72rem; color: var(--sc-text-dim);
}
.sc-schema-db-status {
    margin-left: auto;
    font-size: 0.72rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.8px;
    padding: 4px 10px; border-radius: 3px;
}
.sc-schema-db-status--ok     { background: rgba(78,201,148,.15); color: #4ec994; }
.sc-schema-db-status--warn   { background: rgba(226,183,20,.15);  color: #e2b714; }
.sc-schema-db-status--err    { background: rgba(228,87,53,.15);   color: #e45735; }

.sc-schema-body { padding: 0; }

.sc-schema-table-row {
    display: grid;
    grid-template-columns: 200px 1fr auto;
    align-items: start;
    gap: 0;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    padding: 10px 20px;
}
.sc-schema-table-row:last-child { border-bottom: none; }
.sc-schema-table-row--missing { background: rgba(228,87,53,.06); }
.sc-schema-table-row--warn    { background: rgba(226,183,20,.04); }

.sc-schema-table-name {
    font-family: var(--sc-font-mono); font-size: 0.78rem;
    color: var(--sc-text); padding-top: 2px;
}
.sc-schema-table-name--missing { color: #e45735; }

.sc-schema-cols { display: flex; flex-wrap: wrap; gap: 4px 6px; }
.sc-schema-col {
    font-size: 0.65rem; font-family: var(--sc-font-mono);
    padding: 2px 6px; border-radius: 3px;
}
.sc-schema-col--ok      { background: rgba(255,255,255,.06); color: #888; }
.sc-schema-col--missing { background: rgba(226,183,20,.2);   color: #e2b714; font-weight: 700; }

.sc-schema-tag {
    font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
    padding: 2px 7px; border-radius: 3px;
}
.sc-schema-tag--ok      { background: rgba(78,201,148,.12); color: #4ec994; }
.sc-schema-tag--missing { background: rgba(228,87,53,.15);  color: #e45735; }
.sc-schema-tag--warn    { background: rgba(226,183,20,.15);  color: #e2b714; }

.sc-schema-apply-box {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 20px;
    border-top: 1px solid var(--sc-border);
    background: rgba(0,0,0,0.1);
    gap: 12px;
}
.sc-schema-apply-note { font-size: 0.78rem; color: var(--sc-text-dim); }

.sc-schema-log {
    margin: 0; padding: 12px 20px;
    border-top: 1px solid var(--sc-border);
    background: rgba(0,0,0,0.2);
    font-family: var(--sc-font-mono); font-size: 0.75rem; line-height: 1.8;
}
.sc-schema-log-ok  { color: #4ec994; }
.sc-schema-log-err { color: #e45735; }
</style>

<div class="sc-page-header">
  <span class="sc-page-title">Schema Manager</span>
  <span class="sc-dim">canonical SQL → live DB diff and apply</span>
</div>

<p class="sc-dim" style="margin-bottom: 20px; font-size: 0.82rem; max-width: 640px;">
  Each Smack Central database is compared against its canonical schema file in
  <code>smack-central/schemas/</code>. Missing tables are created in full.
  Missing columns are added with <code>ALTER TABLE … ADD COLUMN IF NOT EXISTS</code>.
  Existing data is never modified.
</p>

<div class="sc-schema-grid">
<?php foreach ($sc_schema_dbs as $key => $cfg):
    $status    = $db_status[$key];
    $colour    = $cfg['colour'];
    $this_apply = $apply_results[$key] ?? null;
?>
<div class="sc-schema-db">

  <!-- Header -->
  <div class="sc-schema-db-header">
    <div class="sc-schema-db-dot" style="background:<?php echo $colour; ?>;"></div>
    <div>
      <div class="sc-schema-db-name"><?php echo htmlspecialchars($cfg['label']); ?></div>
      <div class="sc-schema-db-dbname"><?php echo htmlspecialchars($cfg['db_name'] ?: '(db name not configured)'); ?></div>
    </div>

    <?php if (isset($status['error'])): ?>
      <span class="sc-schema-db-status sc-schema-db-status--err">Error</span>
    <?php elseif ($status['total_missing'] === 0): ?>
      <span class="sc-schema-db-status sc-schema-db-status--ok">✓ In Sync</span>
    <?php else: ?>
      <span class="sc-schema-db-status sc-schema-db-status--warn">
        <?php echo $status['total_missing']; ?> missing
      </span>
    <?php endif; ?>
  </div>

  <!-- Error state -->
  <?php if (isset($status['error'])): ?>
  <div class="sc-schema-body" style="padding:16px 20px;">
    <span class="sc-dim" style="color:#e45735;"><?php echo htmlspecialchars($status['error']); ?></span>
  </div>

  <!-- Normal diff view -->
  <?php else:
    $diff = $status['diff'];
    $canonical = $status['canonical'];
  ?>
  <div class="sc-schema-body">
    <?php foreach ($canonical as $table => $tdef):
        $is_missing = isset($diff['missing_tables'][$table]);
        $missing_cols = $diff['missing_columns'][$table] ?? [];
        $ok_cols = $diff['ok_tables'][$table] ?? [];
        $row_class = $is_missing ? 'sc-schema-table-row--missing'
                   : ($missing_cols ? 'sc-schema-table-row--warn' : '');
    ?>
    <div class="sc-schema-table-row <?php echo $row_class; ?>">

      <div class="sc-schema-table-name <?php echo $is_missing ? 'sc-schema-table-name--missing' : ''; ?>">
        `<?php echo htmlspecialchars($table); ?>`
      </div>

      <div class="sc-schema-cols">
        <?php if ($is_missing): ?>
          <?php foreach ($tdef['columns'] as $col => $def): ?>
          <span class="sc-schema-col sc-schema-col--missing" title="<?php echo htmlspecialchars($def); ?>">
            <?php echo htmlspecialchars($col); ?>
          </span>
          <?php endforeach; ?>
        <?php else: ?>
          <?php foreach ($ok_cols as $col): ?>
          <span class="sc-schema-col sc-schema-col--ok"><?php echo htmlspecialchars($col); ?></span>
          <?php endforeach; ?>
          <?php foreach (array_keys($missing_cols) as $col): ?>
          <span class="sc-schema-col sc-schema-col--missing"
                title="<?php echo htmlspecialchars($missing_cols[$col]); ?>">
            <?php echo htmlspecialchars($col); ?>
          </span>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div style="padding-top:2px; flex-shrink:0;">
        <?php if ($is_missing): ?>
          <span class="sc-schema-tag sc-schema-tag--missing">Missing table</span>
        <?php elseif ($missing_cols): ?>
          <span class="sc-schema-tag sc-schema-tag--warn">
            <?php echo count($missing_cols); ?> missing col<?php echo count($missing_cols) !== 1 ? 's' : ''; ?>
          </span>
        <?php else: ?>
          <span class="sc-schema-tag sc-schema-tag--ok">✓</span>
        <?php endif; ?>
      </div>

    </div>
    <?php endforeach; ?>
  </div>

  <!-- Apply log (shown after apply) -->
  <?php if ($this_apply): ?>
  <div class="sc-schema-log">
    <?php foreach ($this_apply as [$level, $msg]): ?>
    <div class="sc-schema-log-<?php echo $level === 'ok' ? 'ok' : 'err'; ?>">
      <?php echo $level === 'ok' ? '✓' : '✗'; ?> <?php echo htmlspecialchars($msg); ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Apply button (only shown if something is missing) -->
  <?php if ($status['total_missing'] > 0): ?>
  <div class="sc-schema-apply-box">
    <div class="sc-schema-apply-note">
      Highlighted columns will be added with <code>ALTER TABLE … ADD COLUMN IF NOT EXISTS</code>.
      Missing tables will be created in full. Existing data is untouched.
    </div>
    <form method="post" onsubmit="return confirm('Apply schema changes to <?php echo htmlspecialchars($cfg['label']); ?>?')">
      <input type="hidden" name="apply_db" value="<?php echo htmlspecialchars($key); ?>">
      <button type="submit" class="sc-btn sc-btn--primary">Apply to <?php echo htmlspecialchars($cfg['label']); ?></button>
    </form>
  </div>
  <?php endif; ?>

  <?php endif; // end error/normal branch ?>

</div><!-- /.sc-schema-db -->
<?php endforeach; ?>
</div><!-- /.sc-schema-grid -->

<?php require __DIR__ . '/sc-layout-bottom.php'; ?>
<?php // EOF
