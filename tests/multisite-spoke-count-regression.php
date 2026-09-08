<?php
/** Multisite connected-spoke count regression. */
$page = file_get_contents(dirname(__DIR__) . '/smack-multisite.php');
$checks = [
    'dashboard derives a connected spoke count' => str_contains($page, '$connected_spoke_count = count(array_filter($nodes'),
    'count includes spoke rows only' => str_contains($page, "(\$n['role'] ?? '') === 'spoke'"),
    'count excludes disconnected rows' => str_contains($page, "(\$n['status'] ?? '') !== 'disconnected'"),
    'heading displays the count' => str_contains($page, '<h3>CONNECTED SPOKES — <?php echo $connected_spoke_count; ?></h3>'),
];
$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) $failed++;
}
exit($failed === 0 ? 0 : 1);
// ===== SNAPSMACK EOF =====
