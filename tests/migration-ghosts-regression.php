<?php
/**
 * Regression — no phantom PENDING migrations.
 *
 * Sean, 2026-11-11, looking at dithering.pixhellated.ca: "sql files that have
 * been applied are piling up when I go through the update screen. Find out why
 * ghosts are hanging around."
 *
 * There were two independent causes, and a third cosmetic one.
 *
 * 1. THE INSTALLER STAMPED NOTHING. install.php globbed
 *    migrations/[0-9][0-9][0-9]_*.php — the numbered-PHP scheme used up to
 *    0.7.159 (60afaf74, 2026-05-20). Migrations were later converted to
 *    migrate-*.sql and the stamp was not converted with them, so the glob
 *    matched zero files and a fresh install recorded NOT ONE migration, while
 *    canonical.sql had already built the full schema. Every new site showed all
 *    41 known migrations PENDING from its first login, for ever.
 *
 * 2. FOUR KNOWN MIGRATIONS HAD NO FILE. The Schema Recovery panel builds its
 *    pending list from UPDATER_KNOWN_MIGRATIONS; the runner can only run files
 *    that exist. migrate-076/077/078.sql and migrate-comment-identity.sql were
 *    deleted in eca7e4e9 (v0.7.8e, 2026-04-07) and left in the constant, so
 *    they were pending on EVERY site and nothing could clear them — not an
 *    update, not RUN SCHEMA SYNC, only MARK ALL MIGRATIONS APPLIED, which
 *    records migrations without running their SQL and would bury a genuine
 *    schema gap.
 *
 * 3. ONE SHIPPED FILE WAS NOT IN THE LIST. migrate-game-on-scores.sql was
 *    skipped silently and surfaced as a ghost. Harmless — canonical.sql defines
 *    snap_game_on_scores, and nothing in the codebase reads that table; it is
 *    legacy, superseded by snap_game_scores via migrate-game-scores-ledger.sql.
 *    Deleted rather than registered: registering it would run a redundant
 *    CREATE TABLE IF NOT EXISTS on every site to no purpose.
 *
 * The invariant that keeps all three fixed: THE CONSTANT AND THE DIRECTORY MUST
 * AGREE, EXACTLY, and the installer must stamp what it actually ships.
 *
 * Static assertions — no DB, no network, no site required.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root  = dirname(__DIR__);
$fails = 0;

function mg(string $label, bool $ok): void {
    global $fails;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fails++;
}

$install   = (string) @file_get_contents($root . '/install.php');
$updater   = (string) @file_get_contents($root . '/core/updater.php');
$canonical = (string) @file_get_contents($root . '/database/schema/snapsmack_canonical.sql');

mg('install.php is readable',     $install   !== '');
mg('core/updater.php is readable', $updater  !== '');

if ($install === '' || $updater === '') {
    fwrite(STDERR, "\nMigration ghosts: source files missing — cannot check.\n");
    exit(1);
}

// ── 1. THE INSTALLER STAMPS WHAT IT SHIPS ───────────────────────────────────

mg(
    'installer no longer globs the dead numbered-PHP migration scheme',
    !str_contains($install, "/migrations/[0-9][0-9][0-9]_*.php")
);

mg(
    'installer globs the migration files that actually ship',
    (bool) preg_match("/glob\s*\(\s*__DIR__\s*\.\s*'\/migrations\/migrate\*\.sql'\s*\)/", $install)
);

// basename($mf, '.php') stripped an extension these files do not have. The
// updater records the FULL filename, so the installer must stamp the same
// string or the two never match.
mg(
    'installer stamps the full filename, not a stripped one',
    (bool) preg_match("/\\\$stamp->execute\s*\(\s*\[\s*basename\s*\(\s*\\\$mf\s*\)\s*\]\s*\)/", $install)
    && !preg_match("/basename\s*\(\s*\\\$mf\s*,\s*'\.php'\s*\)/", $install)
);

// ── 2. THE CONSTANT AND THE DIRECTORY AGREE ─────────────────────────────────
//
// This is the load-bearing assertion. Parsed as text rather than by requiring
// core/updater.php, which self-heals .htaccess and .user.ini at include time
// and must never be pulled into a test run.

$known = [];
if (preg_match('/const UPDATER_KNOWN_MIGRATIONS = \[(.*?)\];/s', $updater, $m)) {
    preg_match_all("/'(migrate-[^']+\.sql)'/", $m[1], $k);
    $known = $k[1];
}
$disk = array_map('basename', glob($root . '/migrations/migrate*.sql') ?: []);
sort($known);
sort($disk);

mg('UPDATER_KNOWN_MIGRATIONS parsed', $known !== []);
mg('migrations/ directory is not empty', $disk !== []);

$fileless = array_values(array_diff($known, $disk));
$ghosts   = array_values(array_diff($disk, $known));

mg(
    'no known migration is missing its file' . ($fileless ? ' (found: ' . implode(', ', $fileless) . ')' : ''),
    $fileless === []
);

mg(
    'no shipped migration is missing from the known list' . ($ghosts ? ' (found: ' . implode(', ', $ghosts) . ')' : ''),
    $ghosts === []
);

// The four that caused it must not come back.
foreach (['migrate-076.sql', 'migrate-077.sql', 'migrate-078.sql', 'migrate-comment-identity.sql'] as $dead) {
    mg(
        "{$dead} is not listed as a known migration (deleted in v0.7.8e)",
        !in_array($dead, $known, true)
    );
}

mg(
    'migrate-game-on-scores.sql is gone from migrations/ (legacy, canonical covers it)',
    !is_file($root . '/migrations/migrate-game-on-scores.sql')
);

// ── 3. CANONICAL STILL COVERS WHAT THOSE FILES USED TO DO ───────────────────
//
// The four were removed from the list on the strength of canonical.sql holding
// their schema. If that stops being true, removing them stops being safe.

if ($canonical === '') {
    echo "SKIP canonical schema not present — cannot confirm coverage.\n";
} else {
    mg('canonical defines snap_posts (was migrate-076.sql)',
        str_contains($canonical, 'CREATE TABLE IF NOT EXISTS `snap_posts`'));
    mg('canonical defines snap_images.img_source_file (was migrate-077.sql)',
        str_contains($canonical, 'img_source_file'));
    mg('canonical defines snap_pages.image_size (was migrate-078.sql)',
        str_contains($canonical, 'image_size'));
    mg('canonical defines snap_game_on_scores (was migrate-game-on-scores.sql)',
        str_contains($canonical, 'CREATE TABLE IF NOT EXISTS `snap_game_on_scores`'));
}

if ($fails) {
    fwrite(STDERR, "\n{$fails} FAILURE(S): phantom PENDING migrations can return — a site will show "
        . "migrations it can never clear, and the only button that clears them records SQL without running it.\n");
    exit(1);
}
echo 'Migration ghosts: PASS (' . count($known) . " known, " . count($disk) . " on disk, in agreement)\n";
exit(0);

// ===== SNAPSMACK EOF =====
