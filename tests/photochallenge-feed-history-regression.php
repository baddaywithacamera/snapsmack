<?php
/** Feed is the previous completed round; Board is the live round. */
$root = dirname(__DIR__);
$photo = file_get_contents($root . '/core/photochallenge.php');
$board = file_get_contents($root . '/photochallenge-board.php');
$header = file_get_contents($root . '/core/header.php');
$gram = file_get_contents($root . '/core/gram-nav-links.php');
$ht = file_get_contents($root . '/core/htaccess-template');
$checks = [
    'previous completed window helper exists' => str_contains($photo, 'function pc_previous_window('),
    'historical round uses its own prompt tag' => str_contains($photo, 'SELECT tag FROM pc_prompts WHERE week_key=?'),
    'embed accepts an explicit window' => str_contains($photo, 'array $settings, ?array $window = null'),
    'feed selects previous while board stays current' => str_contains($board, "? pc_previous_window(\$pdo, \$settings) : pc_window(\$settings)"),
    'human feed has its own route instead of colliding with RSS' =>
        str_contains($ht, 'RewriteRule ^challenge-feed/?$ photochallenge-board.php?view=previous [L,QSA]')
        && !str_contains($ht, 'RewriteRule ^feed/?$ photochallenge-board.php?view=previous [L,QSA]'),
    'ordinary navigation targets human feed' => str_contains($header, "\$base . 'challenge-feed'"),
    'gram navigation targets human feed' => str_contains($gram, "\$base . 'challenge-feed'"),
    'pretty human feed route exists' => str_contains($ht, '^challenge-feed/?$'),
];
$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ": {$label}\n";
    if (!$ok) $failed++;
}
exit($failed ? 1 : 0);
