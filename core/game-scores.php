<?php
/**
 * Reusable, database-backed scoreboards for SnapSmack games.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

function snapsmack_game_score_periods(): array {
    return [
        'daily'  => 'Today',
        'weekly' => 'This week',
        'monthly'=> 'This month',
        'all'    => 'All time',
    ];
}

function snapsmack_game_score_since(string $period): ?string {
    $now = new DateTimeImmutable('now');
    return match ($period) {
        'daily'   => $now->format('Y-m-d'),
        'weekly'  => $now->modify('monday this week')->format('Y-m-d'),
        'monthly' => $now->format('Y-m-01'),
        default   => null,
    };
}

function snapsmack_game_score_rows(PDO $pdo, string $game, string $metric, string $period, string $direction = 'DESC', int $limit = 10): array {
    $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
    $limit = max(1, min(100, $limit));
    $since = snapsmack_game_score_since($period);
    $sql = 'SELECT initials, score_value, score_date, score_meta FROM snap_game_scores WHERE game_key = ? AND metric_key = ?';
    $args = [$game, $metric];
    if ($since !== null) {
        $sql .= ' AND score_date >= ?';
        $args[] = $since;
    }
    $sql .= " ORDER BY score_value {$direction}, id ASC LIMIT {$limit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function snapsmack_game_scoreboards(PDO $pdo, string $game = 'game-on'): array {
    $boards = [];
    foreach (snapsmack_game_score_periods() as $key => $label) {
        $boards[$key] = [
            'label' => $label,
            'fastest' => snapsmack_game_score_rows($pdo, $game, 'fastest_solve', $key, 'ASC'),
            'most_solved' => snapsmack_game_score_rows($pdo, $game, 'session_solved', $key, 'DESC'),
        ];
    }
    return ['periods' => $boards];
}

function snapsmack_game_score_record(PDO $pdo, string $game, string $initials, array $metrics, array $meta = []): void {
    $run = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare('INSERT INTO snap_game_scores (game_key, initials, metric_key, score_value, score_meta, session_key, score_date) VALUES (?, ?, ?, ?, ?, ?, CURRENT_DATE)');
    $pdo->beginTransaction();
    try {
        foreach ($metrics as $metric => $value) {
            if (!preg_match('/^[a-z0-9_-]{1,64}$/', (string)$metric)) continue;
            $stmt->execute([$game, $initials, $metric, max(0, (int)$value), json_encode($meta, JSON_UNESCAPED_SLASHES), $run]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function snapsmack_game_scores_html(PDO $pdo, string $game = 'game-on'): string {
    $data = snapsmack_game_scoreboards($pdo, $game);
    $out = '<div class="snap-game-score-history">';
    foreach ($data['periods'] as $period) {
        $out .= '<section class="snap-game-score-period"><h2>' . htmlspecialchars($period['label']) . '</h2><div class="snap-game-score-columns">';
        foreach (['fastest' => 'Fastest solve', 'most_solved' => 'Most solved in one session'] as $key => $heading) {
            $out .= '<div><h3>' . htmlspecialchars($heading) . '</h3><ol>';
            if (!$period[$key]) $out .= '<li>No scores yet</li>';
            foreach ($period[$key] as $row) {
                $value = $key === 'fastest'
                    ? floor(((int)$row['score_value']) / 60000) . ':' . str_pad(number_format((((int)$row['score_value']) % 60000) / 1000, 1), 4, '0', STR_PAD_LEFT)
                    : (string)(int)$row['score_value'];
                $out .= '<li><strong>' . htmlspecialchars($row['initials']) . '</strong> <span>' . htmlspecialchars($value) . '</span> <time>' . htmlspecialchars($row['score_date']) . '</time></li>';
            }
            $out .= '</ol></div>';
        }
        $out .= '</div></section>';
    }
    return $out . '</div>';
}
// ===== SNAPSMACK EOF =====
