<?php
declare(strict_types=1);

/**
 * SS-1323. A heart on each photograph, and the things it must never do.
 *
 * Sean's spec: outline heart bottom-left of each conveyor image, appears on
 * hover, clickable in place so a visitor can like several in a row, NO count
 * on the conveyor.
 *
 * The like itself was never the hard part -- process-like.php and snap_likes
 * already exist, which is why this needed no new public write path. The hard
 * part is NOT doing anything else: the tile is a link to the solo page, and
 * on a GLIDE conveyor the row is also a drag surface that flings.
 */

require_once dirname(__DIR__) . '/core/tile-like-control.php';

$root = dirname(__DIR__);
$fails = [];
$check = static function (string $what, bool $ok) use (&$fails): void {
    if (!$ok) $fails[] = $what;
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . "\n";
};

// --- the control itself -----------------------------------------------------
$off = snap_tile_like_html(0, false);
$check('a bad post id renders nothing at all', $off === '');

$html = snap_tile_like_html(42, false);
$check('it is a real <button>, so Enter and Space work with no extra code',
    str_starts_with($html, '<button type="button"'));
$check('it carries the id the engine posts', str_contains($html, 'data-tile-like="42"'));

// THE ID IS AN IMAGE ID, NOT A POST ID, however snap_likes.post_id is named.
// process-like.php validates it against snap_images and the dock sends
// (int)$img['id']. Getting this wrong 404s every tile, or likes whichever
// photograph shares the number. Pinned here because the column name actively
// misleads.
$control = (string)file_get_contents($root . '/core/tile-like-control.php');
$check('the control takes an IMAGE id and says so',
    str_contains($control, 'function snap_tile_like_html(int $image_id')
    && str_contains($control, 'snap_likes.post_id HOLDS AN IMAGE ID'));
$endpoint = (string)file_get_contents($root . '/process-like.php');
$check('and that matches what the endpoint actually validates',
    str_contains($endpoint, 'FROM snap_images WHERE id = ?'));
$dock = (string)file_get_contents($root . '/core/community-dock.php');
$check('and what the existing dock sends', str_contains($dock, "\$post_id        = (int)\$img['id']"));
$check('it is labelled for a screen reader', str_contains($html, 'aria-label="Like this photograph"'));
$check('and its state is announced', str_contains($html, 'aria-pressed="false"'));
$check('the glyph is hidden from assistive tech, because the label carries it',
    str_contains($html, 'aria-hidden="true"'));
$check('NO COUNT ANYWHERE, which is the spec',
    !preg_match('/\d+\s*<\/|count/i', str_replace(['data-tile-like="42"', '24'], '', $html)));
$check('no static style attribute', !str_contains($html, 'style='));

$liked = snap_tile_like_html(42, true);
$check('a liked heart says so in its class', str_contains($liked, 'is-liked'));
$check('and to a screen reader', str_contains($liked, 'aria-pressed="true"')
    && str_contains($liked, 'aria-label="Unlike this photograph"'));

// --- the setting ------------------------------------------------------------
// THE GATE MUST MATCH process-like.php, AND BOTH MUST FOLLOW THE DEFAULT-ON
// RULE. The control decides whether to draw a button that endpoint will
// accept or refuse, so a heart that 403s on click -- with nothing on screen
// to say why -- is worse than no heart. Muse found the two disagreeing.
// The endpoint was the one breaking the rule, reading `!== '1'` while the
// dock reads `!== '0'`; it is fixed in the same commit. These checks pin
// BOTH ends of that agreement.
$check('it respects community_likes_enabled being off',
    !snap_tile_like_enabled(['community_likes_enabled' => '0']));
$check('and is on by default, like the dock',
    snap_tile_like_enabled([]));
$check('an empty likes setting is NOT off -- the site never asked',
    snap_tile_like_enabled(['community_likes_enabled' => '']));
$check('the community being off draws no heart, whatever likes says',
    !snap_tile_like_enabled(['community_enabled' => '0', 'community_likes_enabled' => '1']));
$check('an empty community setting is not off either',
    snap_tile_like_enabled(['community_enabled' => '', 'community_likes_enabled' => '1']));
$check('both on explicitly still draws it',
    snap_tile_like_enabled(['community_enabled' => '1', 'community_likes_enabled' => '1']));

// The endpoint's half of the same agreement, read from its source: it must
// not refuse a site whose setting is merely empty.
$likeSrc = (string)@file_get_contents(dirname(__DIR__) . '/process-like.php');
$check('process-like.php reads community_enabled the default-on way',
    strpos($likeSrc, "(\$settings['community_enabled'] ?? '1') === '0'") !== false);
$check('process-like.php reads community_likes_enabled the default-on way',
    strpos($likeSrc, "(\$settings['community_likes_enabled'] ?? '1') === '0'") !== false);
$check('and no longer demands an exact 1 from either',
    strpos($likeSrc, "?? '1') !== '1'") === false);

