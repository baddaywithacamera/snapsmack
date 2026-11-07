<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

/**
 * Screenshot name transition: new positional names, legacy fallback, both.
 *
 * The reader prefers the new scheme as a whole and falls back to the legacy one,
 * so a transition package carrying both is not shown twice. The packager plan
 * publishes a migrated skin's shots under BOTH names, so a core that predates the
 * rename (reads the legacy names) still finds screenshots in a new package.
 * Pure helpers in core/skin-screenshots.php, exercised against temp fixtures.
 */

require_once dirname(__DIR__) . '/core/skin-screenshots.php';

$pass = 0; $fail = 0;
function cx(bool $ok, string $what): void {
    global $pass, $fail;
    echo ($ok ? "  ok    " : "  FAIL  ") . $what . "\n";
    $ok ? $GLOBALS['pass']++ : $GLOBALS['fail']++;
}

function mk(array $files): string {
    $dir = sys_get_temp_dir() . '/scs-ss-' . uniqid();
    mkdir($dir, 0777, true);
    foreach ($files as $f) file_put_contents("$dir/$f", "img:$f");
    return $dir;
}
function rm(string $dir): void { array_map('unlink', glob("$dir/*") ?: []); @rmdir($dir); }

function names(array $shots): array { return array_map(fn($s) => $s['name'], $shots); }

// ── READER ───────────────────────────────────────────────────────────────────
echo "READER: snapsmack_skin_screenshot_shots\n";

$d = mk(['screenshot-primary.png', 'screenshot-secondary.png', 'screenshot-tertiary.png']);
$shots = snapsmack_skin_screenshot_shots($d);
cx(names($shots) === ['screenshot-primary.png', 'screenshot-secondary.png', 'screenshot-tertiary.png'],
   'new-only: reads the three new names in order');
cx($shots[0]['label'] === 'Primary', 'new-only: labels are positional (Primary first)');
rm($d);

$d = mk(['screenshot-landing.png', 'screenshot-archive.png', 'screenshot-page.png']);
cx(names(snapsmack_skin_screenshot_shots($d)) === ['screenshot-landing.png', 'screenshot-archive.png', 'screenshot-page.png'],
   'legacy-only: still reads the three legacy names');
rm($d);

$d = mk(['screenshot-primary.png', 'screenshot-secondary.png', 'screenshot-tertiary.png',
         'screenshot-landing.png', 'screenshot-archive.png', 'screenshot-page.png']);
$shots = snapsmack_skin_screenshot_shots($d);
cx(count($shots) === 3 && names($shots)[0] === 'screenshot-primary.png',
   'both present (transition package): new wins, NOT shown twice (3 shots, not 6)');
rm($d);

$d = mk(['screenshot.png']);
cx(names(snapsmack_skin_screenshot_shots($d)) === ['screenshot.png'],
   'bare: single screenshot.png fallback');
rm($d);

$d = mk(['not-a-shot.png']);
cx(snapsmack_skin_screenshot_shots($d) === [], 'none: no screenshots found -> empty');
rm($d);

// ── PACKAGER PLAN ────────────────────────────────────────────────────────────
echo "\nPACKAGER: snapsmack_skin_screenshot_package_plan\n";

$d = mk(['screenshot-primary.png', 'screenshot-secondary.png', 'screenshot-tertiary.png']);
$plan = snapsmack_skin_screenshot_package_plan($d);
cx(count($plan) === 3, 'migrated: three shots planned');
cx($plan[0]['publish'] === ['screenshot-primary.png', 'screenshot-landing.png'],
   'migrated: each shot published under its new name AND its legacy alias (old cores keep working)');
cx($plan[1]['publish'] === ['screenshot-secondary.png', 'screenshot-archive.png']
   && $plan[2]['publish'] === ['screenshot-tertiary.png', 'screenshot-page.png'],
   'migrated: secondary->archive, tertiary->page aliases are correct');
rm($d);

$d = mk(['screenshot-landing.png', 'screenshot-archive.png', 'screenshot-page.png']);
$plan = snapsmack_skin_screenshot_package_plan($d);
cx(array_merge(...array_map(fn($p) => $p['publish'], $plan))
   === ['screenshot-landing.png', 'screenshot-archive.png', 'screenshot-page.png'],
   'not-yet-migrated: legacy names published as-is, no new names invented');
rm($d);

$d = mk(['screenshot.png']);
cx(snapsmack_skin_screenshot_package_plan($d) === [['src' => "$d/screenshot.png", 'publish' => ['screenshot.png'], 'label' => 'Preview']],
   'bare: single screenshot.png published as-is');
rm($d);

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
