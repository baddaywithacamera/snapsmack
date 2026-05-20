<?php
/**
 * One-shot seed: creates the General Chat forum category.
 * Upload to the same directory as the forum API config.php,
 * hit it once in a browser, then DELETE THIS FILE.
 */

require __DIR__ . '/projects/forum-server/api/forum/config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $existing = $pdo->query("SELECT COUNT(*) FROM ss_forum_categories WHERE slug = 'general-chat'")->fetchColumn();
    if ($existing > 0) {
        echo 'Already exists — nothing to do. DELETE THIS FILE.';
        exit;
    }

    $pdo->prepare("INSERT INTO ss_forum_categories (slug, name, description, sort_order, is_active) VALUES (?, ?, ?, ?, 1)")
        ->execute(['general-chat', 'General Chat', '', 1]);

    echo 'Done — General Chat category created. DELETE THIS FILE NOW.';
} catch (PDOException $e) {
    echo 'DB error: ' . htmlspecialchars($e->getMessage());
}
