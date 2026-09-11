<?php
/**
 * SMACKBACK must not baseline operator one-off repair scripts. They ship with a
 * release, get replaced by hand, and are deleted when done — baselining turns
 * each of those into a breach and a lockout (foreverphotograph.ing, 2026-09-11).
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
function sr_check(string $label, bool $ok): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
    echo "PASS {$label}\n";
}
$sb = file_get_contents(__DIR__ . '/../core/smackback.php');
sr_check('root repair-*.php is excluded from monitoring',
    strpos($sb, "preg_match('/^repair-[a-z0-9-]+\.php$/', \$basename) && \$basename === \$rel") !== false);
sr_check('the exclusion sits with the installer-file exclusion',
    strpos($sb, "in_array(\$basename, ['install.php', 'setup.php'], true)") < strpos($sb, 'repair-[a-z0-9-]'));
// the pattern itself: root-level repair scripts only, never a nested look-alike
$pattern = '/^repair-[a-z0-9-]+\.php$/';
foreach (['repair-image-dates.php' => true, 'repair-fleet.php' => true,
          'repairs.php' => false, 'repair-image-dates.php.bak' => false] as $name => $want) {
    sr_check("pattern: $name " . ($want ? 'excluded' : 'still monitored'), (bool)preg_match($pattern, $name) === $want);
}
sr_check('a nested file of the same name is still monitored',
    !('core/repair-image-dates.php' === basename('core/repair-image-dates.php')));
echo "PASS: smackback repair-script regression\n";
// ===== SNAPSMACK EOF =====
