<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * The archive's controls are resolved once, and they reach the markup bound.
 *
 * Sean, 28 October 2026: "Where are my thumbs, masonry, and calendar controls
 * i had?" -- then, when I said the archive row was back: "have thumbs,
 * masonry, and calendar been removed from other skins?" and "why aren't you
 * checking for this.?"
 *
 * MEASURED ACROSS ALL THIRTY SKINS. The control row existed in exactly one
 * place, the 'archive-filters' component. The T/M toggle inside it had never
 * rendered on any skin at all: the component reads response.archive_layouts,
 * nothing assigned it, and the view-model allowlist would have stripped it
 * even once something did. The calendar reached the strict path in no form.
 * And the links the component did draw carried no data-layout, while
 * ss-engine-archive-toggle.js selects '.archive-layout-toggle [data-layout]'
 * and RETURNS EARLY when it finds none -- so the engine bound nothing: no
 * in-place switch, no T/M hotkeys, no active-state update.
 *
 * All of the resolution lived inside archive.php, between its redirects and
 * its thumbnail arithmetic. Twenty-eight of the thirty skins are strict and
 * never run that file. The engines that make the row work were hardcoded in
 * <script> tags at the bottom of it, which is why the row's stylesheet
 * arrived only for the five skins whose manifests happen to name those
 * engines.
 *
 * WHAT THIS ASSERTS, in the order the faults happened:
 *
 *  1. The one resolver still answers exactly what archive.php's own
 *     arithmetic answered. The reference below IS that arithmetic, copied out
 *     before it was deleted. Two implementations is how the paths came to
 *     disagree; this is the check that they have not started again.
 *  2. The resolved layouts reach a rendered archive and carry data-layout,
 *     so the engine's own selector finds them.
 *  3. The calendar button is emitted with the attribute its engine binds.
 *  4. The fields survive the view-model allowlist.
 *  5. The row and the stylesheet that styles it travel together, which is the
 *     check that would have caught TRUE GRIT printing an unstyled row.
 */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;

