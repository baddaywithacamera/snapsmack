<?php
/**
 * SNAPSMACK — Asset Library Inventory generator.
 *
 * Scans the shared asset library (assets/js, assets/fonts, assets/css) and writes
 * a catalogue of everything a skin developer — or the OH SNAP skin-builder AI —
 * can draw on, WITH a description of what each thing is and what it is for.
 *
 * Descriptions are read from each file's own top-of-file comment (its "purpose"
 * line), so the catalogue stays current as engines change — re-run this after
 * adding or editing an asset.
 *
 * The manifest is a cross-tool API contract (OH SNAP, SUYB, future tools all read
 * it), so this pass is FAIL-LOUD at generation time — it refuses to write a
 * complete-looking manifest that is actually incomplete:
 *   - every JS engine/helper must have a non-blank enable, family and purpose;
 *   - every CSS block must have a non-blank purpose;
 *   - every `requires` entry must point at an asset that is actually in the
 *     catalogue (no dangling dependency).
 * A gap stops the build with a listed reason and a non-zero exit — it does not
 * ship a lie. Fill the header(s) it names and re-run. (Interop Step 0.)
 *
 * schema_version: a top-level MAJOR.MINOR stamp. Consumers refuse an unknown
 * MAJOR, accept a newer MINOR forward-compatibly. Bump MAJOR only on a
 * breaking shape change, MINOR on additive fields.
 *
 * requires: a per-asset list of other asset filenames this one depends on
 * (e.g. ss-engine-52-pickup.js requires ss-engine-organized-mayhem.js), read
 * from an optional "Requires: a.js, b.js" line in the file header. Lets a skin
 * declare an engine and pull its dependencies instead of inlining them.
 *
 * Two outputs from one pass:
 *   assets/ASSET-INVENTORY.json  — machine-readable, for the OH SNAP AI to load.
 *   assets/ASSET-INVENTORY.md    — plain-English, for skin developers to read.
 *
 * Run:  php tools/asset-inventory.php
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

/**
 * Manifest schema version (MAJOR.MINOR). Bump MAJOR on a breaking shape change
 * (renamed/removed fields), MINOR when adding fields. Consumers refuse an unknown
 * MAJOR and accept a newer MINOR forward-compatibly.
 */
const INV_SCHEMA_VERSION = '1.0';

$root      = dirname(__DIR__);
$js_dir    = $root . '/assets/js';
$css_dir   = $root . '/assets/css';
$fonts_dir = $root . '/assets/fonts';

/** Tidy one raw comment block down to a single "purpose" sentence ('' if none). */
function inv_tidy(string $text, string $base): string {
    // Drop the EOF-marker boilerplate (both the header note and the closing
    // "===== SNAPSMACK EOF =====" sentinel), comment punctuation, collapse space.
    $text = preg_replace('~SNAPSMACK_EOF_HEADER.*$~s', '', $text);
    $text = preg_replace('~=+\s*SNAPSMACK\s+EOF\s*=+~i', '', $text);
    $text = preg_replace('~^[\s*!/#]+~m', '', $text);
    $text = trim(preg_replace('~\s+~', ' ', $text));
    $text = preg_replace('~^SNAPSMACK\s*[-–—:]\s*~i', '', $text);
    // Drop a redundant filename echo and any leading emoji/punctuation bytes.
    if ($base !== '') $text = trim(str_replace($base, '', $text));
    while ($text !== '' && !ctype_alnum($text[0])) $text = substr($text, 1);
    $text = trim(preg_replace('~\s+~', ' ', $text));
    // First sentence / first ~200 chars is the purpose.
    if ($text !== '' && preg_match('~^(.{20,200}?[.!])\s~s', $text . ' ', $mm)) {
        $text = $mm[1];
    } elseif (strlen($text) > 200) {
        $text = substr($text, 0, 197) . '...';
    }
    return $text;
}

/**
 * Pull the human "purpose" out of a file's leading comment(s).
 * Reads a generous window (headers can be long) and walks the comment blocks in
 * document order, returning the FIRST that yields a real sentence — so a file
 * whose first block is only the EOF-marker boilerplate still gets the real
 * description from the block below it.
 */
function inv_describe(string $path): array {
    $raw = (string) @file_get_contents($path, false, null, 0, 16000);
    if ($raw === '') return ['vendor' => false, 'desc' => ''];

    // Third-party minified libraries announce themselves with /*! ... !*/.
    $vendor = (bool) preg_match('/\.min\.js$/', $path) || str_starts_with(ltrim($raw), '/*!');
    $base   = basename($path);

    $blocks = [];
    if (preg_match_all('~/\*(.*?)\*/~s', $raw, $m)) {          // every /* ... */ block
        foreach ($m[1] as $b) $blocks[] = $b;
    }
    if (preg_match('~^(?:\s*//.*\n)+~m', $raw, $m)) {           // a leading // line run
        $blocks[] = preg_replace('~^\s*//~m', '', $m[0]);
    }
    foreach ($blocks as $b) {
        $desc = inv_tidy($b, $base);
        if ($desc !== '') return ['vendor' => $vendor, 'desc' => $desc];
    }
    return ['vendor' => $vendor, 'desc' => ''];
}

