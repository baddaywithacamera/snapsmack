<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/snapsmack-exact-media-' . bin2hex(random_bytes(6));
mkdir($fixture . '/core', 0700, true);
mkdir($fixture . '/img_uploads', 0700);
foreach (['image-ingest.php', 'palette-extract.php'] as $file) {
    copy($root . '/core/' . $file, $fixture . '/core/' . $file);
}
require $fixture . '/core/image-ingest.php';

$bytes = 'exact reproduction of the stored image bytes';
$checksum = hash('sha256', $bytes);
foreach (['first.png' => 'wrong bytes', 'draft.png' => $bytes, 'owned.png' => $bytes,
          'published.png' => $bytes, 'newer.png' => $bytes] as $name => $body) {
    file_put_contents($fixture . '/img_uploads/' . $name, $body);
}
file_put_contents($fixture . '/outside.png', $bytes);
try {
    $db = new PDO('sqlite::memory:');
    $db->exec('CREATE TABLE snap_images (id INTEGER PRIMARY KEY, img_title TEXT, img_file TEXT,
        img_thumb_square TEXT, img_checksum TEXT, img_status TEXT, post_id INTEGER)');
    $insert = $db->prepare('INSERT INTO snap_images VALUES (?,?,?,?,?,?,?)');
    foreach ([
        [1,'Missing','img_uploads/missing.png',null,$checksum,'published',null],
        [2,'Stale checksum','img_uploads/first.png',null,$checksum,'published',null],
        [3,'Private','img_uploads/draft.png',null,$checksum,'draft',null],
        [4,'Post owned','img_uploads/owned.png',null,$checksum,'published',10],
        [5,'Outside uploads','outside.png',null,$checksum,'published',null],
        [6,'Original signature','img_uploads/published.png','img_uploads/thumb.png',$checksum,'published',null],
        [7,'Another copy','img_uploads/newer.png',null,$checksum,'published',null],
    ] as $row) {
        $insert->execute($row);
    }
    $match = snap_ingest_find_exact_image($db, $checksum);
    if ($match !== ['ok'=>true,'id'=>6,'thumb'=>'img_uploads/thumb.png','title'=>'Original signature','reused'=>true]) {
        throw new RuntimeException('Reuse must return the first verified published gallery copy.');
    }
    if (snap_ingest_find_exact_image($db, hash('sha256', 'other image')) !== null) {
        throw new RuntimeException('Different stored bytes must remain a distinct upload.');
    }
    echo "SMACKPRESS exact media lookup: PASS\n";
} finally {
    foreach (glob($fixture . '/core/*.php') ?: [] as $file) unlink($file);
    foreach (glob($fixture . '/img_uploads/*.png') ?: [] as $file) unlink($file);
    unlink($fixture . '/outside.png');
    rmdir($fixture . '/core');
    rmdir($fixture . '/img_uploads');
    rmdir($fixture);
}

// ===== SNAPSMACK EOF =====
