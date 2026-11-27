<?php
declare(strict_types=1);

/**
 * SS-1633 — the unused-media DEFINITION, which is the dangerous half.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 *
 * A finder that misses one place an image can be referenced will offer to
 * delete a photograph that is in use, and the owner will believe it. So this
 * file does two different jobs and both matter:
 *
 *   1. IT RE-DERIVES THE EXPECTED SET FROM THE CANONICAL SCHEMA and compares
 *      it to the registry. It does NOT read the registry and check the
 *      registry. A check that builds its expectation from the thing it is
 *      checking passes by construction and proves nothing -- that is the fault
 *      that has bitten this repository repeatedly. A new image column added to
 *      the schema fails this test until somebody either registers it or
 *      excludes it WITH A REASON.
 *
 *   2. IT EXECUTES THE FINDER against a fixture that holds a reference in
 *      every single place, and the fixture's tables are written out BY HAND
 *      from the schema rather than generated from the registry. Generating
 *      them from the registry would mean a registry missing a table also had a
 *      fixture missing that table, and everything would pass.
 */

$root = dirname(__DIR__);
$checks = 0;
$fails  = [];
$check = static function (string $what, bool $ok) use (&$checks, &$fails): void {
    $checks++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . "\n";
    if (!$ok) { $fails[] = $what; }
};

require_once $root . '/core/media-usage.php';

// ---------------------------------------------------------------------------
// 1. THE REGISTRY AGAINST THE SCHEMA, derived independently
// ---------------------------------------------------------------------------
echo "\n-- the registry against the canonical schema --\n";
$sql = (string) @file_get_contents($root . '/database/schema/snapsmack_canonical.sql');
$check('the canonical schema is readable', $sql !== '');

preg_match_all('/CREATE TABLE IF NOT EXISTS `(\w+)`\s*\((.*?)\n\)\s*ENGINE=/s', $sql, $m, PREG_SET_ORDER);
$tables = [];
foreach ($m as $one) { $tables[$one[1]] = $one[2]; }
$check('more than ninety tables parsed out of it', count($tables) > 90);

// CANDIDATES, found by shape and not by my list:
//   (a) an integer column whose name mentions an image and ends in _id
//   (b) featured_asset_id, the one that says asset where it means image
//   (c) a POLYMORPHIC PAIR: a type column whose enum includes image, or whose
//       name is destination_type, beside an id column
$candidates = [];
foreach ($tables as $table => $body) {
    if ($table === 'snap_images') { continue; }   // it IS the image
    foreach (preg_split('/\n/', $body) as $line) {
        if (preg_match('/`(\w+)`\s+(?:INT|BIGINT|SMALLINT|MEDIUMINT)\b/i', $line, $c)) {
            $col = $c[1];
            if (preg_match('/(image|img)/i', $col) && preg_match('/_id$/', $col)) {
                $candidates[$table . '.' . $col] = true;
            }
            if ($col === 'featured_asset_id') { $candidates[$table . '.' . $col] = true; }
        }
    }
    // polymorphic: an enum naming 'image', or a free-text destination_type
    if (preg_match("/`(\w+_type)`\s+(?:enum|ENUM)\(([^)]*)\)/", $body, $t)
        && stripos($t[2], "'image'") !== false) {
        if (preg_match('/`(\w*(?:item|target|object|entity)_id)`\s+(?:INT|BIGINT)/i', $body, $i)) {
            $candidates[$table . '.' . $i[1]] = true;
        }
    }
    if (strpos($body, '`destination_type`') !== false
        && preg_match('/`(destination_id)`\s+(?:INT|BIGINT)/i', $body, $i)) {
        $candidates[$table . '.' . $i[1]] = true;
    }
}

// EXCLUSIONS, each with a reason. An exclusion is a decision and has to read
// like one; "it looked irrelevant" is how a reference gets missed.
$excluded = [
    // none at present. Kept as an explicit, empty list so that adding one is a
    // visible act rather than a quiet edit to the derivation above.
];
foreach ($excluded as $site => $why) { unset($candidates[$site]); }

$registered = [];
foreach (SNAP_MEDIA_REFERENCE_SITES as $site) {
    $registered[$site['table'] . '.' . $site['column']] = true;
}

$missing = array_diff_key($candidates, $registered);
$extra   = array_diff_key($registered, $candidates);
$check('every image-reference column in the schema is REGISTERED', $missing === []);
if ($missing) {
    echo "    NOT IN THE REGISTRY (register it, or exclude it with a reason):\n";
    foreach (array_keys($missing) as $s) { echo "      - {$s}\n"; }
}
$check('and every registered site still exists in the schema', $extra === []);
if ($extra) {
    echo "    IN THE REGISTRY BUT NOT IN THE SCHEMA:\n";
    foreach (array_keys($extra) as $s) { echo "      - {$s}\n"; }
}

