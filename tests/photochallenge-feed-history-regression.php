<?php
/** The Board is one newest-first stream: live week, then prior weeks. */
$root = dirname(__DIR__);
$photo = file_get_contents($root . '/core/photochallenge.php');
$board = file_get_contents($root . '/photochallenge-board.php');
$parser = file_get_contents($root . '/core/parser.php');
$header = file_get_contents($root . '/core/header.php');
$gram = file_get_contents($root . '/core/gram-nav-links.php');
$ht = file_get_contents($root . '/core/htaccess-template');
$checks = [
    'chronological board window helper exists' => str_contains($photo, 'function pc_board_windows('),
    'historical rounds carry their own prompt tags' =>
        str_contains($photo, 'p.submit_end,p.prompt,p.tag,p.tag_display')
        && str_contains($photo, "pc_round_tag(\$pdo, \$settings, (string)\$row['week_key'])"),
    'prompt index has its own renderer and links completed prompts to board rounds' =>
        str_contains($photo, 'function pc_prompt_index_embed_html(')
        && str_contains($photo, 'class="pc-prompt-index"')
        && str_contains($photo, 'prompt_image')
        && str_contains($photo, "'the-board#round-'")
        && str_contains($photo, 'id="round-'),
    'prompt index separates current coming and past records' =>
        str_contains($photo, "['current' => [], 'coming' => [], 'past' => []]")
        && str_contains($photo, "['current' => 'Current', 'coming' => 'Coming', 'past' => 'Past']"),
    'embed accepts an explicit window' => str_contains($photo, 'array $settings, ?array $window = null'),
    'standalone board uses the shared historical renderer' =>
        str_contains($board, 'pc_board_rounds_embed_html($pdo, $settings)'),
    'static-page board shortcode renders the same historical rounds' =>
        str_contains($photo, 'function pc_board_rounds_embed_html(')
        && str_contains($photo, 'pc_board_windows($pdo, $settings, $history_limit)')
        && str_contains($parser, 'pc_board_rounds_embed_html($this->pdo, $settings)'),
    'static-page prompts shortcode uses only the prompt renderer' =>
        str_contains($parser, "stripos(\$content, '[prompts]')")
        && str_contains($parser, 'pc_prompt_index_embed_html($this->pdo, $settings)'),
    'board renderer no longer prepends the prompt catalogue' =>
        !str_contains(substr($photo, strpos($photo, 'function pc_board_rounds_embed_html('), strpos($photo, 'function pc_prompt_index_embed_html(') - strpos($photo, 'function pc_board_rounds_embed_html(')), 'pc-prompt-index'),
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
