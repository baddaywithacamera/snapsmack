<?php
/** Regression coverage for the shared upload-directory execution guard. */

require_once __DIR__ . '/../core/upload-execution-guard.php';

$failures = [];
$guard = snapsmack_upload_execution_guard_content();

$must_contain = [
    '# SNAPSMACK-UPLOAD-EXECUTION-GUARD',
    '<FilesMatch "\\.(php|phtml|php3|php4|php5|php7|php8|phar|pht|cgi|pl)$">',
    '<IfModule mod_authz_core.c>',
    'Require all denied',
    '<IfModule !mod_authz_core.c>',
    'Order Allow,Deny',
    'Deny from all',
];
foreach ($must_contain as $needle) {
    if (strpos($guard, $needle) === false) {
        $failures[] = "Guard is missing: {$needle}";
    }
}

foreach (['php_flag', '.jpg', '.jpeg', '.png', '.gif', '.webp', '.svg'] as $forbidden) {
    if (stripos($guard, $forbidden) !== false) {
        $failures[] = "Guard must not contain: {$forbidden}";
    }
}

$maintenance = file_get_contents(__DIR__ . '/../smack-maintenance.php');
foreach (['img_uploads', 'media_assets', 'assets/img'] as $directory) {
    if (strpos($maintenance, "'{$directory}'") === false) {
        $failures[] = "Maintenance omits guarded directory: {$directory}";
    }
}

$temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'snapsmack-guard-' . bin2hex(random_bytes(6));
if (!mkdir($temp, 0700, true)) {
    $failures[] = 'Could not create temporary test directory.';
} else {
    if (!snapsmack_write_upload_execution_guard($temp)) {
        $failures[] = 'Guard writer failed.';
    } elseif (!snapsmack_upload_execution_guard_is_current($temp)) {
        $failures[] = 'Freshly written guard was not recognized.';
    }

    $guard_path = $temp . DIRECTORY_SEPARATOR . '.htaccess';
    if (is_file($guard_path)) {
        unlink($guard_path);
    }
    rmdir($temp);
}

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "PASS: upload execution guards are canonical and cover all web-served upload directories.\n";

