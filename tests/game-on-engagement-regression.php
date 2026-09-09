<?php
/**
 * GAME ON modal engagement regression checks.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = dirname(__DIR__);
$game = (string) file_get_contents($root . '/assets/js/ss-engine-game-on.js');
$time = (string) file_get_contents($root . '/assets/js/ss-engine-scrolltime.js');
$fail = 0;

function go_engagement_test(bool $ok, string $message): void
{
    global $fail;
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
}

go_engagement_test(str_contains($game, "snapsmack:engagement-start"), 'opening a puzzle announces engagement');
go_engagement_test(str_contains($game, "snapsmack:engagement-stop"), 'closing a puzzle releases engagement');
go_engagement_test(str_contains($game, 'if (!modalEngaged)'), 'puzzle replacement cannot double-count an open modal');
go_engagement_test(str_contains($time, "addEventListener('snapsmack:engagement-start'"), 'scroll-time engine receives rich-interaction starts');
go_engagement_test(str_contains($time, "addEventListener('snapsmack:engagement-stop'"), 'scroll-time engine receives rich-interaction stops');
go_engagement_test(str_contains($time, "document.visibilityState === 'visible'"), 'hidden tabs remain excluded');
go_engagement_test(str_contains($time, 'externalEngagement > 0 ||'), 'active rich interactions bypass only the idle cutoff');

exit($fail ? 1 : 0);
// ===== SNAPSMACK EOF =====