/**
 * Read an asset's declared dependencies from an optional header line:
 *   Requires: ss-engine-organized-mayhem.js, other.js
 * Returns a list of asset filenames (basenames). Empty when none declared.
 * (Prose like "built on top of ORGANIZED MAYHEM" is NOT parsed — it can't be
 * validated; the structured line is the source of truth. Step 1 fills these.)
 */
function inv_requires(string $path): array {
    $raw = (string) @file_get_contents($path, false, null, 0, 16000);
    if ($raw === '') return [];
    if (!preg_match('~^[\s*/#]*Requires?\s*:\s*(.+)$~mi', $raw, $m)) return [];
    $out = [];
    foreach (preg_split('~[,\s]+~', trim($m[1])) as $tok) {
        $tok = trim($tok, " \t*/\"'`");
        if ($tok !== '' && preg_match('~\.(js|css)$~i', $tok)) $out[] = basename($tok);
    }
    return array_values(array_unique($out));
}

/** Which family/role a JS file belongs to, for grouping. */
function inv_js_family(string $name): string {
    if (preg_match('/\.min\.js$/', $name))       return 'Third-party libraries';
    if (str_starts_with($name, 'ss-engine-'))    return 'Skin engines (ss-engine-*) — public-facing behaviour a skin can switch on';
    if (str_starts_with($name, 'smack-'))        return 'Admin & tool scripts (smack-*) — the CMS back office';
    if (str_starts_with($name, 'ss-'))           return 'Shared front-end helpers (ss-*)';
    return 'Other';
}

$catalog = ['schema_version' => INV_SCHEMA_VERSION, 'generated_note' => 'Auto-generated by tools/asset-inventory.php from each asset\'s own header. Re-run after adding/editing assets.', 'javascript' => [], 'fonts' => [], 'css' => []];

// --- JavaScript ---
foreach (glob($js_dir . '/*.js') ?: [] as $f) {
    if (str_ends_with($f, '.bak')) continue;
    $name = basename($f);
    $d = inv_describe($f);
    $catalog['javascript'][] = [
        'file'    => 'assets/js/' . $name,
        'enable'  => str_starts_with($name, 'ss-engine-') ? 'declare in the skin manifest require_scripts, or it loads via core/footer-scripts.php' : 'loaded by the CMS where needed',
        'family'  => inv_js_family($name),
        'vendor'  => $d['vendor'],
        'requires'=> inv_requires($f),
        'purpose' => $d['desc'] !== '' ? $d['desc'] : 'NEEDS-DESCRIPTION (no purpose line in the file header)',
    ];
}

// --- Fonts ---
foreach (glob($fonts_dir . '/*', GLOB_ONLYDIR) ?: [] as $d) {
    $fam  = basename($d);
    $files = array_map('basename', glob($d . '/*.{ttf,otf,woff,woff2}', GLOB_BRACE) ?: []);
    $lic  = glob($d . '/*{LICENSE,license,LICENCE,licence,OFL,ofl,EULA,eula,COPYING,copying,README,readme}*', GLOB_BRACE) ?: [];
    $catalog['fonts'][] = [
        'family'  => $fam,
        'dir'     => 'assets/fonts/' . $fam,
        'files'   => $files,
        'license' => $lic ? basename($lic[0]) : 'NEEDS-CHECK (no licence file in the folder)',
        'purpose' => 'Display/branding typeface. Skins reference it by family name in @font-face / CSS.',
    ];
}

// --- CSS ---
foreach (glob($css_dir . '/*.css') ?: [] as $f) {
    if (str_ends_with($f, '.bak')) continue;
    $name = basename($f);
    $d = inv_describe($f);
    $catalog['css'][] = [
        'file'    => 'assets/css/' . $name,
        'requires'=> inv_requires($f),
        'purpose' => $d['desc'] !== '' ? $d['desc'] : 'NEEDS-DESCRIPTION (no purpose line in the file header)',
    ];
}

// --- FAIL-LOUD GATE (Interop Step 0) -------------------------------------
// The manifest is a cross-tool contract; a complete-looking but incomplete one
// is worse than none, because a consumer trusts it. Validate the full field set
// the consumer needs, then refuse to write anything if a single check fails.
// (A blank `enable` is the worst gap: the AI wants the asset but can't write the
//  declaration line, so it inlines — the exact duplication this catalogue kills.)
$errors = [];

