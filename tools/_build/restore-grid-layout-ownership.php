<?php
declare(strict_types=1);

// Mechanical recovery aid: generate the common grid skeleton into each skin.
// The generated layout is the runtime artifact; this tool is never packaged.
$root = dirname(__DIR__, 2);
$source = (string)file_get_contents($root . '/skins/game-on/layout.php');
$source = preg_replace(
    '#<div class="go-puzzle-field"[\s\S]*?<div class="go-content-wrap landing-feed">#',
    '{{BACKGROUND}}<div class="go-content-wrap landing-feed">',
    $source,
    1
);
if (!is_string($source) || !str_contains($source, '{{BACKGROUND}}')) {
    throw new RuntimeException('Could not isolate the common grid skeleton.');
}

$profiles = [
    'the-grid' => ['class' => 'tg', 'option' => 'tg', 'background' => ''],
    'aurora' => ['class' => 'au', 'option' => 'au', 'background' => '<div class="au-aurora-bg" aria-hidden="true"></div>'],
    'sudden-impact' => ['class' => 'tg', 'option' => 'tg', 'background' => ''],
    'jive-turkey' => ['class' => 'jt', 'option' => 'jt', 'background' => '<div class="jt-jive-turkey-bg" aria-hidden="true"></div>'],
    'heuristic' => ['class' => 'he', 'option' => 'he', 'background' => '<div class="he-heuristic-bg" aria-hidden="true"></div>'],
    'sliders' => ['class' => 'tg', 'option' => 'ic', 'background' => '<div class="sl-glide-bg" aria-hidden="true"><div class="sl-glide-field"></div></div>'],
];

foreach ($profiles as $slug => $profile) {
    $layout = str_replace('game-on-v2', $slug . '-v2', $source);
    $layout = str_replace('go-', $profile['class'] . '-', $layout);
    $layout = str_replace("['go_profile_header']", "['{$profile['option']}_profile_header']", $layout);
    $layout = str_replace("['go_show_tagline']", "['{$profile['option']}_show_tagline']", $layout);
    $layout = str_replace('{{BACKGROUND}}', $profile['background'], $layout);
    file_put_contents($root . '/skins/' . $slug . '/layout.php', $layout);
}

echo "Restored layout ownership for " . count($profiles) . " grid skins.\n";
