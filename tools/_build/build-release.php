<?php
/**
 * SNAPSMACK - Release Builder
 *
 * Builds a core update package between two git tags. Uses git diff to
 * determine added/modified/deleted files, filters out protected paths,
 * copies changed files into a zip preserving directory structure.
 *
 * The updater (core/updater.php) auto-detects wrapper folders, so the
 * zip uses no top-level wrapper — files go in at their project-relative
 * paths directly.
 *
 * USAGE:
 *   php build-release.php --from v0.7 --to v0.8
 *   php build-release.php --from v0.7 --to v0.8 --output ./releases/
 *
 * REQUIREMENTS:
 *   - Git available on PATH
 *   - Run from within the SnapSmack git repository
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


if (php_sapi_name() !== 'cli') {
    die("This tool must be run from the command line.\n");
}

// ─── PATHS ──────────────────────────────────────────────────────────────────

$project_root = dirname(__DIR__);

// ─── ARGUMENT PARSING ───────────────────────────────────────────────────────

$args = parse_args($argv);

if (!$args['from'] || !$args['to']) {
    echo "SNAPSMACK RELEASE BUILDER\n";
    echo "─────────────────────────\n\n";
    echo "Usage:\n";
    echo "  php build-release.php --from TAG --to TAG [--output DIR]\n\n";
    echo "Options:\n";
    echo "  --from TAG       Starting git tag (e.g. v0.7)\n";
    echo "  --to TAG         Ending git tag or branch (e.g. v0.8)\n";
    echo "  --output DIR     Output directory (default: project root)\n\n";
    echo "Examples:\n";
    echo "  php build-release.php --from v0.7 --to v0.8\n";
    echo "  php build-release.php --from v0.7 --to v0.8 --output ./releases/\n\n";
    exit(0);
}

$from_tag   = $args['from'];
$to_tag     = $args['to'];
$output_dir = $args['output'] ?? $project_root;

if (!is_dir($output_dir)) {
    mkdir($output_dir, 0755, true);
}

// ─── VERIFY GIT ─────────────────────────────────────────────────────────────

chdir($project_root);

$git_check = shell_exec('git rev-parse --is-inside-work-tree 2>&1');
if (trim($git_check) !== 'true') {
    die("ERROR: Not inside a git repository. Run this from the SnapSmack project root.\n");
}

// Verify tags exist
$from_exists = trim(shell_exec("git tag -l " . escapeshellarg($from_tag) . " 2>&1"));
$to_exists   = trim(shell_exec("git tag -l " . escapeshellarg($to_tag) . " 2>&1"));

if (empty($from_exists)) {
    die("ERROR: Tag '{$from_tag}' not found. Run: git tag -l\n");
}
if (empty($to_exists)) {
    // Allow branch names too (for building from HEAD before tagging)
    $branch_check = trim(shell_exec("git rev-parse --verify " . escapeshellarg($to_tag) . " 2>&1"));
    if (empty($branch_check) || str_contains($branch_check, 'fatal')) {
        die("ERROR: Tag or branch '{$to_tag}' not found.\n");
    }
    echo "  NOTE  '{$to_tag}' is not a tag, using as ref\n";
}

// ─── LOAD PROTECTED PATHS ──────────────────────────────────────────────────

$protected_file = $project_root . '/protected_paths.json';
$protected = [];

if (file_exists($protected_file)) {
    $pdata = json_decode(file_get_contents($protected_file), true);
    $protected = $pdata['protected'] ?? [];
    echo "  Loaded " . count($protected) . " protected path rules\n";
} else {
    echo "  WARN  protected_paths.json not found, using defaults\n";
    $protected = [
        'core/db.php', 'core/constants.php', 'core/release-pubkey.php',
        'protected_paths.json', 'img_uploads/', 'media_assets/',
        'assets/img/', 'backups/', 'skins/', '.htaccess', 'robots.txt',
    ];
}

// ─── GIT DIFF ───────────────────────────────────────────────────────────────

echo "\nAnalysing changes from {$from_tag} to {$to_tag}...\n";
echo "──────────────────────────────────────────────────\n";

$diff_cmd = sprintf(
    'git diff --name-status %s...%s 2>&1',
    escapeshellarg($from_tag),
    escapeshellarg($to_tag)
);

$diff_output = shell_exec($diff_cmd);
if ($diff_output === null) {
    die("ERROR: git diff failed.\n");
}

$lines = array_filter(explode("\n", trim($diff_output)));
if (empty($lines)) {
    die("ERROR: No changes found between {$from_tag} and {$to_tag}.\n");
}

$added    = [];
$modified = [];
$deleted  = [];
$skipped  = [];

foreach ($lines as $line) {
    $parts = preg_split('/\t+/', $line, 2);
    if (count($parts) !== 2) continue;

    $status = $parts[0];
    $file   = $parts[1];

    // Handle renames (R100, etc.)
    if (str_starts_with($status, 'R')) {
        $rename_parts = preg_split('/\t+/', $line, 3);
        if (count($rename_parts) === 3) {
            $file = $rename_parts[2]; // Use the destination path
            $status = 'A'; // Treat rename destination as added
        }
    }

    // Check if protected
    if (is_protected($file, $protected)) {
        $skipped[] = $file;
        continue;
    }

    switch ($status[0]) {
        case 'A':
            $added[] = $file;
            break;
        case 'M':
            $modified[] = $file;
            break;
        case 'D':
            $deleted[] = $file;
            break;
    }
}

echo "  Added:     " . count($added) . "\n";
echo "  Modified:  " . count($modified) . "\n";
echo "  Deleted:   " . count($deleted) . "\n";
echo "  Protected: " . count($skipped) . " (excluded)\n";

$files_to_include = array_merge($added, $modified);

if (empty($files_to_include) && empty($deleted)) {
    die("\nERROR: No distributable changes after filtering protected paths.\n");
}

// ─── CHECK FOR MIGRATION ───────────────────────────────────────────────────

// Extract version numbers from tags (strip 'v' prefix)
$from_ver = ltrim($from_tag, 'v');
$to_ver   = ltrim($to_tag, 'v');
$migration_file = "migrations/migrate-{$from_ver}-{$to_ver}.sql";
$has_migration = false;

if (file_exists($project_root . '/' . $migration_file)) {
    $has_migration = true;
    echo "\n  Migration found: {$migration_file}\n";
    // Ensure migration is included even if not in git diff
    if (!in_array($migration_file, $files_to_include)) {
        $files_to_include[] = $migration_file;
        echo "  (Added to package)\n";
    }
}

// ─── BUILD ZIP ──────────────────────────────────────────────────────────────

$zip_name = "snapsmack-{$to_ver}.zip";
$zip_path = rtrim($output_dir, '/') . '/' . $zip_name;

echo "\nBuilding {$zip_name}...\n";

$zip = new ZipArchive();
if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die("ERROR: Could not create {$zip_path}\n");
}

$file_count = 0;
$errors = 0;

foreach ($files_to_include as $file) {
    // Get file content from the target ref (not working tree)
    $content = shell_exec(sprintf(
        'git show %s:%s 2>/dev/null',
        escapeshellarg($to_tag),
        escapeshellarg($file)
    ));

    if ($content === null) {
        echo "  WARN  Could not read {$file} from {$to_tag}\n";
        $errors++;
        continue;
    }

    // Allowlist gate (default-deny) — mirror sc-release.php. Ship only runtime
    // file types; drop docs (.md/.docx), lockfiles and dev-meta even when they
    // appear in the changed-file set. .sql is allowed because this differential
    // builder explicitly ships migration files; protected_paths.json, the
    // htaccess-template and the canonical schema are runtime non-allowlist files
    // that must pass.
    $b_ext   = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $b_keep  = ['php','css','js','txt','png','jpg','jpeg','gif','svg','ico','webp','sql'];
    $b_force = ['protected_paths.json', 'core/htaccess-template', 'database/schema/snapsmack_canonical.sql'];
    if (!in_array($b_ext, $b_keep, true) && !in_array($file, $b_force, true)) {
        echo "  SKIP  {$file} (not a runtime file type)\n";
        continue;
    }

    $zip->addFromString($file, $content);
    $file_count++;
}

// ─── SMACKBACK MANIFEST ─────────────────────────────────────────────────────
// Generate per-file SHA-256 hashes for ALL monitored files at $to_tag.
// Written into the ZIP before signing. Covered by the Ed25519 signature.

echo "\nGenerating SMACKBACK manifest...\n";

$all_files_raw = shell_exec(
    'git ls-tree -r --name-only ' . escapeshellarg($to_tag) . ' 2>/dev/null'
);

$smackback_files  = [];
$smackback_errors = 0;

if ($all_files_raw) {
    $all_files = array_filter(explode("\n", trim($all_files_raw)));
    foreach ($all_files as $rel_path) {
        if (!smackback_build_should_monitor($rel_path)) {
            continue;
        }
        $content = shell_exec(sprintf(
            'git show %s:%s 2>/dev/null',
            escapeshellarg($to_tag),
            escapeshellarg($rel_path)
        ));
        if ($content === null) {
            echo "  WARN  SMACKBACK: Could not read {$rel_path}\n";
            $smackback_errors++;
            continue;
        }
        $smackback_files[$rel_path] = [
            'hash'          => hash('sha256', $content),
            'size'          => strlen($content),
            'eof_signature' => smackback_build_eof_signature($content),
        ];
    }
}

$smackback_manifest = json_encode([
    'smackback_version' => 1,
    'package_version'   => $to_ver,
    'generated_at'      => gmdate('Y-m-d\TH:i:s\Z'),
    'files'             => $smackback_files,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

$zip->addFromString('smackback-manifest.json', $smackback_manifest);

$manifest_count = count($smackback_files);
echo "  → {$manifest_count} files hashed" . ($smackback_errors ? ", {$smackback_errors} errors" : '') . "\n";

$zip->close();

$checksum = hash_file('sha256', $zip_path);
$filesize = filesize($zip_path);

echo "\n  → {$zip_name} ({$file_count} files, " . number_format($filesize) . " bytes)\n";
echo "  → SHA-256: {$checksum}\n";

// ─── FILE MANIFEST ──────────────────────────────────────────────────────────

echo "\nFILE CHANGES SUMMARY\n";
echo "════════════════════\n";

if (!empty($added)) {
    echo "\nAdded:\n";
    foreach ($added as $f) echo "  + {$f}\n";
}
if (!empty($modified)) {
    echo "\nModified:\n";
    foreach ($modified as $f) echo "  ~ {$f}\n";
}
if (!empty($deleted)) {
    echo "\nDeleted:\n";
    foreach ($deleted as $f) echo "  - {$f}\n";
}
if (!empty($skipped)) {
    echo "\nProtected (excluded):\n";
    foreach ($skipped as $f) echo "  # {$f}\n";
}

// ─── LATEST.JSON TEMPLATE ───────────────────────────────────────────────────

echo "\n\nLATEST.JSON TEMPLATE\n";
echo "════════════════════\n";
echo "Sign this package with sign-release.php, then copy the JSON into:\n";
echo "https://snapsmack.ca/releases/latest.json\n\n";

$template = [
    'version'         => $to_ver,
    'version_full'    => "Alpha {$to_ver}",
    'codename'        => '',
    'released'        => date('Y-m-d'),
    'download_url'    => "https://snapsmack.ca/releases/{$zip_name}",
    'checksum_sha256' => $checksum,
    'signature'       => 'SIGN_WITH_sign-release.php',
    'download_size'   => $filesize,
    'requires_php'    => '8.0',
    'requires_mysql'  => '5.7',
    'schema_changes'  => $has_migration,
    'changelog'       => parse_changelog($project_root . '/CHANGELOG.md', $to_ver),
    'file_changes'    => [
        'added'    => $added,
        'modified' => $modified,
        'removed'  => $deleted,
    ],
];

echo json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

echo "\nNEXT STEPS:\n";
echo "  1. php tools/sign-release.php {$zip_name} --version {$to_ver}\n";
echo "  2. Review changelog entries above (auto-pulled from CHANGELOG.md)\n";
echo "  3. Upload {$zip_name} to https://snapsmack.ca/releases/\n";
echo "  4. Upload latest.json to https://snapsmack.ca/releases/\n";
echo "  5. Test: Admin → System Updates → Check for Updates\n";

exit($errors > 0 ? 1 : 0);


// ─── HELPERS ────────────────────────────────────────────────────────────────

/**
 * Parse CHANGELOG.md and return changelog entries for the given version as a
 * flat array of strings, prefixed with the section heading (Added/Fixed/etc.)
 * so the updater can display them without needing the full document.
 *
 * Looks for a heading matching "## {version}" (with any trailing text) and
 * collects every bullet point up to the next "## " heading.
 *
 * Falls back to a prompt string if the version section isn't found.
 */
