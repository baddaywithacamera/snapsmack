<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * The SMACKTALK archive must filter on the query its own search box posts.
 *
 * Every SMACKTALK skin draws a search box. TILEZ's posts to /archive with
 * ?q=. core/smacktalk-public-controller.php never read that parameter and
 * SnapPublicRepository::archivePhotographs() had no search argument to read
 * it into, so the archive answered every search with the whole library.
 *
 * Measured on baddaywithacamera.ca at 0.7.952D before this: /archive?q= with
 * a real phrase and with deliberate gibberish returned byte-identical pages,
 * 6,998 photographs each time. archive.php -- the photoblog road -- has
 * filtered on ?q= and ?search= for a long time; this road was built without
 * it. Verified on the same day that photowalk.ing, acolourlesslife.ca,
 * wateronthebrain.ca and foreverphotograph.ing all filter correctly, so this
 * was SMACKTALK mode alone.
 *
 * Three things have to hold, and each one on its own was enough to break it:
 * the router must carry the parameter, the controller must pass it on, and
 * the query must use it.
 */

require_once dirname(__DIR__) . '/core/public-repository.php';

$failures = 0;
function sas_check(bool $ok, string $what): void
{
    global $failures;
    if ($ok) return;
    $failures++;
    fwrite(STDERR, "FAIL: {$what}\n");
}

/** Captures the SQL and parameters instead of running them. */
final class SasStatement extends PDOStatement
{
    public array $bound = [];
    public function __construct(public string $sql) {}
    #[\ReturnTypeWillChange]
    public function execute(?array $params = null): bool { $this->bound = $params ?? []; return true; }
    #[\ReturnTypeWillChange]
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, ...$args): array { return []; }
}

final class SasPdo extends PDO
{
    public ?SasStatement $last = null;
    public function __construct() {}
    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []): SasStatement
    {
        return $this->last = new SasStatement($query);
    }
}

// --- the query itself -------------------------------------------------------

$pdo = new SasPdo();
$repository = new SnapPublicRepository($pdo);

$repository->archivePhotographs(10000, 0, 0, 0, '');
$plain = $pdo->last;
sas_check($plain !== null, 'no query was prepared for an unsearched archive');
sas_check(!str_contains((string)$plain?->sql, 'img_title LIKE'),
    'an unsearched archive built a search clause anyway');
$plainParams = count($plain?->bound ?? []);

$repository->archivePhotographs(10000, 0, 0, 0, 'wood');
$searched = $pdo->last;
sas_check(str_contains((string)$searched?->sql, 'i.img_title LIKE ?'),
    'a searched archive does not filter on the title');
sas_check(str_contains((string)$searched?->sql, 'i.img_description LIKE ?'),
    'a searched archive does not filter on the description');
sas_check(str_contains((string)$searched?->sql, 'snap_tags'),
    'a searched archive does not reach tags');
sas_check(str_contains((string)$searched?->sql, 'snap_albums'),
    'a searched archive does not reach album names');
sas_check(str_contains((string)$searched?->sql, 'snap_categories'),
    'a searched archive does not reach category names');
sas_check(in_array('%wood%', $searched?->bound ?? [], true),
    'the typed word never reached the query parameters');
sas_check(count($searched?->bound ?? []) === $plainParams + 6,
    'one word should add exactly six parameters; it added '
        . (count($searched?->bound ?? []) - $plainParams));

// Every word must match somewhere, so two words bind twice as much.
$repository->archivePhotographs(10000, 0, 0, 0, 'got wood');
sas_check(count($pdo->last?->bound ?? []) === $plainParams + 12,
    'a two-word search does not AND both words');
sas_check(in_array('%got%', $pdo->last?->bound ?? [], true)
    && in_array('%wood%', $pdo->last?->bound ?? [], true),
    'a two-word search lost one of its words');

// A leading # is a hashtag; the tag clause already covers it once stripped.
$repository->archivePhotographs(10000, 0, 0, 0, '#rust');
sas_check(in_array('%rust%', $pdo->last?->bound ?? [], true),
    'a hashtag search kept the # and would match nothing');

// A query of nothing but spaces is not a search.
$repository->archivePhotographs(10000, 0, 0, 0, '   ');
sas_check(!str_contains((string)$pdo->last?->sql, 'img_title LIKE'),
    'whitespace was treated as a search');

// Scope still works alongside it.
$repository->archivePhotographs(10000, 0, 7, 0, 'wood');
sas_check(str_contains((string)$pdo->last?->sql, 'cm.cat_id=?')
    && str_contains((string)$pdo->last?->sql, 'i.img_title LIKE ?'),
    'a category filter and a search cannot be combined');

// --- the two call sites, which is where this was actually lost --------------

$controller = (string)file_get_contents(dirname(__DIR__) . '/core/smacktalk-public-controller.php');
sas_check(str_contains($controller, "\$request['search']"),
    'the SMACKTALK controller does not read the search parameter');
sas_check((bool)preg_match('/snapsmack_smacktalk_archive_tiles\([^;]*\$archiveSearch/s', $controller),
    'the controller does not pass the search to the archive tiles');
sas_check((bool)preg_match('/archivePhotographs\([^)]*\$search\s*\)/', $controller),
    'the archive tiles do not pass the search to the query');

$router = (string)file_get_contents(dirname(__DIR__) . '/index.php');
$wanted = chr(39) . 'search' . chr(39) . ' => $_GET[' . chr(39) . 'q' . chr(39) . '] ?? $_GET[' . chr(39) . 'search' . chr(39) . ']';
sas_check(str_contains($router, $wanted),
    'index.php does not put ?q= or ?search= into the SMACKTALK request');

if ($failures > 0) {
    fwrite(STDERR, "\nSMACKTALK archive search regression FAILED with {$failures} problem(s).\n");
    exit(1);
}
print("SMACKTALK archive search filters: query, controller and router all carry the term.\n");
exit(0);
// ===== SNAPSMACK EOF =====
