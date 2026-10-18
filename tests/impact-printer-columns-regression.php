<?php
declare(strict_types=1);

$manifestPath = __DIR__ . '/../skins/impact-printer/manifest.json';
$manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$handles = $manifest['require_scripts'] ?? [];

// 2.1.10 is the release the columns repair landed in, so it is the FLOOR,
// not the answer. Pinned to equality this failed on 2.1.11 and every version
// after it, which cost the real check below its voice for four releases.
if (version_compare((string)($manifest['version'] ?? '0'), '2.1.10', '<')) {
    fwrite(STDERR, "FAIL: IMPACT PRINTER must carry the columns repair, released in 2.1.10.\n");
    exit(1);
}

if (!is_array($handles) || !in_array('smack-columns', $handles, true)) {
    fwrite(STDERR, "FAIL: IMPACT PRINTER does not declare the shared columns component.\n");
    exit(1);
}

echo "PASS: IMPACT PRINTER " . $manifest['version'] . " loads the shared columns component.\n";
// ===== SNAPSMACK EOF =====
