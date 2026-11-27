<?php
declare(strict_types=1);

/**
 * SS-1617(a): Test for SMACKPRESS content-hash media deduplication.
 *
 * Extracts smackpress_find_media_by_hash(), smackpress_record_media_hash(),
 * and smackpress_ingest_or_reuse_media() directly from core/smackpress-api.php
 * and verifies that two posts importing the same signature image produce
 * exactly one asset in snap_images.
 *
 * Run: php core/tests/test_smackpress_media_dedupe.php
 *
 * SNAPSMACK_EOF_HEADER
 * // ===== SNAPSMACK EOF =====
 */

$src = file_get_contents(__DIR__ . '/../smackpress-api.php');
if ($src === false) {
    fwrite(STDERR, "FAIL: could not read core/smackpress-api.php\n");
    exit(1);
}

foreach (['smackpress_find_media_by_hash', 'smackpress_record_media_hash', 'smackpress_ingest_or_reuse_media'] as $fn) {
    if (!preg_match('/\nfunction ' . preg_quote($fn, '/') . '\(.*?\n\}/s', $src, $m)) {
        fwrite(STDERR, "FAIL: could not extract {$fn} from core/smackpress-api.php\n");
        exit(1);
    }
    eval($m[0]);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE snap_images (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    img_title TEXT NOT NULL,
    img_thumb_square TEXT NOT NULL,
    img_checksum TEXT DEFAULT NULL
)');

$sigBytes = "\x89PNG\r\n\x1a\n" . str_repeat('SIG_BYTES_', 16);
$photoBytes = "\x89PNG\r\n\x1a\n" . str_repeat('PHOTO_BYTES_', 16);

$tmp1 = tempnam(sys_get_temp_dir(), 'ss_sig1_');
$tmp2 = tempnam(sys_get_temp_dir(), 'ss_sig2_');
$tmp3 = tempnam(sys_get_temp_dir(), 'ss_pho3_');
file_put_contents($tmp1, $sigBytes);
file_put_contents($tmp2, $sigBytes);
file_put_contents($tmp3, $photoBytes);

$ingestCalls = 0;
$fakeIngest = static function (PDO $db, array $settings, array $file, array $opts) use (&$ingestCalls): array {
    $ingestCalls++;
    // Simulate GD re-encoding changing stored file bytes vs raw upload bytes.
    $storedHash = hash('sha256', 'reencoded:' . file_get_contents($file['tmp_name']));
    $title = pathinfo((string)$file['name'], PATHINFO_FILENAME);
    $thumb = '/uploads/thumbs/t_' . $title . '.png';
    $stmt = $db->prepare('INSERT INTO snap_images (img_title, img_thumb_square, img_checksum) VALUES (?, ?, ?)');
    $stmt->execute([$title, $thumb, $storedHash]);
    return [
        'ok'    => true,
        'id'    => (int)$db->lastInsertId(),
        'thumb' => $thumb,
        'title' => $title,
    ];
};

$fail = 0;
$check = static function (string $label, $got, $want) use (&$fail): void {
    if ($got !== $want) {
        $fail++;
        fwrite(STDERR, "FAIL: {$label}\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n");
    }
};

try {
    // Post 1 imports signature.png
    $r1 = smackpress_ingest_or_reuse_media($pdo, [], ['name' => 'post1-signature.png', 'tmp_name' => $tmp1], [], $fakeIngest);
    $check('post 1 signature ok', !empty($r1['ok']), true);
    $check('post 1 signature not deduplicated', $r1['deduplicated'], false);
    $check('post 1 signature id', (int)$r1['id'], 1);

    // Post 2 imports a byte-identical signature under a different filename
    $r2 = smackpress_ingest_or_reuse_media($pdo, [], ['name' => 'post2-signature.png', 'tmp_name' => $tmp2], [], $fakeIngest);
    $check('post 2 signature ok', !empty($r2['ok']), true);
    $check('post 2 signature deduplicated', $r2['deduplicated'], true);
    $check('post 2 signature reuses post 1 asset id', (int)$r2['id'], 1);
    $check('post 2 signature preserves existing thumb', $r2['thumb'], '/uploads/thumbs/t_post1-signature.png');

    // Different image still creates a distinct asset
    $r3 = smackpress_ingest_or_reuse_media($pdo, [], ['name' => 'post2-photo.png', 'tmp_name' => $tmp3], [], $fakeIngest);
    $check('distinct photo not deduplicated', $r3['deduplicated'], false);
    $check('distinct photo id', (int)$r3['id'], 2);

    $totalAssets = (int)$pdo->query('SELECT COUNT(*) FROM snap_images')->fetchColumn();
    $check('two posts with same signature + one distinct photo produce 2 assets total', $totalAssets, 2);
    $check('ingest invoked once for shared signature and once for distinct photo', $ingestCalls, 2);

    // Pre-existing asset matched directly via snap_images.img_checksum (e.g. un-reencoded match)
    $directBytes = 'DIRECT_CHECKSUM_BYTES';
    $tmp4 = tempnam(sys_get_temp_dir(), 'ss_dir4_');
    file_put_contents($tmp4, $directBytes);
    $pdo->prepare('INSERT INTO snap_images (img_title, img_thumb_square, img_checksum) VALUES (?, ?, ?)')
        ->execute(['legacy-direct', '/uploads/thumbs/t_legacy.png', hash('sha256', $directBytes)]);
    $r4 = smackpress_ingest_or_reuse_media($pdo, [], ['name' => 'legacy-reupload.png', 'tmp_name' => $tmp4], [], $fakeIngest);
    $check('direct img_checksum match deduplicated', $r4['deduplicated'], true);
    $check('direct img_checksum match reuses id 3', (int)$r4['id'], 3);
    @unlink($tmp4);
} finally {
    @unlink($tmp1);
    @unlink($tmp2);
    @unlink($tmp3);
}

if ($fail > 0) {
    fwrite(STDERR, "FAILED: {$fail} check(s)\n");
    exit(1);
}
echo "PASS: SS-1617(a) SMACKPRESS content-hash dedupe (two posts with same signature produce one asset).\n";
// ===== SNAPSMACK EOF =====
