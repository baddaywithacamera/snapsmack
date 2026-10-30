<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A skin whose landing IS the archive must not be given a link to itself.
 *
 * SS-1062. Sean, on usedcarparts.photoblogs.fyi running SCROLL: "why does
 * SCROLL have an archive link and symbol I never asked for? The landing page
 * IS the archive." He counted the icons: nine in the original, ten now.
 *
 * WHERE IT CAME FROM, because this is not an invented feature. Commit
 * fab2ce54 on 19 October restored the pre-conversion navigation after the
 * 6 October rewrite reduced it to Home and Blogroll -- Sean had reported
 * "archive link is missing" on hekeepsdroningon.ca. The restore was right.
 * What was wrong is that it gated the Archive View item on archive_layout, a
 * SITE setting, when whether that link makes sense is a SKIN fact. So it
 * appeared on all 30 skins that fall through to the fallback, including the
 * ones where the landing already IS the archive.
 *
 * THE FIX IS A SKIN DECLARATION, not a slug check: core must never learn
 * about individual skins -- tests/agreed-rules-gate.php refuses a skin slug
 * in core and is right to -- so features.landing_is_archive is the only legal
 * shape, the same way canvas_width_option works.
 *
 * AND THE TRAP THIS TEST EXISTS TO PIN. The obvious fix was to give SCROLL
 * its own cms_navigation in the manifest, as TILEZ has. That would have been
 * WRONG: a manifest menu replaces the fallback entirely, and the fallback is
 * what appends the site's active static pages. A nav page item needs a
 * database id (target_id), which a manifest cannot know, so SCROLL would have
 * silently lost ABOUT on every site running it. Removing something the owner
 * had is the same fault as adding something he did not ask for. These
 * assertions hold the line that everything OTHER than Archive View survives.
 */

$root = dirname(__DIR__);
require_once $root . '/core/skin-view-model.php';

$pass = 0;
$fail = 0;

function nv_test(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

/**
 * A PDO that cannot reach a database. The navigation builder already wraps
 * its snap_pages query in try/catch for older installs, so this exercises the
 * real code path with the pages lookup unavailable. There is no sqlite driver
 * in this environment, so the pages loop itself cannot be executed here and
 * is asserted against source below, which is stated rather than implied.
 */
final class NvNoDb extends PDO {
    public function __construct() { /* deliberately does not connect */ }
    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, ...$args) {
        throw new PDOException('no database in this test');
    }
}

$pdo = new NvNoDb();
$settings = ['archive_layout' => 'square', 'homepage_mode' => 'feed'];

function nv_labels(array $nav): array {
    $out = [];
    foreach ($nav as $item) {
        if (is_array($item) && isset($item['label'])) $out[] = (string)$item['label'];
    }
    return $out;
}

echo "Without the declaration, the restored Archive View item is still there\n";
// If this ever fails, hekeepsdroningon.ca has lost its archive link again and
// the 19 October restore has been undone.
$plain = snapsmack_prepare_skin_navigation($pdo, $settings, ['features' => []]);
$plainLabels = nv_labels($plain);
nv_test(in_array('Archive View', $plainLabels, true),
    'a skin that does NOT declare landing_is_archive keeps its Archive View link');
nv_test(in_array('Home', $plainLabels, true), 'and Home');
nv_test(in_array('Blogroll', $plainLabels, true), 'and Blogroll');

echo "\nWith the declaration, that one item is gone and nothing else is\n";
$declared = snapsmack_prepare_skin_navigation($pdo, $settings,
    ['features' => ['landing_is_archive' => true]]);
$declaredLabels = nv_labels($declared);
nv_test(!in_array('Archive View', $declaredLabels, true),
    'a skin that declares it gets NO Archive View link');
nv_test(in_array('Home', $declaredLabels, true),
    'Home survives -- the declaration removes one item, not the menu');
nv_test(in_array('Blogroll', $declaredLabels, true), 'Blogroll survives');
nv_test(count($declaredLabels) === count($plainLabels) - 1,
    'exactly ONE item fewer than without the declaration, not a rebuilt menu');
$removed = array_values(array_diff($plainLabels, $declaredLabels));
nv_test($removed === ['Archive View'],
    'and the only difference is Archive View'
    . ($removed === ['Archive View'] ? '' : ': ' . implode(', ', $removed)));

echo "\nThe owner's own menu still wins over everything\n";
// A menu he built in the Menu Manager is his, and neither the fallback nor
// this declaration may touch it.
$owner = snapsmack_prepare_skin_navigation($pdo,
    $settings + ['nav_menu_json' => '[{"type":"home","label":"MINE"},{"type":"image_archive","label":"MY ARCHIVE"}]'],
    ['features' => ['landing_is_archive' => true]]);
$ownerLabels = nv_labels($owner);
nv_test(in_array('MINE', $ownerLabels, true),
    'a saved Menu Manager configuration is used as-is');
nv_test(in_array('MY ARCHIVE', $ownerLabels, true),
    'and his OWN archive item is NOT stripped by the declaration -- his menu is his');

echo "\nSCROLL declares it, and its version moved with the change\n";
$scroll = json_decode((string)file_get_contents($root . '/skins/scroll/manifest.json'), true);
nv_test(is_array($scroll), 'skins/scroll/manifest.json parses');
nv_test(!empty($scroll['features']['landing_is_archive']),
    'SCROLL declares features.landing_is_archive');
nv_test(($scroll['version'] ?? '') !== '0.1.75',
    'and its version is no longer 0.1.75, because a skin file changed');

echo "\nCore still names no skin slug for this\n";
// The whole reason this is a feature flag.
$model = (string) file_get_contents($root . '/core/skin-view-model.php');
foreach (['scroll', 'slickr', 'tilez', 'true-grit'] as $slug) {
    nv_test(strpos($model, "'" . $slug . "'") === false,
        "core/skin-view-model.php does not name '{$slug}'");
}

echo "\nSOURCE ASSERTIONS -- no sqlite driver here, so these are read, not run\n";
$navStart = strpos($model, 'function snapsmack_prepare_skin_navigation');
$navSrc = $navStart === false ? '' : substr($model, $navStart, 4000);
nv_test($navSrc !== '', 'the navigation builder was located');
$atArchive = strpos($navSrc, "'label' => 'Archive View'");
$atPages = strpos($navSrc, 'FROM snap_pages');
nv_test($atArchive !== false && $atPages !== false && $atArchive < $atPages,
    'the static-pages lookup comes AFTER the Archive View item, so suppressing one does not reach the other');
nv_test(strpos($navSrc, "\$_landing_is_archive = !empty(\$manifest['features']['landing_is_archive'])") !== false,
    'the declaration is read from the manifest the builder already receives, with no new plumbing');
nv_test(substr_count($navSrc, "'label' => 'Archive View'") === 1,
    'there is exactly one place that adds the item, so one condition covers it');

echo "\n  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "Nav landing-is-archive: FAIL ({$fail}).\n";
    exit(1);
}
echo "Nav landing-is-archive: PASS ({$pass} assertions).\n";

// ===== SNAPSMACK EOF =====
