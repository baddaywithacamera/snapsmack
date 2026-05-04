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
    // 2. COLUMN ADDITIONS — idempotent via INFORMATION_SCHEMA
    //    Each entry: [table, column, ALTER TABLE ... ADD COLUMN ... DDL]
    //    Only runs the ALTER if INFORMATION_SCHEMA confirms the column absent.
    // ─────────────────────────────────────────────────────────────────────────

    $column_additions = [

        // 0.7.4 / 0.7.6 — snap_tags
        ['snap_tags', 'color_family',
            "ALTER TABLE `snap_tags`
             ADD COLUMN `color_family` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `use_count`"],

        // 0.7.6 — snap_community_comments
        ['snap_community_comments', 'guest_name',
            "ALTER TABLE `snap_community_comments`
             ADD COLUMN `guest_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `user_id`"],

        ['snap_community_comments', 'guest_email',
            "ALTER TABLE `snap_community_comments`
             ADD COLUMN `guest_email` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `guest_name`"],

        ['snap_community_comments', 'edited_at',
            "ALTER TABLE `snap_community_comments`
             ADD COLUMN `edited_at` datetime DEFAULT NULL AFTER `created_at`"],

        // 0.7.7 — snap_images
        ['snap_images', 'img_source_file',
            "ALTER TABLE `snap_images`
             ADD COLUMN `img_source_file` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
             COMMENT 'Original filename on the posting machine at upload time' AFTER `img_file`"],

        ['snap_images', 'sort_order',
            "ALTER TABLE `snap_images`
             ADD COLUMN `sort_order` int NOT NULL DEFAULT 0
             COMMENT 'Manual display order. Lower = earlier in feed. 0 = unset (falls back to img_date DESC).' AFTER `post_id`"],

        // 0.7.8b — snap_users (recovery code + forced password change)
        ['snap_users', 'recovery_code_hash',
            "ALTER TABLE `snap_users`
             ADD COLUMN `recovery_code_hash` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `preferred_skin`"],

        ['snap_users', 'force_password_change',
            "ALTER TABLE `snap_users`
             ADD COLUMN `force_password_change` tinyint(1) NOT NULL DEFAULT 0 AFTER `recovery_code_hash`"],

        // 0.7.8 — snap_pages
        ['snap_pages', 'image_size',
            "ALTER TABLE `snap_pages`
             ADD COLUMN `image_size` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'full' AFTER `image_asset`"],

        ['snap_pages', 'image_align',
            "ALTER TABLE `snap_pages`
             ADD COLUMN `image_align` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'center' AFTER `image_size`"],

        ['snap_pages', 'image_shadow',
            "ALTER TABLE `snap_pages`
             ADD COLUMN `image_shadow` tinyint(1) NOT NULL DEFAULT 0 AFTER `image_align`"],

        // 0.7.9f — snap_categories (archive visibility toggle)
        ['snap_categories', 'show_in_archive',
            "ALTER TABLE `snap_categories`
             ADD COLUMN `show_in_archive` tinyint(1) NOT NULL DEFAULT 1
             COMMENT '1 = visible in public archive; 0 = hidden'"],

    ];

    $col_check = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = ?
           AND COLUMN_NAME  = ?"
    );

    foreach ($column_additions as [$table, $column, $ddl]) {
        try {
            $col_check->execute([$table, $column]);
            $exists = (int) $col_check->fetchColumn();

            if ($exists) {
                $report['skipped'][] = "{$table}.{$column}";
                continue;
            }

            $ps = $pdo->query($ddl);
            if ($ps !== false) $ps->closeCursor();
            $report['columns_added'][] = "{$table}.{$column}";

        } catch (\PDOException $e) {
            $report['errors'][] = "ALTER {$table}.{$column}: " . $e->getMessage();
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
// EOF
