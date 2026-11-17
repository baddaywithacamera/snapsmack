<?php
/**
 * SS-1197 — the installer may offer only modes that have been tested.
 *
 * SMACKTHEMUP was on the install screen as a selectable radio card, and the
 * server accepted it as a posted site_mode. It had not been tested. The mode
 * decides the shape of a new owner's whole site — which skin family renders it,
 * whether there is a web composer, whether federation is even possible — and it
 * is not a casual thing to undo once content exists.
 *
 * It was offered because the decision lived in TWO places that nothing tied
 * together: a hand-written card in the markup, and a separate list of accepted
 * POST values. Add a card, forget the list, or the reverse, and the two drift.
 * The existing regression made it worse by asserting that the installer DID
 * offer it, so a green run proved the unwanted behaviour. Those assertions are
 * inverted in tests/smackthemup-mode-registration-regression.php, with the
 * reason recorded next to them.
 *
 * WHAT IS PINNED HERE:
 *   - ONE shared list, install_selectable_site_modes(), holding exactly
 *     photoblog, carousel and smacktalk;
 *   - the POST validation and the rendered cards both come from that list, so
 *     neither can drift from the other;
 *   - SMACKTHEMUP has NO radio, no label wrapper and no click handler, so there
 *     is nothing to post, nothing for Tab to focus and nothing for Space to
 *     select — the keyboard requirement cannot be met by styling alone;
 *   - a stale session value cannot become a persisted site_mode, because the
 *     mode is re-gated where it is written and not only where it is posted;
 *   - rejection happens before any database, session or install-state write;
 *   - every assignment to site_mode in the installer is inventoried, and a new
 *     one that bypasses the shared list fails this test.
 *
 * install.php cannot be included: it renders a page and drives a live install.
 * The shared list is extracted and evaluated, the same way
 * tests/bootstrap-signature-refusal-regression.php does for setup_verify().
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root  = dirname(__DIR__);
$fails = 0;

function im(string $label, bool $ok): void {
    global $fails;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fails++;
}

$src = (string) @file_get_contents($root . '/install.php');
im('install.php is readable', $src !== '');
if ($src === '') {
    fwrite(STDERR, "\nSS-1197: install.php missing — cannot check.\n");
    exit(1);
}

// ── The shared list, run rather than read ──────────────────────────────────
foreach (['install_selectable_site_modes', 'install_mode_is_selectable', 'install_persistable_site_mode'] as $fn) {
    if (preg_match('/(function\s+' . $fn . '\s*\(.*?\n\})/s', $src, $m)) {
        eval($m[1]);
    }
    im("{$fn}() exists and is callable", function_exists($fn));
}
if (!function_exists('install_selectable_site_modes')) {
    fwrite(STDERR, "\nSS-1197: the shared mode list is missing.\n");
    exit(1);
}

$modes = install_selectable_site_modes();
sort($modes);
im('the shared list is exactly photoblog, carousel, smacktalk',
    $modes === ['carousel', 'photoblog', 'smacktalk']);

im('smackthemup is not selectable',           !install_mode_is_selectable('smackthemup'));
im('an unknown value is not selectable',      !install_mode_is_selectable('nonsense'));
im('an empty value is not selectable',        !install_mode_is_selectable(''));
foreach (['photoblog', 'carousel', 'smacktalk'] as $good) {
    im("{$good} is selectable", install_mode_is_selectable($good));
}

// ── A stale session value cannot be persisted ──────────────────────────────
// This is the one the POST check alone cannot cover: a session written by an
// earlier build, or by a half-finished install, is read later and written to
// the database without passing the gate again.
im('a stale smackthemup session falls back to photoblog',
    install_persistable_site_mode('smackthemup') === 'photoblog');
im('an unknown session value falls back to photoblog',
    install_persistable_site_mode('nonsense') === 'photoblog');
im('an empty session value falls back to photoblog',
    install_persistable_site_mode('') === 'photoblog');
foreach (['photoblog', 'carousel', 'smacktalk'] as $good) {
    im("a {$good} session is persisted unchanged", install_persistable_site_mode($good) === $good);
}
// fedistructure is NOT selectable and MUST still persist: the installer sets it
// from the package it shipped in, never from a card. Dropping it here would
// silently turn a Fedistructure install into a photoblog.
im('fedistructure is not selectable from a card', !install_mode_is_selectable('fedistructure'));
im('fedistructure still persists, being package-set',
    install_persistable_site_mode('fedistructure') === 'fedistructure');

// ── The validation and the markup both use the list ────────────────────────
im('the POST check calls the shared list rather than its own array',
    (bool) preg_match('/install_mode_is_selectable\(\s*\$posted_mode\s*\)/', $src));
im('the hardcoded accepted-values array is gone',
    !preg_match("/'photoblog',\s*'carousel',\s*'smacktalk',\s*'smackthemup'/", $src));
im('the persisted mode is re-gated through the shared list',
    (bool) preg_match('/install_persistable_site_mode\(\s*\(string\)\(\s*\$_SESSION\[\s*\x27site_mode\x27\s*\]/', $src));

// ── Nothing to post, focus or click ────────────────────────────────────────
im('there is no smackthemup radio input at all',
    !preg_match('/value\s*=\s*"smackthemup"/', $src));
im('the card is marked unavailable',
    str_contains($src, 'install-mode-card-unavailable'));
im('the card is not a <label>, so a click activates no control',
    (bool) preg_match('/<div class="install-mode-card install-mode-card-unavailable/', $src));
im('the card says so in words, not only by styling',
    str_contains($src, 'Not available yet'));
im('the card is marked disabled for assistive technology',
    (bool) preg_match('/install-mode-card-unavailable[^>]*aria-disabled="true"/', $src));
// A disabled-looking card that is still in the tab order would fail the
// keyboard requirement, so assert there is no tabindex pulling it back in.
im('the card is not given a tabindex',
    !preg_match('/install-mode-card-unavailable[^>]*tabindex/', $src));
im('SMACKTHEMUP is still shown, not deleted from the page',
    str_contains($src, 'SMACKTHEMUP'));

// Every site_mode radio that IS offered must be in the shared list.
preg_match_all('/name\s*=\s*"site_mode"\s+value\s*=\s*"([a-z-]+)"/', $src, $radios);
$offered = array_values(array_unique($radios[1] ?? []));
$stray   = array_values(array_diff($offered, install_selectable_site_modes()));
im('every rendered site_mode radio is in the shared list'
   . ($stray ? ' (stray: ' . implode(', ', $stray) . ')' : ''), $stray === []);
sort($offered);
im('all three supported modes are still rendered as radios',
    $offered === ['carousel', 'photoblog', 'smacktalk']);

// ── Inventory every assignment, so a new bypass fails this test ────────────
// If someone adds a fourth place that writes site_mode, this count moves and
// this assertion fails until they say what it is.
preg_match_all('/\$_SESSION\[\s*\x27site_mode\x27\s*\]\s*=/', $src, $assigns);
$n = count($assigns[0]);
im("exactly three session assignments to site_mode, all accounted for (found {$n})", $n === 3);
im('the fedistructure assignment is the package branch, not a posted value',
    (bool) preg_match('/\$installer_is_fedistructure\s*\)\s*\{[\s\S]{0,1200}?\$_SESSION\[\s*\x27site_mode\x27\s*\]\s*=\s*\x27fedistructure\x27/', $src));
im('the posted assignment sits inside the selectable check',
    (bool) preg_match('/install_mode_is_selectable\(\s*\$posted_mode\s*\)\s*\)\s*\{[\s\S]{0,1400}?\$_SESSION\[\s*\x27site_mode\x27\s*\]\s*=\s*\$posted_mode/', $src));
im('the fallback assignment is the safe default',
    (bool) preg_match('/\$_SESSION\[\s*\x27site_mode\x27\s*\]\s*=\s*\x27photoblog\x27/', $src));

// ── Rejection precedes any write ───────────────────────────────────────────
$check_at   = strpos($src, 'install_mode_is_selectable($posted_mode)');
// The CALL SITE, not the function definition near the top of install.php:
// anchoring on the bare name made this assertion compare the wrong things.
$persist_at = strpos($src, 'install_persistable_site_mode((string)(');
$defaults_at = strpos($src, "'site_mode'                 => \$install_mode");
im('the selectable check precedes the persistable gate',
    $check_at !== false && $persist_at !== false && $check_at < $persist_at);
im('the persistable gate precedes the defaults array that writes to the database',
    $persist_at !== false && $defaults_at !== false && $persist_at < $defaults_at);

if ($fails) {
    fwrite(STDERR, "\n{$fails} FAILURE(S): the installer can offer or accept an untested site mode, "
        . "or a stale session value can reach the database.\n");
    exit(1);
}
echo "SS-1197 installer offers only tested modes: PASS\n";
exit(0);

// ===== SNAPSMACK EOF =====
