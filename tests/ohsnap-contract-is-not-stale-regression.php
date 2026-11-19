<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * The OH SNAP contract matches the inventory it was generated from.
 *
 * WHAT WENT WRONG. tools/oh-snap/src/data/skin-kit-contract.js is generated
 * from assets/ASSET-INVENTORY.json by tools/oh-snap/scripts/build-contract.mjs.
 * Nobody re-ran it after the inventory grew, so it sat three weeks out of date:
 * 94 JavaScript entries against 104, 39 CSS against 40, and a membership
 * difference larger than those counts suggest.
 *
 * WHY THAT MATTERS AND IS NOT HOUSEKEEPING. The contract is what the Oh Snap!
 * desktop skin designer bundles. A skin author working from it could not see
 * ten engines, nor the shared stylesheets the inventory had gained -- including
 * public-base.css and ss-engine-mosaic.css, the two sheets the project most
 * wants reused instead of recopied into each skin. Sean's goal is that a new
 * skin author can use every shared engine from the inventory alone. A stale
 * bundle defeats that silently: everything present looks correct, and what is
 * missing is invisible.
 *
 * A GENERATED FILE WITH NO STALENESS CHECK GOES STALE. That is not a surprise,
 * it is a guarantee, and this repository already knows it -- the strength
 * record, the skin version/content baseline and the control-selector baseline
 * all exist because a generated artefact drifted from its source once.
 *
 * HOW THIS CHECKS, and it is the point: it RUNS THE GENERATOR into a temporary
 * file and compares. It does not read the committed file and assert that it
 * looks plausible. I have spent this week being caught by tests that checked
 * the shape of a thing rather than the thing, so this one executes the thing.
 *
 * generated_at is ignored, because it changes on every run by design. Nothing
 * else is.
 */

$root = dirname(__DIR__);
$failures = [];

function oc_check(string $label, bool $ok, array &$failures): void {
    if ($ok) {
        echo "  PASS  {$label}\n";
    } else {
        echo "  FAIL  {$label}\n";
        $failures[] = $label;
    }
}

/** Pull the JSON object out of `window.OH_SNAP_CONTRACT = {...};`. */
function oc_contract(string $js): ?array {
    $i = strpos($js, 'window.OH_SNAP_CONTRACT');
    if ($i === false) return null;
    $eq = strpos($js, '=', $i);
    if ($eq === false) return null;
    $body = substr($js, $eq + 1);
    $end = strrpos($body, '}');
    if ($end === false) return null;
    $decoded = json_decode(rtrim(trim(substr($body, 0, $end + 1)), ';'), true);
    return is_array($decoded) ? $decoded : null;
}

echo "OH SNAP contract freshness\n\n";

$committed = $root . '/tools/oh-snap/src/data/skin-kit-contract.js';
$generator = $root . '/tools/oh-snap/scripts/build-contract.mjs';

oc_check('the committed contract is present', is_file($committed), $failures);
oc_check('the generator is present', is_file($generator), $failures);

$node = trim((string)shell_exec('node --version 2>&1'));
$haveNode = str_starts_with($node, 'v');
echo '  (node ' . ($haveNode ? $node : 'NOT AVAILABLE') . ")\n";

if (!$haveNode) {
    // Said out loud rather than passing quietly. A skipped check that reports
    // success is how a gate stops meaning anything.
    oc_check('SKIPPED: node is unavailable, so freshness was NOT CHECKED here',
             false, $failures);
} elseif (is_file($committed) && is_file($generator)) {
    $tmp = sys_get_temp_dir() . '/ohsnap_contract_' . bin2hex(random_bytes(6)) . '.js';
    $cmd = 'node ' . escapeshellarg($generator) . ' --out ' . escapeshellarg($tmp) . ' 2>&1';
    exec($cmd, $out, $code);
    oc_check('the generator ran: ' . trim(implode(' ', array_slice($out, -1))),
             $code === 0 && is_file($tmp), $failures);

    if (is_file($tmp)) {
        $a = oc_contract((string)file_get_contents($committed));
        $b = oc_contract((string)file_get_contents($tmp));
        oc_check('both contracts parsed as JSON', is_array($a) && is_array($b), $failures);

        if (is_array($a) && is_array($b)) {
            // generated_at is a timestamp and differs every run, by design.
            unset($a['generated_at'], $b['generated_at']);

            // THE COUNTS FIRST, because they are what a person can act on. A
            // bare "the files differ" tells nobody what went stale.
            foreach (['javascript', 'css', 'fonts'] as $section) {
                $ca = count($a['asset_inventory'][$section] ?? []);
                $cb = count($b['asset_inventory'][$section] ?? []);
                oc_check("the bundled {$section} list matches the inventory "
                         . "({$ca} committed, {$cb} current)",
                         $ca === $cb, $failures);
            }

            // Then membership, which catches a swap that keeps the count.
            foreach (['javascript', 'css'] as $section) {
                $fa = array_map(static fn (array $e): string => (string)($e['file'] ?? ''),
                                $a['asset_inventory'][$section] ?? []);
                $fb = array_map(static fn (array $e): string => (string)($e['file'] ?? ''),
                                $b['asset_inventory'][$section] ?? []);
                sort($fa);
                sort($fb);
                $missing = array_values(array_diff($fb, $fa));
                $extra   = array_values(array_diff($fa, $fb));
                oc_check("every current {$section} asset is in the bundled contract"
                         . ($missing ? ' — MISSING: ' . implode(', ', array_slice($missing, 0, 6)) : ''),
                         $missing === [], $failures);
                oc_check("and the contract lists no {$section} asset that no longer exists"
                         . ($extra ? ' — STALE: ' . implode(', ', array_slice($extra, 0, 6)) : ''),
                         $extra === [], $failures);
            }

            // And the whole thing, so a drift in any other part is caught too.
            oc_check('and the rest of the contract matches what the generator produces',
                     json_encode($a) === json_encode($b), $failures);
        }
        @unlink($tmp);
    }
}

// The EOF marker survives generation: a generated file that loses it reads as
// truncated to the check that exists to spot truncation.
if (is_file($committed)) {
    $lines = array_values(array_filter(array_map('rtrim',
        explode("\n", (string)file_get_contents($committed))), static fn ($l) => $l !== ''));
    oc_check('the generated contract still ends with the canonical EOF marker',
             end($lines) === '// ===== SNAPSMACK EOF =====', $failures);
}

echo "\n";
if ($failures) {
    fwrite(STDERR, 'OH SNAP contract FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    fwrite(STDERR, "\nRegenerate it:  cd tools/oh-snap && node scripts/build-contract.mjs\n");
    exit(1);
}
echo "OH SNAP contract matches the inventory it is generated from.\n";
echo "NOT COVERED: whether the Oh Snap! app reads every field it is given. This\n";
echo "proves the bundle is current, not that the designer uses all of it.\n";
// ===== SNAPSMACK EOF =====
