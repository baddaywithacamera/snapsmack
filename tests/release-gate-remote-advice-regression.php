<?php
// SNAPSMACK_EOF_HEADER: retain the canonical EOF marker on the final line.
// Execute the real gate's verdict call, not a second copy of its arguments.
$repo = dirname(__DIR__);
require $repo . '/tools/_build/release-tag-rules.php';
$gate = file_get_contents($repo . '/tests/agreed-rules-gate.php');
$start = strpos($gate, "require_once \$root . '/tools/_build/release-tag-rules.php';");
$start = strpos($gate, ';', $start) + 1;
$end = strpos($gate, '$check($verdict', $start);
$block = substr($gate, $start, $end - $start);
$scratch = sys_get_temp_dir() . '/snapsmack-remote-' . bin2hex(random_bytes(8));
mkdir($scratch);
$fail = 0;
$oldOverride = getenv('SNAPSMACK_GATE_ORIGIN_TAG');
putenv('SNAPSMACK_GATE_ORIGIN_TAG=unknown');
try {
    exec('git -C ' . escapeshellarg($scratch) . ' init --quiet', $out, $status);
    if ($status !== 0) throw new RuntimeException('Cannot create disposable git fixture');
    exec('git -C ' . escapeshellarg($scratch) . ' remote add Github https://example.invalid/repo.git');
    $root = $scratch;
    $declared = '0.7.974D'; $tagged = 'v0.7.974D';
    $tagCommit = 'old'; $headCommit = 'new';
    eval($block);
    foreach ([
        [!$verdict['ok'], 'unknown publication state still refuses'],
        [str_contains($verdict['message'], 'git ls-remote --tags Github'), 'real gate advice names the configured remote'],
        [!str_contains($verdict['message'], 'git ls-remote --tags origin'), 'real gate does not invent origin'],
    ] as [$ok, $label]) {
        echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
        if (!$ok) $fail++;
    }
} finally {
    putenv($oldOverride === false ? 'SNAPSMACK_GATE_ORIGIN_TAG' : 'SNAPSMACK_GATE_ORIGIN_TAG=' . $oldOverride);
    $resolvedScratch = realpath($scratch);
    if ($resolvedScratch === false || dirname($resolvedScratch) !== realpath(sys_get_temp_dir())
        || !str_starts_with(basename($resolvedScratch), 'snapsmack-remote-')) {
        throw new RuntimeException('Refusing cleanup outside the disposable fixture directory');
    }
    $remove = function (string $path) use (&$remove): void {
        foreach (scandir($path) as $name) {
            if ($name === '.' || $name === '..') continue;
            $child = $path . '/' . $name;
            is_dir($child) ? $remove($child) : unlink($child);
        }
        rmdir($path);
    };
    $remove($resolvedScratch);
}
exit($fail ? 1 : 0);
// ===== SNAPSMACK EOF =====
