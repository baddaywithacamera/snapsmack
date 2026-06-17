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
 * Extract the COMPLETE set of column names from a canonical CREATE TABLE DDL.
 * Reads the raw DDL (not the lossy typed-regex of snap_canonical_col_meta), so
 * EVERY column is captured regardless of its data type. Used by the drift
 * detector — a column we fail to recognise as canonical here could be flagged as
 * debris and dropped, so completeness matters more than type detail.
 *
 * @return array<string,true> column name => true
 */
function _snap_canonical_columns_from_ddl(string $ddl): array {
    $start = strpos($ddl, '(');
    $end   = strrpos($ddl, ')');
    if ($start === false || $end === false || $end <= $start) return [];
    $body = substr($ddl, $start + 1, $end - $start - 1);

    $cols = [];
    foreach (explode("\n", $body) as $raw) {
        $line = trim($raw, " \t,");
        if ($line === '') continue;
        // Skip key / constraint definition lines — only column lines count.
        if (preg_match('/^(PRIMARY\s+KEY|UNIQUE\s+KEY|UNIQUE|KEY|INDEX|CONSTRAINT|FOREIGN\s+KEY|FULLTEXT|SPATIAL|CHECK)\b/i', $line)) {
            continue;
        }
        if (preg_match('/^`([^`]+)`/', $line, $m)) {
            $cols[$m[1]] = true;
        }
    }
    return $cols;
}

/**
 * REVERSE diff — what exists in the LIVE DB but NOT in canonical (schema debris).
 *
 * The forward sync (snap_schema_sync) is additive/corrective only: it ADDs and
 * MODIFYs whatever canonical requires and NEVER removes — by design, so a
 * canonical typo can't auto-drop a column across the fleet. The cost is that
 * leftovers (columns/tables from dropped features or old migrations) accumulate
 * silently. This surfaces them. READ-ONLY — alters nothing.
 *
 * @return array{extra_tables:string[], extra_columns:array<string,string[]>}
 */
function snap_schema_drift(PDO $pdo): array {
    $out = ['extra_tables' => [], 'extra_columns' => []];

    try {
        $canon = snap_parse_canonical_schema();   // [table => full DDL]
    } catch (Exception $e) {
        // Can't parse canonical — report nothing rather than risk false debris.
        return $out;
    }

    $canon_cols = [];
    foreach ($canon as $t => $ddl) {
        $canon_cols[$t] = _snap_canonical_columns_from_ddl($ddl);
    }

    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();

    // Tables present live but absent from canonical.
    $lt = $pdo->prepare(
        "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'"
    );
    $lt->execute([$db]);
    foreach ($lt->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if (!isset($canon_cols[$t])) {
            $out['extra_tables'][] = $t;
        }
    }

    // Columns present live but absent from canonical — but ONLY on tables that
    // ARE canonical (an extra table is reported whole, above) and whose columns
    // parsed cleanly (empty set = parse failure → skip, never guess).
    $lc = $pdo->prepare(
        "SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = ?
         ORDER BY TABLE_NAME, ORDINAL_POSITION"
    );
    $lc->execute([$db]);
    foreach ($lc->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $t = $row['TABLE_NAME'];
        $c = $row['COLUMN_NAME'];
        if (!isset($canon_cols[$t]) || empty($canon_cols[$t])) continue;
        if (!isset($canon_cols[$t][$c])) {
            $out['extra_columns'][$t][] = $c;
        }
    }

    return $out;
}

/**
 * Prune schema debris. DESTRUCTIVE — callers MUST gate this behind
 * reauth_verify() (password + 2FA) per [[feedback_stepup_auth_pass_plus_2fa]].
 *
 * Safety: re-computes drift right now and only drops items that are STILL drift
 * (guards against stale form data / a canonical update between view and prune),
 * and validates every identifier. A canonical table/column can never be dropped.
 *
 * @param string[]                 $drop_tables
 * @param array<string,string[]>   $drop_columns  table => [col, ...]
 * @return array{dropped_tables:string[],dropped_columns:string[],skipped:string[],errors:string[]}
 */
function snap_schema_prune(PDO $pdo, array $drop_tables, array $drop_columns): array {
    $report = ['dropped_tables' => [], 'dropped_columns' => [], 'skipped' => [], 'errors' => []];

    $current      = snap_schema_drift($pdo);
    $extra_tables = array_flip($current['extra_tables']);
    $extra_cols   = $current['extra_columns'];

    foreach ($drop_tables as $t) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$t)) { $report['skipped'][] = "table {$t} (invalid name)"; continue; }
        if (!isset($extra_tables[$t]))                    { $report['skipped'][] = "table {$t} (no longer drift)"; continue; }
        try {
            $pdo->exec("DROP TABLE `{$t}`");
            $report['dropped_tables'][] = $t;
        } catch (\PDOException $e) {
            $report['errors'][] = "DROP TABLE {$t}: " . $e->getMessage();
        }
    }

    foreach ($drop_columns as $t => $cols) {
        foreach ((array)$cols as $c) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$t) || !preg_match('/^[A-Za-z0-9_]+$/', (string)$c)) {
                $report['skipped'][] = "{$t}.{$c} (invalid name)"; continue;
            }
            if (!isset($extra_cols[$t]) || !in_array($c, $extra_cols[$t], true)) {
                $report['skipped'][] = "{$t}.{$c} (no longer drift)"; continue;
            }
            try {
                $pdo->exec("ALTER TABLE `{$t}` DROP COLUMN `{$c}`");
                $report['dropped_columns'][] = "{$t}.{$c}";
            } catch (\PDOException $e) {
                $report['errors'][] = "DROP {$t}.{$c}: " . $e->getMessage();
            }
        }
    }

    return $report;
}

/**
 * Returns true if two normalised type strings are platform-equivalent.
 * MariaDB stores JSON columns as LONGTEXT internally and INFORMATION_SCHEMA
 * reports 'longtext' for them, so ALTER TABLE ... json is a no-op there.
 * Treating json/longtext as equivalent prevents phantom corrections on MariaDB.
 */
function snap_types_equivalent(string $a, string $b): bool {
    if ($a === $b) return true;
    static $json_compat = ['json', 'longtext'];
    return in_array($a, $json_compat, true) && in_array($b, $json_compat, true);
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
                            if (!snap_types_equivalent($live_t, $meta['type']) || $live_n !== $meta['nullable']) {
                                // Column exists but wrong type or nullability — MODIFY it
                                $pdo->exec("ALTER TABLE `{$tbl}` MODIFY COLUMN `{$col}` {$def_sql}");
                                $why = [];
                                if ($live_t !== $meta['type']) {
                                    $why[] = $live_t . ' → ' . $meta['type'];
                                }
                                if ($live_n !== $meta['nullable']) {
                                    $why[] = ($live_n ? 'nullable' : 'NOT NULL') . ' → ' . ($meta['nullable'] ? 'nullable' : 'NOT NULL');
                                }
                                $report['columns_added'][] = "[schema-sync] {$tbl}.{$col} (corrected: " . implode(', ', $why) . ")";
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
