<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$generator = $root . '/tools/_build/generate-skin-parity-ledger.php';
$first = tempnam(sys_get_temp_dir(), 'skin-ledger-a-');
$second = tempnam(sys_get_temp_dir(), 'skin-ledger-b-');
if ($first === false || $second === false) {
    throw new RuntimeException('Could not allocate ledger fixtures.');
}

try {
    foreach ([$first, $second] as $destination) {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($generator)
            . ' ' . escapeshellarg('v0.7.772D') . ' ' . escapeshellarg($destination);
        passthru($command, $status);
        if ($status !== 0) {
            throw new RuntimeException('Skin parity ledger generator failed.');
        }
    }
    $firstJson = file_get_contents($first);
    $secondJson = file_get_contents($second);
    if ($firstJson === false || $firstJson !== $secondJson) {
        throw new RuntimeException('Skin parity ledger is not deterministic.');
    }
    $ledger = json_decode($firstJson, true, flags: JSON_THROW_ON_ERROR);
    $currentSkins = array_map(static fn (string $path): string => basename(dirname($path)), glob($root . '/skins/*/manifest.json') ?: []);
    sort($currentSkins, SORT_STRING);
    $baselineSkins = array_keys($ledger['skins'] ?? []);
    if (($ledger['skin_count'] ?? null) !== count($currentSkins) || $baselineSkins !== $currentSkins) {
        throw new RuntimeException('The baseline and current packaged skin inventories differ.');
    }
    if (($ledger['baseline_only_skins'] ?? null) !== ['beatbox'] || ($ledger['current_only_skins'] ?? null) !== []) {
        throw new RuntimeException('Historical Beatbox retirement is not explicitly accounted for.');
    }
    foreach (['instant-camera', 'game-on', 'parade'] as $skin) {
        if (empty($ledger['skins'][$skin]['templates']) || empty($ledger['skins'][$skin]['dom_classes'])) {
            throw new RuntimeException("{$skin} is missing structural baseline evidence.");
        }
    }
    if (!in_array('request', $ledger['skins']['instant-camera']['authority_findings']['skin-profile.php'] ?? [], true)) {
        throw new RuntimeException('The ledger failed to preserve the Instant Camera authority finding.');
    }
    echo "Skin parity ledger regression: PASS\n";
} finally {
    @unlink($first);
    @unlink($second);
}
