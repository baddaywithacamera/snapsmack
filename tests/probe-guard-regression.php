<?php
// A missing legacy WordPress asset is not an attack. The probe guard may block
// high-confidence exploit endpoints, but must never auto-ban an owner or import
// tool merely for requesting an old wp-content URL.

$template = file_get_contents(__DIR__ . '/../core/htaccess-template');
$failures = [];

if ($template === false) {
    $failures[] = 'Could not read core/htaccess-template';
} else {
    $probe_line = '';
    foreach (preg_split('/\R/', $template) as $line) {
        if (str_contains($line, 'probe-ban.php [L]')) {
            $probe_line = $line;
            break;
        }
    }
    if ($probe_line === '') {
        $failures[] = 'Probe Guard rewrite rule is missing';
    } else {
        if (str_contains($probe_line, 'wp-content')) {
            $failures[] = 'Probe Guard must not auto-ban wp-content asset requests';
        }
        // SS-1170: setup.php is SnapSmack's own bootstrap deployer, not a scanner
        // path. Banning it locked the owner out of the whole fleet for 30 days.
        if (preg_match('/\bsetup\\\\?\.php\b/', $probe_line)) {
            $failures[] = 'Probe Guard must not auto-ban setup.php, a file SnapSmack ships (SS-1170)';
        }
        // ...but the genuine scanner signatures must still be there — removing a
        // real exploit path would gut the guard. Spot-check a representative set.
        foreach (['wp-login\.php', 'xmlrpc\.php', '\.env', 'phpmyadmin', 'shell\.php', '\.git/config'] as $sig) {
            if (!str_contains($probe_line, $sig)) {
                $failures[] = "Probe Guard lost a genuine scanner signature: {$sig}";
            }
        }
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "probe-guard-regression: OK\n";
