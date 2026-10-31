<?php
declare(strict_types=1);
/**
 * The admin calls a category a CATEGORY and an album an ALBUM, everywhere.
 *
 * WHY THIS EXISTS. The owner reported that Manage Archive rows still printed
 * "[ REG: ... ]" and "[ MISSION: ... ]" -- the old names for categories and
 * albums, renamed on the edit, gallery and post screens months ago and missed
 * here. The sweep found the rename was also never done on the two taxonomy
 * management pages: an owner who clicked ALBUMS in the sidebar landed on a
 * page headed MISSION REGISTRY (SS-1126).
 *
 * AND HIS INSTRUCTION WAS TRANSPOSED, which is the part worth remembering. He
 * asked for REG -> ALBM and MISSION -> CATEGORY. The code says the opposite:
 * smack-manage.php prints $p['category_list'] under REG and $p['album_list']
 * under MISSION, and those come from snap_categories and snap_albums. Doing
 * what was asked would have put the album label on category data, which is
 * WORSE than the old names because both words are current product vocabulary
 * and nobody would spot it. The direction here is the one the code dictates,
 * confirmed by the coordinator; the wording ALBUM rather than ALBM is Sean's
 * own answer of 31 October 2026.
 *
 * WHAT THIS GUARDS, and the second half matters as much as the first:
 *   1. the old names do not come back in the three admin files that had them
 *   2. the words REGISTRY and MISSION are still PRESENT where they mean
 *      something else entirely -- the skin registry, the reaction registry,
 *      the tool registry, and the "MISSION FAILURE" error alert. A later
 *      rename-all would silently eat those, and the skin registry is how
 *      skins ship.
 *
 * NOT GUARDED, and deliberately: archive.php:613 still labels the category
 * filter group REGISTRY. It is a legacy entry point that no strict skin runs,
 * and all 30 renderable skins are strict, so it is not visible on the fleet.
 * Listed on SS-1126, not changed, not asserted either way.
 *
 * Run:  php tests/admin-taxonomy-labels-regression.php
 *
 * # SNAPSMACK_EOF_HEADER
 * # Last non-empty line must be: // ===== SNAPSMACK EOF =====
 */

$root = dirname(__DIR__);
$failures = [];

function tl_fail(string $message): void
{
    global $failures;
    if ($message !== '') $failures[] = $message;
}

function tl_read(string $root, string $rel): string
{
    $body = (string) @file_get_contents($root . '/' . $rel);
    if ($body === '') tl_fail("cannot read {$rel}");
    return $body;
}

/* ── 1. The old names are gone from the three files that carried them ────
 *
 * Matched as WHOLE WORDS. "MISSION" is a substring of TRANSMISSIONS, which is
 * the image-count label on both taxonomy pages and has nothing to do with
 * albums; a naive search-and-replace during the fix would have produced
 * TRANSALBUMS, and a naive check here would report a defect that is not one.
 */
$renamed = [
    'smack-manage.php' => 'the Manage list rows and the album filter label',
    'smack-albums.php' => 'the Albums management page',
    'smack-cats.php'   => 'the Categories management page',
];
foreach ($renamed as $rel => $what) {
    $body = tl_read($root, $rel);
    foreach (['MISSION', 'REGISTRY'] as $old) {
        preg_match_all('/\b' . $old . '\b/', $body, $hits);
        $n = count($hits[0] ?? []);
        tl_fail($n === 0 ? ''
            : "{$rel} ({$what}) uses the old name {$old} again, {$n} time(s). "
            . 'A category is a CATEGORY and an album is an ALBUM throughout the '
            . 'admin; SS-1126 is the ticket and the owner reported this himself. '
            . 'If a NEW use of the word is legitimate -- the skin registry, say '
            . '-- it does not belong in this file.');
    }
    // The short form on the Manage rows, which is what he actually saw.
    tl_fail(strpos($body, '[ REG:') === false ? ''
        : "{$rel} prints '[ REG:' again. That label sits over category data.");
}

/* ── 2. The words still mean what they mean elsewhere ────────────────────
 *
 * THIS IS THE HALF A FUTURE RENAME-ALL WOULD BREAK. Each of these is a
 * different concept that merely contains the same word, and one of them --
 * the skin registry -- is how every skin reaches every site.
 */
