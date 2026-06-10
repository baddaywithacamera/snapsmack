<?php
/**
 * SNAPSMACK - Schema Sync Engine
 *
 * Declares the canonical database schema for the current version and applies
 * any missing tables or columns against a live database. All operations are
 * idempotent — safe to run on any install at any version, as many times as
 * needed.
 *
 * CREATE TABLE IF NOT EXISTS handles tables missing entirely (fresh installs
 * or features added in later versions). INFORMATION_SCHEMA column checks
 * handle columns added to existing tables in point releases.
 *
 * This replaces the fragile migration-chain approach for schema structure.
 * Versioned migration files in /migrations/ should only contain data changes:
 * seed rows, value transforms, or renames that cannot be expressed as
 * idempotent column additions.
 *
 * Usage:
 *   require_once 'core/schema-sync.php';
 *   $report = snap_schema_sync($pdo);
 *   // $report['created']       — tables created
 *   // $report['columns_added'] — columns added
 *   // $report['skipped']       — already-present items (no-op)
 *   // $report['errors']        — failures with message
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */




/**
 * Parse CREATE TABLE statements from the canonical schema SQL file.
 * Returns an associative array: table_name => CREATE TABLE DDL string.
 */
function snap_parse_canonical_schema(): array {
    $canonical_path = __DIR__ . '/../database/schema/snapsmack_canonical.sql';

    if (!file_exists($canonical_path)) {
        throw new RuntimeException("Canonical schema file not found: {$canonical_path}");
    }

    $sql_content = file_get_contents($canonical_path);
    if ($sql_content === false) {
        throw new RuntimeException("Failed to read canonical schema file: {$canonical_path}");
    }

    $create_tables = [];
    $current_statement = '';
    $in_create_table = false;
    $table_name = '';
    $paren_depth = 0;

    // Split into lines for easier processing
    $lines = explode("\n", $sql_content);

    foreach ($lines as $line) {
        $line = rtrim($line);

        // Skip empty lines and comment-only lines
        if (empty($line) || preg_match('/^\s*--/', $line)) {
            if ($in_create_table) {
                $current_statement .= "\n" . $line;
            }
            continue;
        }

        if (!$in_create_table) {
            // Check if this line starts a CREATE TABLE statement
            if (preg_match('/^\s*CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`(\w+)`/i', $line, $m)) {
                $table_name = $m[1];
                $in_create_table = true;
                $current_statement = $line;
                $paren_depth = substr_count($line, '(') - substr_count($line, ')');
            }
        } else {
            // We're inside a CREATE TABLE statement
            $current_statement .= "\n" . $line;

            // Update paren depth (accounting for strings to avoid false counts)
            $depth_change = 0;
            $in_string = false;
            for ($i = 0; $i < strlen($line); $i++) {
                $char = $line[$i];
                if ($char === "'" && ($i === 0 || $line[$i-1] !== '\\')) {
                    $in_string = !$in_string;
                }
                if (!$in_string) {
                    if ($char === '(') $depth_change++;
                    elseif ($char === ')') $depth_change--;
                }
            }
            $paren_depth += $depth_change;

            // Check if statement is complete (semicolon at end and parens balanced)
            if ($paren_depth === 0 && preg_match('/;\s*$/', $line)) {
                $ddl = trim($current_statement);
                $create_tables[$table_name] = $ddl;

                $in_create_table = false;
                $current_statement = '';
                $table_name = '';
            }
        }
    }

    if (empty($create_tables)) {
        throw new RuntimeException("No CREATE TABLE statements found in canonical schema file");
    }

    return $create_tables;
}

/**
 * Parse per-column type/nullable metadata from the canonical schema file.
 * Returns [table_name => [col_name => ['def_sql'=>string, 'type'=>string, 'nullable'=>bool]]]
 * Used by snap_schema_sync() to replace the old hardcoded column-additions list.
 */
function snap_canonical_col_meta(): array {
    $path = __DIR__ . '/../database/schema/snapsmack_canonical.sql';
    if (!file_exists($path)) return [];

    $sql = file_get_contents($path);
    if ($sql === false) return [];
    $sql = str_replace("\r\n", "\n", $sql);
    $sql = preg_replace('/--[^\n]*/', '', $sql); // strip -- comments, not COMMENT '...'

    $col_pat = '/^`?(\w+)`?\s+((?:INT|BIGINT|TINYINT|SMALLINT|MEDIUMINT|'
             . 'VARCHAR|CHAR|TEXT|MEDIUMTEXT|LONGTEXT|DECIMAL|FLOAT|DOUBLE|'
             . 'TIMESTAMP|DATETIME|DATE|TIME|YEAR|ENUM|SET|JSON|'
             . 'BLOB|MEDIUMBLOB|LONGBLOB)\b.*)/i';

    $result = [];
    foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
        $stmt = trim($stmt);
        if (!preg_match(
            '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\((.+)\)\s*ENGINE\s*=/si',
            $stmt, $m
        )) continue;

        $table = $m[1];
        $body  = $m[2];
        $cols  = [];

        foreach (explode("\n", $body) as $raw) {
            $line = trim($raw, " \t,");
            if (!preg_match($col_pat, $line, $cm)) continue;
            $col_name = $cm[1];
            $cols[$col_name] = [
                'def_sql'  => $line,
                'type'     => snap_sync_col_type($line),
                'nullable' => !preg_match('/\bNOT\s+NULL\b/i', $line),
            ];
        }

        if ($cols) $result[$table] = $cols;
    }
    return $result;
}

