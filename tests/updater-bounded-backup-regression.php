<?php
/*
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line must be: // ===== SNAPSMACK EOF =====
 */
/** Static contract: pre-update backups drain across bounded HTTP requests. */
$root = dirname(__DIR__);
$helper = file_get_contents($root . '/core/updater.php');
$update = file_get_contents($root . '/smack-update.php');

$checks = [
    'bounded backup helper exists' => str_contains($helper, 'function updater_backup_step('),
    'backup has a wall-time budget' => str_contains($helper, 'microtime(true) - $started >= $budget_seconds'),
    'large tables use primary-key paging' => str_contains($helper, "SHOW INDEX FROM {\$quoted} WHERE Key_name = 'PRIMARY'"),
    'keyset query resumes after last row' => str_contains($helper, "' > ' . \$pdo->quote(\$state['last_key'])"),
    'keyset query has stable ordering' => str_contains($helper, 'ORDER BY {$key_quoted} ASC LIMIT {$batch}'),
    'backup progress persists in session' => str_contains($update, "['backup_progress']"),
    'incomplete backup remains in progress' => str_contains($update, '$backup_file === null'),
    'completion clears progress state' => str_contains($update, "unset(\$_SESSION['update_state']['backup_progress'])"),
    'completion advances update stage' => str_contains($update, "['stage']       = 'backed_up'"),
];

$failed = false;
foreach ($checks as $name => $ok) {
    if ($ok) continue;
    fwrite(STDERR, "FAIL: {$name}\n");
    $failed = true;
}
if ($failed) exit(1);
echo "PASS: updater database backup is resumable and time-bounded.\n";
// ===== SNAPSMACK EOF =====
