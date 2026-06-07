<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 *
 * tools/check-schema.php — Canonical schema completeness audit
 *
 * Scans all PHP files in the repo for SQL table references and verifies
 * every referenced table exists in database/schema/snapsmack_canonical.sql.
 * Exits 0 if clean, 1 if gaps found.
 *
 * Usage:
 *   php tools/check-schema.php              # audit from repo root
 *   php tools/check-schema.php --quiet      # suppress table-by-table output
 *   php tools/check-schema.php --strict     # exit 1 on warnings too
 *
 * This script is intentionally dependency-free (no DB, no includes).
 */

if (PHP_SAPI !== 'cli') {
    echo "Run from CLI only.\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

$repo_root = dirname(__DIR__);

// Directories to skip when scanning PHP files
$skip_dirs = [
    'vendor',
    'node_modules',
    'smack-central',
    '.git',
];

// Tables that may appear in PHP via dynamic construction and are acceptable
// to not flag as missing (add sparingly)
$known_dynamic = [];

$quiet  = in_array('--quiet',  $argv ?? [], true);
$strict = in_array('--strict', $argv ?? [], true);

// ---------------------------------------------------------------------------
// Step 1: Parse canonical schema — collect defined tables
// ---------------------------------------------------------------------------

$schema_file = $repo_root . '/database/schema/snapsmack_canonical.sql';
if (!file_exists($schema_file)) {
    echo "FATAL: database/schema/snapsmack_canonical.sql not found at {$schema_file}\n";
    exit(1);
}

$schema_sql    = file_get_contents($schema_file);
$schema_tables = [];
preg_match_all(
    '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(snap_[a-z_]+)[`"]?/i',
    $schema_sql,
    $m
);
foreach ($m[1] as $t) {
    $schema_tables[strtolower($t)] = true;
}

if (!$quiet) {
    echo "Canonical schema: " . count($schema_tables) . " tables defined\n";
}

// ---------------------------------------------------------------------------
// Step 2: Scan PHP files — collect referenced tables
// ---------------------------------------------------------------------------

/**
 * Recursively collect .php files under $dir, skipping $skip_dirs.
 */
function collect_php_files(string $dir, array $skip_dirs): array {
    $files = [];
    $it    = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            function ($file, $key, $it) use ($skip_dirs) {
                if ($it->hasChildren()) {
                    return !in_array($file->getFilename(), $skip_dirs, true);
                }
                return $file->getExtension() === 'php';
            }
        ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $file) {
        $files[] = $file->getPathname();
    }
    return $files;
}

$php_files = collect_php_files($repo_root, $skip_dirs);

if (!$quiet) {
    echo "PHP files scanned: " . count($php_files) . "\n";
}

// Regex: SQL keyword immediately followed by a snap_* identifier
// Catches: FROM, JOIN, INTO (INSERT INTO), UPDATE, ALTER TABLE, TRUNCATE TABLE,
//          TRUNCATE, CREATE TABLE (in SQL strings), DROP TABLE
$table_ref_pattern = '/\b(?:FROM|JOIN|INTO|UPDATE|ALTER\s+TABLE|TRUNCATE(?:\s+TABLE)?|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|DROP\s+TABLE(?:\s+IF\s+EXISTS)?)\s+[`"]?(snap_[a-z_]+)[`"]?/i';

// $refs[$table] = [file1, file2, ...]
$refs = [];

foreach ($php_files as $f) {
    $src = file_get_contents($f);
    if ($src === false) continue;

    preg_match_all($table_ref_pattern, $src, $m);
    foreach ($m[1] as $t) {
        $t = strtolower($t);
        if (!isset($refs[$t])) {
            $refs[$t] = [];
        }
        $short = str_replace($repo_root . DIRECTORY_SEPARATOR, '', $f);
        if (!in_array($short, $refs[$t], true)) {
            $refs[$t][] = $short;
        }
    }
}

ksort($refs);

if (!$quiet) {
    echo "Unique snap_* tables referenced in PHP: " . count($refs) . "\n\n";
}

// ---------------------------------------------------------------------------
// Step 3: Cross-reference — find gaps
// ---------------------------------------------------------------------------

$missing  = [];
$warnings = [];

foreach ($refs as $table => $files) {
    if (in_array($table, $known_dynamic, true)) {
        continue; // deliberate dynamic reference, skip
    }
    if (!isset($schema_tables[$table])) {
        $missing[$table] = $files;
    }
}

// Tables in schema but never referenced in PHP (informational only)
$unreferenced = [];
foreach ($schema_tables as $t => $_) {
    if (!isset($refs[$t])) {
        $unreferenced[] = $t;
    }
}

// ---------------------------------------------------------------------------
// Step 4: Report
// ---------------------------------------------------------------------------

if (!$quiet) {
    if ($unreferenced) {
        echo "── Tables in schema but not referenced in PHP (informational) ──\n";
        foreach ($unreferenced as $t) {
            echo "  {$t}\n";
        }
        echo "\n";
    }
}

if (empty($missing)) {
    echo "✓ Schema completeness check PASSED — all PHP-referenced tables exist in canonical schema\n";
    exit(0);
}

echo "✗ Schema completeness check FAILED\n\n";
echo count($missing) . " table(s) referenced in PHP but MISSING from canonical schema:\n\n";
foreach ($missing as $table => $files) {
    echo "  {$table}\n";
    if (!$quiet) {
        foreach ($files as $f) {
            echo "    ↳ {$f}\n";
        }
    }
}
echo "\nFix: add these tables to database/schema/snapsmack_canonical.sql\n";
echo "     and create a migrations/migrate-*.sql with IF NOT EXISTS DDL.\n";

exit(1);
// ===== SNAPSMACK EOF =====