function parse_changelog(string $changelog_path, string $version): array
{
    if (!file_exists($changelog_path)) {
        return ["See CHANGELOG.md for details (file not found during build)"];
    }

    $lines   = file($changelog_path, FILE_IGNORE_NEW_LINES);
    $entries = [];
    $in_ver  = false;
    $section = '';

    foreach ($lines as $line) {
        // Detect version heading: "## 0.7.8e — ..." or "## 0.7.8e"
        if (preg_match('/^## ' . preg_quote($version, '/') . '(\s|$)/', $line)) {
            $in_ver = true;
            continue;
        }

        // Stop at the next version heading
        if ($in_ver && preg_match('/^## /', $line)) {
            break;
        }

        if (!$in_ver) {
            continue;
        }

        // Track sub-section (### Added, ### Fixed, etc.)
        if (preg_match('/^### (.+)$/', $line, $m)) {
            $section = trim($m[1]);
            continue;
        }

        // Collect bullet points
        if (preg_match('/^[-*] (.+)$/', $line, $m)) {
            $text      = trim($m[1]);
            $entries[] = $section ? "{$section}: {$text}" : $text;
        }
    }

    if (empty($entries)) {
        return ["No CHANGELOG.md entry found for {$version} — add one before publishing"];
    }

    return $entries;
}

