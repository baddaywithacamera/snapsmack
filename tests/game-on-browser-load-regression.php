<?php
require_once __DIR__ . '/strict-skin-feature-helper.inc';
snapsmack_assert_strict_skin_feature('game-on');
[$repository, $engine] = [
    (string) file_get_contents(__DIR__ . '/../core/public-repository.php'),
    (string) file_get_contents(__DIR__ . '/../assets/js/ss-engine-game-on.js'),
];
if (!str_contains($repository, 'gameOnPuzzlePhotographs(int $limit = 160)')) {
    throw new RuntimeException('GAME ON still sends its former oversized puzzle candidate pool.');
}
if (!str_contains($engine, 'if (shown && !board.initialized)')
    || str_contains($engine, 'boards.push(board); makeTiles(board, false);')) {
    throw new RuntimeException('GAME ON eagerly builds hidden fifteen-piece boards.');
}
echo "PASS: game-on behavior is CMS-owned and its presentation contract is strict.
";