/* CASE MATTERS HERE, AND MY FIRST VERSION OF THIS GUARD WAS TOO WEAK.
 * It asked stripos($body, 'REGISTRY'), which is case-INSENSITIVE, and every
 * one of these files also contains the lowercase word in filenames and
 * variable names. So replacing every UPPERCASE REGISTRY in
 * core/skin-registry.php left the guard green -- it was matching 'skin-registry'
 * in an include path. Mutation 4 caught it. That is the same fault as the ones
 * recorded on SS-1114: a check satisfied by a different occurrence than the one
 * it names.
 *
 * Both forms are now required explicitly, case-sensitively, per file. */
$keep = [
    'core/skin-registry.php'             => ['REGISTRY', 'registry'],
    'core/updater.php'                   => ['REGISTRY', 'registry'],
    'cron-version-check.php'             => ['REGISTRY', 'registry'],
    'smack-skin.php'                     => ['REGISTRY', 'registry'],
    'smack-maintenance.php'              => ['REGISTRY', 'registry'],
    'smack-multisite.php'                => ['REGISTRY', 'registry'],
    'process-reaction.php'               => ['REGISTRY', 'registry'],
    'smack-community-settings.php'       => ['REGISTRY', 'registry'],
    // Only the uppercase comment banner; no lowercase use in this one.
    'smack-tools.php'                    => ['REGISTRY'],
    'tools/_build/generate-registry.php' => ['REGISTRY', 'registry'],
    'tools/_build/sign-registry.php'     => ['REGISTRY', 'registry'],
    'assets/js/ss-engine-updater.js'     => ['codename'],
];
foreach ($keep as $rel => $words) {
    $body = tl_read($root, $rel);
    if ($body === '') continue;
    foreach ($words as $word) {
        tl_fail(strpos($body, $word) !== false ? ''
            : "{$rel} no longer contains '{$word}' (exact case). If a taxonomy "
            . 'rename removed it, that is a FAULT, not a tidy-up: this file is '
            . 'about the skin registry, the reaction registry, the tool '
            . 'registry or the release codename, none of which is a category '
            . 'or an album. SS-1126 lists every one of these as out of scope, '
            . 'and the skin registry is how every skin reaches every site.');
    }
}

// The error alert. Themed language, not a taxonomy label.
$ui = tl_read($root, 'assets/js/ss-engine-admin-ui.js');
tl_fail(strpos($ui, 'MISSION FAILURE') !== false ? ''
    : 'assets/js/ss-engine-admin-ui.js no longer says "MISSION FAILURE". That '
    . 'is an error alert, not a name for an album, and SS-1126 put it out of '
    . 'scope explicitly. Removing it was not part of the rename.');

// The image-count label on both taxonomy pages.
foreach (['smack-albums.php', 'smack-cats.php'] as $rel) {
    $body = tl_read($root, $rel);
    tl_fail(strpos($body, 'TRANSMISSIONS:') !== false ? ''
        : "{$rel} no longer says 'TRANSMISSIONS:'. That is the image count and "
        . 'it contains MISSION only as a substring. If a rename ate it, the '
        . 'rename was done with a blind search-and-replace.');
}

/* ── 3. The new words are actually there ─────────────────────────────────
 * Removing a label and replacing it with nothing would pass everything above.
 */
$present = [
    'smack-manage.php' => ['[ CATEGORY:', '[ ALBUM:'],
    'smack-albums.php' => ['<h2>ALBUMS</h2>', 'ALBUM NAME'],
    'smack-cats.php'   => ['ACTIVE CATEGORIES', 'NEW CATEGORY'],
];
foreach ($present as $rel => $needles) {
    $body = tl_read($root, $rel);
    foreach ($needles as $needle) {
        tl_fail(strpos($body, $needle) !== false ? ''
            : "{$rel} does not contain {$needle}. The old label was removed "
            . 'and the new one is missing, so the screen now says nothing '
            . 'where it used to say the wrong thing.');
    }
}

/* ── Report ─────────────────────────────────────────────────────────────── */
if ($failures !== []) {
    echo "\nFAIL admin taxonomy labels (" . count($failures) . ")\n";
    foreach ($failures as $f) echo "- " . $f . "\n";
    exit(1);
}
echo "\nPASS the admin calls a category a CATEGORY and an album an ALBUM,\n";
echo "     and the skin / reaction / tool registries are untouched.\n";

// ===== SNAPSMACK EOF =====
