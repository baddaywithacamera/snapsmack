<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * A strict skin can place the shared social dock. SS-1446, item 4.
 *
 * WHAT WAS WRONG. ALFRED's own footer social markup and its copied Hitchcock
 * icon CSS were removed in favour of "the shared dock", and nothing on the
 * strict SMACKTALK path emits one. index.php renders the strict template and
 * exits; neither the registered-assets component nor the public engine assets
 * render core/social-dock.php. So the links went and nothing replaced them.
 *
 * HOW IT GOT THROUGH TWO PAIRS OF EYES. The browser proof inserted
 * <div class="social-dock"> into its own fixture HTML and then asserted that
 * its own markup existed, so the screenshots showed a dock that the skin had
 * not rendered. I reviewed that commit and passed this item: I checked that
 * removing ALFRED's markup lost nothing (core/skin-view-model.php only fills
 * crop_circles.social when social_dock_enabled is '1', the same condition
 * under which the dock renders) and then ASSUMED the dock reaches a strict
 * skin. I had the counter-evidence in hand -- no footer-scripts include in
 * ALFRED's layout, none in the SMACKTALK controller -- and moved on. Codex
 * found it in his own authored work.
 *
 * SO NOTHING HERE IS HAND-WRITTEN MARKUP. Every assertion drives either the
 * real component or the real strict render function, and the end-to-end stage
 * renders a template through snapsmack_render_strict_skin_template() exactly
 * as index.php does.
 *
 * NOT COVERED: the rendered appearance, and ALFRED's own placement, which is
 * the skin author's change and gets its own review. This file proves the seam
 * exists and behaves; it does not claim ALFRED uses it yet.
 */

$root = dirname(__DIR__);
$failures = [];

function sd_check(string $label, bool $ok, array &$failures): void {
    if ($ok) { echo "  PASS  {$label}\n"; }
    else { echo "  FAIL  {$label}\n"; $failures[] = $label; }
}

if (!defined('BASE_URL')) { define('BASE_URL', 'https://example.invalid/'); }
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/indieweb.php';

/** The crop_circles view model core builds, with two configured platforms. */
function sd_model(array $social = []): array {
    return [
        'identity' => 'A Photographer',
        'home_url' => 'https://example.invalid/',
        'navigation' => [],
        'search' => ['action' => 'https://example.invalid/', 'placeholder' => 'Search'],
        'filters' => [],
        'social' => $social,
        'appearance' => ['background' => '#ffffff', 'icon' => '#1a1a1a', 'opacity' => 0.5],
        'icon_sprite_url' => 'https://example.invalid/assets/icons/crop-circles.svg',
    ];
}

$two = [
    ['url' => 'https://bsky.app/profile/example.test', 'label' => 'Bluesky', 'icon' => 'bluesky'],
    ['url' => 'https://linktr.ee/example', 'label' => 'Linktree', 'icon' => 'linktree'],
];

echo "SS-1446 item 4 — a strict skin can place the shared social dock\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — THE COMPONENT EXISTS AND RENDERS FROM THE VIEW MODEL\n";
// ─────────────────────────────────────────────────────────────────────────────
$dock = (string)snap_render_component('social-dock', ['model' => sd_model($two)]);
sd_check('the component produces a dock', str_contains($dock, 'class="social-dock'), $failures);
sd_check('  placed inline by default, not floating',
         str_contains($dock, 'social-dock-inline') && str_contains($dock, 'data-inline="true"'),
         $failures);
sd_check('  carrying both configured links',
         substr_count($dock, 'class="dock-link u-url"') === 2, $failures);
sd_check('  with rel="me" so they count as identity links',
         substr_count($dock, 'rel="me noopener"') === 2, $failures);
sd_check('  the Bluesky URL', str_contains($dock, 'https://bsky.app/profile/example.test'), $failures);
sd_check('  the Linktree URL', str_contains($dock, 'https://linktr.ee/example'), $failures);
sd_check('  icons from the shared sprite, not from the skin',
         str_contains($dock, 'crop-circles.svg#bluesky')
         && str_contains($dock, 'crop-circles.svg#linktree'), $failures);