/**
 * Check whether a path is protected using the same logic as core/updater.php.
 */
function is_protected(string $path, array $protected): bool {
    foreach ($protected as $rule) {
        if (str_ends_with($rule, '/')) {
            if (str_starts_with($path, $rule) || $path === rtrim($rule, '/')) {
                return true;
            }
        }
        if ($path === $rule) {
            return true;
        }
    }
    return false;
}

/**
 * Determine if a relative path should be included in the SMACKBACK manifest.
 * Mirrors the rules in core/smackback.php smackback_should_monitor().
 * Standalone (no runtime dependency) so it works in the build tool context.
 */
function smackback_build_should_monitor(string $rel): bool {
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    if (!in_array($ext, ['php', 'css', 'js'], true)) {
        return false;
    }

    $excluded_dirs = [
        'uploads/', 'smack-central/', 'reference/', 'node_modules/',
        'vendor/', 'tools/', 'backups/', 'migrations/',
        // Skins are NEVER in the CORE integrity manifest. They ship/update via the
        // Skin Packager and are monitored at runtime through their own skin_id rows.
        // A skin hash in the core package manifest is exactly what false-breached the
        // fleet on every core update (the 0.7.262 Photogram lockout) — keep them out
        // at the source.
        'skins/',
    ];
    foreach ($excluded_dirs as $dir) {
        if (strpos($rel, $dir) === 0) {
            return false;
        }
    }

    $basename = basename($rel);
    // Installer / setup files self-delete right after install — baselining them
    // false-breaches every fresh install. Ship in the zip, never in the manifest.
    if (in_array($basename, ['install.php', 'setup.php'], true)) {
        return false;
    }
    if (str_ends_with($basename, '.min.js') || str_ends_with($basename, '.min.css')) {
        return false;
    }
    if (strpos($rel, 'fjGallery') !== false) {
        return false;
    }

    return true;
}

