<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

/**
 * No owner-facing control may be born dead.
 *
 * Every skin manifest control that drives CSS names a selector and a property.
 * The control does nothing if that selector matches no element the skin's
 * markup emits. The old control scan counted a control as wired because the
 * selector string existed; it never checked the selector against the markup, so
 * whole families of controls shipped dead and silent (SS-1214 and the sweep
 * that followed).
 *
 * tools/check-control-selectors.php resolves each control against the real
 * emitted markup. This gate runs it and fails when a control is dead that was
 * not already known dead at the baseline. The baseline is the accepted set at
 * the time it was written; it is an upper bound with known false positives
 * (custom-property controls a skin consumes from its own script, classes a skin
 * assembles at runtime), which is why it is a frozen allowlist and not a target
 * of zero. Shrink it as real dead controls are removed; never add to it to mute
 * a genuine new one.
 */

$root = dirname(__DIR__);
require_once $root . '/tools/check-control-selectors.php';

$pass = 0; $fail = 0;
function cx(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

cx(function_exists('snapsmack_scan_control_selectors'),
   'the control-selector scanner is available');

$baselinePath = $root . '/tools/_build/control-selector-baseline.json';
cx(is_file($baselinePath), 'the accepted-dead baseline is present');
$baseline = json_decode((string)file_get_contents($baselinePath), true);
cx(is_array($baseline) && isset($baseline['dead']) && is_array($baseline['dead']),
   'the baseline parses and carries a dead list');
$accepted = array_fill_keys($baseline['dead'] ?? [], true);

$rows = snapsmack_scan_control_selectors($root);
cx($rows !== [], 'the scan returned control rows (' . count($rows) . ')');

$currentDead = [];
foreach ($rows as $r) {
    if ($r['status'] === 'DEAD') $currentDead[$r['skin'] . '::' . $r['control']] = $r;
}

// The gate: a control that is dead and was not dead at the baseline is a
// regression. One assertion per new-dead control so each shows by name.
$newDead = [];
foreach ($currentDead as $key => $r) {
    if (!isset($accepted[$key])) $newDead[$key] = $r;
}
foreach ($newDead as $key => $r) {
    cx(false, "NEW dead control {$key}: selector " . $r['selector']
        . ' matches nothing the skin emits. Repoint it, or drop the control.');
}
cx($newDead === [], 'no control is newly dead against the baseline');

// Fixed controls are reported so the baseline can be trimmed, but they do not
// fail the gate.
$fixed = [];
foreach ($accepted as $key => $_) {
    if (!isset($currentDead[$key])) $fixed[] = $key;
}
if ($fixed !== []) {
    sort($fixed);
    echo "\nNo longer dead (trim from the baseline): " . count($fixed) . "\n";
    foreach ($fixed as $k) echo "  - {$k}\n";
}

echo "\n{$pass} checks, {$fail} failed; "
   . count($currentDead) . " dead now, " . count($accepted) . " accepted at baseline, "
   . count($newDead) . " newly dead.\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
