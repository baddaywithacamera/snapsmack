<?php
/** Regression: queue eligibility must use the database clock that writes DATETIME rows. */
$src = file_get_contents(__DIR__ . '/../core/fediverse.php');
$checks = [
    'due deliveries compare against the database clock' =>
        strpos($src, 'next_try_at <= NOW()') !== false,
    'delivery planner does not compare DATETIME with PHP date' =>
        strpos($src, "next_try_at <= ?") === false,
    'completed worker timestamp is recorded separately' =>
        strpos($src, "'fediverse_cron_last_completed'") !== false,
];
$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? "PASS" : "FAIL") . ": {$label}\n";
    if (!$ok) $failed++;
}
exit($failed ? 1 : 0);
