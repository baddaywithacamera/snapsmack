<?php
/**
 * SS-1356. A signature is furniture, not one of the photographs.
 *
 * Measured by Codex on https://baddaywithacamera.ca/the-idea at installed
 * 0.7.996D: the decorative signature rendered as class="... asset-full ..."
 * with data-lightbox-src, so it displayed full width, counted as one of the
 * page's two Photos, and clicking it opened the photo lightbox.
 *
 * ss-engine-lightbox.js is NOT misbehaving -- it wires every
 * img[data-lightbox-src] and that is its job. The parser hardcoded the hook
 * on every image it rendered, and the [img] grammar had no way to say "this
 * is an image but it is not one of the pictures". Width could be reduced;
 * nothing else could.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

require_once __DIR__ . '/../core/parser.php';

$fails = [];
$check = static function (string $what, bool $ok) use (&$fails): void {
    if (!$ok) $fails[] = $what;
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . "\n";
};

/** A parser whose asset lookup answers with one fixture row. */
function ss1356_parser(): SnapSmack {
    $row = ['path' => 'img_uploads/2026/signature.png', 'name' => 'Signature',
            'alt' => 'Sean McCormick', 'width' => 600, 'height' => 180];
    $pdo = new class($row) extends PDO {
        private array $row;
        public function __construct(array $row) { $this->row = $row; }
        public function query(string $q, ?int $m = null, mixed ...$a): PDOStatement|false {
            throw new PDOException('no settings table in this regression');
        }
        public function prepare(string $q, array $o = []): PDOStatement|false {
            return new class($this->row) extends PDOStatement {
                private array $row;
                public function __construct(array $row) { $this->row = $row; }
                public function execute(?array $p = null): bool { return true; }
                public function fetch(int $m = PDO::FETCH_DEFAULT, int $c = PDO::FETCH_ORI_NEXT, int $o = 0): mixed {
                    return $this->row;
                }
            };
        }
    };
    return new SnapSmack($pdo);
}

$p = ss1356_parser();

// --- THE REPORTED FAULT ----------------------------------------------------
$deco = $p->parseContent('[img:7|small|left|30|decorative]');
$check('A DECORATIVE IMAGE CARRIES NO LIGHTBOX HOOK',
    !str_contains($deco, 'data-lightbox-src'));
$check('so it cannot be a step in the viewer previous/next sequence',
    !str_contains($deco, 'data-lightbox-src'));
$check('and it does not promise a click with a zoom-in cursor',
    !str_contains($deco, 'cursor:zoom-in'));
$check('it is marked so a skin and anything downstream can tell it apart',
    str_contains($deco, 'snap-decorative') && str_contains($deco, 'snap-decorative-img'));

// THE MARKER MUST SURVIVE THE SANITISER, which is the layer my first version
// of this test did not look at. The parser's raw output is not what reaches a
// page: core/trusted-html.php strips every img attribute outside its
// allow-list, and the first attempt marked the image with data-decorative="1",
// which was silently removed. The test passed anyway, because it read the
// parser instead of the page. A marker that does not survive is no marker.
require_once __DIR__ . '/../core/trusted-html.php';
$sanitised = (string)snapsmack_trusted_html($deco);
$check('THE MARKER SURVIVES THE HTML SANITISER',
    str_contains($sanitised, 'snap-decorative'));
$check('and no lightbox hook appears after sanitising either',
    !str_contains($sanitised, 'data-lightbox-src'));
$check('a data- attribute would NOT have survived, which is why it is a class',
    !str_contains((string)snapsmack_trusted_html(
        '<img src="/a.png" alt="x" data-decorative="1">'), 'data-decorative'));
$check('it still renders as an image', str_contains($deco, '<img src='));
$check('it keeps its alt text, because decorative here means NOT A PHOTOGRAPH,'
     . ' not invisible to a screen reader',
    str_contains($deco, 'alt="Sean McCormick"'));

// --- THE EXPLICIT SMALL WIDTH MUST SURVIVE ---------------------------------
$check('the explicit display width is preserved',
    str_contains($deco, '--snap-image-width:30%'));
$check('and the alignment is preserved', str_contains($deco, 'align-left'));

// --- ORDINARY PHOTOGRAPHS MUST BE UNTOUCHED --------------------------------
$photo = $p->parseContent('[img:7]');
$check('AN ORDINARY IMAGE STILL LIGHTBOXES', str_contains($photo, 'data-lightbox-src'));
$check('and still gets the zoom-in cursor', str_contains($photo, 'cursor:zoom-in'));
$check('and is NOT marked decorative', !str_contains($photo, 'snap-decorative'));

$sized = $p->parseContent('[img:7|small|left|30]');
$check('an ordinary sized image is unchanged by this work',
    str_contains($sized, 'data-lightbox-src') && str_contains($sized, '--snap-image-width:30%'));

// --- THE GRAMMAR, IN THE FORMS SOMEBODY WILL ACTUALLY TYPE -----------------
$check('[img:7|decorative] works with no size or align given',
    !str_contains($p->parseContent('[img:7|decorative]'), 'data-lightbox-src'));
$check('[img:7|full|decorative] works', 
    !str_contains($p->parseContent('[img:7|full|decorative]'), 'data-lightbox-src'));
$check('[img:7|center|decorative] works',
    !str_contains($p->parseContent('[img:7|center|decorative]'), 'data-lightbox-src'));
$check('and the word is not swallowed as something else',
    !str_contains($p->parseContent('[img:7|decorative]'), 'decorative</'));

// A word that is not the token must not silently turn an image decorative.
$check('an unknown trailing word does not make an image decorative',
    !str_contains($p->parseContent('[img:7|ornamental]'), 'snap-decorative'));

echo (count($fails) ? "\nFAIL" : "\nPASS")
   . ": a decorative image is not treated as a photograph (SS-1356)\n";
exit(count($fails) ? 1 : 0);
// ===== SNAPSMACK EOF =====
