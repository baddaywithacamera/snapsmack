<?php
/**
 * SNAPSMACK — GAME ON public scoreboard endpoint.
 * GET returns fastest solves and strongest sessions. POST records one result.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/community-session.php';
require_once __DIR__ . '/core/game-scores.php';

function go_scores_reply(array $body, int $status = 200): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

function go_scores_board(PDO $pdo): array {
    return snapsmack_game_scoreboards($pdo, 'game-on');
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        go_scores_reply(go_scores_board($pdo));
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: GET, POST');
        go_scores_reply(['error' => 'method_not_allowed'], 405);
    }

    if (function_exists('community_rate_limit') && !community_rate_limit('game_scores')) {
        go_scores_reply(['error' => 'rate_limited'], 429);
    }

    $raw = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($raw)) go_scores_reply(['error' => 'invalid_json'], 400);
    $initials = strtoupper(trim((string)($raw['initials'] ?? '')));
    $best_ms = (int)($raw['best_ms'] ?? 0);
    $solved = (int)($raw['solved_count'] ?? 0);
    if (!preg_match('/^[A-Z0-9]{3}$/', $initials)) go_scores_reply(['error' => 'initials_must_be_three_characters'], 400);
    if ($best_ms < 1000 || $best_ms > 86400000 || $solved < 1 || $solved > 9999) {
        go_scores_reply(['error' => 'invalid_score'], 400);
    }

    snapsmack_game_score_record($pdo, 'game-on', $initials, [
        'fastest_solve' => $best_ms,
        'session_solved' => $solved,
    ], ['best_ms' => $best_ms, 'solved_count' => $solved]);
    go_scores_reply(['saved' => true] + go_scores_board($pdo));
} catch (PDOException $e) {
    go_scores_reply(['error' => 'scoreboard_unavailable'], 503);
}
// ===== SNAPSMACK EOF =====