/**
 * Normalize a column type string for comparison in schema-sync.
 * Accepts a raw COLUMN_TYPE value ('varchar(500)') or a full canonical
 * column-def line ('`title` text COLLATE ... NOT NULL').
 * Returns lowercase, whitespace-collapsed type token.
 * Strips MySQL 5.x integer display widths (e.g. int(11) → int);
 * preserves tinyint(1).
 */
function snap_sync_col_type(string $input): string {
    $s = preg_replace('/^`\w+`\s+/i', '', trim($input));
    if (preg_match('/^(\w+(?:\s*\([^)]+\))?(?:\s+UNSIGNED|\s+SIGNED)?)/i', $s, $m)) {
        $t = strtolower(preg_replace('/\s+/', ' ', trim($m[1])));
    } else {
        $t = strtolower(strtok(trim($s), ' ') ?: $s);
    }
    if ($t !== 'tinyint(1)') {
        $t = preg_replace(
            '/^(bigint|int|mediumint|smallint|tinyint)\(\d+\)(\s+unsigned)?$/',
            '$1$2', $t
        );
        $t = trim($t);
    }
    return $t;
}

/**
 * Apply the canonical schema to the connected database.
 * Returns a report array (see file header).
 */
function snap_schema_sync(PDO $pdo): array {

    $report = [
        'created'       => [],
        'columns_added' => [],
        'skipped'       => [],
        'errors'        => [],
    ];

    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();

    // ─────────────────────────────────────────────────────────────────────────
    // 1. TABLES — CREATE IF NOT EXISTS
    //    Parsed from the canonical schema file (single source of truth).
    //    Each statement includes the full current column set so that fresh
    //    installs get everything in one pass. Existing tables are untouched.
    // ─────────────────────────────────────────────────────────────────────────

    try {
        $create_tables = snap_parse_canonical_schema();
    } catch (Exception $e) {
        return [
            'created'       => [],
            'columns_added' => [],
            'skipped'       => [],
            'errors'        => ["Failed to parse canonical schema: " . $e->getMessage()],
        ];
    }

    foreach ($create_tables as $table => $ddl) {
        try {
            $ps = $pdo->query($ddl);
            if ($ps !== false) $ps->closeCursor();

            // Check whether it existed already by seeing if we actually created it
            $exists_before = (int) $pdo->query(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table)
            )->fetchColumn();

            // We can't easily tell "created vs already existed" post-hoc with IF NOT EXISTS,
            // so just report it as verified.
            $report['skipped'][] = $table . ' (verified)';

        } catch (\PDOException $e) {
            $report['errors'][] = "CREATE {$table}: " . $e->getMessage();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. COLUMN SYNC — canonical diff: ADD missing columns, MODIFY wrong-type
    //    Reads snapsmack_canonical.sql, extracts per-column type + nullable,
    //    diffs against INFORMATION_SCHEMA, and applies ADD COLUMN or MODIFY
    //    COLUMN as needed. Fully replaces the old hardcoded column list.
    //    Idempotent — safe to run on any install at any version.
    // ─────────────────────────────────────────────────────────────────────────

    $canonical_cols = snap_canonical_col_meta();

    if (!empty($canonical_cols)) {
        try {
            $sync_db   = $pdo->query('SELECT DATABASE()')->fetchColumn();
            $lcol_stmt = $pdo->prepare("
                SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = ?
                ORDER BY TABLE_NAME, ORDINAL_POSITION
            ");
            $lcol_stmt->execute([$sync_db]);
            $live_schema = [];
            foreach ($lcol_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $live_schema[$row['TABLE_NAME']][$row['COLUMN_NAME']] = [
                    'type'     => snap_sync_col_type($row['COLUMN_TYPE']),
                    'nullable' => ($row['IS_NULLABLE'] === 'YES'),
                ];
            }

            foreach ($canonical_cols as $tbl => $cols) {
                if (!isset($live_schema[$tbl])) continue; // table just created above — all cols present

                foreach ($cols as $col => $meta) {
                    try {
                        $def_sql = preg_replace('/\s+COMMENT\s+\'[^\']*\'/i', '', $meta['def_sql']);
                        $def_sql = trim(preg_replace('/^`?' . preg_quote($col, '/') . '`?\s+/i', '', $def_sql));

                        if (!isset($live_schema[$tbl][$col])) {
                            // Column is missing — ADD it
                            $pdo->exec("ALTER TABLE `{$tbl}` ADD COLUMN `{$col}` {$def_sql}");
                            $report['columns_added'][] = "{$tbl}.{$col}";
                        } else {
                            $live_t = $live_schema[$tbl][$col]['type'];
                            $live_n = $live_schema[$tbl][$col]['nullable'];
                            if ($live_t !== $meta['type'] || $live_n !== $meta['nullable']) {
                                // Column exists but wrong type or nullability — MODIFY it
                                $pdo->exec("ALTER TABLE `{$tbl}` MODIFY COLUMN `{$col}` {$def_sql}");
                                $report['columns_added'][] = "{$tbl}.{$col} (corrected: {$live_t} → {$meta['type']})";
                            } else {
                                $report['skipped'][] = "{$tbl}.{$col}";
                            }
                        }
                    } catch (\PDOException $e) {
                        $report['errors'][] = "SYNC {$tbl}.{$col}: " . $e->getMessage();
                    }
                }
            }
        } catch (\PDOException $e) {
            $report['errors'][] = "Column sync query failed: " . $e->getMessage();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. INDEX ADDITIONS — idempotent via INFORMATION_SCHEMA.STATISTICS
    // ─────────────────────────────────────────────────────────────────────────

    $index_additions = [
        ['snap_tags', 'idx_tags_color_family',
            "ALTER TABLE `snap_tags` ADD INDEX `idx_tags_color_family` (`color_family`)"],
    ];

    $idx_check = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = ?
           AND INDEX_NAME   = ?"
    );

    foreach ($index_additions as [$table, $index, $ddl]) {
        try {
            $idx_check->execute([$table, $index]);
            $exists = (int) $idx_check->fetchColumn();

            if ($exists) {
                $report['skipped'][] = "INDEX {$table}.{$index}";
                continue;
            }

            $ps = $pdo->query($ddl);
            if ($ps !== false) $ps->closeCursor();
            $report['columns_added'][] = "INDEX {$table}.{$index}";

        } catch (\PDOException $e) {
            $report['errors'][] = "ADD INDEX {$table}.{$index}: " . $e->getMessage();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. ENUM REPAIRS — fix enum columns on existing tables
    //    CREATE TABLE IF NOT EXISTS cannot update an existing column type.
    //    This section detects stale enum values and ALTERs them to match the
    //    canonical schema. Each entry checks COLUMN_TYPE before acting.
    // ─────────────────────────────────────────────────────────────────────────

    $enum_repairs = [

        // Migration 032: satellite → spoke rename. If the table existed before
        // that migration, the enum may still say ('hub','satellite').
        [
            'table'    => 'snap_multisite_nodes',
            'column'   => 'role',
            'bad'      => "'satellite'",                     // substring present in stale enum
            'good'     => "'spoke'",                         // substring expected in repaired enum
            'alter'    => "ALTER TABLE `snap_multisite_nodes`
                           MODIFY COLUMN `role` enum('hub','satellite','spoke') COLLATE utf8mb4_unicode_ci NOT NULL",
            'update'   => "UPDATE `snap_multisite_nodes` SET `role` = 'spoke' WHERE `role` = 'satellite'",
            'fix_blank'=> "UPDATE `snap_multisite_nodes` SET `role` = 'spoke' WHERE `role` = '' AND `site_url` != ''",
            'finalise' => "ALTER TABLE `snap_multisite_nodes`
                           MODIFY COLUMN `role` enum('hub','spoke') COLLATE utf8mb4_unicode_ci NOT NULL",
        ],

    ];

    $col_type_check = $pdo->prepare(
        "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = ?
           AND COLUMN_NAME  = ?"
    );

    foreach ($enum_repairs as $repair) {
        try {
            $col_type_check->execute([$repair['table'], $repair['column']]);
            $col_type = $col_type_check->fetchColumn();

            if ($col_type === false) {
                // Table or column doesn't exist yet — nothing to repair.
                $report['skipped'][] = "ENUM {$repair['table']}.{$repair['column']} (column absent)";
                continue;
            }

            if (str_contains($col_type, $repair['good']) && !str_contains($col_type, $repair['bad'])) {
                // Already correct.
                $report['skipped'][] = "ENUM {$repair['table']}.{$repair['column']}";
                continue;
            }

            // Widen enum to include both old and new values
            $pdo->exec($repair['alter']);

            // Migrate rows from old value to new
            $changed = $pdo->exec($repair['update']);
            $report['columns_added'][] = "ENUM {$repair['table']}.{$repair['column']}: migrated {$changed} row(s)";

            // Fix any blank rows left by MySQL silent-fail inserts
            if (!empty($repair['fix_blank'])) {
                $blank_fixed = $pdo->exec($repair['fix_blank']);
                if ($blank_fixed > 0) {
                    $report['columns_added'][] = "ENUM {$repair['table']}.{$repair['column']}: fixed {$blank_fixed} blank row(s)";
                }
            }

            // Shrink enum to canonical set
            $pdo->exec($repair['finalise']);

        } catch (\PDOException $e) {
            $report['errors'][] = "ENUM REPAIR {$repair['table']}.{$repair['column']}: " . $e->getMessage();
        }
    }

    return $report;
}
// ===== SNAPSMACK EOF =====
