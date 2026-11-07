<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

/**
 * A Media Library asset's CAPTION reaches the inline-image lightbox, and only
 * when it is set. (SS-1216)
 *
 * The owner-written caption (snap_assets.asset_caption, separate from asset_alt)
 * rides to the shared lightbox as a data-caption attribute emitted by the ONE
 * CMS render path -- the [img:ID] parser. An empty caption emits no attribute at
 * all, so the lightbox shows nothing: never the surrounding page or post title.
 * Caption and ALT are independent. This exercises the real parser method against
 * a database row, so the SELECT and the emission are proven, not asserted.
 *
 * Run: php -d extension=pdo_sqlite tests/asset-caption-lightbox-regression.php
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "SKIP: no pdo_sqlite. Re-run with -d extension=pdo_sqlite\n");
    exit(2);
}

define('BASE_URL', 'https://example.test/');
require_once dirname(__DIR__) . '/core/parser.php';

$pass = 0; $fail = 0;
function cx(bool $ok, string $what): void {
    global $pass, $fail;
    echo ($ok ? "  ok    " : "  FAIL  ") . $what . "\n";
    $ok ? $GLOBALS['pass']++ : $GLOBALS['fail']++;
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE snap_assets (
    id INTEGER PRIMARY KEY, asset_name TEXT, asset_path TEXT,
    asset_alt TEXT, asset_caption TEXT,
    asset_border_width INTEGER NOT NULL DEFAULT 0,
    asset_border_color TEXT NOT NULL DEFAULT '#000000')");
// id 1: both alt and caption set, and distinct.  id 2: alt only, caption empty.
$pdo->exec("INSERT INTO snap_assets (id,asset_name,asset_path,asset_alt,asset_caption) VALUES
    (1,'DSC_001.jpg','uploads/a.jpg','Fog over the bay','A quiet morning on the water'),
    (2,'DSC_002.jpg','uploads/b.jpg','Alt text only','')");

$ref = new ReflectionClass(SnapSmack::class);
$parser = $ref->newInstanceWithoutConstructor();
$pdoProp = $ref->getProperty('pdo');
$pdoProp->setValue($parser, $pdo);
$parseImages = $ref->getMethod('parseImages');

$withCaption = (string) $parseImages->invoke($parser, '[img:1]');
$noCaption   = (string) $parseImages->invoke($parser, '[img:2]');

cx(str_contains($withCaption, 'data-caption="A quiet morning on the water"'),
   'a set caption reaches the lightbox as data-caption');
cx(str_contains($withCaption, 'alt="Fog over the bay"'),
   'ALT is emitted independently and is not the caption');
cx(!str_contains($withCaption, 'data-caption="DSC_001.jpg"'),
   'the asset name (and no page/post title) is never used as the caption');

cx(!str_contains($noCaption, 'data-caption'),
   'an empty caption emits NO data-caption attribute (lightbox shows nothing)');
cx(str_contains($noCaption, 'alt="Alt text only"'),
   'an asset with no caption still renders its ALT');

// The caption is HTML-escaped in the attribute.
$pdo->exec("INSERT INTO snap_assets (id,asset_name,asset_path,asset_alt,asset_caption) VALUES
    (3,'c.jpg','uploads/c.jpg','','She said \"hello\" <there>')");
$escaped = (string) $parseImages->invoke($parser, '[img:3]');
cx(str_contains($escaped, 'data-caption="She said &quot;hello&quot; &lt;there&gt;"'),
   'a caption with quotes/angle brackets is escaped in the attribute');

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
