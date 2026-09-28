<?php
require_once __DIR__ . '/../core/login-route.php';

$failures = [];
function login_route_test(bool $ok, string $message): void {
    global $failures;
    if (!$ok) $failures[] = $message;
}

login_route_test(
    snapsmack_login_requested_path([
        'THE_REQUEST' => 'GET /snap-in HTTP/1.1',
        'REQUEST_URI' => '/snap-in.php',
    ]) === '/snap-in',
    'internal rewrite was mistaken for direct PHP access'
);

$login_source = file_get_contents(__DIR__ . '/../snap-in.php');
login_route_test(!str_contains($login_source, 'DIRECT ACCESS PROTECTION'), 'login still refuses direct filename access');
login_route_test(!str_contains($login_source, 'USER-AGENT FILTER'), 'login still refuses browsers by User-Agent');
login_route_test(substr_count($login_source, 'http_response_code(403)') === 1, 'unexpected silent GET-time 403 remains in login');
login_route_test(
    snapsmack_login_requested_path([
        'THE_REQUEST' => 'GET /snap-in.php?x=1 HTTP/1.1',
        'REQUEST_URI' => '/snap-in.php?x=1',
    ]) === '/snap-in.php',
    'true direct PHP request was not detected'
);
login_route_test(
    snapsmack_login_requested_path(['REQUEST_URI' => '/custom-login?x=1']) === '/custom-login',
    'REQUEST_URI fallback did not preserve a custom login slug'
);

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "PASS: login route regression suite\n";
// ===== SNAPSMACK EOF =====
