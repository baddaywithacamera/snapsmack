<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * The admin content preview carries the shared shortcode stylesheets, on EVERY
 * skin, because the CMS supplies them and not the skin.
 *
 * FOUND ON A LIVE MIGRATION. Codex reported it on mccormickphotography while
 * standing up the new site: the owner's visual check could not prove the public
 * layout, because the gallery stacked in the admin preview. The owner gate on
 * SS-1185 is "one representative portfolio migrated and verified" -- a preview
 * that does not render like the page cannot satisfy it.
 *
 * THE CAUSE, AND IT IS THE CHAPLIN FAULT AGAIN. A public page that runs
 * parseContent() gets shortcodes.css and columns.css from core/meta.php. The
 * preview runs the same parseContent() and then built its OWN asset list, which
 * named only the mosaic engine. Two lists that have to agree, which drifted --
 * the same cause as the containment check that was repaired twice this week.
 *
 * HOW BIG IT IS: 2 of 31 skins declare these handles themselves, SLICKR and
 * TILEZ. The other 29 did not get the stylesheets at all. I miscounted this
 * twice before counting properly, because a grep for "columns" matches
 * archive_columns, blogroll_columns, grid-template-columns and smack-columns,
 * and the last is the legacy handle for the columns JAVASCRIPT, not its CSS.
 *
 * AND HERE IS WHY NOTHING CAUGHT IT. The suite's one preview test,
 * tests/admin-content-preview-regression.php, renders with active_skin
 * 'tilez' -- one of the two skins that declares the files itself. It asserted
 * the mosaic stylesheet was present and it was right, on that skin. A test
 * pinned to the single configuration where a gap is invisible passes forever.
 *
 * SO THIS TEST USES ALFRED DELIBERATELY: the skin on the site that found the
 * fault, and representative of the 29. It declares nothing of its own, so a
 * stylesheet in its preview can only have come from the CMS rail. TILEZ then
 * serves as the de-duplication case, since it declares what the CMS now also
 * supplies.
 *
 * NOT COVERED: this proves the stylesheet LINKS are in the rendered preview. It
 * does not prove the resulting geometry, which needs a browser; the repo's
 * measuring tools are tools/_build/render-geometry-fixtures.php and
 * measure-geometry-matrix.cjs, and the owner's own check remains the gate.
 */

define('BASE_URL', 'https://preview.invalid/');
require_once dirname(__DIR__) . '/core/admin-content-preview.php';

$root = dirname(__DIR__);
$failures = [];

function pv_check(string $label, bool $ok, array &$failures): void {
    if ($ok) {
        echo "  PASS  {$label}\n";
    } else {
        echo "  FAIL  {$label}\n";
        $failures[] = $label;
    }
}

/**
 * Read-only PDO, copied from tests/admin-content-preview-regression.php
 * rather than written again -- the preview is read-only by contract and that
 * fixture already enforces it by throwing on any write.
 */
