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
    } elseif (str_contains($probe_line, 'wp-content')) {
        $failures[] = 'Probe Guard must not auto-ban wp-content asset requests';
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "probe-guard-regression: OK\n";
