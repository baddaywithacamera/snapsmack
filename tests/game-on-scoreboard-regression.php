<?php
$root = dirname(__DIR__);
$js = file_get_contents($root . '/assets/js/ss-engine-game-on.js');
$layout = file_get_contents($root . '/skins/game-on/layout.php');
$endpoint = file_get_contents($root . '/game-on-scores.php');
$migration = file_get_contents($root . '/migrations/migrate-game-on-scores.sql');
$ledger = file_get_contents($root . '/migrations/migrate-game-scores-ledger.sql');
$scoreCore = file_get_contents($root . '/core/game-scores.php');
$parser = file_get_contents($root . '/core/parser.php');
$fail = [];
foreach ([
    [$layout, 'data-play-as-puzzle', 'solo view has no PLAY AS PUZZLE carrier'],
    [$layout, 'data-action-placement="community"', 'solo puzzle action is not assigned to the community bar'],
    [$js, "e.target.closest('[data-play-as-puzzle]')", 'game engine does not open solo images'],
    [$js, "like.insertAdjacentElement('afterend', button)", 'solo puzzle action is not placed after Like'],
    [$js, 'data-scoreboard', 'high-score modal is missing'],
    [$js, 'data-score-initials', 'three-initial entry is missing'],
    [$js, "solved_count: session.solved", 'session solve total is not submitted'],
    [$endpoint, "preg_match('/^[A-Z0-9]{3}$/', \$initials)", 'server does not enforce three initials'],
    [$endpoint, "community_rate_limit('game_scores')", 'public score writes are not rate limited'],
    [$migration, 'snap_game_on_scores', 'score table migration is missing'],
    [$ledger, 'snap_game_scores', 'reusable score ledger migration is missing'],
    [$scoreCore, "'daily'", 'daily leaderboard is missing'],
    [$scoreCore, "'weekly'", 'weekly leaderboard is missing'],
    [$scoreCore, "'monthly'", 'monthly leaderboard is missing'],
    [$scoreCore, "'all'", 'all-time leaderboard is missing'],
    [$parser, '[game_scores]', 'static-page game score shortcode is missing'],
    [$js, 'data-score-period="daily"', 'score period controls are missing'],
    [$js, 'stopImmediatePropagation', 'puzzle keys do not suppress global post navigation'],
] as $check) if (strpos($check[0], $check[1]) === false) $fail[] = $check[2];
if ($fail) { fwrite(STDERR, "FAIL: " . implode('; ', $fail) . "\n"); exit(1); }
echo "PASS: GAME ON solo puzzle and site scoreboard contracts are present.\n";
// ===== SNAPSMACK EOF =====
