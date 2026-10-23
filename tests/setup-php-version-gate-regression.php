<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/**
 * setup.php refuses to install on a PHP it cannot run on, and says so.
 *
 * The docblock at the top of setup.php claimed "Requirements: PHP 8.3+" from
 * the day it was written. Measured 23 Oct 2026: the file contained no
 * version_compare and no PHP_VERSION anywhere. It checked cURL, ZipArchive
 * and sodium, then installed on anything. An owner on an older PHP got a
 * deploy that looked successful and a site that could not run.
 *
 * Two things are tested, and the second is the one that is easy to lose:
 *
 *   1. The gate exists, refuses, and names both numbers -- what we need and
 *      what they have -- so the owner has something specific to take to their
 *      host.
 *
 *   2. setup.php stays parseable by PHP 7. A gate that cannot execute on the
 *      versions it exists to turn away is decoration: PHP fails to compile the
 *      whole file before any line of it runs, and the owner sees a blank white
 *      page. One union return type (string|false, PHP 8.0+) was enough to do
 *      that, and it was in the file.
 *
 * We deliberately do NOT test for a patch-level or CVE check. Refusing on an
 * unpatched PHP would tell the owner we vet their PHP, and then a CVE we miss
 * reads as our all-clear. setup.php recommends they confirm patches with their
 * host instead -- disclosure, not enforcement.
 */

function setup_gate_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root  = dirname(__DIR__);
$path  = $root . '/setup.php';
setup_gate_check(is_file($path), 'setup.php is missing.');
$src = (string)file_get_contents($path);

// --- 1. THE GATE ---

setup_gate_check(
    str_contains($src, "define('SETUP_PHP_MINIMUM'"),
    'setup.php no longer defines SETUP_PHP_MINIMUM. The minimum must be one named value, not a number repeated in the check and the message.'
);
setup_gate_check(
    (bool)preg_match('/version_compare\(\s*PHP_VERSION\s*,\s*SETUP_PHP_MINIMUM\s*,\s*[\'"]<[\'"]\s*\)/', $src),
    'setup.php does not compare PHP_VERSION against SETUP_PHP_MINIMUM. Without that comparison the stated requirement is a comment, which is what it was before 23 Oct 2026.'
);

// It must REFUSE, not warn. The refusal has to be a die() so nothing below it
// -- including the deploy POST handler -- can run.
if (!preg_match('/version_compare\(\s*PHP_VERSION\s*,\s*SETUP_PHP_MINIMUM.*?\n(.*?)\n\}/s', $src, $m)) {
    throw new RuntimeException('Could not read the body of the PHP version gate in setup.php.');
}
$body = $m[1];
setup_gate_check(
    str_contains($body, 'die('),
    'The PHP version gate in setup.php no longer calls die(). A gate that only prints a message lets the deploy POST handler further down the file run anyway.'
);
setup_gate_check(
    str_contains($body, 'PHP_VERSION'),
    'The refusal message must tell the owner which PHP version this server is actually running; otherwise they cannot tell their host what to change.'
);
setup_gate_check(
    str_contains($body, 'SETUP_PHP_MINIMUM'),
    'The refusal message must name the required version.'
);
setup_gate_check(
    str_contains($body, 'Nothing has been installed'),
    'The refusal must say plainly that nothing was installed and nothing changed. A red page with no such line reads as a half-finished install.'
);

// The minimum we ship must not drift below the one in the docblock.
preg_match("/define\('SETUP_PHP_MINIMUM',\s*'([^']+)'\)/", $src, $mv);
setup_gate_check(!empty($mv[1]), 'Could not read the value of SETUP_PHP_MINIMUM.');
setup_gate_check(
    version_compare($mv[1], '8.3.0', '>='),
    'SETUP_PHP_MINIMUM is ' . $mv[1] . ', below the 8.3 this project requires.'
);

