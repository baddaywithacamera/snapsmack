<?php
/**
 * SS1149: run the real installer against a configured scratch site.
 * SNAPSMACK_EOF_HEADER — preserve the canonical EOF marker below.
 */
$root = dirname(__DIR__);
$scratch = sys_get_temp_dir() . '/snapsmack-installer-refusal-' . bin2hex(random_bytes(6));
mkdir($scratch . '/core', 0777, true);
copy($root . '/install.php', $scratch . '/install.php');
// Including this file would be a security regression, even before session_start.
file_put_contents($scratch . '/core/db.php', '<?php throw new RuntimeException("Configuration was executed");');
$cases = [
    ['GET', '/install.php'], ['GET', '/install%2ephp'],
    ['GET', '/INSTALL.PHP'], ['GET', '/install.php/trailing'],
    ['POST', '/install%2Ephp'],
];
try {
    foreach ($cases as [$method, $uri]) {
        // The server resolves these paths before PHP; exercise the resolved
        // entry point with each request environment, without a session cookie.
        $runner = $scratch . '/run.php';
        $code = '<?php $_SERVER["REQUEST_METHOD"]=' . var_export($method, true)
            . '; $_SERVER["REQUEST_URI"]=' . var_export($uri, true) . ';'
            . 'register_shutdown_function(function(){echo json_encode(["status"=>http_response_code(),"session"=>session_status()]);});'
            . 'require __DIR__."/install.php";';
        file_put_contents($runner, $code);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1', $output, $exit);
        $result = json_decode(implode("\n", $output), true);
        $output = [];
        if ($exit !== 0 || $result !== ['status' => 404, 'session' => PHP_SESSION_NONE]
            || file_exists($scratch . '/core/.snapsmack-installer-state.php')) {
            throw new RuntimeException("Configured installer ran for {$method} {$uri}");
        }
    }
    echo "Configured installer refusal: PASS (5 resolved request variants; no session or state file)\n";
} finally {
    foreach (glob($scratch . '/core/*') as $path) unlink($path);
    unlink($scratch . '/run.php');
    unlink($scratch . '/install.php');
    rmdir($scratch . '/core');
    rmdir($scratch);
}
// ===== SNAPSMACK EOF =====