// The four I missed when I filed the ticket. Named individually, because the
// whole value of this file is that these four cannot quietly leave again.
foreach ([
    'snap_comments.img_id'                  => 'a comment on a photograph',
    'snap_ap_likes.target_id'               => 'a fediverse like on a photograph',
    'snap_collection_items.item_id'         => 'a typed collection member',
    'snap_import_map.destination_id'        => 'an import record, type "media"',
] as $site => $what) {
    $check("the ticket missed {$site} ({$what}) and it is registered now",
        isset($registered[$site]));
}

// Each registered column must actually exist in that table in the schema.
$wrong = [];
foreach (SNAP_MEDIA_REFERENCE_SITES as $site) {
    $body = $tables[$site['table']] ?? '';
    if ($body === '' || strpos($body, '`' . $site['column'] . '`') === false) {
        $wrong[] = $site['table'] . '.' . $site['column'];
    }
    if (isset($site['type_column']) && strpos($body, '`' . $site['type_column'] . '`') === false) {
        $wrong[] = $site['table'] . '.' . $site['type_column'];
    }
}
$check('every registered table and column is spelled as the schema spells it', $wrong === []);
if ($wrong) { echo '    wrong: ' . implode(', ', $wrong) . "\n"; }

// Every site carries a kind and a sentence, because the owner is shown both.
$shapeOk = true;
foreach (SNAP_MEDIA_REFERENCE_SITES as $site) {
    if (!in_array($site['kind'] ?? '', ['use', 'record'], true)) { $shapeOk = false; }
    if (trim((string)($site['what'] ?? '')) === '') { $shapeOk = false; }
}
$check('every site declares use-or-record and a plain sentence', $shapeOk);
$check('the settings keys come from the signature contract, not a second copy',
    snap_media_setting_keys() === array_values(SNAP_SIGNATURE_DEFAULT_KEYS)
    && snap_media_setting_keys() !== []);

// ---------------------------------------------------------------------------
// 2. THE FINDER, EXECUTED. Fixture tables written out by hand from the schema.
// ---------------------------------------------------------------------------
echo "\n-- the finder, executed against a reference in every place --\n";
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ([
    'pc_prompts'             => 'id INTEGER PRIMARY KEY, image_id INTEGER',
    'snap_albums'            => 'id INTEGER PRIMARY KEY, cover_image_id INTEGER',
    'snap_ap_likes'          => 'id INTEGER PRIMARY KEY, target_type TEXT, target_id INTEGER',
    'snap_bucket_items'      => 'id INTEGER PRIMARY KEY, image_id INTEGER',
    'snap_categories'        => 'id INTEGER PRIMARY KEY, cover_image_id INTEGER',
    'snap_collection_items'  => 'id INTEGER PRIMARY KEY, image_id INTEGER, item_type TEXT, item_id INTEGER',
    'snap_collections'       => 'id INTEGER PRIMARY KEY, cover_image_id INTEGER',
    'snap_comments'          => 'id INTEGER PRIMARY KEY, img_id INTEGER',
    'snap_group_pool'        => 'id INTEGER PRIMARY KEY, image_id INTEGER',
    'snap_groups'            => 'id INTEGER PRIMARY KEY, cover_image_id INTEGER',
    'snap_gyss_delete_audit' => 'id INTEGER PRIMARY KEY, image_id INTEGER',
    'snap_image_album_map'   => 'id INTEGER PRIMARY KEY, image_id INTEGER',
    'snap_image_cat_map'     => 'id INTEGER PRIMARY KEY, image_id INTEGER',
    'snap_image_tags'        => 'id INTEGER PRIMARY KEY, image_id INTEGER',
    'snap_import_map'        => 'id INTEGER PRIMARY KEY, destination_type TEXT, destination_id INTEGER',
    'snap_oauth_media'       => 'id INTEGER PRIMARY KEY, image_id INTEGER',
    'snap_post_images'       => 'id INTEGER PRIMARY KEY, image_id INTEGER',
    'snap_posts'             => 'id INTEGER PRIMARY KEY, content TEXT, featured_asset_id INTEGER,'
                              . ' featured_image_id INTEGER, signature_image_id INTEGER,'
                              . ' signature_light_image_id INTEGER, signature_dark_image_id INTEGER',
    'snap_stats'             => 'id INTEGER PRIMARY KEY, image_id INTEGER',
    'snap_stats_daily'       => 'id INTEGER PRIMARY KEY, top_image_id INTEGER',
    'snap_mosaics'           => 'id INTEGER PRIMARY KEY, asset_ids TEXT',
    'snap_settings'          => 'setting_key TEXT PRIMARY KEY, setting_val TEXT',
    'snap_pages'             => 'id INTEGER PRIMARY KEY, content TEXT',
] as $t => $cols) { $pdo->exec("CREATE TABLE {$t} ({$cols})"); }