// --- THE PART THAT MATTERS: it must not open the tile or start a drag ------
$js = (string)file_get_contents($root . '/assets/js/ss-engine-tile-like.js');

// THE ENDPOINT PATH IS ROOT-RELATIVE, the way the dock spells it.
// It was written without the leading slash, which resolves against the
// CURRENT PATH: correct on a landing page at the site root, where the
// browser test ran, and wrong on every deeper route -- /archive, a photo
// page, a tag -- where every heart would have failed silently. Muse found
// it in review. Pinned because the passing case and the failing case look
// identical in the source.
$check('the engine posts to the root-relative endpoint, like the dock',
    str_contains($js, "fetch('/process-like.php'"));
$check('and not to a path that resolves against whatever page you are on',
    !str_contains($js, "fetch('process-like.php'"));

// THE CONVEYOR MUST CARRY THE HEART WITH THE PHOTOGRAPH.
// GLIDE redistributes tiles into its inner rows by CLONING them. It cloned
// the anchor, which was the whole tile until the heart became a SIBLING of
// that anchor (a button inside a link is invalid and is announced
// inconsistently). Cloning the anchor alone would copy the photograph and
// leave its heart behind, so the same photograph would be likeable in one
// row and not in another -- and nothing would look broken.
//
// THIS IS A SOURCE PIN, NOT A BEHAVIOUR TEST. It proves the engine reaches
// for the host before falling back to the anchor; it does NOT prove a
// redistributed inner row renders a working heart. That needs a browser and
// belongs in the GLIDE rendered gate.
$glide = (string)file_get_contents($root . '/assets/js/ss-engine-glide.js');
$check('redistribution clones the tile host when there is one',
    str_contains($glide, "tile.closest('.ss-tile-like-host') || tile"));
$check('and still clones the bare anchor when there is not -- it fails safe',
    str_contains($glide, 'unit.cloneNode(true)')
    && !str_contains($glide, 'innerTracks[nextInner++ % innerTracks.length].append(tile.cloneNode(true))'));
foreach (['pointerdown', 'mousedown', 'touchstart', 'click'] as $type) {
    $check("the engine intercepts {$type}, so no ancestor sees it",
        str_contains($js, "'" . $type . "'"));
}
$check('it stops propagation, so the tile link never opens',
    str_contains($js, 'event.stopPropagation()'));
$check('and listens in the CAPTURE phase, so binding order cannot defeat it',
    str_contains($js, ', true)'));
$check('pointerdown is where the conveyor starts a drag, and it is covered',
    str_contains($js, 'pointerdown'));
$check('it posts to the SAME endpoint as the dock, not a new one',
    str_contains($js, 'process-like.php'));
// The endpoint does NOT check a CSRF token today -- I looked rather than
// assumed. The token is sent anyway so that adding one there is a one-sided
// change, and so this control is not the reason it cannot be added.
$check('it sends a CSRF token, so the endpoint can start requiring one',
    str_contains($js, 'csrf_token'));
$check('and the endpoint genuinely has no check today, which is reported not fixed here',
    !preg_match('/csrf/i', $endpoint));
$check('a failed request puts the heart back rather than lying',
    str_contains($js, 'paint(button, was)'));
$check('every visible copy of one photograph shares a single in-flight request guard',
    str_contains($js, 'var pending = Object.create(null)')
    && str_contains($js, 'if (!id || pending[id]) return')
    && str_contains($js, 'delete pending[id]'));
$check('no second keydown handler, which would fire the like twice',
    !str_contains($js, "addEventListener('keydown'"));

// --- the touch rule, which the spec asked to be decided and stated ---------
$css = (string)file_get_contents($root . '/assets/css/ss-tile-like.css');
$check('TOUCH: with no hover the heart is always visible, not hidden forever',
    preg_match('/@media \(hover: none\)\s*\{[^}]*opacity:\s*\.?\d/s', $css) === 1);
$check('it is bottom-left, as specified',
    preg_match('/\.ss-tile-like\s*\{[^}]*left:\s*\d+px;[^}]*bottom:\s*\d+px;/s', $css) === 1);
$check('the touch target is at least 44x44',
    preg_match('/width:\s*44px;\s*height:\s*44px;/', $css) === 1);
$check('it has a visible focus ring', str_contains($css, ':focus-visible'));
$check('a liked heart stays visible rather than fading with the hover',
    preg_match('/\.ss-tile-like\.is-liked\s*\{[^}]*opacity:\s*1/s', $css) === 1);
$check('reduced motion is honoured', str_contains($css, 'prefers-reduced-motion'));
$check('no static style attribute anywhere in the stylesheet', !str_contains($css, 'style="'));

echo (count($fails) ? "\nFAIL" : "\nPASS")
   . ": the tile heart likes in place and does nothing else (SS-1323)\n";
exit(count($fails) ? 1 : 0);
// ===== SNAPSMACK EOF =====
