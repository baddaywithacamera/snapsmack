<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be the PHP SNAPSMACK EOF marker.

require_once __DIR__ . '/../core/tile-like-control.php';

final class SmacktalkLikesStatement extends PDOStatement {
    public function __construct(public array $params = []) {}
    public function execute(?array $params = null): bool {
        $this->params = $params ?? [];
        return true;
    }
    public function fetchColumn(int $column = 0): mixed {
        return 'local-likes-fixture-salt';
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        return [42];
    }
}

final class SmacktalkLikesPDO extends PDO {
    public array $reads = [];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if (!str_contains($query, 'FROM snap_likes')) {
            throw new RuntimeException('Unexpected statement in fixture');
        }
        return $this->reads[] = new SmacktalkLikesStatement();
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false {
        if (!str_contains($query, 'download_salt')) {
            throw new RuntimeException('Unexpected query in fixture');
        }
        return new SmacktalkLikesStatement();
    }
}

function requireHeart(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$pdo = new SmacktalkLikesPDO();
$post = ['id' => 99, 'featured_image_id' => 42, 'featured_image_path' => 'images/42.jpg'];
$missing = ['id' => 101, 'featured_image_id' => 43, 'featured_image_path' => ''];
$feed = ['kind' => 'feed', 'posts' => [$post, $missing, ['id' => 102]]];
$archive = ['kind' => 'archive', 'tiles' => [['image_id' => 65, 'thumb' => '/65.jpg']]];
$single = ['kind' => 'single', 'post' => $post];
$off = ['community_likes_enabled' => '0'];

foreach ([$feed, $archive, $single] as $case) {
    requireHeart(snap_smacktalk_tile_likes($pdo, $off, $case) === $case, 'Owner-off mutated the view');
}
requireHeart(count($pdo->reads) === 0, 'Owner-off still queried likes');

$feedView = snap_smacktalk_tile_likes($pdo, [], $feed);
$archiveView = snap_smacktalk_tile_likes($pdo, [], $archive);
$singleView = snap_smacktalk_tile_likes($pdo, [], $single);
requireHeart(str_contains($feedView['posts'][0]['tile_like'] ?? '', 'data-tile-like="42"'), 'Feed used the wrong ID');
requireHeart(!isset($feedView['posts'][1]['tile_like']) && !isset($feedView['posts'][2]['tile_like']),
    'Feed emitted a heart for a missing photograph');
requireHeart(str_contains($archiveView['tiles'][0]['tile_like'] ?? '', 'data-tile-like="65"'), 'Archive used the wrong ID');
requireHeart(str_contains($singleView['post']['tile_like'] ?? '', 'data-tile-like="42"'), 'Solo used the wrong ID');
requireHeart(array_map(static fn($s) => $s->params[0], $pdo->reads) === [42, 65, 42],
    'State must use one lookup per view using its photograph ID');

echo "PASS SMACKTALK heart identity and owner-off behavior.\n";
// ===== SNAPSMACK EOF =====
