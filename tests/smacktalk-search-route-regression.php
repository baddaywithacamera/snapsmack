<?php
// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====
declare(strict_types=1);
define('BASE_URL', 'https://example.test/');
require_once dirname(__DIR__) . '/core/smacktalk-public-controller.php';
final class SearchRouteStatement extends PDOStatement {
    public function __construct(public string $sql) {}
    public array $params = [];
    public function execute(?array $params = null): bool { $this->params = $params ?? []; return true; }
    public function fetchColumn(int $column = 0): mixed { return ($GLOBALS['search_route_collision'] ?? false); }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return []; }
}
final class SearchRoutePdo extends PDO {
    public ?SearchRouteStatement $last = null;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return $this->last = new SearchRouteStatement($query); }
}
function sr_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$pdo = new SearchRoutePdo();
if (($argv[1] ?? '') === '--collision') {
    $GLOBALS['search_route_collision'] = true;
    $r=snapsmack_smacktalk_request($pdo, [], ['requested_slug'=>'archive','search'=>'badlands','route_aliases'=>[]]);
    sr_check(($r['kind']??'')!=='archive', 'Owner archive slug was hijacked');
    echo "Owner archive slug preserved PASS\n";
    exit;
}
if (($argv[1] ?? '') === '--sql') {
    (new SnapPublicRepository($pdo))->archiveSearchPosts((string)($argv[2] ?? 'badlands'));
    echo json_encode(['sql'=>$pdo->last->sql,'params'=>$pdo->last->params]);
    exit;
}
foreach ([[], ['diary'=>'diary'], ['images'=>'archive']] as $aliases) {
    $r=snapsmack_smacktalk_request($pdo, [], ['requested_slug'=>'archive','search'=>'badlands','route_aliases'=>$aliases]);
    sr_check(($r['handled']??false) && ($r['kind']??'')==='archive', 'Canonical search did not reach archive');
    sr_check(($r['search_term']??'')==='badlands', 'Canonical search lost term');
    sr_check(!isset($r['redirect']), 'Canonical archive redirects to itself');
}
$r=snapsmack_smacktalk_request($pdo, [], ['requested_slug'=>'images','search'=>'dinosaur provincial','route_aliases'=>['images'=>'archive']]);
sr_check(($r['redirect']??'')==='https://example.test/archive?q=dinosaur+provincial', 'Old archive redirect lost query');
$r=snapsmack_smacktalk_request($pdo, [], ['view'=>'archive','search'=>'james','route_aliases'=>['images'=>'archive']]);
sr_check(($r['redirect']??'')==='https://example.test/archive?q=james', 'Explicit view redirect lost query');
$repo = new SnapPublicRepository($pdo);
$repo->archiveSearchPosts('dinosaur provincial');
sr_check(count($pdo->last->params) === 6, 'Post search does not bind both words');
foreach (['title LIKE ?', 'description LIKE ?', 'content LIKE ?', "status='published'", 'created_at <= NOW()'] as $clause) {
    sr_check(str_contains($pdo->last->sql, $clause), 'Post search lost field/publication scope: '.$clause);
}
require_once dirname(__DIR__).'/core/trusted-html.php';
require_once dirname(__DIR__).'/core/skin-render-helpers.php';
$html=(string)snap_render_component('search-summary', ['term'=>'missing <word>', 'posts'=>[], 'tiles'=>[]]);
sr_check(str_contains($html, 'No results found.') && str_contains($html, '&lt;word&gt;'), 'Empty search state or escaping wrong');
$html=(string)snap_render_component('search-summary', ['term'=>'badlands', 'posts'=>[['title'=>'Badlands post','url'=>'/badlands']], 'tiles'=>[]]);
sr_check(str_contains($html, 'href="/badlands"') && !str_contains($html, 'No results found.'), 'Matching post link missing');
echo "SMACKTALK canonical search and redirect terms PASS\n";
// ===== SNAPSMACK EOF =====
