<?php
declare(strict_types=1);

/** Canonical public visibility decision, independent of presentation. */
function snapsmack_public_row_visible(string $kind, ?array $row, DateTimeImmutable $now): bool {
    if ($row === null) return false;
    if ($kind === 'image') {
        if (($row['img_status'] ?? '') !== 'published') return false;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string)($row['img_date'] ?? ''));
        return $date !== false && $date <= $now;
    }
    if ($kind === 'post') {
        if (($row['status'] ?? '') !== 'published') return false;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string)($row['created_at'] ?? ''));
        return $date !== false && $date <= $now;
    }
    if ($kind === 'page') return (int)($row['is_active'] ?? 0) === 1;
    return false;
}

/**
 * Find public-query literals whose own SQL does not enforce the canonical
 * visibility columns. This is a migration harness, not a SQL parser.
 */
function snapsmack_skin_visibility_query_findings(string $path, string $relative): array {
    $source = @file_get_contents($path);
    if ($source === false) return [['file' => $relative, 'line' => 0, 'type' => 'unreadable']];
    $findings = [];
    foreach (token_get_all($source) as $token) {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) continue;
        $sql = $token[1];
        if (!preg_match('/\bSELECT\b/i', $sql)) continue;
        $checks = [
            'post' => [preg_match('/\b(?:FROM|JOIN)\s+snap_posts\b/i', $sql), '/\bstatus\s*=\s*["\']published["\']/i', '/\bcreated_at\s*<=/i'],
            'image' => [preg_match('/\b(?:FROM|JOIN)\s+snap_images\b/i', $sql), '/\bimg_status\s*=\s*["\']published["\']/i', '/\bimg_date\s*<=/i'],
            'page' => [preg_match('/\b(?:FROM|JOIN)\s+snap_pages\b/i', $sql), '/\bis_active\s*=\s*1\b/i', null],
        ];
        foreach ($checks as $kind => [$mentions, $status_pattern, $date_pattern]) {
            if (!$mentions) continue;
            $missing = [];
            if (!preg_match($status_pattern, $sql)) $missing[] = 'publication-state';
            if ($date_pattern !== null && !preg_match($date_pattern, $sql)) $missing[] = 'publication-time';
            if ($missing) {
                $findings[] = [
                    'file' => str_replace('\\', '/', $relative),
                    'line' => (int)$token[2],
                    'type' => $kind,
                    'missing' => $missing,
                ];
            }
        }
    }
    return $findings;
}
