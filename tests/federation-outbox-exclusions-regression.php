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
foreach (['sv_post_row', 'sv_federated_post_row', 'sv_public_unit_count'] as $function) {
    $functionStart = strpos($source, 'function ' . $function . '(');
    $functionEnd = strpos($source, "\nfunction ", $functionStart + 1);
    // Stop at the closing brace, excluding any following docblock.
    $functionSource = substr($source, $functionStart, $functionEnd - $functionStart);
    $functionSource = substr($functionSource, 0, strrpos($functionSource, "\n}") + 2);
    eval($functionSource);
}
function sv_outbox_url(array $settings): string { return 'https://fixture.invalid/ap/outbox'; }
function sv_base(array $settings): string { return 'https://fixture.invalid/'; }
function sv_note_for_image(PDO $pdo, array $row, array $settings): array { return ['id' => 'image:' . $row['id']]; }
function sv_note_for_post(PDO $pdo, array $row, array $settings): array { return ['id' => 'post:' . $row['id']]; }
function sv_note_for_longform(PDO $pdo, array $row, array $settings): array { return ['id' => 'post:' . $row['id']]; }
function sv_create_for_note(array $note, array $settings): array { return ['object' => $note]; }
final class OutboxTestResponse extends RuntimeException {
    public function __construct(public int $status, public ?array $document = null) { parent::__construct(); }
}
function sv_404(): never { throw new OutboxTestResponse(404); }
function sv_respond(array $document): never { throw new OutboxTestResponse(200, $document); }
// Exercise the production Note dispatch, including the HTML hand-off. Blocked
// HTML requests must stop before the template renders or queries attachments.
$routeSource = file_get_contents(__DIR__ . '/../fediverse.php');
$routeStart = strpos($routeSource, "    case 'note':");
$routeEnd = strpos($routeSource, "    case 'inbox':", $routeStart);
$noteRoute = substr($routeSource, $routeStart, $routeEnd - $routeStart);
$noteRoute = str_replace("__DIR__ . '/core/public-post.php'", var_export(__DIR__ . '/../core/public-post.php', true), $noteRoute);

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
    outbox_check(sv_public_unit_count($pdo, $settings) === $expected, "$mode NodeInfo count matches outbox");
    outbox_check(sv_federated_post_row($pdo, 1, $settings) !== null, "$mode eligible direct post remains available");
    foreach ([100, 101, 102, 999] as $blockedId) {
        outbox_check(sv_federated_post_row($pdo, $blockedId, $settings) === null, "$mode direct post $blockedId unavailable");
    }
    outbox_check((sv_federated_post_row($pdo, 103, $settings) !== null) === ($mode !== 'carousel'), "$mode direct longform matches outbox policy");
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
    foreach (['post', 'longform'] as $routeKind) {
        foreach ([100, 101, 102, 999] as $blockedId) {
            $_GET = [$routeKind => $blockedId];
            $_SERVER['HTTP_ACCEPT'] = 'application/activity+json';
            $method = 'GET';
            try { eval("switch ('note') {" . $noteRoute . '}'); throw new RuntimeException('Route did not respond'); }
            catch (OutboxTestResponse $response) {
                outbox_check($response->status === 404, "$mode $routeKind JSON route rejects $blockedId");
            }
        }
    }
    foreach (['post' => 1, 'longform' => 103] as $routeKind => $allowedId) {
        $_GET = [$routeKind => $allowedId];
        try { eval("switch ('note') {" . $noteRoute . '}'); throw new RuntimeException('Route did not respond'); }
        catch (OutboxTestResponse $response) {
            $expectedStatus = ($mode === 'carousel' && $routeKind === 'longform') ? 404 : 200;
            outbox_check($response->status === $expectedStatus, "$mode eligible $routeKind JSON route follows mode policy");
        }
    }
    // The browser representation takes a separate path before JSON dispatch.
    if ($mode === 'photoblog') {
        $_GET = ['post' => 100];
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        try { eval("switch ('note') {" . $noteRoute . '}'); throw new RuntimeException('HTML route did not respond'); }
        catch (OutboxTestResponse $response) {
            outbox_check($response->status === 404, "$mode HTML route rejects excluded post before rendering");
        }
    }
}
$pdo->exec('UPDATE snap_posts SET fedi_enabled=0');
outbox_check(sv_outbox_doc($pdo, ['site_mode' => 'carousel'])['totalItems'] === 0, 'all excluded carousel has zero total');
outbox_check(sv_outbox_doc($pdo, ['site_mode' => 'carousel'], 1)['orderedItems'] === [], 'all excluded carousel has empty first page');
echo "Outbox eligibility: $checks checks passed; network and installed behavior NOT CHECKED.\n";
// ===== SNAPSMACK EOF =====
