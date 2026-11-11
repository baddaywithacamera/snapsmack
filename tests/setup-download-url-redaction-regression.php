<?php
/**
 * SS-1293 — the deployer must not print a release URL's query string.
 *
 * When the bootstrap deployer cannot download the release package it shows the
 * download address on the page. A query string is where a one-time token or a
 * signing parameter lives, and this is the one screen where the reader has no
 * logs, no admin and nowhere else to look — so they will reasonably screenshot
 * it and send it to somebody for help.
 *
 * Today's release links carry no token, so nothing is leaking. This is the
 * defence for the day one does, and it is a line of code.
 *
 * The host and path are KEPT on purpose. They are the diagnostic part — wrong
 * host, wrong channel, a typo in a manifest — and dropping them would trade a
 * possible leak for a useless error message.
 *
 * Found while repairing the write-failure message one line below under SS-1198
 * and deliberately left out of that ticket. The same scrubbing is done inline
 * there; when both land, the two should share this helper rather than keep two
 * copies of the rule. Noted rather than done, because SS-1198 is already under
 * review and editing a branch mid-review is how a reviewed change stops being
 * one.
 *
 * setup.php cannot be included: it renders a page and refuses when core/ is
 * absent. The function is extracted and evaluated, as
 * tests/bootstrap-signature-refusal-regression.php already does for
 * setup_verify().
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root  = dirname(__DIR__);
$fails = 0;

function du(string $label, bool $ok): void {
    global $fails;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fails++;
}

$setup = (string) @file_get_contents($root . '/setup.php');
du('setup.php is readable', $setup !== '');
if ($setup === '') {
    fwrite(STDERR, "\nSS-1293: setup.php missing — cannot check.\n");
    exit(1);
}

// ── The call site ──────────────────────────────────────────────────────────
du(
    'the download-failure message no longer prints the raw URL',
    !preg_match("/Failed to download release package from[^;]*htmlspecialchars\(\s*\\\$release\['download_url'\]\s*\)/", $setup)
);
du(
    'the download-failure message redacts the query first',
    (bool) preg_match('/Failed to download release package from[\s\S]{0,300}?setup_redact_url_query\(/', $setup)
);
// Checked on the line itself rather than with one pattern spanning the whole
// call: the argument contains its own parentheses, so a [^)]* in the middle
// stops early and the assertion fails on code that is correct.
$escaped_ok = false;
foreach (explode("\n", $setup) as $line) {
    if (str_contains($line, 'setup_redact_url_query(')
        && str_contains($line, 'htmlspecialchars(')
        && str_contains($line, 'ENT_QUOTES')) {
        $escaped_ok = true;
        break;
    }
}
du('and still escapes what it prints, with ENT_QUOTES', $escaped_ok);

// ── The helper, run for real ───────────────────────────────────────────────
if (!preg_match('/(function\s+setup_redact_url_query\s*\(.*?\n\})/s', $setup, $fn)) {
    du('setup_redact_url_query() could be extracted', false);
    fwrite(STDERR, "\nSS-1293: the redaction helper is missing.\n");
    exit(1);
}
du('setup_redact_url_query() could be extracted', true);
eval($fn[1]);
du('setup_redact_url_query() is callable', function_exists('setup_redact_url_query'));

$plain = 'https://snapsmack.ca/releases/snapsmack-0.7.993D.zip';
du('a URL with no query is returned unchanged', setup_redact_url_query($plain) === $plain);

$tokened = $plain . '?token=SECRET123&sig=abcdef';
$out = setup_redact_url_query($tokened);
du('a token does not survive',              !str_contains($out, 'SECRET123'));
du('a signature parameter does not survive', !str_contains($out, 'sig=abcdef'));
du('the redaction is labelled, not silent',  str_contains($out, '?[redacted]'));
du('the host and path are kept, being the diagnostic part',
    str_contains($out, 'snapsmack.ca/releases/snapsmack-0.7.993D.zip'));

// A bare '?' and a query that is only a token must both be handled.
du('a trailing question mark is handled',
    setup_redact_url_query($plain . '?') === $plain . '?[redacted]');
du('a query with one bare value is handled',
    setup_redact_url_query($plain . '?abc') === $plain . '?[redacted]');

// Only the FIRST question mark matters; a second cannot smuggle anything past.
$two = $plain . '?a=1?b=2&t=SECRET';
du('everything after the first question mark goes', !str_contains(setup_redact_url_query($two), 'SECRET'));

// Degenerate input must not throw or invent.
du('an empty string stays empty', setup_redact_url_query('') === '');
du('a malformed address is still reported rather than dropped',
    setup_redact_url_query('not a url at all') === 'not a url at all');
du('a malformed address with a query is still redacted',
    setup_redact_url_query('garbage?token=SECRET') === 'garbage?[redacted]');

// A fragment is not a query and must not be mistaken for one.
du('a fragment is left alone',
    setup_redact_url_query($plain . '#top') === $plain . '#top');

if ($fails) {
    fwrite(STDERR, "\n{$fails} FAILURE(S): the deployer can print a release URL's query string, "
        . "which is where a token would be.\n");
    exit(1);
}
echo "SS-1293 download-URL redaction: PASS\n";
exit(0);

// ===== SNAPSMACK EOF =====
