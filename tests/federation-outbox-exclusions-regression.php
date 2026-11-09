<?php
declare(strict_types=1);
// SNAPSMACK_EOF_HEADER: last line must be the canonical EOF marker.

// Execute the production outbox function against an isolated SQL database.
// Note serialization is stubbed: this verifies eligibility, counts and paging,
// not signatures, network delivery or installed endpoint authorization.
$source = file_get_contents(__DIR__ . '/../core/fediverse.php');
$start = strpos($source, 'function sv_outbox_doc(');
$end = strpos($source, "\nfunction ", $start + 1);
if ($start === false || $end === false) throw new RuntimeException('Outbox function missing');
eval(substr($source, $start, $end - $start));
function sv_outbox_url(array $settings): string { return 'https://fixture.invalid/ap/outbox'; }
function sv_note_for_image(PDO $pdo, array $row, array $settings): array { return ['id' => 'image:' . $row['id']]; }
function sv_note_for_post(PDO $pdo, array $row, array $settings): array { return ['id' => 'post:' . $row['id']]; }
function sv_note_for_longform(PDO $pdo, array $row, array $settings): array { return ['id' => 'post:' . $row['id']]; }
function sv_create_for_note(array $note, array $settings): array { return ['object' => $note]; }

$sqliteClass = class_exists('Pdo\\Sqlite') ? 'Pdo\\Sqlite' : PDO::class;
$pdo = new $sqliteClass('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$registerFunction = method_exists($pdo, 'createFunction') ? 'createFunction' : 'sqliteCreateFunction';
$pdo->$registerFunction('NOW', static fn() => '2026-11-08 12:00:00');
$pdo->exec('CREATE TABLE snap_images(id INTEGER PRIMARY KEY,img_status TEXT,img_date TEXT,post_id INTEGER,fedi_published_at TEXT)');
$pdo->exec('CREATE TABLE snap_posts(id INTEGER PRIMARY KEY,status TEXT,created_at TEXT,post_type TEXT,fedi_enabled INTEGER,fedi_published_at TEXT)');
$insert = $pdo->prepare('INSERT INTO snap_posts VALUES(?,?,?,?,?,NULL)');
for ($id = 1; $id <= 45; $id++) $insert->execute([$id, 'published', '2026-11-07 12:00:00', 'single', 1]);
$insert->execute([100, 'published', '2026-11-07 12:00:00', 'single', 0]);
$insert->execute([101, 'draft', '2026-11-07 12:00:00', 'single', 1]);
$insert->execute([102, 'published', '2026-11-09 12:00:00', 'single', 1]);
$insert->execute([103, 'published', '2026-11-07 12:00:00', 'longform', 1]);
$pdo->exec("INSERT INTO snap_images VALUES(1,'published','2026-11-07 12:00:00',NULL,NULL),(2,'draft','2026-11-07 12:00:00',NULL,NULL),(3,'published','2026-11-09 12:00:00',NULL,NULL),(4,'published','2026-11-07 12:00:00',1,NULL)");
$checks = 0;
function outbox_check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
    echo "PASS $message\n";
}
foreach (['photoblog' => 47, 'carousel' => 45, 'smacktalk' => 47] as $mode => $expected) {
    $settings = ['site_mode' => $mode];
    $shell = sv_outbox_doc($pdo, $settings);
    outbox_check($shell['totalItems'] === $expected, "$mode count follows publication and mode rules");
    $ids = [];
    for ($page = 1; $page <= 3; $page++) {
        $doc = sv_outbox_doc($pdo, $settings, $page);
        foreach ($doc['orderedItems'] as $item) $ids[] = $item['object']['id'];
        outbox_check(isset($doc['next']) === ($page < 3), "$mode page $page next link agrees with count");
    }
    outbox_check(count($ids) === $expected && count(array_unique($ids)) === $expected, "$mode pages contain every eligible unit once");
    outbox_check(!array_intersect($ids, ['post:100','post:101','post:102','image:2','image:3','image:4']), "$mode excluded, draft, future and grouped-image rows absent");
    outbox_check(in_array('image:1', $ids, true) === ($mode !== 'carousel'), "$mode standalone image policy matches backfill");
    outbox_check(in_array('post:103', $ids, true) === ($mode !== 'carousel'), "$mode longform policy matches backfill");
}
$pdo->exec('UPDATE snap_posts SET fedi_enabled=0');
outbox_check(sv_outbox_doc($pdo, ['site_mode' => 'carousel'])['totalItems'] === 0, 'all excluded carousel has zero total');
outbox_check(sv_outbox_doc($pdo, ['site_mode' => 'carousel'], 1)['orderedItems'] === [], 'all excluded carousel has empty first page');
echo "Outbox eligibility: $checks checks passed; network and installed behavior NOT CHECKED.\n";
// ===== SNAPSMACK EOF =====
