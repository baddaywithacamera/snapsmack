<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * "IS THIS SLUG TAKEN?" IS A QUESTION, NOT A SEARCH THROUGH A CAPPED LIST.
 *
 * COLD SNAP decides update-or-create by looking for its draft's slug among the
 * posts GET smackpress/posts returns, and that list is capped at 500 with no
 * offset. On a site with more than 500 longform posts the answer was simply
 * not in the page it received: an existing post could be missed and a
 * duplicate created, and the client could not tell a real "no such slug" from
 * "past the cap". Gemini's COLD SNAP 0.7.49 had to refuse to guess when a full
 * page came back, which is the right call and a poor substitute for an answer.
 *
 * THE DANGEROUS DIRECTION IS A FALSE "FREE". Reporting a taken slug as
 * available is what creates the duplicate post, or -- with the confirmation
 * flow on the client -- overwrites the wrong one. So the matching is exact and
 * the slug is validated, and the cases below lean on that edge.
 *
 * These are SOURCE assertions and say so: the endpoint needs a request, a key
 * and a database. The two things source can prove are the ones that went
 * wrong -- that the match is exact rather than a prefix or a LIKE, and that an
 * empty answer is distinguishable from a truncated one.
 */

$root = dirname(__DIR__);
$failures = [];

$src = (string)@file_get_contents($root . '/core/smackpress-api.php');
if ($src === '') {
    throw new RuntimeException('could not read core/smackpress-api.php');
}
$code = (string)php_strip_whitespace($root . '/core/smackpress-api.php');

// Isolate the GET handler so every claim is about IT.
$at = strpos($code, "\$sub === 'posts' && \$method === 'GET'");
if ($at === false) {
    throw new RuntimeException('the GET smackpress/posts handler could not be '
        . 'found; every assertion below would pass for the wrong reason');
}
$postAt = strpos($code, "\$sub === 'posts' && \$method === 'POST'");
$handler = $postAt !== false && $postAt > $at
    ? substr($code, $at, $postAt - $at)
    : substr($code, $at, 4000);

// ── 1. THE SLUG LOOKUP EXISTS AND IS EXACT ───────────────────────────────
if (!str_contains($handler, "\$_GET['slug']")) {
    $failures[] = 'GET smackpress/posts no longer accepts ?slug=, so a client '
        . 'on a site with more than 500 posts is back to guessing from a '
        . 'truncated list';
}
if (!preg_match('/slug\s*=\s*\?/', $handler)) {
    $failures[] = 'the slug is not matched with an equality placeholder. A LIKE '
        . 'or a prefix match answers about a DIFFERENT post, which is how a '
        . 'taken slug gets reported as free.';
}
foreach (['LIKE', '%'] as $fuzzy) {
    if (preg_match('/slug[^;]{0,40}' . preg_quote($fuzzy, '/') . '/i', $handler)) {
        $failures[] = "the slug comparison involves '{$fuzzy}', which makes it "
            . 'fuzzy; it must be exact';
    }
}
// Matched WITHOUT a dollar sign in the pattern: in a single-quoted PHP string
// my first version's \$ became an escaped backslash plus an end-anchor, so it
// failed against correct code. The lesson is the usual one -- a test that
// fails on working code gets "fixed" in the wrong direction by the next person.
if (!preg_match('/preg_match\([^;]{0,90}slug_query/', $handler)) {
    $failures[] = 'the slug is not validated before it reaches the query. A '
        . 'junk value should be a 400, not a silent empty answer that reads as '
        . '"this slug is free".';
}

// The lookup must come BEFORE the capped list, or the cap still decides.
$slugAt = strpos($handler, "\$_GET['slug']");
$limitAt = strpos($handler, "\$_GET['limit']");
if ($slugAt !== false && $limitAt !== false && $slugAt > $limitAt) {
    $failures[] = 'the slug lookup runs after the capped list is built, so the '
        . 'cap still decides the answer';
}

