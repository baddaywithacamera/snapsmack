<?php
/** The Board is one newest-first stream: live week, then prior weeks. */
$root = dirname(__DIR__);
$photo = file_get_contents($root . '/core/photochallenge.php');
$board = file_get_contents($root . '/photochallenge-board.php');
$header = file_get_contents($root . '/core/header.php');
$gram = file_get_contents($root . '/core/gram-nav-links.php');
$ht = file_get_contents($root . '/core/htaccess-template');
$checks = [
    'chronological board window helper exists' => str_contains($photo, 'function pc_board_windows('),
    'historical rounds carry their own prompt tags' =>
        str_contains($photo, 'submit_end,prompt,tag')
        && str_contains($photo, "pc_round_tag(\$pdo, \$settings, (string)\$row['week_key'])"),
    'embed accepts an explicit window' => str_contains($photo, 'array $settings, ?array $window = null'),
    'board renders every round in order' => str_contains($board, 'foreach ($rounds as $round_index => $win)'),
    'old human feed URL aliases the unified board' =>
        str_contains($ht, 'RewriteRule ^challenge-feed/?$ photochallenge-board.php [L,QSA]'),
    'ordinary navigation hides retired feed item' => str_contains($header, "=== 'challenge_feed') continue"),
    'gram navigation hides retired feed item' => str_contains($gram, "=== 'challenge_feed') continue"),
];
$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ": {$label}\n";
    if (!$ok) $failed++;
}
exit($failed ? 1 : 0);