// --- 2. STAYS RUNNABLE ON OLD PHP ---
//
// Syntax that did not exist before PHP 8.0. Any of these makes setup.php fail
// to compile on exactly the boxes the gate above is for, and the owner gets a
// blank page instead of the message.
$php8_only = [
    '/\)\s*:\s*[A-Za-z_]+\|/'        => 'a union return type',
    '/\bmatch\s*\(/'                => 'a match expression',
    '/\?->/'                        => 'the nullsafe operator',
    '/\?\?=/'                       => 'the null-coalescing assignment (PHP 7.4)',
    '/\bfn\s*\(/'                   => 'an arrow function (PHP 7.4)',
    '/#\[/'                         => 'an attribute',
    '/\breadonly\s+/'               => 'a readonly property',
    '/\bstr_contains\s*\(/'         => 'str_contains() (PHP 8.0 function)',
    '/\bstr_starts_with\s*\(/'      => 'str_starts_with() (PHP 8.0 function)',
];
foreach ($php8_only as $pattern => $what) {
    setup_gate_check(
        !preg_match($pattern, $src),
        'setup.php now uses ' . $what . '. That is PHP 8-only, so the file will not parse on the old PHP versions the version gate exists to turn away -- the owner sees a blank page instead of the refusal. Keep setup.php to PHP 7 syntax; it is the one file that has to run before we know what we are running on.'
    );
}

// --- 3. THE RECOMMENDATION, NOT A SECOND GATE ---

setup_gate_check(
    str_contains($src, 'current patch release'),
    'setup.php no longer recommends the owner confirm their PHP patch level with their host. The version gate only proves the version is new enough to run, not that it is patched.'
);
setup_gate_check(
    !preg_match('/\bphp_min_safe\b|\bCVE-/i', $src),
    'setup.php has grown a patch-level or CVE check. That was decided against on 23 Oct 2026: gating on a CVE list tells the owner we vet their PHP, and the next CVE we have not added then reads as our all-clear. Recommend, do not enforce.'
);

// --- 4. install.php REFUSES TOO ---
//
// Step 1 of install.php shows an environment check and hides the Continue
// button when PHP is too old. That is the UI only: $step comes from the
// request, so a POST of step=1b walks straight past it. The refusal has to be
// at the top of the file, before any step runs.
//
// install.php is NOT PHP 7-parseable (str_starts_with and friends) and making
// 2,500 lines of it so is not worth the risk, so this gate covers 8.0 through
// 8.2. setup.php is the file that has to work on anything, and it does.

$ipath = $root . '/install.php';
setup_gate_check(is_file($ipath), 'install.php is missing.');
$isrc = (string)file_get_contents($ipath);

setup_gate_check(
    str_contains($isrc, "define('INSTALL_PHP_MINIMUM'"),
    'install.php no longer defines INSTALL_PHP_MINIMUM.'
);
setup_gate_check(
    (bool)preg_match('/version_compare\(\s*PHP_VERSION\s*,\s*INSTALL_PHP_MINIMUM\s*,\s*.<..\s*\)/', $isrc),
    'install.php does not refuse below INSTALL_PHP_MINIMUM before its steps run. The step 1 environment check is not a gate: $step comes from the request, so a POST of step=1b skips it.'
);

// The refusal must come before the wizard, not after it.
$gate_at = strpos($isrc, 'INSTALL_PHP_MINIMUM');
$step_at = strpos($isrc, '$raw_step');
setup_gate_check(
    $gate_at !== false && $step_at !== false && $gate_at < $step_at,
    'The PHP version gate in install.php now sits below the step routing ($raw_step). It must refuse before any step can run.'
);

// One value, not a literal repeated in the gate and the step 1 row.
setup_gate_check(
    substr_count($isrc, "'8.3.0'") <= 1,
    'install.php has more than one hard-coded 8.3.0. The minimum belongs in INSTALL_PHP_MINIMUM only, or the gate and the environment check will drift apart.'
);

fwrite(STDOUT, "setup.php PHP version gate passed: refuses below {$mv[1]}, names both versions, PHP 7-parseable, recommends patch check without gating on it; install.php refuses before any step runs.\n");

// ===== SNAPSMACK EOF =====