class ShortcodeStylePDO extends PDO {
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new ShortcodeStyleStatement($query);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false {
        $statement = new ShortcodeStyleStatement($query);
        $statement->execute();
        return $statement;
    }
    public function exec(string $statement): int|false {
        throw new RuntimeException('Preview attempted a database write');
    }
}
class ShortcodeStyleStatement extends PDOStatement {
    private array $rows = [];
    public function __construct(private string $sql) {}
    public function execute(?array $params = null): bool {
        if (!preg_match('/^\s*(?:SELECT|SHOW)\b/i', $this->sql)) {
            throw new RuntimeException('Preview attempted a database write');
        }
        if (str_contains($this->sql, 'FROM snap_mosaics')) {
            $this->rows = [['asset_ids' => '[1,2,3]', 'focus_positions' => '{}', 'gap' => 4,
                            'emphasis' => 'natural', 'layout' => 'asymmetric']];
        } elseif (str_contains($this->sql, 'FROM snap_images WHERE id IN')) {
            for ($id = 1; $id <= 3; $id++) {
                $this->rows[] = ['id' => $id, 'img_title' => 'Photo ' . $id,
                    'img_alt' => 'Fixture photo', 'img_file' => 'fixture-' . $id . '.jpg',
                    'img_thumb_aspect' => '', 'img_width' => 1200, 'img_height' => 800];
            }
        } else {
            $this->rows = [];
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT,
                          int $cursorOffset = 0): mixed { return $this->rows[0] ?? false; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetchColumn(int $column = 0): mixed {
        return array_values($this->rows[0] ?? [0])[$column] ?? 0;
    }
}

echo "The admin preview carries the shared shortcode stylesheets\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — the premise: ALFRED declares NEITHER file\n";
// ─────────────────────────────────────────────────────────────────────────────
// Asserted, not assumed. If ALFRED ever declares them itself this test stops
// proving what it claims, and it should say so rather than quietly pass.
$alfred = (string)file_get_contents($root . '/skins/alfred/manifest.json');
pv_check('skins/alfred/manifest.json does not declare shortcodes.css',
         !str_contains($alfred, 'shortcodes'), $failures);
pv_check('and does not declare columns.css',
         !str_contains($alfred, 'columns'), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — the real preview, rendered\n";
// ─────────────────────────────────────────────────────────────────────────────
$pdo = new ShortcodeStylePDO();
$settings = [
    'active_skin' => 'alfred',
    'site_name'   => 'McCormick fixture',
    'site_author' => 'Photographer',
    'site_url'    => BASE_URL,
];
$html = snapsmack_admin_content_preview($pdo, $settings, [
    'preview_kind' => 'post',
    'title'        => 'PORTFOLIO FIXTURE',
    'content'      => "<p>Three across.</p>[columns=3]\nOne\nTwo\nThree\n[/columns]",
    'post_id'      => 0,
]);

// Assert the render HAPPENED before trusting anything about its contents. A
// thrown template makes every "contains" check below pass on an empty string,
// which is a trap I have already fallen into once this week.
pv_check('the preview rendered through the real ALFRED template',
         $html !== '' && str_contains($html, 'PORTFOLIO FIXTURE'), $failures);

pv_check('the preview carries shortcodes.css',
         str_contains($html, 'shortcodes.css'), $failures);
pv_check('the preview carries columns.css',
         str_contains($html, 'columns.css'), $failures);
pv_check('and still carries the mosaic engine stylesheet it already had',
         str_contains($html, 'ss-engine-mosaic.css'), $failures);
pv_check('each one is a real stylesheet link, not a word in the page text',
         preg_match('#<link[^>]+shortcodes\.css#', $html) === 1
         && preg_match('#<link[^>]+columns\.css#', $html) === 1, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — linked once each, not twice\n";
// ─────────────────────────────────────────────────────────────────────────────
// A duplicated stylesheet link is harmless to look at and a sign the merge is
// not doing its job, so it is asserted rather than assumed.
foreach (['shortcodes.css', 'columns.css', 'ss-engine-mosaic.css'] as $sheet) {
    pv_check("{$sheet} is linked exactly once",
             preg_match_all('#<link[^>]+' . preg_quote($sheet, '#') . '#', $html) === 1,
             $failures);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — TILEZ declares them itself, and still gets one of each\n";
// ─────────────────────────────────────────────────────────────────────────────
// The real de-duplication case: a skin whose manifest names what the CMS now
// also supplies. core/asset-registry.php:57 runs array_unique over the merged
// handles; this proves it instead of trusting the function's name.
//
// TILEZ is also the skin the OLD preview test used, so this stage doubles as
// the record of why that test was green while 29 skins were broken.
$declaring = [];
foreach (glob($root . '/skins/*/manifest.json') ?: [] as $manifestFile) {
    $body = (string)file_get_contents($manifestFile);
    if (str_contains($body, 'asset:public:shortcodes')
        && str_contains($body, 'asset:public:columns')) {
        $declaring[] = basename(dirname($manifestFile));
    }
}
sort($declaring);
echo '  (skins declaring both by handle: ' . (implode(', ', $declaring) ?: 'none') . ")\n";
pv_check('the minority that declares them is small, so the CMS must supply the rest',
         count($declaring) < count(glob($root . '/skins/*/manifest.json') ?: []), $failures);

if (!in_array('tilez', $declaring, true)) {
    echo "  NOT CHECKED  tilez no longer declares these, so the duplicate case moved\n";
    $failures[] = 'NOT CHECKED: duplicate-suppression lost its fixture skin';
} else {
    $html2 = snapsmack_admin_content_preview($pdo, ['active_skin' => 'tilez',
        'site_name' => 'Dup fixture', 'site_author' => 'Photographer',
        'site_url' => BASE_URL], ['preview_kind' => 'post', 'title' => 'DUP FIXTURE',
        'content' => '<p>Body.</p>', 'post_id' => 0]);
    pv_check('the TILEZ preview rendered', str_contains($html2, 'DUP FIXTURE'), $failures);
    foreach (['shortcodes.css', 'columns.css'] as $sheet) {
        pv_check("TILEZ links {$sheet} exactly once, not twice",
                 preg_match_all('#<link[^>]+' . preg_quote($sheet, '#') . '#', $html2) === 1,
                 $failures);
    }
}

echo "\n";
if ($failures) {
    fwrite(STDERR, 'PREVIEW SHORTCODE STYLES FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(1);
}
echo "PASS — the admin preview links shortcodes.css and columns.css on a skin\n";
echo "that declares neither, and links each once on a skin that declares them.\n";
echo "NOT COVERED: the resulting geometry. This proves the stylesheets reach\n";
echo "the preview, not that the gallery lays out correctly -- that needs a\n";
echo "browser, and the owner's own visual check remains the gate.\n";
// ===== SNAPSMACK EOF =====
