<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1481 finding 11 — an inbound fetch honours Retry-After.
 *
 * THE FAULT. The OUTBOUND delivery queue has honoured Retry-After for a long
 * time. Inbound fetching threw it away: sv_fetch_ap() returned null for any
 * non-2xx without reading the header, and the relay ingest queue then retried
 * on a fixed 5min * 2^n schedule regardless of what the origin had asked for.
 * Retrying on your own schedule against a server telling you to slow down is
 * how a polite instance starts rate-limiting you harder.
 *
 * BOTH CLAIMS in the finding were re-verified separately at caebe17b before
 * anything changed -- zero Retry-After handling in sv_fetch_ap(), and the fixed
 * backoff at smackcast-relay.php. That is a change in method: for finding 9 I
 * checked the finding rather than each claim inside it, and half of that
 * finding turned out to be false.
 *
 * WHAT THIS PROVES: the parser against both legal header forms and the junk in
 * between, and that the retry schedule actually takes the header's value.
 *
 * NOT COVERED: a real HTTP response. The header is injected at the accessor,
 * because curl's own header callback is not reachable without a server.
 */

$root = dirname(__DIR__);
$failures = [];

function ra_check(string $label, bool $ok, array &$failures): void {
    if ($ok) { echo "  PASS  {$label}\n"; }
    else { echo "  FAIL  {$label}\n"; $failures[] = $label; }
}

$src = (string)file_get_contents($root . '/core/fediverse.php');
foreach (['sv_last_retry_after', 'sv_retry_after_seconds'] as $name) {
    $start = strpos($src, 'function ' . $name . '(');
    if ($start === false) { fwrite(STDERR, "missing {$name}\n"); exit(1); }
    $end  = strpos($src, "\nfunction ", $start + 1);
    $body = substr($src, $start, $end - $start);
    eval(substr($body, 0, strrpos($body, "\n}") + 2));
}

echo "SS-1481 #11 — an inbound fetch honours Retry-After\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — delta-seconds, the common form\n";
// ─────────────────────────────────────────────────────────────────────────────
ra_check('120 means 120 seconds', sv_retry_after_seconds('120') === 120, $failures);
ra_check('whitespace around the value is tolerated',
         sv_retry_after_seconds(' 60 ') === 60, $failures);
ra_check('a huge value is clamped to the cap, so a remote cannot park the queue',
         sv_retry_after_seconds('99999999') === 86400, $failures);
ra_check('and the cap is the callers to choose',
         sv_retry_after_seconds('9999', 600) === 600, $failures);
ra_check('zero means nothing usable', sv_retry_after_seconds('0') === null, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — HTTP-date, the other legal form\n";
// ─────────────────────────────────────────────────────────────────────────────
$future = gmdate('D, d M Y H:i:s', time() + 300) . ' GMT';
$secs = sv_retry_after_seconds($future);
ra_check('a date 300s ahead gives about 300 seconds',
         $secs !== null && $secs > 290 && $secs <= 300, $failures);
ra_check('a date in the PAST gives nothing, rather than a negative wait',
         sv_retry_after_seconds(gmdate('D, d M Y H:i:s', time() - 300) . ' GMT') === null,
         $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — JUNK, including the one that bit me\n";
// ─────────────────────────────────────────────────────────────────────────────
// Measured while writing this, before the test existed: strtotime('-5') returns
// a time 18000 seconds AHEAD, because it reads -5 as the timezone offset
// -05:00. So a nonsense header became a five-hour wait. A value that is not
// plain delta-seconds now has to look like a date -- carry a letter -- before
// it goes anywhere near strtotime().
foreach (['-5', '+5', '5.5', '', 'banana', '!!', '12 34'] as $junk) {
    ra_check("junk " . var_export($junk, true) . " gives nothing",
             sv_retry_after_seconds($junk) === null, $failures);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — THE ACCESSOR CARRIES ONE ATTEMPT'S ANSWER\n";
// ─────────────────────────────────────────────────────────────────────────────
sv_last_retry_after('');
ra_check('it starts empty', sv_last_retry_after() === '', $failures);
sv_last_retry_after('90');
ra_check('it holds what a response gave', sv_last_retry_after() === '90', $failures);
ra_check('and the parser reads it back', sv_retry_after_seconds(sv_last_retry_after()) === 90, $failures);
sv_last_retry_after('');
ra_check('and it can be cleared, so a later fetch is not judged on an older header',
         sv_retry_after_seconds(sv_last_retry_after()) === null, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 5 — THE RETRY SCHEDULE TAKES THE LONGER OF THE TWO\n";
// ─────────────────────────────────────────────────────────────────────────────
// The schedule in sc_relay_run_ingest_jobs() is
//   delay = min(86400, 300 * 2^(attempts-1)),  then  max(delay, asked)
// Honouring a SHORTER Retry-After would let a remote pull us back into a tight
// retry loop after we had already decided to back off, so the header can only
// slow us down. This reproduces that arithmetic against the real source rather
// than driving the database loop, and asserts the source still reads that way.
$relay = (string)file_get_contents($root . '/core/smackcast-relay.php');
ra_check('the ingest loop asks the parser for the header',
         str_contains($relay, 'sv_retry_after_seconds(sv_last_retry_after())'), $failures);
ra_check('and takes the LONGER of its own backoff and the header',
         str_contains($relay, '$delay = max($delay, $asked)'), $failures);

$backoff = static fn (int $attempts): int => min(86400, 300 * (2 ** min(8, $attempts - 1)));
$applied = static fn (int $attempts, ?int $asked): int =>
    $asked === null ? $backoff($attempts) : max($backoff($attempts), $asked);
ra_check('a first failure with no header waits the usual 300s',
         $applied(1, null) === 300, $failures);
ra_check('a longer Retry-After wins over a short backoff',
         $applied(1, 1800) === 1800, $failures);
ra_check('a SHORTER Retry-After does NOT shorten a long backoff',
         $applied(6, 60) === $backoff(6), $failures);
ra_check('and nothing exceeds the day cap',
         $applied(8, 86400) <= 86400, $failures);

echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1481 #11 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(1);
}
echo "SS-1481 #11 PASS — Retry-After is read in both legal forms, junk is\n";
echo "refused, and the ingest retry takes the longer of its own backoff and\n";
echo "what the origin asked for.\n";
echo "NOT COVERED: a real HTTP response; curl's header callback needs a server.\n";
echo "Stage 5's two source checks are shape, not behaviour, and say so.\n";
// ===== SNAPSMACK EOF =====
