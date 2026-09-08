<?php
/** Static contract for gradual, additive fleet peer-follow reconciliation. */
$root = dirname(__DIR__);
$fedi = file_get_contents($root . '/core/fediverse.php');
$cron = file_get_contents($root . '/cron-fediverse.php');

$checks = [
    'reconciler exists' => str_contains($fedi, 'function sv_reconcile_mesh_follows'),
    'active peers only' => str_contains($fedi, "WHERE status='active'"),
    'enabled peers only when supported' => str_contains($fedi, 'AND fediverse_enabled=1'),
    'existing follows are preserved' => str_contains($fedi, 'isset($existing[$actor])'),
    'no delete in reconciler' => !str_contains(substr($fedi,
        strpos($fedi, 'function sv_reconcile_mesh_follows'),
        strpos($fedi, '/** Unfollow:', strpos($fedi, 'function sv_reconcile_mesh_follows'))
            - strpos($fedi, 'function sv_reconcile_mesh_follows')), 'DELETE FROM'),
    'cron advances one edge' => str_contains($cron, 'sv_reconcile_mesh_follows($pdo, $settings, 1)'),
    'manual follow sends exact row' => str_contains($fedi,
        'sv_process_deliveries($pdo, $settings, 1, 0, $follow_qid, $follow_qid, $inbox)'),
    'handshakes outrank content backlog' => str_contains($fedi, 'activity_json REGEXP')
        && str_contains($fedi, '(Accept|Reject|Follow|Undo)'),
];

$failed = false;
foreach ($checks as $name => $ok) {
    if ($ok) continue;
    fwrite(STDERR, "FAIL: {$name}\n");
    $failed = true;
}
if ($failed) exit(1);
echo "PASS: fleet peer follows reconcile gradually without destructive cleanup.\n";
// ===== SNAPSMACK EOF =====
