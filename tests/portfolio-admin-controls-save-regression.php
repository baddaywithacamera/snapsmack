<?php
declare(strict_types=1);

/**
 * SS-1185. The owner must be able to designate a portfolio from his own admin.
 *
 * THE FAULT THIS CLOSES. The six portfolio columns have existed since the
 * routing work landed; core/portfolio-route.php reads them, the public
 * surfaces honour them, core/smackpress-api.php writes them, and four tests
 * passed. NOTHING IN THE ADMIN COULD SET THEM. Measured on dev 39a23e8a: zero
 * occurrences of any of the six in any post editor. So the tests were green
 * and Sean still could not mark one of his own galleries without driving the
 * API by hand, six times.
 *
 * That is the four-controls-that-did-nothing failure pointing the other way,
 * and the lesson applies to this test too: injecting a settings array only
 * proves the READ path. So this drives the real reader with a real $_POST and
 * checks what it produces, and it checks that both editors actually write
 * every column -- a control that posts a value nobody stores is the same
 * defect wearing a different hat.
 */

require_once dirname(__DIR__) . '/core/portfolio-designation-controls.php';

$fails = [];
$check = static function (string $what, bool $ok) use (&$fails): void {
    if (!$ok) $fails[] = $what;
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . "\n";
};

$COLUMNS = snap_portfolio_control_fields();
$check('the component owns exactly the six portfolio columns', count($COLUMNS) === 6);

// --- ticking the boxes ------------------------------------------------------
$got = snap_portfolio_control_read([
    'portfolio_controls_present' => '1',
    'portfolio_post' => '1',
    'exclude_from_archive' => '1',
    'primary_album_id' => '7',
], []);
$check('a ticked box saves as 1', $got['portfolio_post'] === 1);
$check('a ticked exclusion saves as 1', $got['exclude_from_archive'] === 1);
$check('an unticked box on a rendered form saves as 0', $got['exclude_from_search'] === 0);
$check('a chosen album saves as its id', $got['primary_album_id'] === 7);
$check('an unchosen category saves as NULL, not 0',
    array_key_exists('primary_category_id', $got) && $got['primary_category_id'] === null);

// --- unticking must actually clear ------------------------------------------
$got = snap_portfolio_control_read(
    ['portfolio_controls_present' => '1'],
    ['portfolio_post' => 1, 'exclude_from_feed' => 1, 'primary_album_id' => 7]);
$check('UNTICKING clears the flag, it does not keep the old value',
    $got['portfolio_post'] === 0 && $got['exclude_from_feed'] === 0);
$check('clearing the album returns NULL', $got['primary_album_id'] === null);

// --- ABSENT IS NOT OFF: the part that protects the API ----------------------
$stored = ['portfolio_post' => 1, 'exclude_from_archive' => 1,
           'exclude_from_search' => 0, 'exclude_from_feed' => 1,
           'primary_album_id' => 4, 'primary_category_id' => 9];
$got = snap_portfolio_control_read(['title' => 'edited elsewhere'], $stored);
$check('a form WITHOUT the fieldset preserves every stored value',
    $got['portfolio_post'] === 1 && $got['exclude_from_archive'] === 1
    && $got['exclude_from_feed'] === 1 && $got['primary_album_id'] === 4
    && $got['primary_category_id'] === 9);

// --- the rendered control actually posts what the reader expects ------------
ob_start();
snap_portfolio_controls_render(
    ['portfolio_post' => 1, 'primary_album_id' => 4],
    [['id' => 4, 'name' => 'Landscapes'], ['id' => 5, 'name' => 'Portraits']],
    [['id' => 9, 'name' => 'Alberta']]);
$html = (string)ob_get_clean();

$check('the fieldset posts its presence marker',
    str_contains($html, 'name="portfolio_controls_present"'));
foreach (['portfolio_post', 'exclude_from_archive', 'exclude_from_search',
          'exclude_from_feed', 'primary_album_id', 'primary_category_id'] as $field) {
    $check("the form carries a control for {$field}",
        str_contains($html, 'name="' . $field . '"'));
}
$check('a set flag renders ticked', str_contains($html, 'name="portfolio_post" value="1" checked'));
$check('the chosen album renders selected',
    str_contains($html, 'value="4" selected') || str_contains($html, 'value="4"  selected'));
$check('an album the owner has not chosen is not selected',
    !str_contains($html, 'value="5" selected'));
$check('NOT DESIGNATED is offered, so a designation can be removed',
    str_contains($html, 'NOT DESIGNATED'));

// NO STATIC INLINE STYLE. The repo rule, and this file is new, so it starts clean.
$check('the fieldset carries no static style= attribute',
    !preg_match('/\sstyle="/i', $html));

// --- BOTH EDITORS MUST WRITE ALL SIX ---------------------------------------
// A rendered control whose value nobody stores is the same defect again.
foreach (['smack-post-long.php', 'smack-edit-carousel.php'] as $editor) {
    $src = (string)file_get_contents(dirname(__DIR__) . '/' . $editor);
    $check("{$editor} renders the shared fieldset",
        str_contains($src, 'snap_portfolio_controls_render('));
    $check("{$editor} reads it through the shared reader",
        str_contains($src, 'snap_portfolio_control_read('));
    foreach ($COLUMNS as $col) {
        $check("{$editor} writes {$col} to the database",
            preg_match('/' . preg_quote($col, '/') . '\s*=\s*\?/', $src) === 1
            || preg_match('/\b' . preg_quote($col, '/') . '\b[^;]{0,400}VALUES/s', $src) === 1);
    }
}

echo (count($fails) ? "\nFAIL" : "\nPASS")
   . ": the owner can designate a portfolio from the admin (SS-1185)\n";
exit(count($fails) ? 1 : 0);
// ===== SNAPSMACK EOF =====
