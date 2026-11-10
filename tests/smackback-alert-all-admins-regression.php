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

echo "\n  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "SMACKBACK alert all-admins: FAIL ({$fail}).\n";
    exit(1);
}
echo "SMACKBACK alert all-admins: PASS ({$pass} assertions).\n";

// ===== SNAPSMACK EOF =====