// EVERY appearance variable, named individually. A mutation that hardcoded
// only the background survived a version of this check that asserted two of
// the three, which is the same shape of gap as everything else on this ticket.
sd_check('  the appearance settings the owner chose, all of them',
         str_contains($dock, '--dock-bg:#ffffff')
         && str_contains($dock, '--dock-icon:#1a1a1a')
         && str_contains($dock, '--dock-idle-opacity:0.5'), $failures);
sd_check('  and a name for screen readers on every link',
         substr_count($dock, 'screen-reader-text') === 2, $failures);
$emailDock = (string)snap_render_component('social-dock', ['model' => sd_model([
    ['url' => snapsmack_social_email_url('owner@example.test'), 'label' => 'Email', 'icon' => 'email'],
    ['url' => 'https://pixelfed.social/owner', 'label' => 'Pixelfed', 'icon' => 'pixelfed'],
])]);
sd_check('Email and external Pixelfed are independent dock links',
         str_contains($emailDock, 'href="mailto:owner@example.test"')
         && str_contains($emailDock, 'crop-circles.svg#email')
         && str_contains($emailDock, 'https://pixelfed.social/owner')
         && str_contains($emailDock, 'crop-circles.svg#pixelfed'), $failures);
sd_check('an invalid email cannot become a dock URL',
         snapsmack_social_email_url('not an address') === '', $failures);

// A corner position, for a skin that wants the floating dock rather than a row.
$corner = (string)snap_render_component('social-dock',
    ['model' => sd_model($two), 'position' => 'bottom-right']);
sd_check('a declared corner position is honoured',
         str_contains($corner, 'dock-bottom-right')
         && str_contains($corner, 'data-dock-position="bottom-right"'), $failures);
