<?php
/**
 * SNAPSMACK - Skin Packager
 *
 * Packages a skin directory into a distributable zip for the skin registry.
 * Creates {slug}-{version}.zip with {slug}/ as the top-level folder,
 * matching the unwrap logic in core/skin-registry.php skin_registry_install().
 *
 * USAGE:
 *   php package-skin.php <slug>               Package one skin
 *   php package-skin.php --all                Package all skins
 *   php package-skin.php --all --output DIR   Output to specific directory
 *
 * EXAMPLES:
 *   php package-skin.php galleria
 *   php package-skin.php --all --output ./packages/
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

$project_root = dirname(__DIR__, 2);
$skins_dir    = $project_root . '/skins';
require_once $project_root . '/core/skin-security-policy.php';
// The version rules. A skin that is at 0.8 or above must not be packaged at
// all, and a re-baselined one must carry its epoch or no site holding the
// old bigger number will ever take it.
require_once $project_root . '/core/constants.php';
require_once $project_root . '/core/version-series.php';

// Files/dirs excluded from the zip
$exclude = ['.git', '.DS_Store', 'Thumbs.db', '.gitkeep', '__MACOSX'];

// ─── ARGUMENT PARSING ───────────────────────────────────────────────────────

$args = parse_args($argv);

if (!$args['slug'] && !$args['all']) {
    echo "SNAPSMACK SKIN PACKAGER\n";
    echo "───────────────────────\n\n";
    echo "Usage:\n";
    echo "  php package-skin.php <slug>               Package one skin\n";
    echo "  php package-skin.php --all                Package all skins\n";
    echo "  php package-skin.php --all --output DIR   Output to a specific directory\n";
    echo "  php package-skin.php --all --output=DIR   Same; either spelling works\n\n";
    echo "Packages are written OUTSIDE the checkout, never into it.\n";
    echo "Default: \$SNAPSMACK_BUILD_DIR, or a snapsmack-builds folder beside the repository.\n\n";
    echo "Examples:\n";
    echo "  php package-skin.php galleria\n";
    echo "  php package-skin.php --all --output ./packages/\n\n";
    exit(0);
}

// WHERE THE PACKAGES GO.
//
// This used to default to the PROJECT ROOT, so running the packager with no
// --output dropped zips straight into the checkout. I did exactly that while
// testing and had to delete tilez-0.2.79.zip out of the working tree. Sean's
// rule is no scattered files, and a build artifact in the source tree is the
// scattered-file problem at its most avoidable. PM ruling, room 8472: default
// to a build directory OUTSIDE the checkout, never the project root.
//
// SNAPSMACK_BUILD_DIR wins if it is set, so a machine with an established
// build location keeps using it. Otherwise the default is one predictable
// sibling folder next to the checkout -- one folder, not a scatter.
$default_output = getenv('SNAPSMACK_BUILD_DIR') ?: null;
if ($default_output === null || trim($default_output) === '') {
    // THE SIBLING OF THE MAIN REPOSITORY, not of this directory. The first
    // version of this used dirname($project_root), and run from a git worktree
    // that resolves to .claude/worktrees/snapsmack-builds -- INSIDE the
    // repository's own internals, which is the scatter this change exists to
    // stop. Measured from this worktree, not reasoned about. git tells us
    // where the real repository is; without git we fall back to the sibling.
    $common = @shell_exec('git -C ' . escapeshellarg($project_root) . ' rev-parse --git-common-dir 2>&1');
    $main_root = null;
    if (is_string($common)) {
        $common = trim($common);
        if ($common !== '' && strpos($common, 'fatal') === false) {
            // "<root>/.git" for a normal checkout and for a worktree alike.
            if (!preg_match('~^(?:[a-zA-Z]:|/)~', $common)) {
                $common = $project_root . '/' . $common;
            }
            $resolved = realpath($common);
            if ($resolved !== false) { $main_root = dirname($resolved); }
        }
    }
    $base = $main_root ?? $project_root;
    $default_output = dirname($base) . '/' . basename($base) . '-builds';
}
$output_dir = $args['output'] ?? $default_output;

// AND NEVER THE CHECKOUT, even when asked explicitly. A zip in the source
// tree gets committed by accident, and the next person wonders why the
// repository holds a build.
$resolved_output = realpath($output_dir);
$resolved_root = realpath($project_root);
if ($resolved_output !== false && $resolved_root !== false
    && rtrim($resolved_output, '/\\') === rtrim($resolved_root, '/\\')) {
    echo "REFUSED: the output directory is the checkout itself.\n";
    echo "        Packages belong outside the source tree -- pass --output DIR,\n";
    echo "        or set SNAPSMACK_BUILD_DIR. The default is {$default_output}.\n";
    exit(1);
}
if (!is_dir($output_dir) && !@mkdir($output_dir, 0755, true) && !is_dir($output_dir)) {
    echo "REFUSED: could not create the output directory {$output_dir}\n";
    exit(1);
}

// ─── BUILD SKIN LIST ────────────────────────────────────────────────────────

$slugs = [];

if ($args['all']) {
    foreach (glob($skins_dir . '/*/manifest.json') as $manifest_path) {
        $slugs[] = basename(dirname($manifest_path));
    }
    sort($slugs);
} else {
    $slugs[] = $args['slug'];
}

