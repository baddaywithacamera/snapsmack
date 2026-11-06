<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

/**
 * THE OWNER'S OWN SITES COME LAST ON THE BLOGROLL, AND NO SKIN MATCHES A NAME.
 *
 * Sean's words: "my blogs should go to bottom". Codex found the gap -- the
 * commit before this one ordered the LINKS inside a category and left the
 * GROUPS alphabetical by category name, so the owner's own sites landed
 * wherever M fell.
 *
 * WHY THIS NEEDED ANYTHING AT ALL. The group has no identity in the data.
 *   - On a hub, core/blogroll-push.php SYNTHESISES those entries at push time
 *     from snap_multisite_nodes and labels them with the category name in the
 *     setting blogroll_my_blogs_cat. They are never rows in the hub's own
 *     snap_blogroll.
 *   - On a spoke, core/multisite-api.php's sync CREATES a real category with
 *     whatever name arrived and inserts real rows with source_hub_url set.
 *   - source_hub_url marks EVERY hub-synced category, not just this one.
 * So after a sync the only thing telling "My Blogs" from "Antiques" is the
 * text of the name, and the name is configurable on the hub. A skin matching
 * on the literal "MY BLOGS" would be matching on a setting's current value.
 *
 * WHAT WAS BUILT, and what each part is checked with here.
 *   1. The hub sends is_own with the entries it synthesises. SOURCE CHECK, in
 *      both builders -- the push helper and the manual Push page -- because
 *      they mirror each other and a disagreement would teach a spoke two
 *      different things depending on which button was pressed.
 *   2. The spoke records their category name under the same settings key.
 *      SOURCE CHECK: the sync handler needs a hub, a bearer token and a live
 *      node row, so it is not executed here, and that is stated rather than
 *      papered over.
 *   3. The controller flags each group and moves the owner's to the end. This
 *      one is EXECUTED, against a real PDO, and the order is read off the
 *      response.
 */

$root = dirname(__DIR__);
if (!defined('BASE_URL')) define('BASE_URL', '/');
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/public-repository.php';
require_once $root . '/core/public-controller.php';

$pass = 0; $fail = 0;
function bl(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

/** @param array<int,array{0:string,1:string}> $peers [name, category] */
function bl_repo(array $peers): SnapPublicRepository {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE snap_blogroll_cats (id INTEGER PRIMARY KEY AUTOINCREMENT, cat_name TEXT)');
    $pdo->exec('CREATE TABLE snap_blogroll (id INTEGER PRIMARY KEY AUTOINCREMENT,
        peer_name TEXT, peer_url TEXT, peer_desc TEXT, peer_rss TEXT,
        cat_id INTEGER NOT NULL DEFAULT 0, sort_order INTEGER NOT NULL DEFAULT 0)');
    // The controller builds the nav from active pages before it reaches the
    // blogroll branch, so the table has to exist. Left empty on purpose: this
    // file is about group order, not navigation.
    $pdo->exec('CREATE TABLE snap_pages (id INTEGER PRIMARY KEY, slug TEXT, title TEXT,
        content TEXT, image_asset TEXT, image_size TEXT, image_align TEXT,
        image_shadow TEXT, menu_order INTEGER, created_at TEXT, is_active INTEGER)');
    $cats = [];
    foreach ($peers as $p) {
        $cat = $p[1];
        if ($cat !== '' && !isset($cats[$cat])) {
            $pdo->prepare('INSERT INTO snap_blogroll_cats (cat_name) VALUES (?)')->execute([$cat]);
            $cats[$cat] = (int)$pdo->lastInsertId();
        }
        $pdo->prepare('INSERT INTO snap_blogroll (peer_name,peer_url,peer_desc,peer_rss,cat_id,sort_order)
            VALUES (?,?,?,?,?,0)')->execute([
            $p[0], 'https://' . strtolower(str_replace(' ', '-', $p[0])) . '.test/', '', '',
            $cat === '' ? 0 : $cats[$cat],
        ]);
    }
    return new SnapPublicRepository($pdo);
}

