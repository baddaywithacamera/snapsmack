<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Every skin must be able to show the real comment system.
 *
 * 19 October 2026. Measured on nine live sites, strict skins and legacy
 * alike: zero comment forms on any photo page, while
 * assets/css/ss-community.css loaded on every one of them. The feature --
 * thread, form, guest fields, avatars, likes, reactions, moderation state,
 * fediverse replies -- is core/community-component.php, complete and called
 * by nothing.
 *
 * 24 skins included it at v0.7.603D. A strict template runs in a closure
 * holding only $view, so the include now returns at its own
 * `empty($img['id'])` guard, in silence. Nobody noticed for two weeks
 * because a missing feature emits no error.
 *
 * What is pinned here is the path from the CMS to the skin, without a
 * database: the runtime renders the block, the contract hands it over, and
 * the comments component -- which all 26 skins already call where their own
 * stylesheet puts comments -- emits it instead of the author-and-text stub.
 */

$root = dirname(__DIR__);
$fail = [];
$check = static function (bool $ok, string $what) use (&$fail): void {
    if (!$ok) $fail[] = $what;
};

require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';

// ── the component emits the block, not the stub ─────────────────────────────
$block = SnapTrustedHtml::__snapsmackCmsOnly('<div class="ss-community">FORM</div>');
snap_community_block($block, true);
$rendered = (string)snap_render_component('comments', ['items' => [
    ['author' => 'Ray', 'text' => 'asshat'],
]]);
$check (str_contains($rendered, 'ss-community'), 'the comments component ignores the community block');
$check(!str_contains($rendered, 'snap-comments'),
    'the stub is rendered alongside the real thing, so comments appear twice');

// ── and the stub is still there when there is no block ──────────────────────
// Comments off for the site or the post, or the community migration not run.
snap_community_block(null, true);
$stub = (string)snap_render_component('comments', ['items' => [
    ['author' => 'Ray', 'text' => 'asshat'],
]]);
$check(str_contains($stub, 'snap-comments') && str_contains($stub, 'Ray'),
    'with no community block the fallback list is gone too, so comments vanish');

// ── the contract carries it and hands it over ───────────────────────────────
$check(str_contains((string)file_get_contents($root . '/core/skin-view-contract.php'), "'community'"),
    "the view model drops the community key, so it never reaches a skin");
$view = snapsmack_build_skin_view(
    ['status' => 200, 'kind' => 'photo', 'navigation' => [], 'community' => $block],
    ['site_name' => 'x', 'base_url' => '/']);
$check(snap_community_block() === $block,
    'building the view did not hand the community block to the render helpers');
$reason = null;
$check(snapsmack_validate_skin_view($view, $reason),
    'a view carrying the community block fails validation: ' . (string)$reason);

// A skin cannot forge one. Only the opaque type is accepted, and a skin has
// no way to construct it -- see tests/privileged-wrapper-construction-regression.php.
snapsmack_build_skin_view(
    ['status' => 200, 'kind' => 'photo', 'navigation' => [], 'community' => '<script>alert(1)</script>'],
    ['site_name' => 'x', 'base_url' => '/']);
$check(snap_community_block() === null,
    'a plain string was accepted as the community block, which a skin could forge');

// ── the renderer is on the CMS side of the boundary ─────────────────────────
$runtime = (string)file_get_contents($root . '/core/public-runtime.php');
$check(str_contains($runtime, 'function snapsmack_public_community_block'),
    'the community block is no longer rendered in the runtime');
$check(str_contains($runtime, "include __DIR__ . '/community-component.php'"),
    'the runtime no longer includes the real comment component');
$check(str_contains($runtime, "\$response['community'] = \$_community"),
    'the rendered block is not attached to the response');
// It must stay gated by the same switch as the navigation's COMMENTS link,
// or a site with comments off grows a comment form.
$check(str_contains($runtime, "!empty(\$response['comments_enabled'])"),
    'the community block ignores comments_enabled, so switching comments off leaves the form');

// ── and no skin had to change for it ───────────────────────────────────────
// This is the delivery half (OPAUDIT 025). A fix that needed 26 skin packages
// built, published and installed per site would have reached almost nobody.
$touched = [];
foreach (glob($root . '/skins/*/layout.php') as $layout) {
    if (str_contains((string)file_get_contents($layout), "'community'")) {
        $touched[] = basename(dirname($layout));
    }
}
$check($touched === [],
    'a skin now reads the community key, which means a manifest bump and a '
    . 'per-site skin update before anyone sees comments: ' . implode(', ', $touched));

if ($fail) {
    fwrite(STDERR, "FAIL: the community block does not reach the skins\n  - " . implode("\n  - ", $fail) . "\n");
    exit(1);
}
echo "PASS: the real comment system reaches every skin, on a CMS deploy alone.\n";
// ===== SNAPSMACK EOF =====