if (empty($slugs)) {
    die("ERROR: No skins found.\n");
}

// ─── PACKAGE EACH SKIN ─────────────────────────────────────────────────────

$results = [];
$errors  = 0;

foreach ($slugs as $slug) {
    $skin_path = $skins_dir . '/' . $slug;

    // Validate skin directory
    if (!is_dir($skin_path)) {
        echo "  SKIP  {$slug} — directory not found\n";
        $errors++;
        continue;
    }

    // Validate manifest
    $manifest_file = $skin_path . '/manifest.json';
    if (!file_exists($manifest_file)) {
        echo "  SKIP  {$slug} — no manifest.json\n";
        $errors++;
        continue;
    }

    $manifest = json_decode((string)file_get_contents($manifest_file), true);
    if (!is_array($manifest)) {
        echo "  SKIP  {$slug} — manifest.json is invalid\n";
        $errors++;
        continue;
    }
    $policy_findings = snapsmack_skin_security_gate($skin_path);
    if ($policy_findings) {
        echo "  BLOCK {$slug} — skin security policy failed (" . count($policy_findings) . " new/strict findings)\n";
        $errors++;
        continue;
    }

    $name    = $manifest['name']    ?? ucfirst($slug);
    $version = $manifest['version'] ?? '1.0';
    $status  = $manifest['status']  ?? 'unknown';
    $epoch   = (int)($manifest['version_epoch'] ?? 0);

    // REFUSE AN OFF-SERIES VERSION HERE, where the package is made, rather
    // than discovering it once it is in the registry. Sean asked for the
    // version inflation to stop and asking did not work, so this is one of
    // the places that now says no, and it names the version to use instead.
    $series_problem = snap_version_series_problem((string)$version);
    if ($series_problem !== '') {
        echo "  BLOCK {$slug} — {$series_problem}\n";
        $suggested = snap_version_rebaseline((string)$version);
        if ($suggested !== null) {
            echo "        set \"version\": \"{$suggested}\" and \"version_epoch\": "
               . SNAP_VERSION_EPOCH . " in skins/{$slug}/manifest.json\n";
        }
        $errors++;
        continue;
    }

    // A RE-BASELINED SKIN MUST DECLARE ITS EPOCH. 0.7.31 after 2.1.30 is a
    // LOWER number, so without the epoch every site holding the old one reads
    // the new package as a downgrade and stays behind for ever with nothing
    // reported. The manifest states what it was re-baselined from, and this
    // refuses the package if the epoch or the number does not match it.
    if (!empty($manifest['rebaselined_from'])) {
        $from = (string)$manifest['rebaselined_from'];
        $expected = snap_version_rebaseline($from);
        if ($epoch < SNAP_VERSION_EPOCH) {
            echo "  BLOCK {$slug} — manifest says it was re-baselined from {$from}, so it must "
               . 'declare "version_epoch": ' . SNAP_VERSION_EPOCH
               . " or no site holding {$from} will ever take it\n";
            $errors++;
            continue;
        }
        if ($expected === null) {
            // FAIL CLOSED on a from-value this code cannot read. Muse found
            // this open on f94cbb26: with the epoch present and a nonsense
            // rebaselined_from, the wrong-number check was skipped because the
            // expected version was null, so the package went through unchecked.
            // A claim about where a version came from is either readable or it
            // is not evidence.
            echo "  BLOCK {$slug} — rebaselined_from is '{$from}', which cannot be read as a "
               . "version, so the number it should carry cannot be derived
";
            $errors++;
            continue;
        }
        if ($version !== $expected) {
            echo "  BLOCK {$slug} — re-baselined from {$from}, so its version must be "
               . "{$expected}, not {$version}\n";
            $errors++;
            continue;
        }
    }

    // Build zip filename
    $zip_name = "{$slug}-{$version}.zip";
    $zip_path = rtrim($output_dir, '/') . '/' . $zip_name;

    echo "  PACK  {$slug} v{$version}"
       . ($epoch > 0 ? " epoch {$epoch}" : '') . " ({$status})\n";

    // Create zip with {slug}/ as top-level folder
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        echo "  FAIL  Could not create {$zip_path}\n";
        $errors++;
        continue;
    }

    $file_count   = add_directory_to_zip($zip, $skin_path, $slug, $exclude);
    $smack_hashes = collect_smackback_hashes($skin_path, $slug, $exclude);

    // Add SMACKBACK manifest before signing
    $smackback_manifest = json_encode([
        'smackback_version' => 1,
        'package_version'   => $version,
        'package_epoch'     => $epoch,
        'skin_id'           => $slug,
        'generated_at'      => gmdate('Y-m-d\TH:i:s\Z'),
        'files'             => $smack_hashes,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $zip->addFromString('smackback-manifest.json', $smackback_manifest);

    $zip->close();

    // Checksum
    $checksum = hash_file('sha256', $zip_path);
    $filesize = filesize($zip_path);

    echo "        → {$zip_name} ({$file_count} files, " . number_format($filesize) . " bytes)\n";
    echo "        → SHA-256: {$checksum}\n";

    $results[] = [
        'slug'     => $slug,
        'name'     => $name,
        'version'  => $version,
        // The registry entry carries it, because that is what a site
        // compares against its installed copy.
        'version_epoch' => $epoch,
        'status'   => $status,
        'zip'      => $zip_name,
        'size'     => $filesize,
        'checksum' => $checksum,
    ];
}

// ─── SUMMARY ────────────────────────────────────────────────────────────────

echo "\n";
echo "Packaged " . count($results) . " skin(s)";
if ($errors > 0) echo ", {$errors} error(s)";
echo ".\n";

if (!empty($results)) {
    echo "Output directory: {$output_dir}\n";
}

exit($errors > 0 ? 1 : 0);


// ─── HELPERS ────────────────────────────────────────────────────────────────

/**
 * Recursively add a directory to a ZipArchive.
 *
 * @param ZipArchive $zip        Open zip archive
 * @param string     $source     Absolute path to source directory
 * @param string     $zip_prefix Prefix inside the zip (e.g. "galleria")
 * @param array      $exclude    Filenames to skip
 * @return int Number of files added
 */
function add_directory_to_zip(ZipArchive $zip, string $source, string $zip_prefix, array $exclude): int {
    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $basename = $item->getBasename();

        // Skip excluded files/dirs
        if (in_array($basename, $exclude, true)) {
            continue;
        }

        // Relative path inside the skin directory
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($source) + 1));
        $zip_path = $zip_prefix . '/' . $relative;

        if ($item->isDir()) {
            $zip->addEmptyDir($zip_path);
        } else {
            $zip->addFile($item->getPathname(), $zip_path);
            $count++;
        }
    }

    return $count;
}