// Image 42 is referenced EVERYWHERE. Image 99 is referenced nowhere.
$USED = 42;
$pdo->exec("INSERT INTO pc_prompts (image_id) VALUES ({$USED})");
$pdo->exec("INSERT INTO snap_albums (cover_image_id) VALUES ({$USED})");
$pdo->exec("INSERT INTO snap_ap_likes (target_type, target_id) VALUES ('image', {$USED})");
$pdo->exec("INSERT INTO snap_bucket_items (image_id) VALUES ({$USED})");
$pdo->exec("INSERT INTO snap_categories (cover_image_id) VALUES ({$USED})");
$pdo->exec("INSERT INTO snap_collection_items (image_id) VALUES ({$USED})");
$pdo->exec("INSERT INTO snap_collection_items (item_type, item_id) VALUES ('image', {$USED})");
$pdo->exec("INSERT INTO snap_collections (cover_image_id) VALUES ({$USED})");
$pdo->exec("INSERT INTO snap_comments (img_id) VALUES ({$USED})");
$pdo->exec("INSERT INTO snap_group_pool (image_id) VALUES ({$USED})");
$pdo->exec("INSERT INTO snap_groups (cover_image_id) VALUES ({$USED})");
$pdo->exec("INSERT INTO snap_gyss_delete_audit (image_id) VALUES ({$USED})");
$pdo->exec("INSERT INTO snap_image_album_map (image_id) VALUES ({$USED})");
$pdo->exec("INSERT INTO snap_image_cat_map (image_id) VALUES ({$USED})");
$pdo->exec("INSERT INTO snap_image_tags (image_id) VALUES ({$USED})");
$pdo->exec("INSERT INTO snap_import_map (destination_type, destination_id) VALUES ('media', {$USED})");
$pdo->exec("INSERT INTO snap_oauth_media (image_id) VALUES ({$USED})");
$pdo->exec("INSERT INTO snap_post_images (image_id) VALUES ({$USED})");
$pdo->exec("INSERT INTO snap_posts (id, content, featured_asset_id) VALUES (1, '', {$USED})");
$pdo->exec("INSERT INTO snap_posts (id, content, featured_image_id) VALUES (2, '', {$USED})");
$pdo->exec("INSERT INTO snap_posts (id, content, signature_image_id) VALUES (3, '', {$USED})");
$pdo->exec("INSERT INTO snap_posts (id, content, signature_light_image_id) VALUES (4, '', {$USED})");
$pdo->exec("INSERT INTO snap_posts (id, content, signature_dark_image_id) VALUES (5, '', {$USED})");
$pdo->exec("INSERT INTO snap_stats (image_id) VALUES ({$USED})");
$pdo->exec("INSERT INTO snap_stats_daily (top_image_id) VALUES ({$USED})");
$pdo->exec("INSERT INTO snap_mosaics (id, asset_ids) VALUES (753, '[11,{$USED},9]')");
$pdo->exec("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('signature_default_light_image_id', '{$USED}')");
$pdo->exec("INSERT INTO snap_posts (id, content) VALUES (6, 'text [img:{$USED}|small|left] more')");
$pdo->exec("INSERT INTO snap_pages (id, content) VALUES (7, 'a page [mosaic-images:11,{$USED},9 layout=masonry] here')");

$r = snap_media_references($pdo, $USED);
$found = [];
foreach ($r['references'] as $ref) { $found[$ref['site'] . ($ref['via'] ?? '')] = true; }

$check('nothing was left unchecked on a complete install', $r['not_checked'] === []);
if ($r['not_checked']) {
    foreach ($r['not_checked'] as $nc) { echo "    unchecked: {$nc['site']} — {$nc['why']}\n"; }
}
// EVERY registered column site must come back, swept rather than spot-checked.
$blind = [];
foreach (SNAP_MEDIA_REFERENCE_SITES as $site) {
    if (!isset($found[$site['table'] . '.' . $site['column']])) {
        $blind[] = $site['table'] . '.' . $site['column'];
    }
}
$check('EVERY registered site reports the reference (' . count(SNAP_MEDIA_REFERENCE_SITES)
    . ' swept)', $blind === []);
