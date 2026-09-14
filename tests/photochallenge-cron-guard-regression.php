<?php
/** The fediverse cron must survive a site with no PHOTO CHALLENGE tables (2026-09-14: 18 sites failed every tick). */
$src = file_get_contents(dirname(__DIR__) . '/core/photochallenge.php');
$ok = preg_match('/function pc_activate_due_prompts\(PDO \$pdo, array &\$settings\): int \{\s*\/\/[^\n]*\n(?:\s*\/\/[^\n]*\n)*\s*if \(!pc_enabled\(\$settings\)\) return 0;\s*try \{/', $src) === 1;
echo ($ok ? "PASS" : "FAIL") . ": pc_activate_due_prompts is enabled-gated and table-safe\n";
exit($ok ? 0 : 1);
// ===== SNAPSMACK EOF =====