// ── 2. AN EXACT ANSWER SAYS SO ───────────────────────────────────────────
// Without a marker, an empty list from the slug lookup looks exactly like an
// empty page from a truncated list, and the client is guessing again.
if (!str_contains($handler, "'exact'")) {
    $failures[] = "the slug answer carries no 'exact' marker, so a client "
        . 'cannot tell a complete "no such slug" from a short page';
}

// ── 3. THE LIST SAYS HOW MUCH IT IS NOT SHOWING ──────────────────────────
foreach (["'total'", "'truncated'"] as $field) {
    if (!str_contains($handler, $field)) {
        $failures[] = "the list answer no longer carries {$field}. \"I received "
            . 'exactly 500" is also what a site with exactly 500 posts looks '
            . 'like, which is the ambiguity that forced the client to refuse.';
    }
}
if (!preg_match("/COUNT\(\*\)\s*FROM\s*snap_posts/i", $handler)) {
    $failures[] = 'the total is not counted from snap_posts, so it cannot be '
        . 'the real number';
}
if (!preg_match("/COUNT\(\*\)\s*FROM\s*snap_posts\s*WHERE\s*post_type\s*=\s*'longform'/i", $handler)) {
    $failures[] = 'the total counts posts of every type while the list returns '
        . 'longform only, so truncated would be wrong on every site that has '
        . 'any other kind of post -- which is all of them';
}

// ── 4. THE SCOPE DID NOT WIDEN ───────────────────────────────────────────
// This endpoint is the longform picker. A slug lookup that reached other post
// types would answer about a gram or a page and call it a collision.
if (substr_count($handler, "post_type='longform'") < 3) {
    $failures[] = 'one of the three queries in this handler no longer scopes to '
        . "post_type='longform' (slug lookup, list, count)";
}
if (preg_match('/\bDELETE\b|\bUPDATE\b|\bINSERT\b/i', $handler)) {
    $failures[] = 'the GET handler now writes to the database';
}

// ── 5. THE CAP IS STILL A CAP ────────────────────────────────────────────
// The fix is a second way to ask, not permission to pull the whole table.
if (!preg_match('/min\(\s*500\s*,/', $handler)) {
    $failures[] = 'the 500-row cap on the list is gone; the slug lookup exists '
        . 'so the cap does not have to be raised';
}

// ── 6. THE PURE SLUG PATTERN, EXECUTED ───────────────────────────────────
// The one piece that can run here: the pattern the handler validates with.
// A pattern that accepts a wildcard or whitespace would put either into the
// comparison.
if (!preg_match("/'(\/\^\[a-z0-9\]\[a-z0-9_-\]\{0,\d+\}\\\$\/i)'/", $handler, $m)) {
    $failures[] = 'the slug validation pattern is not the house shape '
        . '(leading alphanumeric, then alphanumeric, hyphen or underscore)';
} else {
    $pattern = $m[1];
    foreach (['start-your-engines', 'ig-20260701-041832', 'a', 'A_b-9'] as $ok) {
        if (!preg_match($pattern, $ok)) {
            $failures[] = "a legitimate slug '{$ok}' is rejected by the pattern";
        }
    }
    foreach (['%', 'a%b', 'a b', '-leading', '', 'a/b', "a'b", 'a;b', "a\nb"] as $bad) {
        if (preg_match($pattern, $bad)) {
            $failures[] = 'the pattern accepts ' . var_export($bad, true)
                . ', which would reach the slug comparison';
        }
    }
    if (preg_match($pattern, str_repeat('a', 300))) {
        $failures[] = 'the pattern accepts a 300-character slug; it should be '
            . 'bounded';
    }
}

if ($failures) {
    throw new RuntimeException("The slug lookup cannot be trusted:\n  - "
        . implode("\n  - ", $failures));
}

echo "smackpress/posts: exact slug lookup, marked exact, list reports its own "
    . "truncation: passed.\n";
// ===== SNAPSMACK EOF =====