if ($blind) { echo '    found nothing at: ' . implode(', ', $blind) . "\n"; }

$check('the mosaic member list is found through its JSON',
    isset($found['snap_mosaics.asset_ids']));
$check('the settings VALUE is found, which no column scan could see',
    isset($found['snap_settings']));
$check('the [img:ID] shortcode in a post body is found',
    isset($found['snap_posts.contentimg_shortcode']));
$check('the inline regroup with NO mosaic row is found in a page body',
    isset($found['snap_pages.contentinline_mosaic']));
// EXACT, NOT "AT LEAST". My first version of these two said 3 records and at
// least 22 uses, and the 3 was wrong: snap_import_map is a record as well as
// the delete audit and the two statistics tables, so it is 4. A >= would have
// hidden that, and it would hide a site changing kind later, which is exactly
// the thing Sean is being asked to rule on.
//   records: snap_gyss_delete_audit, snap_import_map, snap_stats, snap_stats_daily
//   uses:    the other 21 columns, plus the mosaic list, the settings value,
//            the post-body shortcode and the page-body inline regroup = 25
$recordSites = [];
foreach ($r['references'] as $ref) {
    if ($ref['kind'] === 'record') { $recordSites[] = $ref['site']; }
}
sort($recordSites);
$check('exactly the four record sites are records, named not counted',
    $recordSites === ['snap_gyss_delete_audit.image_id', 'snap_import_map.destination_id',
                      'snap_stats.image_id', 'snap_stats_daily.top_image_id']);
$check('and the remaining 25 references are uses', $r['uses'] === 25);
$check('29 reference entries in all, which is 25 columns plus the four '
    . 'places with no column to find an id by', count($r['references']) === 29);
$check('a photograph referenced anywhere is never offered for purge',
    snap_media_purge_blocker($r) !== null);

// ---------------------------------------------------------------------------
// 3. THE UNUSED CASE, and the traps around it
// ---------------------------------------------------------------------------
echo "\n-- an unused photograph, and the near misses --\n";
$u = snap_media_references($pdo, 99);
$check('an unreferenced photograph reports no uses and no records',
    $u['uses'] === 0 && $u['records'] === 0);
$check('and nothing unchecked, so "unused" can actually be claimed',
    $u['not_checked'] === []);
$check('so it IS offered as a candidate', snap_media_purge_blocker($u) === null);

// THE 7530 TRAP. The renderer's pattern, not a LIKE.
$pdo->exec("INSERT INTO snap_posts (id, content) VALUES (8, 'decoy [img:9942] and [img:4299]')");
$trap = snap_media_references($pdo, 4299);
$trapFound = false;
foreach ($trap['references'] as $ref) {
    if (($ref['site'] ?? '') === 'snap_posts.content') { $trapFound = true; }
}
$check('[img:4299] is found for 4299', $trapFound);
$again = snap_media_references($pdo, 99);
$check('and [img:9942] is NOT read as a reference to 99', $again['uses'] === 0);
$check('nor [img:4299] read as a reference to 42 beyond its real places',
    count(array_filter(snap_media_references($pdo, 42)['references'],
        static fn($x) => ($x['site'] ?? '') === 'snap_posts.content')) === 1);

// THE POLYMORPHIC TRAP IN BOTH DIRECTIONS. A like on a POST that happens to
// carry the same id must not count as a like on the photograph.
$pdo->exec("INSERT INTO snap_ap_likes (target_type, target_id) VALUES ('post', 99)");
$pdo->exec("INSERT INTO snap_import_map (destination_type, destination_id) VALUES ('post', 99)");
$pdo->exec("INSERT INTO snap_collection_items (item_type, item_id) VALUES ('post', 99)");
$poly = snap_media_references($pdo, 99);
$check('a like on a POST with the same id is not a reference to the photograph',
    $poly['uses'] === 0 && $poly['records'] === 0);
$check('nor an import whose destination type is post rather than media',
    snap_media_purge_blocker($poly) === null);

// ---------------------------------------------------------------------------
// 4. FAIL CLOSED. The property that stops this tool deleting a photograph.
// ---------------------------------------------------------------------------
echo "\n-- fail closed: could not look is not the same as nothing there --\n";
$bare = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$bare->exec('CREATE TABLE snap_posts (id INTEGER PRIMARY KEY, content TEXT, featured_image_id INTEGER)');
$bare->exec('CREATE TABLE snap_images (id INTEGER PRIMARY KEY)');
$b = snap_media_references($bare, 42);
$check('an un-migrated site reports the places it could not read',
    count($b['not_checked']) > 15);
