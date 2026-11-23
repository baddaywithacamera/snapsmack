<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1548. Dependent community settings keep their saved values while the
 * master switch is off, but the admin must say plainly that they are inactive.
 */

$root = dirname(__DIR__);
$page = (string)file_get_contents($root . '/smack-community-settings.php');
$script = (string)file_get_contents($root . '/assets/js/admin-community-settings.js');

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ': ' . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};

$check(str_contains($page, 'id="community-master-toggle"'),
    'the master switch has a stable browser hook');
$check(str_contains($page, 'id="community-master-warning"')
    && str_contains($page, 'COMMUNITY SYSTEM IS OFF.'),
    'the panel states the override in plain words');
$check(str_contains($page, 'role="status"') && str_contains($page, 'aria-live="polite"'),
    'the changing warning is announced to assistive technology');
$check(str_contains($page, "community_enabled'] ?? '1') === '1' ? 'hidden' : ''"),
    'the server renders the correct warning state before JavaScript runs');
$check(substr_count($page, 'aria-describedby="community-master-warning"') >= 4,
    'comments, identity, likes and verification controls name the override warning');
$check(str_contains($script, "getElementById('community-master-toggle')")
    && str_contains($script, "getElementById('community-master-warning')"),
    'the external admin script binds the real master and warning elements');
$check(str_contains($script, 'warning.hidden = master.checked')
    && str_contains($script, "master.addEventListener('change', syncMasterWarning)"),
    'the warning updates immediately when the master switch changes');
$check(!preg_match('/\.disabled\s*=|setAttribute\([\'\"]disabled/', $script),
    'the notice does not disable or erase dependent saved values');
$warningTag = [];
$check((bool)preg_match('/<div id="community-master-warning"([^>]*)>/', $page, $warningTag)
    && !str_contains($warningTag[1] ?? '', 'style='),
    'the warning adds no static inline presentation');

echo (count($failures) ? 'FAIL' : 'PASS')
    . ': community master override is visible, immediate and non-destructive (SS-1548)' . PHP_EOL;
exit(count($failures) ? 1 : 0);
// ===== SNAPSMACK EOF =====
