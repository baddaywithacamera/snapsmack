<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1386 step (a): THE MEDIUM THUMBNAIL'S COLUMN AND SIZE REACH A LIVE SITE.
 *
 * Both halves of this have a failure mode that only shows up on an installed
 * site, which is to say on Sean's fleet and not here.
 *
 * THE SIZE. core/constants.php is a PROTECTED PATH: an update does not
 * overwrite it, so a constant defined only there never arrives on a site that
 * updates rather than reinstalls. Every size the shared generator uses is
 * therefore also declared in core/thumb-generator.php behind if (!defined()),
 * which does ship. This test loads the generator WITHOUT constants.php, the
 * way a partially-updated site would, and asks what the constant is -- rather
 * than reading the file and agreeing with it.
 *
 * THE COLUMN. The archive grids will name img_thumb_medium in SELECT lists
 * that are explicit, not SELECT *, so on a site missing the column every grid
 * query throws Unknown column. Three things have to be true: the canonical
 * schema declares it (so the updater's diff adds it), install.php declares it
 * (so a fresh install has it), and the shared ingest carries the
 * belt-and-suspenders ADD COLUMN IF NOT EXISTS the new-column checklist
 * requires (so a site deployed by direct code copy, which bypasses the diff,
 * self-heals). That third one is not a formality: it is exactly how
 * hekeepsdroningon.ca ended up missing the 0.7.393 fedi columns and fatal on
 * save.
 */

$root = dirname(__DIR__);
$failures = [];

// --- 1. the size survives a site whose constants.php predates it -----------
// Deliberately NOT requiring core/constants.php first. This is the partial
// update, reproduced.
if (defined('SNAPSMACK_THUMB_MEDIUM_LONG')) {
    $failures[] = 'the constant was already defined before the generator was '
        . 'loaded, so this test cannot see whether the generator declares it';
} else {
    require_once $root . '/core/thumb-generator.php';
    if (!defined('SNAPSMACK_THUMB_MEDIUM_LONG')) {
        $failures[] = 'core/thumb-generator.php does not declare '
            . 'SNAPSMACK_THUMB_MEDIUM_LONG, so on a site whose constants.php '
            . 'predates SS-1386 the medium size is undefined -- a fatal, on '
            . 'exactly the installed sites this derivative exists for';
    } elseif (SNAPSMACK_THUMB_MEDIUM_LONG !== 600) {
        $failures[] = 'the medium long edge is ' . SNAPSMACK_THUMB_MEDIUM_LONG
            . 'px, not the 600px the owner approved';
    }
    // The sizes have to stay in the order the srcset assumes: m_ smaller
    // than a_, or the browser picks the big one for small tiles and the
    // whole point of SS-1386 is lost.
    if (defined('SNAPSMACK_THUMB_ASPECT_LONG')
            && SNAPSMACK_THUMB_MEDIUM_LONG >= SNAPSMACK_THUMB_ASPECT_LONG) {
        $failures[] = 'the medium thumbnail is not smaller than the aspect '
            . 'thumbnail, so srcset would gain nothing';
    }
}

// --- 2. constants.php still carries it as the single source of truth ------
$constants = (string)@file_get_contents($root . '/core/constants.php');
if (strpos($constants, "define('SNAPSMACK_THUMB_MEDIUM_LONG'") === false) {
    $failures[] = 'core/constants.php does not define the medium size; the '
        . 'generator fallback is the safety net, not the declaration';
}

// --- 3. the column is declared where both kinds of site will find it ------
$canonical = (string)@file_get_contents($root . '/database/schema/snapsmack_canonical.sql');
if (strpos($canonical, '`img_thumb_medium`') === false) {
    $failures[] = 'the canonical schema does not declare img_thumb_medium, so '
        . "the updater's diff will never add it to an existing site";
}
$install = (string)@file_get_contents($root . '/install.php');
if (strpos($install, '`img_thumb_medium`') === false) {
    $failures[] = 'install.php does not declare img_thumb_medium, so a fresh '
        . 'install is missing it';
}

// --- 4. and it self-heals on a site that bypassed the diff ----------------
$ingest = (string)@file_get_contents($root . '/core/image-ingest.php');
if (!preg_match('/ADD COLUMN IF NOT EXISTS\s+img_thumb_medium/i', $ingest)) {
    $failures[] = 'core/image-ingest.php has no defensive ADD COLUMN IF NOT '
        . 'EXISTS for img_thumb_medium. A site deployed by direct code copy '
        . 'bypasses the schema diff and would throw Unknown column on every '
        . 'archive grid.';
}

// --- 5. the canonical declaration is nullable ----------------------------
// NULL is the honest value for "not generated yet", and the grids fall back to
// a_ when they see it. A NOT NULL column would force an empty string that
// reads as a path.
if (preg_match('/`img_thumb_medium`[^,]*/', $canonical, $m)) {
    if (stripos($m[0], 'NOT NULL') !== false) {
        $failures[] = 'img_thumb_medium is NOT NULL in the canonical schema; '
            . 'the backlog is unfilled for most photographs and NULL is what '
            . 'the fallback reads';
    }
    if (stripos($m[0], 'varchar(255)') === false) {
        $failures[] = 'img_thumb_medium is not varchar(255) like the other '
            . 'two thumbnail paths';
    }
} else {
    $failures[] = 'could not isolate the img_thumb_medium declaration to '
        . 'check its type';
}

if ($failures) {
    throw new RuntimeException("Medium thumbnail column/size contract broken:\n  - "
        . implode("\n  - ", $failures));
}

echo "Medium thumbnail: column declared three ways, size ships outside constants.php: passed.\n";
// ===== SNAPSMACK EOF =====
