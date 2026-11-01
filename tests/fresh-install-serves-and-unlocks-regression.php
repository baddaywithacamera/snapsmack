<?php
/**
 * Three faults that a fresh install walks into, all measured on 1 November 2026
 * against a real Apache 2.4.69 + PHP 8.5.3 FastCGI host (not php -S), installing
 * the 974D candidate tree from scratch.
 *
 *  SS-1154  core/htaccess-template carried four unguarded `php_value` lines and
 *           two 2.2-era `Order Allow,Deny` blocks. On any host where PHP is not
 *           mod_php, or mod_access_compat is not loaded, those are FATAL
 *           .htaccess errors: Apache answered 500 for EVERY url on the freshly
 *           installed site. updater_reconcile_htaccess() self-heals the live
 *           file from this template, so removing them by hand did not stick.
 *  SS-1149  smackback_init_from_disk() hashed core/.snapsmack-installer-state.php
 *           and install.php unlinked it four lines later, so the first admin
 *           login reported it MISSING and restricted admin access. install.php
 *           and setup.php were already excluded for exactly this reason; the
 *           state file was added later and never joined the list.
 *  SS-1156  core/smackback.php read $settings['smackback_alert_email'] with ?:
 *           instead of ??, and a fresh install never seeds that key, so the
 *           breach-alert path printed a PHP warning into the response body.
 *
 * Every check here fails if its fix is reverted; see
 * tools/_build/ for the mutation harness convention.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root     = realpath(__DIR__ . '/..');
$failures = [];

/**
 * Report any directive in $text that Apache treats as fatal when its module is
 * absent, unless it sits inside an <IfModule ...> block. Returns the offending
 * "line N: directive" strings.
 */
$unguarded = static function (string $text, string $label): array {
    $fatal = '/^\s*(php_value|php_flag|php_admin_value|php_admin_flag|Order|Allow\s+from|Deny\s+from|Satisfy)\b/i';
    $bad   = [];
    $depth = 0;
    foreach (explode("\n", $text) as $i => $line) {
        $trimmed = trim($line);
        if (stripos($trimmed, '<IfModule') === 0) {
            $depth++;
            continue;
        }
        if (stripos($trimmed, '</IfModule>') === 0) {
            $depth = max(0, $depth - 1);
            continue;
        }
        if ($depth === 0 && preg_match($fatal, $line, $m)) {
            $bad[] = $label . ' line ' . ($i + 1) . ': ' . $m[1];
        }
    }
    return $bad;
};

// 1. SS-1154 — the template the updater self-heals from.
$template = file_get_contents("$root/core/htaccess-template");
foreach ($unguarded($template, 'core/htaccess-template') as $hit) {
    $failures[] = "SS-1154 unguarded directive would 500 the whole site: $hit";
}

// 2. SS-1154 — the installer's own heredoc copy, which is what is live on a host
//    where the updater self-heal does not run.
$install = file_get_contents("$root/install.php");
if (!preg_match('/\$snapsmack_rules = <<<.HTACCESS.\n(.*?)\nHTACCESS;/s', $install, $m)) {
    $failures[] = 'SS-1154 install.php no longer holds a $snapsmack_rules heredoc to check';
} else {
    foreach ($unguarded($m[1], 'install.php $snapsmack_rules') as $hit) {
        $failures[] = "SS-1154 unguarded directive would 500 the whole site: $hit";
    }
}

// 3. SS-1149 — measured through the real function, not by reading its source.
if (!defined('SNAPSMACK_ROOT')) {
    define('SNAPSMACK_ROOT', $root);
}
require_once "$root/core/smackback.php";

$state_file = $root . '/core/.snapsmack-installer-state.php';
if (smackback_should_monitor($state_file)) {
    $failures[] = 'SS-1149 smackback_should_monitor() still monitors the installer '
        . 'state file — a fresh install locks the admin out at first login';
}
// Controls: the exclusion must be by name, not a blanket core/ or dotfile pass.
if (!smackback_should_monitor($root . '/core/db.php')) {
    $failures[] = 'control failed: core/db.php is no longer monitored';
}
if (!smackback_should_monitor($root . '/core/updater.php')) {
    $failures[] = 'control failed: core/updater.php is no longer monitored';
}
if (smackback_should_monitor($root . '/install.php')) {
    $failures[] = 'control failed: install.php became monitored again';
}

// 4. SS-1155 -- no unguarded call to a function PHP only defines on some platforms.
//    sys_getloadavg() is absent on Windows; unguarded it is a fatal error that takes
//    the entire admin dashboard down. core/mayhem-data.php guards it; smack-admin.php
//    did not, and the lockout of SS-1149 was hiding it.
foreach (['smack-admin.php', 'core/mayhem-data.php'] as $rel) {
    $src = file_get_contents("$root/$rel");
    foreach (['sys_getloadavg'] as $fn) {
        foreach (explode("
", $src) as $i => $line) {
            if (strpos($line, $fn . '(') === false) {
                continue;
            }
            if (strpos($line, "function_exists('" . $fn . "')") !== false) {
                continue;
            }
            if (preg_match('/^\s*(\*|\/\/|#)/', $line)) {
                continue;   // a comment mentioning it is not a call
            }
            $failures[] = "SS-1155 $rel line " . ($i + 1) . ": $fn() called without a "
                . 'function_exists() guard — fatal on any host that lacks it';
        }
    }
}

// 4. SS-1156 — no ?: on a settings key that a fresh install never seeds.
$smackback = file_get_contents("$root/core/smackback.php");
if (preg_match_all('/\$settings\[\'[^\']+\'\]\s*\?:/', $smackback, $hits)) {
    $failures[] = 'SS-1156 ' . count($hits[0]) . ' settings key(s) read with ?: instead of '
        . '?? in core/smackback.php: ' . implode(', ', $hits[0]);
}

if ($failures) {
    fwrite(STDERR, "FAIL:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "PASS: fresh install serves and does not self-lock (SS-1154, SS-1149, SS-1156, SS-1155)\n";

// ===== SNAPSMACK EOF =====