// Every asset filename we know about, so a `requires` can't dangle.
$known = [];
foreach ($catalog['javascript'] as $e) $known[basename($e['file'])] = true;
foreach ($catalog['css'] as $e)        $known[basename($e['file'])] = true;

$inv_blank = static function ($v): bool {
    return !is_string($v) || trim($v) === '' || str_starts_with($v, 'NEEDS');
};

foreach ($catalog['javascript'] as $e) {
    $f = $e['file'];
    foreach (['enable', 'family', 'purpose'] as $field) {
        if ($inv_blank($e[$field] ?? '')) $errors[] = "{$f}: missing {$field} (add it to the file header, then re-run)";
    }
    foreach ($e['requires'] as $dep) {
        if (empty($known[$dep])) $errors[] = "{$f}: requires '{$dep}', which is not an asset in the catalogue";
    }
}
foreach ($catalog['css'] as $e) {
    $f = $e['file'];
    if ($inv_blank($e['purpose'] ?? '')) $errors[] = "{$f}: missing purpose (add a description to the file header, then re-run)";
    foreach ($e['requires'] as $dep) {
        if (empty($known[$dep])) $errors[] = "{$f}: requires '{$dep}', which is not an asset in the catalogue";
    }
}

if ($errors) {
    fwrite(STDERR, "ASSET INVENTORY REFUSED — the shared library is incomplete, so no manifest was written.\n");
    fwrite(STDERR, "Fix these and re-run (the OH SNAP AI designs against this — a gap here makes it design blind):\n\n");
    foreach ($errors as $err) fwrite(STDERR, "  - {$err}\n");
    fwrite(STDERR, "\n" . count($errors) . " problem(s). Nothing written.\n");
    exit(1);
}

// --- Write JSON (for the OH SNAP AI) ---
@file_put_contents($root . '/assets/ASSET-INVENTORY.json',
    json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

// --- Write Markdown (for skin developers) ---
$md  = "# SnapSmack Asset Library — Inventory\n\n";
$md .= "> Auto-generated by `tools/asset-inventory.php` from each asset's own header. **Re-run it after adding or editing an asset.** Anything marked NEEDS-DESCRIPTION has no purpose line in its file header yet — add one and re-run.\n\n";

$js_by_family = [];
foreach ($catalog['javascript'] as $e) $js_by_family[$e['family']][] = $e;
$md .= "## JavaScript (" . count($catalog['javascript']) . " files)\n\n";
foreach ($js_by_family as $family => $items) {
    $md .= "### {$family}\n\n";
    foreach ($items as $e) {
        $md .= "- **`" . basename($e['file']) . "`** — {$e['purpose']}\n";
    }
    $md .= "\n";
}
$md .= "## Fonts (" . count($catalog['fonts']) . " families)\n\n";
foreach ($catalog['fonts'] as $e) {
    $md .= "- **{$e['family']}** — {$e['purpose']} (licence: {$e['license']})\n";
}
$md .= "\n## Shared CSS (" . count($catalog['css']) . " files)\n\n";
foreach ($catalog['css'] as $e) {
    $md .= "- **`" . basename($e['file']) . "`** — {$e['purpose']}\n";
}
$md .= "\n<!-- generated; edit the source assets' headers, not this file -->\n";
@file_put_contents($root . '/assets/ASSET-INVENTORY.md', $md);

// Reached only when the fail-loud gate passed, so every asset is complete.
$deps = 0;
foreach ($catalog['javascript'] as $e) $deps += count($e['requires']);
foreach ($catalog['css'] as $e)        $deps += count($e['requires']);
echo "Asset inventory written (schema " . INV_SCHEMA_VERSION . "):\n  assets/ASSET-INVENTORY.json\n  assets/ASSET-INVENTORY.md\n";
echo "JS: " . count($catalog['javascript']) . " · Fonts: " . count($catalog['fonts']) . " · CSS: " . count($catalog['css']) . " · declared dependencies: {$deps}\n";
echo "All assets complete (fail-loud gate passed).\n";

// Non-fatal: font licences are NOT gated (a licence gap doesn't make the AI
// design blind), but don't let them pass silently either.
$lic_gaps = [];
foreach ($catalog['fonts'] as $e) if (str_starts_with($e['license'], 'NEEDS')) $lic_gaps[] = $e['family'];
if ($lic_gaps) {
    fwrite(STDERR, "WARNING: " . count($lic_gaps) . " font(s) have no licence file (not gated, but check them): " . implode(', ', $lic_gaps) . "\n");
}
// ===== SNAPSMACK EOF =====
