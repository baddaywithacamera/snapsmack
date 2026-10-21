<?php
declare(strict_types=1);

$page = (string) file_get_contents(dirname(__DIR__) . '/smack-post-long.php');

foreach (['$all_posts', 'EXISTING LONGFORM POSTS LIST', '<h3>LONGFORM POSTS</h3>', 'LIMIT 200'] as $obsolete) {
    if (str_contains($page, $obsolete)) {
        throw new RuntimeException("Longform editor still contains its redundant post list: {$obsolete}");
    }
}

echo "longform editor post-list regression: ok\n";
