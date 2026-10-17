<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

/**
 * A skin may not declare a JavaScript engine and then fail to emit the markup
 * that engine needs. OPAUDIT 023.
 *
 * PARADE declared smack-aurora-wave, shipped a BORDER WAVE control section,
 * advertised "animated flag-coloured tile borders" in its own description, and
 * for eighteen days emitted no ring element and no tile position. The engine
 * loaded, every setting the owner had chosen was published correctly, the
 * stylesheet was byte-identical to the repository, and the engine returned
 * before drawing anything. Nothing failed. The owner found it on his own site.
 *
 * Two deliberate choices about this check:
 *
 * 1. It runs against RENDERED output, not the template source. A grep of
 *    layout.php would pass on an element that sits inside a branch which never
 *    executes. The lesson of OPAUDIT 022 is that reading the source is not
 *    measuring, so this renders each skin and inspects the page.
 *
 * 2. The contract lives beside the engine, in core/manifest-inventory.php,
 *    under 'requires_markup'. A new engine declares what it needs once, where
 *    it is already described, instead of this file growing a list that drifts.
 *
 * The prefix is derived here exactly as ss-engine-aurora-wave.js derives it,
 * from nav[class*="-sticky-nav"] and then the background carrier. If prefix
 * derivation breaks, this check fails for the same reason the engine would,
 * rather than relying on a copy of the skin-to-prefix map (which exists in
 * four places in core/ and is already on the de-duplication list).
 *
 * What this check does NOT cover, stated rather than implied: it proves the
 * markup the engine looks for is present, not that the animation is correct.
 * PARADE had a second fault that this check cannot see -- a lightness floor
 * fed from the wrong control, which painted every ring pure white. Markup
 * presence is necessary, not sufficient. Visual correctness is measured in a
 * browser harness.
 */

define('SNAPSMACK_SKIN_RENDER', true);
require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';
require_once dirname(__DIR__) . '/core/skin-presentation.php';
require_once dirname(__DIR__) . '/core/public-controller.php';

$failures = [];
function contract_fail(string $message): void { $GLOBALS['failures'][] = $message; }

/**
 * The deliberately small CSS subset this contract may use. Anything else
 * throws rather than silently matching nothing -- a selector this translator
 * does not understand must not read as "requirement satisfied".
 */
function contract_selector_xpath(string $selector): string
{
    $parts = preg_split('/\s+/', trim($selector)) ?: [];
    $xpath = '';
    foreach ($parts as $part) {
        if (preg_match('/^\.([a-z0-9_-]+)$/i', $part, $m)) {
            $xpath .= "//*[contains(concat(' ',normalize-space(@class),' '),' {$m[1]} ')]";
            continue;
        }
        if (preg_match('/^\[([a-z0-9_-]+)\]$/i', $part, $m)) {
            $xpath .= "//*[@{$m[1]}]";
            continue;
        }
        if (preg_match('/^([a-z0-9]+)\[([a-z0-9_-]+)\*="([^"]+)"\]$/i', $part, $m)) {
            $xpath .= "//{$m[1]}[contains(@{$m[2]},'{$m[3]}')]";
            continue;
        }
        throw new RuntimeException("Contract selector not supported by this checker: {$selector}");
    }
    return $xpath;
}

function contract_dom(string $html): DOMXPath
{
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return new DOMXPath($doc);
}

function contract_matches(DOMXPath $x, string $selector): DOMNodeList
{
    $found = $x->query(contract_selector_xpath($selector));
    return $found === false ? new DOMNodeList() : $found;
}

/** Mirrors derivePrefix() in ss-engine-aurora-wave.js. */
function contract_derive_prefix(DOMXPath $x): ?string
{
    foreach (contract_matches($x, 'nav[class*="-sticky-nav"]') as $nav) {
        if (preg_match('/(?:^|\s)([a-z]+)-sticky-nav(?:\s|$)/', (string)$nav->getAttribute('class'), $m)) {
            return $m[1];
        }
    }
    foreach (['aurora', 'parade'] as $kind) {
        foreach (contract_matches($x, '.' . $kind . '-bg') as $bg) {
            if (preg_match('/(?:^|\s)([a-z]+)-(?:aurora|parade)-bg(?:\s|$)/', (string)$bg->getAttribute('class'), $m)) {
                return $m[1];
            }
        }
    }
    return null;
}

function contract_has_class(DOMElement $el, string $class): bool
{
    return in_array($class, preg_split('/\s+/', trim((string)$el->getAttribute('class'))) ?: [], true);
}

/**
 * The whole check, pure over (contract, rendered html). Kept pure so the
 * mutation tests at the bottom can feed it deliberately broken pages: a check
 * nobody has watched fail is not evidence. OPAUDIT 022 required this.
 *
 * @return string[] human-readable reasons the engine would draw nothing
 */