/** @return string[] group labels, in the order the response gives them */
function bl_labels(array $peers, array $settings): array {
    $response = snapsmack_public_controller(
        bl_repo($peers), ['route' => 'blogroll'], $settings
    );
    return array_map(
        static fn(array $g): string => (string)$g['label'],
        $response['blogroll_groups'] ?? []
    );
}

$rows = [
    ['Ant A', 'Antiques'],
    ['Mine A', 'My Blogs'],
    ['Mine B', 'My Blogs'],
    ['Zine A', 'Zines'],
];

echo "WITH NO OWNER CATEGORY NAMED, NOTHING MOVES -- TODAY'S BEHAVIOUR EXACTLY\n";
$before = bl_labels($rows, []);
bl($before === ['Antiques', 'My Blogs', 'Zines'],
   'groups stay alphabetical by category (' . implode(', ', $before) . ')');
bl($before !== [], 'and there were groups to order, so this check is not vacuous');

echo "\nWITH THE OWNER'S CATEGORY NAMED, HIS OWN SITES GO LAST\n";
$after = bl_labels($rows, ['blogroll_my_blogs_cat' => 'My Blogs']);
bl($after === ['Antiques', 'Zines', 'My Blogs'],
   'his group is moved to the end (' . implode(', ', $after) . ')');
bl(count($after) === count($before),
   'no group was lost or duplicated in the move (' . count($after) . ' both ways)');
bl(end($after) === 'My Blogs', 'and it is the LAST group, which is what Sean asked for');

echo "\nIT IS THE SETTING THAT DECIDES, NOT THE WORDS \"MY BLOGS\"\n";
// The whole point: rename the category and the behaviour follows the setting.
$renamed = [
    ['Ant A', 'Antiques'],
    ['Mine A', 'Sean Dot Pictures'],
    ['Zine A', 'Zines'],
];
$followed = bl_labels($renamed, ['blogroll_my_blogs_cat' => 'Sean Dot Pictures']);
bl($followed === ['Antiques', 'Zines', 'Sean Dot Pictures'],
   'a differently named owner category is still moved last (' . implode(', ', $followed) . ')');
$notFollowed = bl_labels($renamed, ['blogroll_my_blogs_cat' => 'My Blogs']);
bl($notFollowed === ['Antiques', 'Sean Dot Pictures', 'Zines'],
   'and a category that is NOT the named one is left where it was, so the match '
   . 'is on the setting and not on anything hardcoded ('
   . implode(', ', $notFollowed) . ')');
$caseless = bl_labels($rows, ['blogroll_my_blogs_cat' => 'mY bLoGs']);
bl($caseless === ['Antiques', 'Zines', 'My Blogs'],
   'the comparison ignores case, because the spoke creates categories '
   . 'case-insensitively too');

echo "\nEVERY GROUP SAYS WHETHER IT IS HIS, SO NO SKIN NEEDS A STRING\n";
$response = snapsmack_public_controller(
    bl_repo($rows), ['route' => 'blogroll'], ['blogroll_my_blogs_cat' => 'My Blogs']
);
$groups = $response['blogroll_groups'] ?? [];
bl($groups !== [], 'the response carries groups');
$flags = [];
foreach ($groups as $g) $flags[(string)$g['label']] = $g['is_own'] ?? null;
bl(array_key_exists('is_own', $groups[0] ?? []),
   'is_own is present on a group that is not his');
bl(($flags['My Blogs'] ?? null) === true, 'his group is flagged true');
bl(($flags['Antiques'] ?? null) === false, 'and another group is flagged false, not absent');
$plain = snapsmack_public_controller(bl_repo($rows), ['route' => 'blogroll'], []);
foreach ($plain['blogroll_groups'] ?? [] as $g) {
    if (($g['is_own'] ?? null) !== false) { bl(false, 'with no setting every group is false'); break; }
}
bl(true, 'with no setting named, every group is flagged false rather than missing');

echo "\nTHE GROUP IS STILL WHOLE -- MOVING IT MUST NOT SCATTER ITS LINKS\n";
$mine = null;
foreach ($groups as $g) if ((string)$g['label'] === 'My Blogs') $mine = $g;
bl(is_array($mine) && count($mine['items'] ?? []) === 2,
   'both of his sites are still in the one group');