function ac_test(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

require_once $root . '/core/archive-controls.php';

/**
 * archive.php's resolution as it stood at 0.7.967D, before it was lifted into
 * core/archive-controls.php. Copied, not summarised: if the resolver and this
 * disagree the repair changed behaviour, which it must not have.
 */
function ac_reference(array $settings, array $manifest, array $query, array $cookies): array
{
    $skin_layout_fallback = 'square';
    $skin_masonry_locked = false;
    $skin_thumbs_locked = false;
    $skin_force_thumb_style = null;
    if (!empty($manifest['features']['archive_layout_default'])) {
        $skin_layout_fallback = $manifest['features']['archive_layout_default'];
    }
    if (isset($manifest['features']['masonry_supported']) && $manifest['features']['masonry_supported'] === false) {
        $skin_masonry_locked = true;
    }
    if (isset($manifest['features']['thumbs_supported']) && $manifest['features']['thumbs_supported'] === false) {
        $skin_thumbs_locked = true;
    }
    if (!empty($manifest['features']['archive_thumb_style'])
        && in_array($manifest['features']['archive_thumb_style'], ['square', 'cropped'], true)) {
        $skin_force_thumb_style = $manifest['features']['archive_thumb_style'];
    }
    $archive_layout_default = $settings['archive_layout'] ?? $skin_layout_fallback;

    $archive_thumb_style = $settings['archive_thumb_style'] ?? 'cropped';
    $archive_calendar_enabled = !empty($settings['archive_calendar_enabled']);
    $archive_calendar_default_open = !empty($settings['archive_calendar_default_open']);
    $archive_show_layout_toggle = isset($settings['archive_show_layout_toggle'])
        ? !empty($settings['archive_show_layout_toggle']) : true;
    if (!in_array($archive_thumb_style, ['square', 'cropped'], true)) $archive_thumb_style = 'cropped';
    if ($skin_force_thumb_style !== null) $archive_thumb_style = $skin_force_thumb_style;

    if (in_array($archive_layout_default, ['square', 'cropped', 'croppedwithcalendar'], true)) {
        $archive_layout_default = 'thumbs';
    }
    if (!in_array($archive_layout_default, ['thumbs', 'masonry'], true)) $archive_layout_default = 'thumbs';

    $available_modes = [];
    if (!$skin_thumbs_locked)  $available_modes[] = 'thumbs';
    if (!$skin_masonry_locked) $available_modes[] = 'masonry';
    if (empty($available_modes)) $available_modes = ['thumbs'];
    if (!in_array($archive_layout_default, $available_modes, true)) {
        $archive_layout_default = $available_modes[0];
    }

    $layout_override = $query['layout'] ?? '';
    $force_calendar_open = false;
    if ($layout_override === 'croppedwithcalendar') { $layout_override = 'thumbs'; $force_calendar_open = true; }
    elseif (in_array($layout_override, ['square', 'cropped'], true)) $layout_override = 'thumbs';
    if ($layout_override === '' && isset($cookies['smack_archive_layout'])) {
        $cookie_val = $cookies['smack_archive_layout'];
        if (in_array($cookie_val, ['square', 'cropped', 'croppedwithcalendar'], true)) $cookie_val = 'thumbs';
        if (in_array($cookie_val, ['thumbs', 'masonry'], true)) $layout_override = $cookie_val;
    }
    $archive_layout = in_array($layout_override, $available_modes, true)
        ? $layout_override : $archive_layout_default;

    $archive_calendar_open = false;
    if ($archive_calendar_enabled) {
        if ($force_calendar_open) $archive_calendar_open = true;
        elseif (isset($cookies['smack_archive_calendar'])) {
            $archive_calendar_open = ($cookies['smack_archive_calendar'] === 'open');
        } else $archive_calendar_open = $archive_calendar_default_open;
    }

    return [
        'modes'        => $available_modes,
        'active'       => $archive_layout,
        'default'      => $archive_layout_default,
        'thumb_style'  => $archive_thumb_style,
        'offer_toggle' => $archive_show_layout_toggle && count($available_modes) > 1,
        'calendar_enabled' => $archive_calendar_enabled,
        'calendar_open'    => $archive_calendar_open,
    ];
}

echo "One resolver, and it answers what archive.php answered\n";

$settingsCases = [
    'unset'              => [],
    'square'             => ['archive_layout' => 'square'],
    'cropped'            => ['archive_layout' => 'cropped'],
    'croppedwithcalendar'=> ['archive_layout' => 'croppedwithcalendar'],
    'thumbs'             => ['archive_layout' => 'thumbs'],
    'masonry'            => ['archive_layout' => 'masonry'],
    'nonsense'           => ['archive_layout' => 'wallpaper'],
    'empty'              => ['archive_layout' => ''],
    'style square'       => ['archive_layout' => 'thumbs', 'archive_thumb_style' => 'square'],
    'style junk'         => ['archive_layout' => 'thumbs', 'archive_thumb_style' => 'oblong'],
    'toggle off'         => ['archive_layout' => 'thumbs', 'archive_show_layout_toggle' => '0'],
    'cal on'             => ['archive_layout' => 'thumbs', 'archive_calendar_enabled' => '1'],
    'cal on + open'      => ['archive_layout' => 'thumbs', 'archive_calendar_enabled' => '1', 'archive_calendar_default_open' => '1'],
    'cal off + open'     => ['archive_layout' => 'thumbs', 'archive_calendar_default_open' => '1'],
];
$manifestCases = [
    'plain'          => [],
    'masonry-locked' => ['features' => ['masonry_supported' => false]],
    'thumbs-locked'  => ['features' => ['thumbs_supported' => false]],
    'both-locked'    => ['features' => ['masonry_supported' => false, 'thumbs_supported' => false]],
    'prefers masonry'=> ['features' => ['archive_layout_default' => 'masonry']],
    'forces square'  => ['features' => ['archive_thumb_style' => 'square']],
    'has calendar'   => ['require_scripts' => ['smack-calendar']],
];
$queryCases = [
    'none'  => [],
    'thumbs' => ['layout' => 'thumbs'],
    'masonry' => ['layout' => 'masonry'],
    'legacy square' => ['layout' => 'square'],
    'legacy cal' => ['layout' => 'croppedwithcalendar'],
    'junk' => ['layout' => '../etc'],
];
$cookieCases = [
    'none' => [],
    'thumbs' => ['smack_archive_layout' => 'thumbs'],
    'masonry' => ['smack_archive_layout' => 'masonry'],
    'legacy' => ['smack_archive_layout' => 'croppedwithcalendar'],
    'cal open' => ['smack_archive_calendar' => 'open'],
    'cal closed' => ['smack_archive_calendar' => 'closed'],
];

$combinations = 0;
$mismatches = [];
foreach ($settingsCases as $sName => $s) {
    foreach ($manifestCases as $mName => $m) {
        foreach ($queryCases as $qName => $q) {
            foreach ($cookieCases as $cName => $c) {
                $combinations++;
                $got = snapsmack_archive_controls($s, $m, $q, $c);
                $want = ac_reference($s, $m, $q, $c);
                $same = $got['modes'] === $want['modes']
                    && $got['active'] === $want['active']
                    && $got['default'] === $want['default']
                    && $got['thumb_style'] === $want['thumb_style']
                    && $got['offer_toggle'] === $want['offer_toggle']
                    && $got['calendar']['enabled'] === $want['calendar_enabled']
                    && $got['calendar']['open'] === $want['calendar_open'];
                if (!$same && count($mismatches) < 6) {
                    $mismatches[] = "{$sName} / {$mName} / ?layout={$qName} / cookie={$cName}: "
                        . 'resolver active=' . $got['active'] . ' style=' . $got['thumb_style']
                        . ' cal=' . var_export($got['calendar']['open'], true)
                        . ' | archive.php active=' . $want['active'] . ' style=' . $want['thumb_style']
                        . ' cal=' . var_export($want['calendar_open'], true);
                }
            }
        }
    }
}
ac_test($combinations === 3528, "all {$combinations} input combinations were compared");
ac_test($mismatches === [], 'the resolver and archive.php agree on every one of them');
foreach ($mismatches as $m) echo "        {$m}\n";

echo "\nA locked skin is offered no switch it cannot honour\n";
$galleria = snapsmack_archive_controls(['archive_layout' => 'thumbs'], ['features' => ['thumbs_supported' => false]]);
ac_test($galleria['modes'] === ['masonry'], 'a masonry-only skin offers masonry alone');
ac_test($galleria['active'] === 'masonry', 'and shows masonry even though the site asked for thumbs');
ac_test($galleria['layouts'] === [], 'with one mode there is no toggle to draw');
$both = snapsmack_archive_controls(['archive_layout' => 'thumbs'], []);
ac_test(count($both['layouts']) === 2, 'a two-grid skin gets both buttons');
ac_test(($both['layouts'][0]['active'] ?? null) === true && ($both['layouts'][1]['active'] ?? null) === false,
    'and exactly the showing one is marked active');

echo "\nThe visitor's choice outranks the cookie, which outranks the default\n";
ac_test(snapsmack_archive_controls(['archive_layout' => 'thumbs'], [], ['layout' => 'masonry'],
    ['smack_archive_layout' => 'thumbs'])['active'] === 'masonry', '?layout= wins over the cookie');
ac_test(snapsmack_archive_controls(['archive_layout' => 'thumbs'], [], [],
    ['smack_archive_layout' => 'masonry'])['active'] === 'masonry', 'the cookie wins over the stored default');
ac_test(snapsmack_archive_controls(['archive_layout' => 'masonry'], [], ['layout' => 'wallpaper'])['active'] === 'masonry',
    'and a layout nobody offers is ignored rather than rendered');

echo "\nThe engines' own selectors find the markup\n";
if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
if (!defined('BASE_URL')) define('BASE_URL', 'https://example.test/');
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';

$resolved = snapsmack_archive_controls(
    ['archive_layout' => 'thumbs', 'archive_calendar_enabled' => '1'],
    ['require_scripts' => ['smack-calendar']]
);
$linked = snapsmack_archive_layout_links($resolved['layouts'], BASE_URL, ['pictures' => 'archive'], '');
$row = (string) snap_render_component('archive-filters', [
    'show_all_url' => 'https://example.test/pictures',
    'layouts'      => $linked,
    'calendar'     => $resolved['calendar'],
]);

// ss-engine-archive-toggle.js:56 and :130 -- querySelectorAll('.archive-layout-toggle [data-layout]')
ac_test(preg_match_all('/<div class="archive-layout-toggle"/', $row) === 1,
    'there is one layout toggle group');
ac_test(preg_match_all('/data-layout="(thumbs|masonry)"/', $row, $found) === 2,
    'both buttons carry data-layout, which is what the engine selects on');
ac_test(($found[1] ?? []) === ['thumbs', 'masonry'],
    'and they name the two modes the resolver returned, in order');
ac_test(str_contains($row, 'data-calendar-toggle="1"'),
    'the calendar button carries the attribute ss-engine-calendar.js binds');
ac_test(str_contains($row, 'class="alt-btn archive-calendar-toggle'),
    'and the class the stylesheet draws it with');
ac_test(str_contains($row, 'aria-pressed="false"'),
    'a closed calendar reports itself closed to a screen reader');
ac_test(substr_count($row, 'aria-label="Thumbs Layout"') === 1
    && substr_count($row, 'aria-label="Masonry / Justified Layout"') === 1,
    'each button is named, not left as a bare letter');
ac_test(str_contains($row, 'href="https://example.test/pictures?layout=thumbs"'),
    "the link is the site's own archive alias, not the literal /archive that caused the redirect loop");
foreach (snapsmack_archive_layout_link_drops() as $drop) {
    ac_test(!preg_match('/layout=[a-z]+&(amp;)?' . preg_quote($drop, '/') . '=/', $row),
        "a layout link carries no {$drop}=: switching grids does not keep you inside a filter");
}

echo "\nThe calendar is drawn only where it can work\n";
$calOff = (string) snap_render_component('archive-filters', [
    'show_all_url' => '/', 'layouts' => $linked,
    'calendar' => ['enabled' => false, 'open' => false, 'engine' => true],
]);
ac_test(!str_contains($calOff, 'data-calendar-toggle'), 'with the owner switch off there is no button');
ac_test(str_contains($calOff, 'data-layout="thumbs"'), 'and the layout toggle is unaffected');
$calOnly = (string) snap_render_component('archive-filters', [
    'show_all_url' => '/', 'layouts' => [],
    'calendar' => ['enabled' => true, 'open' => true, 'engine' => true],
]);
ac_test(str_contains($calOnly, 'data-calendar-toggle') && str_contains($calOnly, 'aria-pressed="true"'),
    'a single-layout skin with the calendar on still gets its calendar, open');
ac_test(!str_contains($calOnly, 'archive-layout-toggle'),
    'and no empty toggle group around nothing');

echo "\nEnd to end: a real skin page carries the bound controls\n";
// The component and the runtime are asserted separately above. This joins
// them: the response is shaped the way snapsmack_public_runtime() shapes it,
// and the assertion is made on the FINISHED PAGE of a real skin. The row
// being right in isolation is what was claimed on 28 October while the live
// archives had no row at all.
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/skin-presentation.php';

$e2eItem = ['id' => 1, 'img_slug' => 'photo', 'img_title' => 'Photo', 'img_file' => '/media/photo.jpg',
            'img_thumb_aspect' => '/media/thumb-a.jpg', 'img_thumb_square' => '/media/thumb.jpg',
            'img_width' => 3, 'img_height' => 2];
$e2eControls = snapsmack_archive_controls(
    ['archive_layout' => 'thumbs', 'archive_calendar_enabled' => '1'],
    ['require_scripts' => ['smack-calendar']]
);
$e2eResponse = [
    'status' => 200, 'kind' => 'archive', 'items' => [$e2eItem], 'navigation' => [],
    'archive_layouts' => snapsmack_archive_layout_links(
        $e2eControls['layouts'], BASE_URL, ['pictures' => 'archive'], ''),
    'archive_calendar' => $e2eControls['calendar'],
    'archive_layout_active' => $e2eControls['active'],
];
foreach (['chaplin', 'onyx', '50-shades-of-noah-grey'] as $e2eSkin) {
    $e2eView = snapsmack_build_skin_view($e2eResponse, [
        'site_name' => 'Example', 'language' => 'en', 'direction' => 'ltr',
        'skin_style_url' => '/skins/' . $e2eSkin . '/style.css',
        'skin_presentation' => snapsmack_skin_presentation([], $e2eSkin),
        'route_aliases' => ['pictures' => 'archive'],
    ]);
    ob_start();
    $e2eOk = snapsmack_render_strict_skin_template($root . '/skins/' . $e2eSkin, 'layout.php', $e2eView);
    $e2eHtml = (string) ob_get_clean();
    ac_test($e2eOk === true, "{$e2eSkin} renders its archive");
    ac_test(str_contains($e2eHtml, 'class="archive-layout-toggle"'),
        "{$e2eSkin}'s archive carries the layout toggle group");
    ac_test(substr_count($e2eHtml, 'data-layout="') === 2,
        "{$e2eSkin}'s two toggle buttons are bound for the engine");
    ac_test(str_contains($e2eHtml, 'data-calendar-toggle="1"'),
        "{$e2eSkin}'s archive carries the calendar button");
    ac_test(str_contains($e2eHtml, '[ SHOW ALL ]'),
        "{$e2eSkin}'s archive carries SHOW ALL, the rest of the row");
    ac_test(str_contains($e2eHtml, 'layout=masonry'),
        "{$e2eSkin} offers a real address to switch to");
}

echo "\nThe row is capped by the skin's OWN width control\n";
// MEASURED in a browser at 1920 on 29 October, on the geometry fixtures:
// CHAPLIN's docked T/M group sat at 1751..1880 while its photographs end at
// 1760 -- 120px of control hanging past the archive. .archive-filter-inner
// takes max-width from --archive-content-width, the component sets that from
// main_canvas_width, and CHAPLIN's width control is chap_photo_max_width.
// Seven skins use main_canvas_width; the other twenty-three each named their
// own and there is no convention to infer, so the skin nominates it.
$widthResponse = ['status' => 200, 'kind' => 'archive', 'items' => [], 'navigation' => []];
$capped = snapsmack_build_skin_view($widthResponse, [
    'site_name' => 'Example', 'language' => 'en', 'direction' => 'ltr',
    'skin_canvas_width_option' => 'chap_photo_max_width',
    'skin_presentation' => ['options' => ['chap_photo_max_width' => '1600']],
]);
ac_test(str_contains((string)($capped['response']['archive_controls'] ?? ''), '--archive-content-width:1600px'),
    "a nominated option caps the row, so the group cannot measure from the window");
$defaulted = snapsmack_build_skin_view($widthResponse, [
    'site_name' => 'Example', 'language' => 'en', 'direction' => 'ltr',
    'skin_presentation' => ['options' => ['main_canvas_width' => '1520']],
]);
ac_test(str_contains((string)($defaulted['response']['archive_controls'] ?? ''), '--archive-content-width:1520px'),
    'and main_canvas_width still works for the seven skins that use it, un-nominated');
$noWidth = snapsmack_build_skin_view($widthResponse, [
    'site_name' => 'Example', 'language' => 'en', 'direction' => 'ltr',
    'skin_canvas_width_option' => 'au_border_width',
    'skin_presentation' => ['options' => ['au_border_width' => '2', 'main_canvas_width' => '1400']],
]);
ac_test(str_contains((string)($noWidth['response']['archive_controls'] ?? ''), '--archive-content-width:1400px'),
    'a nominated option too small to be a canvas is refused and the default used');

// CHAPLIN by name, because it is the one measured overhang, and the
// nomination is in ITS MANIFEST because tests/agreed-rules-gate.php refused
// the first repair -- a per-skin table in core -- and was right to. 1600px is
// CHAPLIN's own "Content Max Width (px)" default and the width its grid
// measures; after the nomination the group moved to 1591..1720 inside a
// 160..1760 grid, measured in a browser at 1920.
$chapManifest = json_decode((string) file_get_contents($root . '/skins/chaplin/manifest.json'), true);
ac_test(($chapManifest['features']['canvas_width_option'] ?? '') === 'chap_photo_max_width',
    "CHAPLIN's manifest nominates its own width control");
$chapOptions = snapsmack_skin_presentation([], 'chaplin')['options'] ?? [];
ac_test(preg_match('/^\d{3,5}$/', (string)($chapOptions['chap_photo_max_width'] ?? '')) === 1,
    'and that option really resolves to a width, not a border thickness');
$chapCapped = snapsmack_build_skin_view($widthResponse, [
    'site_name' => 'Example', 'language' => 'en', 'direction' => 'ltr',
    'skin_canvas_width_option' => (string)$chapManifest['features']['canvas_width_option'],
    'skin_presentation' => snapsmack_skin_presentation([], 'chaplin'),
]);
ac_test(str_contains((string)($chapCapped['response']['archive_controls'] ?? ''), '--archive-content-width:1600px'),
    'and the nomination produces the 1600px cap its grid measures');
$index = (string) file_get_contents($root . '/index.php');
ac_test(str_contains($index, "'skin_canvas_width_option' => (string)(\$_active_manifest['features']['canvas_width_option']"),
    'index.php carries the nomination from the manifest to the CMS');

echo "\nA skin that does not print the row is completely unchanged\n";
// The row is opt-in markup. A skin whose layout never prints it must not
// suddenly grow one, because that is a restyle nobody asked for.
$silentView = snapsmack_build_skin_view($e2eResponse, [
    'site_name' => 'Example', 'language' => 'en', 'direction' => 'ltr',
    'skin_style_url' => '/skins/galleria/style.css',
    'skin_presentation' => snapsmack_skin_presentation([], 'galleria'),
]);
ob_start();
snapsmack_render_strict_skin_template($root . '/skins/galleria', 'layout.php', $silentView);
$silentHtml = (string) ob_get_clean();
ac_test(!str_contains($silentHtml, 'archive-layout-toggle'),
    'GALLERIA, which prints no row, still has none');

echo "\nThe fields survive the view-model allowlist\n";
$contract = (string) file_get_contents($root . '/core/skin-view-contract.php');
foreach (['archive_layouts', 'archive_calendar', 'archive_layout_active'] as $key) {
    ac_test(preg_match("/'{$key}'/", $contract) === 1 || str_contains($contract, "'{$key}'"),
        "{$key} is named in the contract");
}
$allowStart = strpos($contract, '$allowedResponse = [');
$allowBlock = $allowStart === false ? '' : substr($contract, $allowStart, 1600);
foreach (['archive_controls', 'archive_layouts', 'archive_calendar'] as $key) {
    ac_test(str_contains($allowBlock, "'{$key}'"),
        "{$key} is in the allowlist, so it is not stripped out of the view");
}

echo "\nThe runtime assigns them, which is the line that never existed\n";
$runtime = (string) file_get_contents($root . '/core/public-runtime.php');
ac_test(str_contains($runtime, "require_once __DIR__ . '/archive-controls.php'"),
    'the runtime loads the shared resolver');
ac_test(str_contains($runtime, "\$response['archive_layouts'] = snapsmack_archive_layout_links("),
    'and assigns archive_layouts, which nothing did before');
ac_test(str_contains($runtime, "\$response['archive_calendar'] = \$_archiveControls['calendar']"),
    'and the calendar flags, which reached the strict path in no form');
ac_test(preg_match("/in_array\(\(\\\$response\['kind'\] \?\? ''\), \['archive', 'hashtag'\], true\)/", $runtime) === 1,
    'on the archive kinds the contract builds the row for, and no others');

echo "\nThe row and the stylesheet that styles it travel together\n";
// TRUE GRIT printed the row and received page-archive.css by no route at all,
// because the engine that carries that stylesheet was hardcoded in a <script>
// tag at the bottom of archive.php. No test could see a missing CSS file.
// This one can: it reads what the runtime declares.
ac_test(str_contains($runtime, "['smack-archive-toggle']"),
    'the runtime declares the archive engine as a required asset');
$inventory = require $root . '/core/manifest-inventory.php';
$toggle = $inventory['scripts']['smack-archive-toggle'] ?? [];
ac_test(($toggle['css'] ?? '') === 'assets/css/page-archive.css',
    'and that engine is the one carrying page-archive.css');
ac_test(in_array('assets/js/ss-engine-archive-filter.js', (array)($toggle['companions'] ?? []), true),
    'with the filter engine as its companion, so the FILTER panel opens');
ac_test(str_contains($runtime, "['smack-calendar']"),
    'and the calendar engine is declared when the owner enables the calendar');
ac_test(is_file($root . '/assets/css/page-archive.css'),
    'the stylesheet it names is really there');

// Every skin that PRINTS the row must be able to style it: either the CMS
// delivers page-archive.css (it now does, for every archive page) or the skin
// carries the rules itself. Listed by measurement, not by hand.
$printers = [];
foreach (glob($root . '/skins/*/layout.php') ?: [] as $f) {
    if (str_contains((string) file_get_contents($f), 'archive_controls')) {
        $printers[] = basename(dirname($f));
    }
}
foreach (glob($root . '/core/*-page-component.php') ?: [] as $f) {
    if (str_contains((string) file_get_contents($f), 'archive_controls')
        || str_contains((string) file_get_contents($f), "snap_render_component('archive-filters'")) {
        $printers[] = basename($f, '-page-component.php');
    }
}
ac_test($printers !== [], 'at least one skin prints the archive control row');
echo "        printing the row: " . implode(', ', array_unique($printers)) . "\n";

echo "\n";
echo "  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "Archive controls reach the page: FAIL ({$fail}).\n";
    exit(1);
}
echo "Archive controls reach the page: PASS ({$pass} assertions).\n";

// ===== SNAPSMACK EOF =====
