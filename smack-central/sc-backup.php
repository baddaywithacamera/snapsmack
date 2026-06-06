<?php
/**
 * SMACK CENTRAL - Database Backup / Dump
 *
 * Generates a downloadable .sql dump of any SC database via PDO.
 * No mysqldump required — pure PHP. Suitable for inspection and restore.
 *
 * Dumps: schema (CREATE TABLE) + data (INSERT INTO) for every table.
 * Output: streamed as attachment, no temp file written to disk.
 */

// SNAPSMACK_EOF_HEADER
//     <?php // ===== SNAPSMACK EOF =====
// Last non-empty line of this file MUST match the line above.
// Missing or different = truncated/corrupted. Restore before saving.

require_once __DIR__ . '/sc-auth.php';
$sc_active_nav = 'sc-backup.php';
$sc_page_title = 'DB Backup';

// ── Database registry (mirrors sc-schema.php) ─────────────────────────────
$sc_dbs = [
    'smackcent' => [
        'label'   => 'smackcent',
        'db_name' => defined('SC_DB_NAME')       ? SC_DB_NAME       : '(not configured)',
        'pdo_fn'  => 'sc_db',
        'colour'  => '#5c6bc0',
        'desc'    => 'SC admin tables — settings, releases, phone-home, push subscribers',
    ],
    'smackforum' => [
        'label'   => 'smackforum',
        'db_name' => defined('SC_FORUM_DB_NAME') ? SC_FORUM_DB_NAME : '(not configured)',
        'pdo_fn'  => 'sc_forum_db',
        'colour'  => '#26a69a',
        'desc'    => 'Community forum tables',
    ],
    'enemy' => [
        'label'   => 'enemy',
        'db_name' => defined('STE_DB_NAME')      ? STE_DB_NAME      : '(not configured)',
        'pdo_fn'  => 'sc_enemy_db',
        'colour'  => '#e45735',
        'desc'    => 'SMACK THE ENEMY tables',
    ],
];

// ── Dump action — stream SQL directly to browser ──────────────────────────
$do_dump = isset($_GET['dump']) && isset($sc_dbs[$_GET['dump']]);

if ($do_dump) {
    $key  = $_GET['dump'];
    $db   = $sc_dbs[$key];
    $fn   = $db['pdo_fn'];

    try {
        $pdo = $fn();
    } catch (Throwable $e) {
        // Fall through to page render with error
        $do_dump = false;
        $dump_err = 'Could not connect to ' . $db['label'] . ': ' . $e->getMessage();
        goto render_page;
    }

    $db_name  = $db['db_name'];
    $filename = 'sc-' . $key . '-' . date('Ymd-His') . '.sql';

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');

    // ── Header block ────────────────────────────────────────────────────────
    echo "-- ============================================================\n";
    echo "-- SMACK CENTRAL — Database Dump\n";
    echo "-- Database : " . $db_name . "\n";
    echo "-- Label    : " . $db['label'] . "\n";
    echo "-- Generated: " . date('Y-m-d H:i:s T') . "\n";
    echo "-- ============================================================\n\n";
    echo "SET NAMES utf8mb4;\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\n";
    echo "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";

    // ── Enumerate tables ────────────────────────────────────────────────────
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        // ── Schema ──────────────────────────────────────────────────────────
        echo "\n-- ------------------------------------------------------------\n";
        echo "-- Table: `{$table}`\n";
        echo "-- ------------------------------------------------------------\n\n";
        echo "DROP TABLE IF EXISTS `{$table}`;\n";

        $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
        echo $create[1] . ";\n\n";

        // ── Data ────────────────────────────────────────────────────────────
        $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            echo "-- (no rows)\n";
            continue;
        }

        // Build INSERT batches of up to 50 rows each
        $cols      = '`' . implode('`, `', array_keys($rows[0])) . '`';
        $batch     = [];
        $batch_max = 50;

        foreach ($rows as $row) {
            $vals = [];
            foreach ($row as $v) {
                if ($v === null) {
                    $vals[] = 'NULL';
                } elseif (is_int($v) || is_float($v)) {
                    $vals[] = $v;
                } else {
                    // Escape: backslash, single quote, newline, carriage return, null byte, ctrl-Z
                    $escaped = str_replace(
                        ['\\',   "'",   "\n",  "\r",  "\0",  "\x1a"],
                        ['\\\\', "\\'", '\\n', '\\r', '\\0', '\\Z'],
                        (string)$v
                    );
                    $vals[] = "'" . $escaped . "'";
                }
            }
            $batch[] = '(' . implode(', ', $vals) . ')';

            if (count($batch) >= $batch_max) {
                echo "INSERT INTO `{$table}` ({$cols}) VALUES\n"
                   . implode(",\n", $batch) . ";\n";
                $batch = [];
            }
        }

        if (!empty($batch)) {
            echo "INSERT INTO `{$table}` ({$cols}) VALUES\n"
               . implode(",\n", $batch) . ";\n";
        }
    }

    echo "\nSET FOREIGN_KEY_CHECKS = 1;\n";
    echo "\n-- ===== END OF DUMP =====\n";
    exit;
}