sd_check('  and is not also inline', !str_contains($corner, 'social-dock-inline'), $failures);
sd_check('a junk position cannot inject anything',
         !str_contains((string)snap_render_component('social-dock',
             ['model' => sd_model($two), 'position' => '"><script>x</script>']), '<script'),
         $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — NOTHING TO SHOW MEANS NOTHING RENDERED\n";
// ─────────────────────────────────────────────────────────────────────────────
// core/skin-view-model.php only fills social when social_dock_enabled is '1',
// so "the dock is off" and "no platform configured" arrive here identically.
// Neither may leave a skin an empty element to style around.
sd_check('no configured platforms renders the empty string',
         (string)snap_render_component('social-dock', ['model' => sd_model([])]) === '', $failures);
sd_check('a missing model renders the empty string',
         (string)snap_render_component('social-dock', []) === '', $failures);
sd_check('a malformed entry is skipped rather than printed',
         substr_count((string)snap_render_component('social-dock',
             ['model' => sd_model([$two[0], 'not-an-array'])]), 'dock-link') === 1, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — ONE IMPLEMENTATION, NOT TWO\n";
// ─────────────────────────────────────────────────────────────────────────────
// Crop Circles welds the dock into its sticky nav. It now asks the same helper,
// so the markup cannot drift between the two placements -- which is how ALFRED
// came to have its own icons and its own CSS in the first place.
$nav = (string)snap_render_component('crop-circles', ['model' => sd_model($two)]);
sd_check('Crop Circles still emits a dock', str_contains($nav, 'class="social-dock'), $failures);
sd_check('  with the same two links', substr_count($nav, 'class="dock-link u-url"') === 2, $failures);
$inner = snapsmack_social_dock_markup(sd_model($two), 'inline', 'Social links');
sd_check('  and that dock is BYTE-IDENTICAL to the standalone component\'s',
         str_contains($nav, $inner) && str_contains($dock, $inner), $failures);
sd_check('Crop Circles with no platforms emits no empty dock either',
         !str_contains((string)snap_render_component('crop-circles', ['model' => sd_model([])]),
                       'social-dock'), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — THROUGH THE REAL STRICT RENDER, AS index.php DOES IT\n";
// ─────────────────────────────────────────────────────────────────────────────
// THE STAGE THAT WOULD HAVE CAUGHT THE ORIGINAL FAULT. The failed browser
// proof hand-wrote the dock into its fixture; this renders a template through
// snapsmack_render_strict_skin_template(), the same function index.php calls at
// :450-454, and reads the dock out of what the renderer actually produced.
//
// The template lives in a temporary directory rather than under skins/, because
// adding a directory there would change the skin counts other regressions
// assert on. The render function takes the directory as an argument, so the
// path under test is the real one either way.
$tmp = sys_get_temp_dir() . '/ss1446_strict_' . bin2hex(random_bytes(5));
mkdir($tmp, 0770, true);
register_shutdown_function(static function () use ($tmp): void {
    @unlink($tmp . '/layout.php');
    @rmdir($tmp);
});
file_put_contents($tmp . '/layout.php',
    '<?php defined(\'SNAPSMACK_SKIN_RENDER\') || exit; ?>' . "\n"
    . '<!doctype html><html><head><title>t</title></head><body><footer>'
    . '<?php echo snap_render_html(snap_render_component(\'social-dock\','
    . ' [\'model\' => $view[\'site\'][\'social_dock\'] ?? []])); ?>'
    . '</footer></body></html>');

$view = [
    'model' => 'snapsmack.public',
    'version' => SNAPSMACK_SKIN_VIEW_MODEL_VERSION,
    'response' => ['kind' => 'feed', 'posts' => []],
    'site' => ['site_name' => 'A Photographer', 'language' => 'en', 'direction' => 'ltr',
               'social_dock' => sd_model($two)],
];

ob_start();
$rendered = snapsmack_render_strict_skin_template($tmp, 'layout.php', $view);
$out = (string)ob_get_clean();

sd_check('the strict renderer accepted the view and rendered', $rendered === true, $failures);
sd_check('  and its OUTPUT carries the dock, placed by the template',
         str_contains($out, 'class="social-dock social-dock-inline'), $failures);
sd_check('  with both links present in the rendered page',
         substr_count($out, 'class="dock-link u-url"') === 2, $failures);
sd_check('  inside the footer where the template put it',
         str_contains(str_replace("\n", '', $out), '<footer><div class="social-dock'), $failures);

// And the same template with the dock off renders a footer with nothing in it.
$viewOff = $view;
$viewOff['site']['social_dock'] = sd_model([]);
ob_start();
snapsmack_render_strict_skin_template($tmp, 'layout.php', $viewOff);
$outOff = (string)ob_get_clean();
sd_check('with no platforms configured the rendered footer is empty',
         str_contains(str_replace("\n", '', $outOff), '<footer></footer>'), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 5 — THE STYLESHEET REACHES A SKIN THAT DECLARES THE DOCK\n";
// ─────────────────────────────────────────────────────────────────────────────
// A skin placing this component needs ss-engine-social-dock.css. That wiring
// already exists and is not mine to re-invent: a social_dock block in the
// manifest registers it (core/asset-registry.php). Asserted so that a future
// change to either side cannot quietly separate the markup from its styles.
require_once $root . '/core/asset-registry.php';
$declared = snapsmack_skin_declared_assets(['social_dock' => ['position' => 'bottom-right']]);
sd_check('a manifest declaring social_dock gets the dock stylesheet',
         (bool)array_filter($declared['styles'],
             static fn(string $u): bool => str_contains($u, 'ss-engine-social-dock.css')),
         $failures);
sd_check('and a manifest that does not declare it gets no dock stylesheet',
         !array_filter(snapsmack_skin_declared_assets([])['styles'],
             static fn(string $u): bool => str_contains($u, 'ss-engine-social-dock.css')),
         $failures);

$dockCss = (string)file_get_contents($root . '/assets/css/ss-engine-social-dock.css');
sd_check('the shared dock hides its own screen-reader labels without skin CSS',
         str_contains($dockCss, '.dock-link .screen-reader-text')
         && str_contains($dockCss, 'clip: rect(0, 0, 0, 0)'), $failures);

echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1446 item 4 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    exit(1);
}
echo "SS-1446 item 4 PASS — a strict skin can place the shared social dock, the\n";
echo "markup comes from one helper shared with Crop Circles, nothing renders when\n";
echo "no platform is configured, and the dock is read back out of a page rendered\n";
echo "through the real strict render function rather than written by the test.\n";
echo "NOT COVERED: the rendered appearance, and ALFRED's own placement, which is\n";
echo "the skin author's change and gets its own review.\n";
// ===== SNAPSMACK EOF =====
