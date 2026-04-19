<?php
/**
 * Migration 030: Semantic Analysis Tables
 *
 * Creates tables for storing comment text and TF-IDF vectors for semantic
 * troll detection, plus a keyword/phrase ban list.
 */

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($db->connect_error) {
	die("Connection failed: " . $db->connect_error);
}

// snap_comments_semantic: stores comment text and TF-IDF vectors for semantic analysis
$sql1 = "CREATE TABLE IF NOT EXISTS snap_comments_semantic (
	id INT AUTO_INCREMENT PRIMARY KEY,
	comment_id INT NOT NULL,
	fingerprint_hash VARCHAR(255) NOT NULL,
	comment_text LONGTEXT NOT NULL,
	tfidf_vector JSON DEFAULT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	KEY idx_fingerprint (fingerprint_hash),
	KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if (!$db->query($sql1)) {
	die("Error creating snap_comments_semantic table: " . $db->error);
}

// snap_keywords: banned keywords and phrases for content-based banning
$sql2 = "CREATE TABLE IF NOT EXISTS snap_keywords (
	id INT AUTO_INCREMENT PRIMARY KEY,
	keyword VARCHAR(500) NOT NULL UNIQUE,
	match_type ENUM('exact', 'substring', 'regex') DEFAULT 'substring',
	severity ENUM('flag', 'reject') DEFAULT 'flag',
	reason VARCHAR(255),
	added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	added_by VARCHAR(100),
	KEY idx_keyword (keyword)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if (!$db->query($sql2)) {
	die("Error creating snap_keywords table: " . $db->error);
}

$db->close();

echo "Migration 030 completed: snap_comments and snap_keywords tables created.\n";