// ── Page render ───────────────────────────────────────────────────────────
$dump_err = $dump_err ?? null;
render_page:

// Check table counts for each DB
$db_stats = [];
foreach ($sc_dbs as $key => $db) {
    $fn = $db['pdo_fn'];
    try {
        $p = $fn();
        $tables = $p->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $row_counts = [];
        foreach ($tables as $t) {
            $cnt = (int)$p->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
            $row_counts[$t] = $cnt;
        }
        $db_stats[$key] = ['ok' => true, 'tables' => $row_counts];
    } catch (Throwable $e) {
        $db_stats[$key] = ['ok' => false, 'error' => $e->getMessage()];
    }
}

require __DIR__ . '/sc-layout-top.php';
?>

<div class="sc-page-header">
  <span class="sc-page-title">DB Backup</span>
  <span class="sc-dim">PDO dump — schema + data — no mysqldump required</span>
</div>

<?php if ($dump_err): ?>
<div class="sc-alert sc-alert--warn"><?php echo htmlspecialchars($dump_err); ?></div>
<?php endif; ?>

<?php foreach ($sc_dbs as $key => $db): ?>
<?php $stat = $db_stats[$key]; ?>
<div class="sc-box" style="border-left:4px solid <?php echo $db['colour']; ?>;">
  <div class="sc-box-header">
    <span class="sc-box-title" style="color:<?php echo $db['colour']; ?>;"><?php echo htmlspecialchars($db['label']); ?></span>
    <span class="sc-dim" style="font-size:0.8em;"><?php echo htmlspecialchars($db['db_name']); ?></span>
  </div>
  <div class="sc-box-body">
    <p class="sc-dim" style="font-size:0.85em;margin:0 0 14px;"><?php echo htmlspecialchars($db['desc']); ?></p>

    <?php if (!$stat['ok']): ?>
      <div style="color:#e45735;font-size:0.85em;margin-bottom:12px;">
        &#9888; Connection failed: <?php echo htmlspecialchars($stat['error']); ?>
      </div>
    <?php else: ?>
      <table style="width:100%;border-collapse:collapse;font-size:0.82em;font-family:monospace;margin-bottom:14px;">
        <thead>
          <tr style="border-bottom:1px solid var(--sc-border,#333);">
            <th style="padding:5px 10px;text-align:left;color:var(--sc-dim,#888);">Table</th>
            <th style="padding:5px 10px;text-align:right;color:var(--sc-dim,#888);">Rows</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($stat['tables'] as $tname => $cnt): ?>
          <tr style="border-bottom:1px solid var(--sc-border,#1e1e1e);">
            <td style="padding:4px 10px;"><?php echo htmlspecialchars($tname); ?></td>
            <td style="padding:4px 10px;text-align:right;"><?php echo number_format($cnt); ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($stat['tables'])): ?>
          <tr><td colspan="2" style="padding:8px 10px;color:var(--sc-dim,#888);">No tables found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <a href="sc-backup.php?dump=<?php echo urlencode($key); ?>"
       class="sc-btn"
       <?php echo (!$stat['ok'] || empty($stat['tables'])) ? 'style="opacity:0.4;pointer-events:none;"' : ''; ?>>
      &#8681; Download <?php echo htmlspecialchars($db['label']); ?>.sql
    </a>
  </div>
</div>
<?php endforeach; ?>

<?php require __DIR__ . '/sc-layout-bottom.php'; ?>
<?php // ===== SNAPSMACK EOF =====
