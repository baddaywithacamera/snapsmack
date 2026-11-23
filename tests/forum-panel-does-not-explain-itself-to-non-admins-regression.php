<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// A CAPABILITY YOU DO NOT HAVE SHOULD BE ABSENT, NOT DESCRIBED. (Sean, 2026-11-22)
//
// Every site that is not a forum administrator -- nearly all of them -- was
// shown a box headed FORUM ADMIN on its Community Forum page, explaining that
// board management is not shown, that there is no key to enter, and that an
// administrator asks the forum to grant it. All true, and all unprompted.
//
// Sean's objection, and it is the right one: people will read it and ask. An
// owner shown an explanation of something they cannot have will reasonably want
// to know how to get it, and we would then be answering a question the page
// invented. The board controls were already gated, so the panel's only job was
// to narrate its own absence.
//
// THE DISTINCTION THIS FILE EXISTS TO HOLD. The refusal on the NEW BOARD view
// STAYS. That page is only reached by asking for it, and a blank page in answer
// to a deliberate request is worse than a plain "this site cannot create
// boards". A refusal you asked for is not the same thing as an explanation you
// did not, and it would be easy for a later tidy-up to delete both as
// duplicates of each other.
//
// WHAT IS READ, NOT RUN: smack-forum.php is an admin page that wants a session
// and a database, so this reads it. Stated plainly rather than implied.

$root  = dirname(__DIR__);
$panel = $root . '/smack-forum.php';

$failures = [];
$check = static function (string $what, bool $ok) use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if (!$ok) { $failures[] = $what; }
};

echo "A forum page does not explain the admin section it is not showing\n\n";

$check('the forum panel exists where this test expects it', is_file($panel));
$src = is_file($panel) ? (string) file_get_contents($panel) : '';

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE PANEL THAT NARRATED ITS OWN ABSENCE IS GONE\n";
// ─────────────────────────────────────────────────────────────────────────────

$check('no box is headed FORUM ADMIN any more',
       !str_contains($src, 'FORUM ADMIN</span>')
       && !str_contains($src, '>FORUM ADMIN<'));

$check('and the sentence an owner would have asked about is gone from the markup',
       !str_contains($src, 'board management is not') );

$check('  and so is the invitation to go looking for a key',
       !str_contains($src, 'There is no key to enter here'));

// THE SHARPEST CHECK HERE. The panel was the only place that rendered markup on
// the NOT-admin branch of the forum's own page. Exactly one such branch should
// remain in the whole file, and it is the NEW BOARD refusal below. Counting
// them is what catches a future panel being added back under a different
// heading, which a search for the old words would miss entirely.
$negatives = substr_count($src, 'if (!$forum_can_admin)');
$check('exactly ONE not-an-administrator branch remains in the file', $negatives === 1);
if ($negatives !== 1) {
    echo "        found {$negatives}. If a new one is deliberate, it belongs on a\n";
    echo "        page the owner asked for, not on the forum's own front page.\n";
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWHAT MUST NOT BE SWEPT AWAY WITH IT\n";
// ─────────────────────────────────────────────────────────────────────────────

$check('the NEW BOARD view still refuses in words, rather than going blank',
       str_contains($src, 'so it cannot create boards'));

$check('  and still offers the way back',
       (bool) preg_match('/cannot create boards[^<]*<a href="smack-forum\.php"/', $src));

// The positive gate is the thing that makes the removal safe: if the controls
// were NOT gated, deleting the explanation would leave a non-administrator
// looking at buttons that cannot work.
$check('the board controls are still shown only to an administrator',
       str_contains($src, 'if ($forum_can_admin)'));

// COMMENTS STRIPPED BEFORE LOOKING FOR THE OLD KEY. The first version of this
// check forbade 'forum_mod_key' anywhere in the file and FAILED on correct
// code: line 41 is a comment recording that $forum_can_admin replaced it. That
// is the third time today I have written a check that matched a comment instead
// of code, so this one strips them first.
$code = preg_replace('#/\*.*?\*/#s', '', $src) ?? $src;
$code = preg_replace('#^\s*//.*$#m', '', $code) ?? $code;
$check('the capability is still read from the forum',
       str_contains($src, "!empty(\$me['can']['administer'])"));
$check('  and no code reads a mod key any more (comments about it are fine)',
       !str_contains($code, 'forum_mod_key'));

// Default-closed: a failed or offline call must leave the controls hidden
// rather than shown, which is what makes "absent" the safe state.
$check('it defaults to NOT an administrator, so an offline forum hides rather than offers',
       (bool) preg_match('/\$forum_can_admin\s*=\s*false;/', $src));

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures) {
    echo 'FAIL (' . count($failures) . "):\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "PASS — a site that is not a forum administrator is shown a forum without\n";
echo "board controls, and is told nothing about an administration it does not\n";
echo "have. The NEW BOARD page still answers plainly when asked directly.\n";
echo "NOT PROVED HERE: the rendered page. smack-forum.php wants a session and a\n";
echo "database, so this reads it.\n";
exit(0);

// ===== SNAPSMACK EOF =====