/**
 * Compute the EOF signature for a file's content string.
 * Mirrors smackback_get_eof_signature() in core/smackback.php.
 * Used at build time so the manifest carries the expected last line.
 *
 * @param  string $content  Full file content.
 * @return string|null  Last non-empty line (≤512 chars), 'NULL_BYTES', or null.
 */
function smackback_build_eof_signature(string $content): ?string {
    if ($content === '') {
        return null;
    }
    if (strpos($content, "\x00") !== false) {
        return 'NULL_BYTES';
    }
    $tail  = substr($content, -1024);
    $lines = explode("\n", $tail);
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = rtrim($lines[$i]);
        if ($line !== '') {
            return substr($line, 0, 512);
        }
    }
    return null;
}

function parse_args(array $argv): array {
    $result = ['from' => null, 'to' => null, 'output' => null];

    $skip_next = false;
    for ($i = 1; $i < count($argv); $i++) {
        if ($skip_next) { $skip_next = false; continue; }

        $arg = $argv[$i];

        if ($arg === '--from' && isset($argv[$i + 1])) {
            $result['from'] = $argv[$i + 1];
            $skip_next = true;
        } elseif ($arg === '--to' && isset($argv[$i + 1])) {
            $result['to'] = $argv[$i + 1];
            $skip_next = true;
        } elseif ($arg === '--output' && isset($argv[$i + 1])) {
            $result['output'] = $argv[$i + 1];
            $skip_next = true;
        }
    }

    return $result;
}
// ===== SNAPSMACK EOF =====
