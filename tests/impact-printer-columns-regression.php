<?php
declare(strict_types=1);

$manifestPath = __DIR__ . '/../skins/impact-printer/manifest.json';
$manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$handles = $manifest['require_scripts'] ?? [];

if (($manifest['version'] ?? '') !== '2.1.10') {
    fwrite(STDERR, "FAIL: IMPACT PRINTER must release the columns repair as 2.1.10.\n");
    exit(1);
}

if (!is_array($handles) || !in_array('smack-columns', $handles, true)) {
    fwrite(STDERR, "FAIL: IMPACT PRINTER does not declare the shared columns component.\n");
    exit(1);
}

echo "PASS: IMPACT PRINTER 2.1.10 loads the shared columns component.\n";
// ===== SNAPSMACK EOF =====