/**
 * Collect SHA-256 hashes of every packaged skin file for activation provenance.
 * Returns array keyed by zip-relative path (e.g. "slug/header.php").
 *
 * @param  string   $source      Absolute path to skin directory on disk.
 * @param  string   $zip_prefix  Zip prefix (skin slug, e.g. "chaplin").
 * @param  string[] $exclude     Filenames to exclude (same list as add_directory_to_zip).
 * @return array<string, array{hash: string, size: int, eof_signature: string|null}>
 */
function collect_smackback_hashes(string $source, string $zip_prefix, array $exclude): array {
    $hashes   = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }
        if (in_array($item->getBasename(), $exclude, true)) {
            continue;
        }

        $relative = substr($item->getPathname(), strlen($source) + 1);
        $zip_path = $zip_prefix . '/' . str_replace('\\', '/', $relative);
        $content  = file_get_contents($item->getPathname());

        if ($content !== false) {
            $hashes[$zip_path] = [
                'hash'          => hash('sha256', $content),
                'size'          => strlen($content),
                'eof_signature' => smackback_build_eof_signature($content),
            ];
        }
    }

    return $hashes;
}

/**
 * Compute the EOF signature for a file's content string.
 * Mirrors smackback_get_eof_signature() in core/smackback.php.
 */
function smackback_build_eof_signature(string $content): ?string {
    if ($content === '') { return null; }
    if (strpos($content, "\x00") !== false) { return 'NULL_BYTES'; }
    $tail  = substr($content, -1024);
    $lines = explode("\n", $tail);
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = rtrim($lines[$i]);
        if ($line !== '') { return substr($line, 0, 512); }
    }
    return null;
}

function parse_args(array $argv): array {
    $result = ['slug' => null, 'all' => false, 'output' => null];

    // BOTH SPELLINGS. --output=DIR used to fall through every branch and be
    // SILENTLY IGNORED, so the packager wrote to its default and said nothing.
    // That is how a zip ended up in the checkout while I believed I had sent
    // it elsewhere. An argument the tool does not understand is now an error,
    // because silence is what made the first mistake invisible.
    $skip_next = false;
    for ($i = 1; $i < count($argv); $i++) {
        if ($skip_next) { $skip_next = false; continue; }

        $arg = $argv[$i];

        if ($arg === '--all') {
            $result['all'] = true;
        } elseif (str_starts_with($arg, '--output=')) {
            $value = substr($arg, strlen('--output='));
            if (trim($value) === '') {
                echo "REFUSED: --output= was given with no directory.\n";
                exit(1);
            }
            $result['output'] = $value;
        } elseif ($arg === '--output') {
            if (!isset($argv[$i + 1]) || str_starts_with((string)$argv[$i + 1], '-')) {
                echo "REFUSED: --output needs a directory after it.\n";
                exit(1);
            }
            $result['output'] = $argv[$i + 1];
            $skip_next = true;
        } elseif (str_starts_with($arg, '-')) {
            echo "REFUSED: unknown option {$arg}.\n";
            exit(1);
        } elseif ($result['slug'] === null) {
            $result['slug'] = $arg;
        }
    }

    return $result;
}
// ===== SNAPSMACK EOF =====
