<?php
/**
 * SS-1316. [img:ID|wall] must never render a photograph that is not there.
 *
 * A live SMACKPRESS migration uploaded 23 Gallery photographs on 0.7.993D.
 * Every original returned 200 and every wall thumbnail returned 404, so the
 * whole gallery showed broken. The same ids with |full rendered perfectly.
 *
 * THE CAUSE WAS NOT THE UPLOAD. core/parser.php built 'thumbs/wall_<file>',
 * and that string was the ONLY occurrence of wall_ in the entire product --
 * nothing has ever written such a file, for any image, on any site. So the
 * wall size has pointed at a 404 since the day it was added, for everyone.
 *
 * Fixing the uploader would have repaired nothing already on disk: every
 * photograph on every live site predates it. The renderer falls back instead,
 * and this test proves it falls back to something that EXISTS rather than to
 * another convention.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

require_once __DIR__ . '/../core/parser.php';

$root = dirname(__DIR__);
$rel  = 'img_uploads/ss1316-test';
$dir  = $root . '/' . $rel;
$made = [];
@mkdir($dir . '/thumbs', 0777, true);

function ss1316_put(string $path, array &$made): void {
    // A real 1x1 GIF, so is_file() is answering about a genuine image file.
    file_put_contents($path, base64_decode(
        'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
    $made[] = $path;
}

ss1316_put($dir . '/photo.jpg', $made);          // the original, always present
ss1316_put($dir . '/thumbs/a_photo.jpg', $made); // the aspect thumb ingest writes

$fails = [];
$check = static function (string $what, bool $ok) use (&$fails): void {
    if (!$ok) $fails[] = $what;
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . "\n";
};

/** A parser whose asset lookup returns our fixture row. */
function ss1316_parser(string $path, array $extra = []): SnapSmack {
    $row = array_merge(['path' => $path, 'name' => 'Test', 'alt' => 'Test',
                        'width' => 10, 'height' => 10], $extra);
    $pdo = new class($row) extends PDO {
        private array $row;
        public function __construct(array $row) { $this->row = $row; }
        // loadConfig() reads settings on construction; there is no database
        // here and the parser is expected to cope, as it does on a fresh site.
        public function query(string $q, ?int $m = null, mixed ...$a): PDOStatement|false {
            throw new PDOException('no settings table in this regression');
        }
        public function prepare(string $q, array $o = []): PDOStatement|false {
            return new class($this->row) extends PDOStatement {
                private array $row;
                public function __construct(array $row) { $this->row = $row; }
                public function execute(?array $p = null): bool { return true; }
                public function fetch(int $m = PDO::FETCH_DEFAULT, int $c = PDO::FETCH_ORI_NEXT, int $o = 0): mixed {
                    // snap_assets misses first (no such asset), snap_images answers.
                    return $this->row;
                }
            };
        }
    };
    return new SnapSmack($pdo);
}

$src = static function (string $html): string {
    return preg_match('/<img src="([^"]+)"/', $html, $m) ? $m[1] : '';
};

// --- THE DEFECT ------------------------------------------------------------
$html = ss1316_parser($rel . '/photo.jpg',
    ['thumb_aspect' => $rel . '/thumbs/a_photo.jpg'])->parseContent('[img:1|wall]');
$out  = $src($html);
$check('a wall image no longer asks for the wall_ file that never existed',
    strpos($out, 'wall_') === false);
$check('it uses the aspect thumbnail that ingest really writes',
    str_ends_with($out, $rel . '/thumbs/a_photo.jpg'));

// --- AND WHEN EVEN THAT IS MISSING -----------------------------------------
// The 23 migrated photographs are exactly this case: original on disk, no
// derivative of any kind.
unlink($dir . '/thumbs/a_photo.jpg');
array_pop($made);
$html = ss1316_parser($rel . '/photo.jpg')->parseContent('[img:1|wall]');
$out  = $src($html);
$check('with no derivative on disk it falls back to the ORIGINAL, not a guess',
    str_ends_with($out, $rel . '/photo.jpg'));
$check('and the fallback is a file that is actually there',
    is_file($root . '/' . ltrim(parse_url($out, PHP_URL_PATH) ?: '', '/'))
    || is_file($dir . '/photo.jpg'));

// --- small must not have been broken on the way past ------------------------
ss1316_put($dir . '/thumbs/t_photo.jpg', $made);
$html = ss1316_parser($rel . '/photo.jpg')->parseContent('[img:1|small]');
$check('small still uses the square thumbnail when it exists',
    str_ends_with($src($html), $rel . '/thumbs/t_photo.jpg'));

unlink($dir . '/thumbs/t_photo.jpg');
array_pop($made);
$html = ss1316_parser($rel . '/photo.jpg')->parseContent('[img:1|small]');
$check('and small falls back too rather than 404ing',
    str_ends_with($src($html), $rel . '/photo.jpg'));

// --- full is ALWAYS the original, even when a thumbnail exists --------------
// The first version of the fix sent every size through the fallback, so |full
// started serving the aspect thumb: the one size whose whole job is to be the
// real photograph, quietly degraded. This is the check that caught it.
ss1316_put($dir . '/thumbs/a_photo.jpg', $made);
$html = ss1316_parser($rel . '/photo.jpg',
    ['thumb_aspect' => $rel . '/thumbs/a_photo.jpg'])->parseContent('[img:1|full]');
$check('full renders the ORIGINAL even though a thumbnail is available',
    str_ends_with($src($html), $rel . '/photo.jpg'));

// --- nothing in the tree still builds the imaginary path --------------------
$parser_src = (string)file_get_contents($root . '/core/parser.php');
$check('core/parser.php no longer constructs a wall_ path',
    !preg_match("/'thumbs\/wall_'/", $parser_src));

foreach (array_reverse($made) as $f) { @unlink($f); }
@rmdir($dir . '/thumbs');
@rmdir($dir);

echo (count($fails) ? "\nFAIL" : "\nPASS")
   . ": the wall shortcode never points at a missing file (SS-1316)\n";
exit(count($fails) ? 1 : 0);
// ===== SNAPSMACK EOF =====
