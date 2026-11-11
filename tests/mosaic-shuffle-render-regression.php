<?php
/**
 * SS-1185 — a shuffled gallery renders shuffled, and an unshuffled one does not.
 *
 * Six of the eleven galleries migrating from mccormickphotography.com were
 * owner-random in WordPress. They arrive as [mosaic:ID] in the post body and
 * core/parser.php expands them into the mosaic container a visitor sees, so
 * this is where "random order" has to become true or stay a stored flag that
 * changes nothing.
 *
 * THIS RENDERS THE REAL PARSER AGAINST A REAL DATABASE and reads the HTML it
 * produced. Not a source grep. Twice this week I shipped a test that asserted
 * something had been WRITTEN rather than that it BEHAVED, and the whole point
 * of a shuffle is behaviour: the ids must come out in a different order, and
 * the same ids must come out.
 *
 * WHAT IS PINNED:
 *   - OFF renders the authored order, every time, deterministically;
 *   - ON renders a permutation: same count, no duplicates, nothing lost;
 *   - ON actually reorders across repeated renders, so a no-op cannot pass;
 *   - the stored asset_ids are NEVER rewritten, so turning shuffle off gives
 *     the arranged sequence back exactly;
 *   - a mosaic that predates the column (NULL/absent) renders authored order.
 *
 * Needs SQLite via PDO. Skips cleanly, loudly, if it is unavailable, because a
 * silent skip is how a test stops testing without anyone noticing.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root  = dirname(__DIR__);
$fails = 0;

function ms(string $label, bool $ok): void {
    global $fails;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fails++;
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP mosaic shuffle render: no PDO sqlite driver in this PHP.\n";
    echo "SS-1185 mosaic shuffle render: SKIPPED (driver absent, NOT a pass)\n";
    exit(0);
}

require_once $root . '/core/trusted-html.php';
require_once $root . '/core/parser.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE snap_mosaics (
    id INTEGER PRIMARY KEY, title TEXT, asset_ids TEXT, focus_positions TEXT,
    gap INTEGER DEFAULT 4, emphasis TEXT DEFAULT "natural",
    layout TEXT DEFAULT "asymmetric", gallery_shuffle INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE snap_images (
    id INTEGER PRIMARY KEY, img_file TEXT, img_thumb_aspect TEXT,
    img_title TEXT, img_alt TEXT, img_width INTEGER, img_height INTEGER)');

$ids = [11, 7, 93, 4, 58, 2, 61, 19, 73, 40];
$ins = $pdo->prepare('INSERT INTO snap_images (id, img_file, img_thumb_aspect, img_title, img_alt, img_width, img_height)
                      VALUES (?, ?, ?, ?, ?, 1200, 800)');
foreach ($ids as $id) {
    $ins->execute([$id, "/media/{$id}.jpg", "/media/{$id}-aspect.jpg", "Photo {$id}", "Photo {$id}"]);
}

$mk = $pdo->prepare('INSERT INTO snap_mosaics (id, title, asset_ids, gallery_shuffle) VALUES (?, ?, ?, ?)');
$mk->execute([1, 'Authored',  json_encode($ids), 0]);
$mk->execute([2, 'Shuffled',  json_encode($ids), 1]);
$mk->execute([3, 'Legacy',    json_encode($ids), null]);   // predates the column

$parser = new SnapSmack($pdo);

/**
 * The image ids, in the order the rendered HTML actually puts them.
 *
 * Read out of the data-mosaic payload rather than scraped with a regex over
 * the markup. That payload IS what ss-engine-mosaic.js consumes to lay the
 * gallery out, so its order is the order a visitor sees -- and a regex over
 * the HTML missed every id anyway, because the JSON inside the attribute
 * escapes its slashes. Parsing the thing the engine parses is both exact and
 * closer to the truth.
 */
$rendered_order = static function (int $mosaic) use ($parser): array {
    $html = (string) $parser->parseContent('[mosaic:' . $mosaic . ']');
    if (!preg_match('/data-mosaic="([^"]*)"/', $html, $m)) {
        return [];
    }
    $tiles = json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true);
    if (!is_array($tiles)) {
        return [];
    }
    $out = [];
    foreach ($tiles as $tile) {
        if (isset($tile['id'])) $out[] = (int) $tile['id'];
    }
    return $out;
};

$authored = $rendered_order(1);
ms('the mosaic renders at all (ids found in the HTML)', $authored !== []);
if ($authored === []) {
    fwrite(STDERR, "\nSS-1185: nothing rendered; the rest cannot be judged.\n");
    exit(1);
}

// ── OFF: the authored order, deterministically ─────────────────────────────
ms('shuffle OFF renders the authored order', $authored === $ids);
$stable = true;
for ($i = 0; $i < 10; $i++) {
    if ($rendered_order(1) !== $ids) { $stable = false; break; }
}
ms('shuffle OFF is the same on every render (10 runs)', $stable);

// A mosaic written before the column existed must behave as OFF.
ms('a mosaic with no shuffle value renders the authored order', $rendered_order(3) === $ids);

// ── ON: a permutation, and actually reordered ──────────────────────────────
$sorted = $ids; sort($sorted);
$lost = $dupe = $count = 0;
$moved = false;
for ($i = 0; $i < 40; $i++) {
    $out = $rendered_order(2);
    if (count($out) !== count($ids)) { $count++; continue; }
    if (count(array_unique($out)) !== count($out)) $dupe++;
    $s = $out; sort($s);
    if ($s !== $sorted) $lost++;
    if ($out !== $ids) $moved = true;
}
ms('shuffle ON renders every photograph, none missing (40 runs)', $count === 0 && $lost === 0);
ms('shuffle ON never repeats a photograph (40 runs)',             $dupe === 0);
ms('shuffle ON actually reorders, so a no-op cannot pass',        $moved);

// ── The stored order is never rewritten ────────────────────────────────────
// This is the one that matters most: if rendering scrambled what was saved,
// turning shuffle off later would give back a sequence nobody authored, and
// there would be no way to recover the owner's arrangement.
$stored = json_decode((string) $pdo->query('SELECT asset_ids FROM snap_mosaics WHERE id = 2')->fetchColumn(), true);
ms('rendering a shuffled mosaic leaves asset_ids untouched', $stored === $ids);

$pdo->prepare('UPDATE snap_mosaics SET gallery_shuffle = 0 WHERE id = 2')->execute();
ms('turning shuffle off gives the arranged sequence back exactly', $rendered_order(2) === $ids);

if ($fails) {
    fwrite(STDERR, "\n{$fails} FAILURE(S): a shuffled gallery does not render shuffled, an unshuffled one "
        . "does not render in the owner's order, or a photograph was lost on the way.\n");
    exit(1);
}
echo "SS-1185 mosaic shuffle render: PASS\n";
exit(0);

// ===== SNAPSMACK EOF =====
