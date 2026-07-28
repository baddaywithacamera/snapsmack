<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

require_once __DIR__ . '/../core/client-ip.php';

$failures = [];
function ip_test(bool $ok, string $message): void {
    global $failures;
    if (!$ok) $failures[] = $message;
}

$_SERVER = ['REMOTE_ADDR' => '198.51.100.22', 'HTTP_CF_CONNECTING_IP' => '8.8.8.8'];
ip_test(snap_trusted_client_ip() === '198.51.100.22', 'forged CF header from untrusted peer was accepted');

$_SERVER = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_CF_CONNECTING_IP' => '8.8.8.8'];
ip_test(snap_trusted_client_ip() === '8.8.8.8', 'trusted tunnel CF header was not accepted');

$_SERVER = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1, 8.8.8.8, 127.0.0.1'];
ip_test(snap_trusted_client_ip() === '8.8.8.8', 'XFF was not evaluated from the right');

$_SERVER = ['REMOTE_ADDR' => '8.8.4.4'];
ip_test(snap_trusted_client_ip() === '8.8.4.4', 'direct/no-proxy client was not preserved');

ip_test(!snap_ip_is_bannable('127.0.0.1'), 'loopback address was bannable');
ip_test(!snap_ip_is_bannable('10.2.3.4'), 'private address was bannable');
ip_test(!snap_ip_is_bannable('not-an-ip'), 'malformed address was bannable');
ip_test(snap_ip_is_bannable('8.8.8.8'), 'public address was not bannable');

$parsed = snap_parse_trusted_proxies('127.0.0.1, 10.20.0.0/16, broken, 2001:db8::/32');
ip_test($parsed === ['127.0.0.1', '10.20.0.0/16', '2001:db8::/32'], 'proxy validation did not reject malformed entries');
ip_test(snap_ip_is_trusted_proxy('10.20.4.9', $parsed), 'IPv4 CIDR trust failed');
ip_test(!snap_ip_is_trusted_proxy('10.21.4.9', $parsed), 'IPv4 CIDR trust escaped its range');
ip_test(snap_ip_is_trusted_proxy('2001:db8::2', $parsed), 'IPv6 CIDR trust failed');

$sources = [
    'snap-in.php' => ['snap_trusted_client_ip', 'snap_ip_is_bannable'],
    'probe-ban.php' => ['snap_trusted_client_ip', 'snap_ip_is_bannable'],
    'core/flkrfckr-api.php' => ['snap_trusted_client_ip', 'snap_ip_is_bannable'],
    'core/smackverse.php' => ['snap_trusted_client_ip', 'snap_ip_is_bannable'],
    'password-reset.php' => ['snap_trusted_client_ip'],
    'core/community-session.php' => ['snap_trusted_client_ip'],
];
foreach ($sources as $file => $needles) {
    $body = file_get_contents(__DIR__ . '/../' . $file);
    foreach ($needles as $needle) {
        ip_test(str_contains($body, $needle), "{$file} does not use {$needle}");
    }
}
foreach (['core/flkrfckr-api.php', 'core/smackverse.php'] as $file) {
    $body = file_get_contents(__DIR__ . '/../' . $file);
    ip_test(!str_contains($body, "!function_exists('snap_ip_is_bannable')"), "{$file} still fails open when the ban guard is missing");
    ip_test(str_contains($body, "require_once __DIR__ . '/client-ip.php'"), "{$file} does not require the security component");
}

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "PASS: client-IP security regression suite\n";
// ===== SNAPSMACK EOF =====
