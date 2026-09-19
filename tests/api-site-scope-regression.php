<?php
/*
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line must be: // ===== SNAPSMACK EOF =====
 */
/**
 * Mutual-auth A1 (SECAUDIT 054): a Bearer WRITE that names a different site is
 * refused; reads never are; the check is dark unless api_site_scope is set.
 * Runs core/api-site-scope.php against a stub PDO with the throw hook.
 *
 *   php tests/api-site-scope-regression.php      (exit 0 = pass)
 */
declare(strict_types=1);

define('SNAP_SCOPE_THROW', true);
$root = dirname(__DIR__);
require_once $root . '/core/api-site-scope.php';

class _ScopeStmt { public function __construct(private $v) {} public function fetchColumn() { return $this->v; } }
class _ScopePdo extends PDO {
    public array $settings = [];
    public function __construct() {}
    #[\ReturnTypeWillChange]
    public function query(string $sql, ...$a) {
        if (preg_match("/setting_key='(\\w+)'/", $sql, $m)) return new _ScopeStmt($this->settings[$m[1]] ?? '');
        return new _ScopeStmt('');
    }
}

$fail = 0;
function st(bool $ok, string $name): void { global $fail; echo ($ok ? "PASS " : "FAIL ") . $name . "\n"; if (!$ok) $fail++; }

/** null = passed silently; array = the refusal body. */
function run(array $settings, string $method, ?string $auth, ?string $declared): ?array {
    $pdo = new _ScopePdo(); $pdo->settings = $settings;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['HTTP_HOST'] = 'fallback.example';
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_SNAP_SITE']);
    if ($auth !== null) $_SERVER['HTTP_AUTHORIZATION'] = $auth;
    if ($declared !== null) $_SERVER['HTTP_X_SNAP_SITE'] = $declared;
    try { snap_api_site_scope_check($pdo); return null; }
    catch (RuntimeException $e) { return json_decode($e->getMessage(), true); }
}

$own = ['site_url' => 'https://pixhellated.ca/'];
$B = 'Bearer ' . str_repeat('a', 64);

st(run($own + ['api_site_scope' => ''], 'POST', $B, 'other.example') === null,
   'dark by default: mismatch is ignored when api_site_scope is unset');
st(run($own + ['api_site_scope' => 'off'], 'POST', $B, 'other.example') === null,
   'off: mismatch is ignored');
st(run($own + ['api_site_scope' => 'enforce'], 'GET', $B, 'other.example') === null,
   'enforce: a READ aimed at another site is never refused');
st(run($own + ['api_site_scope' => 'enforce'], 'POST', null, 'other.example') === null,
   'enforce: no Bearer (session/admin) is never touched');
st(run($own + ['api_site_scope' => 'enforce'], 'POST', $B, null) === null,
   'enforce: a write with NO declared site (old tool) is allowed');
st(run($own + ['api_site_scope' => 'enforce'], 'POST', $B, 'pixhellated.ca') === null,
   'enforce: a write naming THIS site passes');
st(run($own + ['api_site_scope' => 'enforce'], 'POST', $B, 'https://PIXHELLATED.ca:443/api.php') === null,
   'enforce: full URL / case / port are normalised');
$r = run($own + ['api_site_scope' => 'enforce'], 'POST', $B, 'foundtextures.ca');
st(($r['code'] ?? '') === 'wrong_site' && ($r['declared'] ?? '') === 'foundtextures.ca' && ($r['site'] ?? '') === 'pixhellated.ca',
   'enforce: a write naming ANOTHER site is refused with both hosts named');
$r = run($own + ['api_site_scope' => 'require'], 'POST', $B, null);
st(($r['code'] ?? '') === 'site_scope_missing',
   'require: a write with no declared site is refused');
st(run($own + ['api_site_scope' => 'require'], 'POST', $B, 'pixhellated.ca') === null,
   'require: a correctly named write passes');
st(run(['api_site_scope' => 'enforce'], 'POST', $B, 'fallback.example') === null,
   'no site_url setting: falls back to HTTP_HOST');
st(run($own + ['api_site_scope' => 'enforce'], 'DELETE', $B, 'foundtextures.ca') !== null,
   'enforce: DELETE counts as a write');

// wiring: both API doors include the module and call the check
$auth = file_get_contents($root . '/core/api-auth.php');
$api  = file_get_contents($root . '/api.php');
st(str_contains($auth, "api-site-scope.php") && str_contains($auth, 'snap_api_site_scope_check($pdo)'),
   'core/api-auth.php runs the check after a key authenticates');
st(str_contains($api, "core/api-site-scope.php") && str_contains($api, 'snap_api_site_scope_check($pdo)'),
   'api.php runs the check before dispatching any route');

echo $fail === 0 ? "ALL PASS\n" : "{$fail} FAILURE(S)\n";
exit($fail === 0 ? 0 : 1);

// ===== SNAPSMACK EOF =====
