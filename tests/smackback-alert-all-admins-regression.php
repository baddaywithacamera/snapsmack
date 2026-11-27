<?php
declare(strict_types=1);

/**
 * SS-1173. A SMACKBACK breach alert must reach EVERY admin, and a failed send
 * must be handled and recorded — not discarded.
 *
 * The old smackback_send_alert() picked a single $to (alert email or the legacy
 * admin_email), called the mailer once, and ignored the return value, so a
 * breach alert that failed to send looked exactly like one that succeeded and no
 * other admin was told. This is a static check of the shape of the fix (the live
 * path needs a DB and the mailer): it reads the function and asserts it
 * enumerates admin users, handles each send result, and persists the outcome.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
function sb_test(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

$src = (string) file_get_contents($root . '/core/smackback.php');

// Isolate smackback_send_alert()'s body by brace-walking from its signature.
$at = strpos($src, 'function smackback_send_alert(');
sb_test($at !== false, 'smackback_send_alert() is present');
$body = '';
if ($at !== false) {
    $i = strpos($src, '{', $at);
    $depth = 0; $end = $i;
    for ($n = strlen($src); $i !== false && $i < $n; $i++) {
        if ($src[$i] === '{') $depth++;
        elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
    }
    $body = substr($src, $at, $end - $at + 1);
}

// Reaches every admin: queries snap_users for the admin tier.
sb_test(str_contains($body, 'FROM snap_users')
    && str_contains($body, "user_role IN ('admin','administrator','owner')"),
    'the alert enumerates admin-tier users from snap_users');

// Sends to a recipient SET, not a single $to.
sb_test(str_contains($body, 'foreach ($recipients as $addr)'),
    'it iterates a recipient set rather than one address');

// Handles the mailer result instead of discarding it.
sb_test((bool) preg_match('/if\s*\(\s*snapsmack_send_mail\s*\(/', $body),
    'the mailer return value is checked, not discarded');

// Records the outcome so a failure is actionable.
sb_test(str_contains($body, 'smackback_record_alert_delivery('),
    'the delivery outcome is recorded');

// The old single-recipient send is gone.
sb_test(!preg_match('/snapsmack_send_mail\(\s*\$to\s*,/', $body),
    'the old single-$to send is gone');

// The persistence helper exists and writes an actionable, secret-free status.
sb_test(str_contains($src, 'function smackback_record_alert_delivery('),
    'smackback_record_alert_delivery() helper exists');
sb_test(str_contains($src, 'smackback_last_alert_failed')
    && str_contains($src, 'smackback_last_alert_at'),
    'it persists last-alert status keys for the dashboard to read');

// Run the breach handler with a local mail sink and a real settings table.
final class AlertFixturePdo extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace(
            'ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)',
            'ON CONFLICT(setting_key) DO UPDATE SET setting_val = excluded.setting_val',
            $query
        ), $options);
    }

    public function exec(string $statement): int|false {
        return parent::exec(str_replace(
            'ON DUPLICATE KEY UPDATE setting_val = setting_val',
            'ON CONFLICT(setting_key) DO NOTHING',
            $statement
        ));
    }
}

$fixture = sys_get_temp_dir() . '/snapsmack-breach-alert-' . bin2hex(random_bytes(6));
mkdir($fixture . '/core', 0700, true);
try {
    copy($root . '/core/smackback.php', $fixture . '/core/smackback.php');
    file_put_contents($fixture . '/core/diaglog.php', <<<'PHP'
<?php
function snap_diaglog(string $log, string $event, array $data): void {
    $GLOBALS['breach_detections'][] = $data['first_breach'];
}
function snap_maint_lock_state(): array { return []; }
PHP);
    file_put_contents($fixture . '/core/mailer.php', <<<'PHP'
<?php
function snapsmack_send_mail(string $to, string $subject, string $body, array $options): bool {
    $GLOBALS['breach_mail'][] = strtolower($to);
    return strtolower($to) !== 'admin@example.test';
}
PHP);
    require $fixture . '/core/smackback.php';
    $pdo = new AlertFixturePdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)');
    $pdo->exec('CREATE TABLE snap_users (email TEXT, user_role TEXT)');
    $pdo->exec('CREATE TABLE snap_smackback_log (detected_at TEXT, resolved_at TEXT, affected_files TEXT, file_count INTEGER, resolution TEXT)');
    $pdo->exec("INSERT INTO snap_settings VALUES ('smackback_alert_email', 'owner@example.test')");
    $pdo->exec("INSERT INTO snap_users VALUES ('OWNER@example.test', 'owner'), ('admin@example.test', 'admin')");
    $breach_mail = $breach_detections = [];

    smackback_handle_breach(['core/constants.php'], []);
    sort($breach_mail);
    sb_test($breach_mail === ['admin@example.test', 'owner@example.test'],
        'a new incident mails each distinct admin even when the status row was absent');
    $pdo->exec("UPDATE snap_settings SET setting_val = '2001-01-01 00:00:00' WHERE setting_key = 'smackback_breach_at'");
    $delivery = $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key = 'smackback_last_alert_failed'")->fetchColumn();
    sb_test($delivery === '1', 'a failed delivery remains visible');

    smackback_handle_breach(['core/constants.php'], []);
    smackback_handle_breach([], ['core/updater.php']);
    sb_test($breach_mail === ['admin@example.test', 'owner@example.test'],
        'repeat detections during the incident send no additional email');
    sb_test($breach_detections === [true, false, false], 'every detection is still logged');
    sb_test($pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key = 'smackback_breach_at'")->fetchColumn()
        === '2001-01-01 00:00:00', 'repeat detections keep the opening timestamp');
    sb_test(smackback_is_breach(), 'containment remains active');

    smackback_resolve_breach();
    smackback_handle_breach(['core/updater.php'], []);
    sort($breach_mail);
    sb_test($breach_mail === ['admin@example.test', 'admin@example.test', 'owner@example.test', 'owner@example.test'],
        'a new incident after recovery mails every admin again');
    sb_test($breach_detections === [true, false, false, true], 'recovery re-arms first detection');
} finally {
    foreach (['smackback.php', 'diaglog.php', 'mailer.php'] as $file) {
        unlink($fixture . '/core/' . $file);
    }
    rmdir($fixture . '/core');
    rmdir($fixture);
}

echo "\n  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "SMACKBACK alert all-admins: FAIL ({$fail}).\n";
    exit(1);
}
echo "SMACKBACK alert all-admins: PASS ({$pass} assertions).\n";

// ===== SNAPSMACK EOF =====
