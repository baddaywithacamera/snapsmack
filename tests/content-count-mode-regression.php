<?php
/** SnapSmack regression: post/image totals must respect the install mode. */

require_once __DIR__ . '/../core/stats-logger.php';

function cc_expect(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

final class CCStatement {
    public function __construct(private int|string $value) {}
    public function execute(array $params = []): bool { return true; }
    public function fetchColumn(): int|string { return $this->value; }
}

final class CCFakePdo {
    public function __construct(private string $mode) {}

    public function query(string $sql): CCStatement {
        if (str_contains($sql, "setting_key = 'site_mode'")) return new CCStatement($this->mode);
        if (str_contains($sql, 'FROM snap_images') && str_contains($sql, 'COUNT(DISTINCT post_id)')) return new CCStatement(1);
        if (str_contains($sql, 'FROM snap_images') && str_contains($sql, 'post_id IS NULL')) return new CCStatement(1);
        if (str_contains($sql, 'FROM snap_images') && str_contains($sql, "img_status='draft'")) return new CCStatement(7);
        if (str_contains($sql, 'FROM snap_images')) return new CCStatement(3);
        if (str_contains($sql, 'FROM snap_posts') && str_contains($sql, "status='draft'")) return new CCStatement(1);
        if (str_contains($sql, 'FROM snap_posts')) return new CCStatement(1);
        throw new RuntimeException('Unexpected query: ' . $sql);
    }

    public function prepare(string $sql): CCStatement {
        if (str_contains($sql, 'FROM snap_posts')) return new CCStatement(1);
        if (str_contains($sql, 'FROM snap_images')) return new CCStatement(3);
        throw new RuntimeException('Unexpected prepared query: ' . $sql);
    }
}

$smacktalk = new CCFakePdo('smacktalk');
$counts = snapsmack_content_counts($smacktalk);
cc_expect($counts === ['posts' => 1, 'images' => 3], 'SMACKTALK counted Gallery images as posts');
$statuses = snapsmack_content_status_counts($smacktalk, '2026-06-01 00:00:00');
cc_expect($statuses === ['published' => 1, 'scheduled' => 1, 'drafts' => 1], 'SMACKTALK dashboard counts did not use snap_posts');

$photo = new CCFakePdo('photoblog');
$counts = snapsmack_content_counts($photo);
cc_expect($counts === ['posts' => 2, 'images' => 3], 'SMACKONEOUT no longer counts standalone/grouped photo entries');

$dashboard = (string)file_get_contents(__DIR__ . '/../smack-admin.php');
cc_expect(str_contains($dashboard, 'snapsmack_content_status_counts'), 'Dashboard bypasses the shared mode-aware counter');

echo "PASS: content counts respect SMACKONEOUT and SMACKTALK post models.\n";