$check('it does NOT report them as "no reference found"', $b['uses'] === 0 && $b['records'] === 0);
$check('AND IT REFUSES TO OFFER THE PHOTOGRAPH FOR PURGE',
    snap_media_purge_blocker($b) !== null);
$check('the refusal says it could not check everywhere, not that it is in use',
    str_contains((string)snap_media_purge_blocker($b), 'not checked everywhere'));

// A site missing ONE table is still refused. One blind spot is enough.
$one = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ([
    'pc_prompts' => 'image_id INTEGER', 'snap_albums' => 'cover_image_id INTEGER',
    'snap_bucket_items' => 'image_id INTEGER', 'snap_categories' => 'cover_image_id INTEGER',
    'snap_collection_items' => 'image_id INTEGER, item_type TEXT, item_id INTEGER',
    'snap_collections' => 'cover_image_id INTEGER', 'snap_comments' => 'img_id INTEGER',
    'snap_group_pool' => 'image_id INTEGER', 'snap_groups' => 'cover_image_id INTEGER',
    'snap_gyss_delete_audit' => 'image_id INTEGER', 'snap_image_album_map' => 'image_id INTEGER',
    'snap_image_cat_map' => 'image_id INTEGER', 'snap_image_tags' => 'image_id INTEGER',
    'snap_import_map' => 'destination_type TEXT, destination_id INTEGER',
    'snap_oauth_media' => 'image_id INTEGER', 'snap_post_images' => 'image_id INTEGER',
    'snap_posts' => 'id INTEGER PRIMARY KEY, content TEXT, featured_asset_id INTEGER,'
                  . ' featured_image_id INTEGER, signature_image_id INTEGER,'
                  . ' signature_light_image_id INTEGER, signature_dark_image_id INTEGER',
    'snap_stats' => 'image_id INTEGER', 'snap_stats_daily' => 'top_image_id INTEGER',
    'snap_mosaics' => 'id INTEGER PRIMARY KEY, asset_ids TEXT',
    'snap_settings' => 'setting_key TEXT PRIMARY KEY, setting_val TEXT',
    'snap_pages' => 'id INTEGER PRIMARY KEY, content TEXT',
    // snap_ap_likes DELIBERATELY ABSENT — the fediverse likes table
] as $t => $cols) { $one->exec("CREATE TABLE {$t} ({$cols})"); }
$o = snap_media_references($one, 42);
$check('ONE missing table is reported, not shrugged off', count($o['not_checked']) === 1);
$check('and one blind spot alone is enough to refuse the purge',
    snap_media_purge_blocker($o) !== null);

// ---------------------------------------------------------------------------
// 5. READ ONLY. The finder is not the purger.
// ---------------------------------------------------------------------------
echo "\n-- read only --\n";
$src = (string) @file_get_contents($root . '/core/media-usage.php');
$check('core/media-usage.php is readable', $src !== '');
foreach (['DELETE' => 'DELETE FROM', 'UPDATE' => 'UPDATE ', 'INSERT' => 'INSERT INTO',
          'DROP' => 'DROP ', 'TRUNCATE' => 'TRUNCATE', 'unlink' => 'unlink('] as $label => $needle) {
    // Comments are stripped first, so a sentence ABOUT deleting cannot pass or
    // fail this: the check must not match its own prose.
    $code = preg_replace('#/\*.*?\*/#s', '', $src);
    $code = preg_replace('#^\s*//.*$#m', '', (string)$code);
    $check("the finder contains no {$label} statement", stripos((string)$code, $needle) === false);
}

echo "\n";
if ($fails) {
    echo 'FAILED (' . count($fails) . " of {$checks}):\n";
    foreach ($fails as $f) { echo "  - $f\n"; }
    fwrite(STDERR, "\nThe unused-media definition is not held. A finder built on it could "
        . "offer to delete a photograph that is in use.\n");
    exit(1);
}
echo "ALL PASS: the unused-media definition holds ({$checks} checks)\n";

// NOT CHECKED HERE, and it is in the ticket as an owner decision: the COST of
// this scan on a real site of ten thousand photographs. Nobody has run it. The
// body scan reads every post and page body that contains a shortcode, once per
// photograph, which is the part that will not scale as written -- a real finder
// wants one pass collecting all referenced ids, not one pass per candidate.
// That is a performance decision that depends on where the finder lives, which
// is Sean's to make, so this file stays correct and simple rather than guessing.

// ===== SNAPSMACK EOF =====