function contract_violations(array $contract, string $html, string $engine): array
{
    $out = [];
    $x = contract_dom($html);

    $needsPrefix = str_contains(json_encode($contract, JSON_THROW_ON_ERROR), '{P}');
    $P = contract_derive_prefix($x);
    if ($needsPrefix && $P === null) {
        return ["{$engine}: no prefix source in the page, so the engine cannot tell which skin it is in and returns immediately"];
    }
    $sub = static fn(string $s): string => str_replace('{P}', (string)$P, $s);

    if (!empty($contract['prefix_source'])) {
        $any = false;
        foreach ((array)$contract['prefix_source'] as $candidate) {
            if (contract_matches($x, $sub($candidate))->length > 0) { $any = true; break; }
        }
        if (!$any) {
            $out[] = "{$engine}: none of its prefix sources are present ("
                . implode(', ', array_map($sub, (array)$contract['prefix_source'])) . ')';
        }
    }

    if (!empty($contract['carrier'])) {
        $carrier = $sub((string)$contract['carrier']);
        if (contract_matches($x, $carrier)->length === 0) {
            $out[] = "{$engine}: settings carrier {$carrier} is absent, so the engine returns before reading anything";
        }
    }

    if (empty($contract['tiles'])) {
        return $out;
    }

    $tilesSelector = $sub((string)$contract['tiles']);
    $tiles = [];
    foreach (contract_matches($x, $tilesSelector) as $tile) {
        if ($tile instanceof DOMElement && !contract_has_class($tile, $P . '-tile--phantom')) {
            $tiles[] = $tile;
        }
    }
    if ($tiles === []) {
        $out[] = "{$engine}: no tiles match {$tilesSelector}";
        return $out;
    }

    foreach ((array)($contract['per_tile'] ?? []) as $childSelector) {
        $child = $sub((string)$childSelector);
        if (!preg_match('/^\.([a-z0-9_-]+)$/i', $child, $m)) {
            throw new RuntimeException("per_tile requirement must be a single class: {$child}");
        }
        $missing = 0;
        foreach ($tiles as $tile) {
            $hit = false;
            foreach ($tile->getElementsByTagName('*') as $desc) {
                if ($desc instanceof DOMElement && contract_has_class($desc, $m[1])) { $hit = true; break; }
            }
            if (!$hit) $missing++;
        }
        if ($missing > 0) {
            $out[] = "{$engine}: {$missing} of " . count($tiles)
                . " tiles have no {$child} element, and the engine skips a tile without one";
        }
    }

    foreach ((array)($contract['tile_attrs'] ?? []) as $attr) {
        $missing = 0; $blank = 0;
        foreach ($tiles as $tile) {
            if (!$tile->hasAttribute($attr)) { $missing++; continue; }
            // An empty value is the silent case: the engine does
            // parseInt(...) || 0, so every tile reports position zero and the
            // wave stops travelling without anything looking wrong.
            if (!preg_match('/^\d+$/', (string)$tile->getAttribute($attr))) $blank++;
        }
        if ($missing > 0) {
            $out[] = "{$engine}: {$missing} of " . count($tiles) . " tiles have no {$attr} attribute";
        }
        if ($blank > 0) {
            $out[] = "{$engine}: {$blank} of " . count($tiles)
                . " tiles have an empty or non-numeric {$attr}, which the engine silently reads as position 0";
        }
    }

    return $out;
}

/** Render a skin's real landing page through the real controller. */
function contract_render(string $skin, int $count = 9): string
{
    $items = [];
    for ($i = 0; $i < $count; $i++) {
        $items[] = [
            'img_title' => 'Photo ' . $i,
            'img_slug'  => 'photo-' . $i,
            'img_thumb_square' => '/thumb.jpg',
            'img_file'  => '/photo.jpg',
        ];
    }
    // Rendered with no owner settings on purpose: the markup an engine needs
    // must not depend on which options happen to be saved.
    $items = snapsmack_grid_frame_items($items, [], $skin);
    $view = snapsmack_build_skin_view(
        ['kind' => 'landing', 'photo_count' => $count, 'navigation' => [], 'items' => $items],
        ['site_name' => 'Contract', 'language' => 'en', 'direction' => 'ltr',
         'skin_style_url' => '/skin.css',
         'skin_presentation' => snapsmack_skin_presentation([], $skin)]
    );
    ob_start();
    $ok = snapsmack_render_strict_skin_template(dirname(__DIR__) . '/skins/' . $skin, 'layout.php', $view);
    $html = (string)ob_get_clean();
    if (!$ok) throw new RuntimeException("{$skin} did not render");
    return $html;
}

// ---------------------------------------------------------------------------
// Every skin that declares an engine with a markup contract
// ---------------------------------------------------------------------------

$snapsmack_manifest_inventory_catalogue_mode = true;
$inventory = require dirname(__DIR__) . '/core/manifest-inventory.php';
$engines = [];
foreach (($inventory['scripts'] ?? []) as $handle => $entry) {
    if (!empty($entry['requires_markup'])) $engines[$handle] = $entry['requires_markup'];
}
if ($engines === []) {
    contract_fail('No engine declares requires_markup. The contract registry has been emptied.');
}