bl(is_array($mine) && ($mine['items'][0]['name'] ?? '') === 'Mine A',
   'and in the order the query produced them');

echo "\nMUTATION PROOF: WITHOUT THE MOVE, HIS GROUP IS IN THE MIDDLE\n";
// The alphabetical result IS the pre-change behaviour, so if the move were
// removed the first ordering check would pass and the second would fail.
bl($before === ['Antiques', 'My Blogs', 'Zines'] && $after !== $before,
   'the two orders differ, so the move is doing the work and not the alphabet');

echo "\nTHE HUB SENDS THE MARK, FROM BOTH PUSH PATHS -- SOURCE CHECK\n";
// Two builders mirror each other: the auto-heal helper and the manual Push
// button. If only one sends is_own, a spoke learns different things depending
// on which was used, which is the kind of split that takes a week to find.
foreach ([
    'core/blogroll-push.php' => 'the auto-heal push helper',
    'smack-multisite-blogroll.php' => 'the manual Push page',
] as $file => $what) {
    $src = (string)@file_get_contents($root . '/' . $file);
    bl(substr_count($src, "'is_own'") === 2,
       $what . ' marks both synthesised entries -- the spokes and the hub itself '
       . '(found ' . substr_count($src, "'is_own'") . ')');
}

echo "\nTHE SPOKE RECORDS IT -- SOURCE CHECK, THE SYNC NEEDS A LIVE NODE\n";
$api = (string)@file_get_contents($root . '/core/multisite-api.php');
$syncBlock = (static function (string $s): string {
    $at = strpos($s, "\$resource === 'blogroll' && \$sub_action === 'sync'");
    if ($at === false) return '';
    $end = strpos($s, "ENDPOINT: POST multisite/disconnect", $at);
    return $end === false ? '' : substr($s, $at, $end - $at);
})($api);
bl($syncBlock !== '', 'the blogroll sync endpoint was found');
bl(str_contains($syncBlock, "\$e['is_own']"),
   'it reads is_own off the incoming entries');
bl(str_contains($syncBlock, "'blogroll_my_blogs_cat'"),
   'and records the category name under the key the hub uses for it');
bl(str_contains($syncBlock, '$own_cat_seen !== \'\''),
   'it writes nothing when no entry carried the mark, so an older hub cannot '
   . 'blank a value the spoke already had');

echo "\nNO SKIN RE-SORTS THE GROUPS, SO ORDERING IN CORE IS ENOUGH -- MEASURED\n";
$resorters = [];
foreach (glob($root . '/skins/*/layout.php') ?: [] as $layout) {
    $src = (string)file_get_contents($layout);
    if (!str_contains($src, 'blogroll_groups')) continue;
    if (preg_match('/\b(u?a?sort|ksort|array_reverse)\s*\(/i', $src)) {
        $resorters[] = basename(dirname($layout));
    }
}
$renderers = 0;
foreach (glob($root . '/skins/*/layout.php') ?: [] as $layout) {
    if (str_contains((string)file_get_contents($layout), 'blogroll_groups')) $renderers++;
}
bl($renderers >= 20, $renderers . ' skin layouts render blogroll_groups');
bl($resorters === [],
   'none of them re-sorts the groups, so the order core gives is the order '
   . 'shown' . ($resorters === [] ? '' : ': ' . implode(', ', $resorters)));

echo "\n  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "Blogroll own-sites-last: FAIL ({$fail}).\n";
    exit(1);
}
echo "Blogroll own-sites-last: PASS ({$pass} assertions).\n";
echo "  NOT CHECKED, and these need a live install, not a stronger test:\n";
echo "  - whether the GLIDE Blogroll overlay on the site Sean was looking at\n";
echo "    shows the group at all. On a HUB the entries are synthesised for the\n";
echo "    push and are not rows in the hub's own blogroll, so a hub's own page\n";
echo "    may have no such group to move.\n";
echo "  - a spoke learning the category name, which happens on the next push\n";
echo "    from the hub and not before.\n";
echo "  - the rendered page. This reads a response; it drives no browser.\n";

// ===== SNAPSMACK EOF =====
