<?php
/**
 * SNAPSMACK - bounded lifecycle maintenance for the Pixelix OAuth adapter.
 *
 * The fixed retention policy is intentionally conservative:
 * - unpublished client media: 7 days
 * - expired, never-redeemed authorization rows: 1 day after expiry
 * - expired/revoked credential rows: 30 days after final expiry/revocation
 * - unused client registrations: 1 day
 * - inactive registration-limit buckets: 2 hours
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

require_once __DIR__ . '/api-input-safety.php';

const SNAP_PIXELIX_DRAFT_RETENTION_DAYS = 7;
const SNAP_PIXELIX_CLEANUP_BATCH = 10;

/** Delete a stored upload only when it is still an ordinary img_uploads path. */
function snap_pixelix_unlink_upload(string $site_root, ?string $relative): bool {
    $relative = (string)$relative;
    if (!snap_api_safe_upload_path($relative)) return false;

    $root = realpath($site_root . '/img_uploads');
    $file = realpath($site_root . '/' . $relative);
    if ($root === false || $file === false || !is_file($file)) return false;
    $root_prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $file_norm = str_replace('\\', '/', $file);
    if (!str_starts_with($file_norm, $root_prefix)) return false;
    return @unlink($file);
}

/**
 * Run one bounded maintenance pass. Dry-run reports candidates without writes.
 * The media rows are locked before removal, so a concurrent publish wins safely.
 */
function snap_pixelix_lifecycle_maintenance(
    PDO $pdo,
    string $site_root,
    bool $dry_run = false,
    int $limit = SNAP_PIXELIX_CLEANUP_BATCH
): array {
    $limit = max(1, min(100, $limit));
    $report = ['draft_media' => 0, 'null_expiry_tokens' => 0, 'authorization_rows' => 0, 'credential_rows' => 0,
        'apps' => 0, 'rate_limits' => 0, 'files' => 0];

    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    $files = [];
    try {
        $media = $pdo->query(
            "SELECT i.id,i.img_file,i.img_thumb_square,i.img_thumb_aspect
             FROM snap_images i JOIN snap_oauth_media om ON om.image_id=i.id
             WHERE i.post_id IS NULL AND i.img_status='draft'
               AND i.img_date < DATE_SUB(NOW(),INTERVAL " . SNAP_PIXELIX_DRAFT_RETENTION_DAYS . " DAY)
             ORDER BY i.id LIMIT {$limit} FOR UPDATE"
        )->fetchAll(PDO::FETCH_ASSOC);
        $report['draft_media'] = count($media);
        if (!$dry_run) {
            $del_owner = $pdo->prepare('DELETE FROM snap_oauth_media WHERE image_id=?');
            $del_image = $pdo->prepare("DELETE FROM snap_images WHERE id=? AND post_id IS NULL AND img_status='draft'");
            foreach ($media as $row) {
                $del_owner->execute([(int)$row['id']]);
                $del_image->execute([(int)$row['id']]);
                if ($del_image->rowCount() === 1) {
                    foreach (['img_file','img_thumb_square','img_thumb_aspect'] as $column) {
                        if (!empty($row[$column])) $files[] = (string)$row[$column];
                    }
                }
            }
        }

        $candidate_sql = [
            'null_expiry_tokens' => "SELECT id FROM snap_oauth_tokens WHERE token_hash IS NOT NULL AND token_expires_at IS NULL AND revoked_at IS NULL LIMIT {$limit}",
            'authorization_rows' => "SELECT t.id FROM snap_oauth_tokens t LEFT JOIN snap_oauth_media om ON om.token_id=t.id WHERE t.token_hash IS NULL AND t.code_expires_at < DATE_SUB(NOW(),INTERVAL 1 DAY) AND om.token_id IS NULL LIMIT {$limit}",
            'credential_rows' => "SELECT t.id FROM snap_oauth_tokens t LEFT JOIN snap_oauth_media om ON om.token_id=t.id WHERE om.token_id IS NULL AND t.authorization_code_hash IS NULL AND ((t.refresh_expires_at IS NOT NULL AND t.refresh_expires_at < DATE_SUB(NOW(),INTERVAL 30 DAY)) OR (t.revoked_at IS NOT NULL AND t.revoked_at < DATE_SUB(NOW(),INTERVAL 30 DAY))) LIMIT {$limit}",
            'apps' => "SELECT a.id FROM snap_oauth_apps a LEFT JOIN snap_oauth_tokens t ON t.app_id=a.id WHERE a.created_at < DATE_SUB(NOW(),INTERVAL 1 DAY) GROUP BY a.id HAVING COUNT(t.id)=0 LIMIT {$limit}",
            'rate_limits' => "SELECT bucket_key FROM snap_oauth_rate_limits WHERE window_started_at < DATE_SUB(NOW(),INTERVAL 2 HOUR) LIMIT {$limit}",
        ];
        $candidates = [];
        foreach ($candidate_sql as $key => $sql) {
            $candidates[$key] = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
            $report[$key] = count($candidates[$key]);
        }

        if (!$dry_run) {
            if ($candidates['null_expiry_tokens']) $pdo->exec('UPDATE snap_oauth_tokens SET revoked_at=NOW() WHERE id IN (' . implode(',',array_map('intval',$candidates['null_expiry_tokens'])) . ') AND token_expires_at IS NULL');
            if ($candidates['authorization_rows']) $pdo->exec('DELETE FROM snap_oauth_tokens WHERE id IN (' . implode(',',array_map('intval',$candidates['authorization_rows'])) . ')');
            if ($candidates['credential_rows']) $pdo->exec('DELETE FROM snap_oauth_tokens WHERE id IN (' . implode(',',array_map('intval',$candidates['credential_rows'])) . ')');
            if ($candidates['apps']) $pdo->exec('DELETE FROM snap_oauth_apps WHERE id IN (' . implode(',',array_map('intval',$candidates['apps'])) . ')');
            if ($candidates['rate_limits']) {
                $marks=implode(',',array_fill(0,count($candidates['rate_limits']),'?'));
                $pdo->prepare("DELETE FROM snap_oauth_rate_limits WHERE bucket_key IN ({$marks})")->execute($candidates['rate_limits']);
            }
        }
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    if (!$dry_run) {
        foreach (array_unique($files) as $relative) {
            if (snap_pixelix_unlink_upload($site_root, $relative)) $report['files']++;
        }
    }
    return $report;
}
// ===== SNAPSMACK EOF =====