$checked = 0;
$rendered = [];
foreach (glob(dirname(__DIR__) . '/skins/*/manifest.json') ?: [] as $manifestPath) {
    $skin = basename(dirname($manifestPath));
    $raw = (string)file_get_contents($manifestPath);
    try {
        $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        continue; // unreadable manifests are another test's business
    }
    $declared = array_intersect((array)($manifest['require_scripts'] ?? []), array_keys($engines));
    if ($declared === []) continue;
    if (!is_file(dirname($manifestPath) . '/layout.php')) continue;

    try {
        $rendered[$skin] ??= contract_render($skin);
    } catch (Throwable $e) {
        contract_fail("{$skin}: declares " . implode(', ', $declared)
            . " but its landing page did not render: " . $e->getMessage());
        continue;
    }
    foreach ($declared as $handle) {
        $checked++;
        foreach (contract_violations($engines[$handle], $rendered[$skin], $handle) as $violation) {
            contract_fail(strtoupper($skin) . ' declares an engine it does not feed -- ' . $violation);
        }
    }
}

if ($checked === 0) {
    contract_fail('No skin was checked. Either no skin declares a contracted engine, or the scan is broken.');
}

// ---------------------------------------------------------------------------
// Mutation tests. A check that has never been seen to fail is not a check.
// Each mutation is one of the ways PARADE was actually broken, or one of the
// silent degradations the engine would not report.
// ---------------------------------------------------------------------------

$subject = $rendered['parade'] ?? reset($rendered);
$subjectName = $rendered['parade'] ?? false ? 'parade' : (string)array_key_first($rendered);
if (!is_string($subject) || $subject === '') {
    contract_fail('Mutation tests have no rendered page to work on.');
    $subject = null;
}

if ($subject !== null) {
    $contract = $engines['smack-aurora-wave'] ?? null;
    if ($contract === null) {
        contract_fail('smack-aurora-wave has no markup contract to mutation-test.');
    } else {
        // Control: the real page must pass, or every mutation below is
        // meaningless -- you cannot prove a check catches a break in a page
        // that is already broken. When the control fails, say so once and stop,
        // so the real failures above stay readable.
        $controlOk = contract_violations($contract, $subject, 'smack-aurora-wave') === [];
        if (!$controlOk) {
            contract_fail('Mutation tests skipped: the real page already violates the contract, '
                . 'so nothing below could prove anything. Fix the failures above first.');
        }

        $mutations = [
            'ring element removed from every tile (the PARADE fault, v0.7.773D)'
                => static fn(string $h): string => (string)preg_replace('/<div class="pa-ring"[^>]*><\/div>/', '', $h),
            'data-row removed from every tile'
                => static fn(string $h): string => (string)preg_replace('/\sdata-row="[^"]*"/', '', $h),
            'data-col removed from every tile'
                => static fn(string $h): string => (string)preg_replace('/\sdata-col="[^"]*"/', '', $h),
            'data-row present but empty (the silent case: every tile reads as position 0)'
                => static fn(string $h): string => (string)preg_replace('/\sdata-row="[^"]*"/', ' data-row=""', $h),
            'settings carrier attribute removed'
                => static fn(string $h): string => (string)preg_replace('/\sdata-pa-palette="[^"]*"/', '', $h),
            'tiles renamed, so the engine finds none'
                => static fn(string $h): string => str_replace('class="pa-tile', 'class="pa-card', $h),
            'grid container renamed, so the tile selector cannot reach the tiles'
                => static fn(string $h): string => str_replace('pa-grid', 'pa-gallery', $h),
        ];

        foreach (($controlOk ? $mutations : []) as $label => $mutate) {
            $mutated = $mutate($subject);
            if ($mutated === $subject) {
                contract_fail("Mutation test is inert -- the page never contained what it tried to break: {$label}");
                continue;
            }
            if (contract_violations($contract, $mutated, 'smack-aurora-wave') === []) {
                contract_fail("Mutation test NOT CAUGHT -- the contract passes a page where {$label}. "
                    . 'This check cannot be trusted until that is fixed.');
            }
        }

        // The translator must refuse a selector it does not understand rather
        // than quietly matching nothing, which would read as "satisfied".
        $refused = false;
        try { contract_selector_xpath('div > span'); } catch (RuntimeException) { $refused = true; }
        // (this one is independent of the subject page, so it always runs)
        if (!$refused) contract_fail('The selector translator accepted a form it cannot evaluate.');
    }
}

if ($failures !== []) {
    fwrite(STDERR, "SKIN ENGINE MARKUP CONTRACT FAILED\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    fwrite(STDERR, "\nA skin that declares an engine must emit the markup that engine needs.\n"
        . "The engine returns silently when it does not, which is how PARADE shipped a\n"
        . "plain grid for eighteen days with every setting and stylesheet correct.\n");
    exit(1);
}

echo "Skin engine markup contract passed: {$checked} skin/engine pairs, "
    . count($engines) . " contracted engines, " . count($mutations ?? []) . " mutation tests.\n";
// ===== SNAPSMACK EOF =====
