<?php
/**
 * SNAPSMACK - RSS feed synchronization utility.
 * Automated background task that fetches latest update dates for blogroll peers.
 * Designed to run via system cron (CLI) to keep the "Network" status current.
 * Git Version Official Alpha 0.5
 */

// Access control: Restrict execution to the Command Line Interface (CLI) only.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

// --- BOOTSTRAP ENVIRONMENT ---
define('SNAPSMACK_CRON', true);
$base = dirname(__FILE__);
require_once $base . '/core/db.php';

// Helper for standardized terminal logging with timestamps.
$log = function(string $msg) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
};

$log('RSS fetch started.');

// Identify all blogroll entries that have an associated RSS/Atom feed URL.
$peers = $pdo->query("
    SELECT id, peer_name, peer_rss 
    FROM snap_blogroll 
    WHERE peer_rss IS NOT NULL AND peer_rss != ''
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($peers)) {
    $log('No peers with RSS feeds. Exiting.');
    exit(0);
}

$log('Found ' . count($peers) . ' peers with RSS feeds.');

// Prepare the update statement for recording fetch results.
$update_stmt = $pdo->prepare("
    UPDATE snap_blogroll 
    SET rss_last_fetched = NOW(), rss_last_updated = ? 
    WHERE id = ?
");

foreach ($peers as $peer) {
    $log("Fetching: {$peer['peer_name']} — {$peer['peer_rss']}");

    try {
        // --- REQUEST CONFIGURATION ---
        // Configures a 10-second timeout and ignores SSL errors to handle varied peer server setups.
        $ctx = stream_context_create([
            'http' => [
                'timeout'     => 10,
                'user_agent'  => 'SnapSmack RSS Reader/1.0',
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ]
        ]);

        $xml_raw = @file_get_contents($peer['peer_rss'], false, $ctx);

        if (!$xml_raw) {
            $log("  SKIP: Could not fetch feed.");
            continue;
        }

        // --- XML PARSING ---
        // Suppress internal XML errors to handle malformed feeds gracefully.
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_raw);
        libxml_clear_errors();

        if (!$xml) {
            $log("  SKIP: Could not parse feed XML.");
            continue;
        }

        $last_updated = null;

        // --- RSS 2.0 DETECTION ---
        // Attempt to find the publication date of the most recent item.
        if (isset($xml->channel->item[0]->pubDate)) {
            $raw_date = (string)$xml->channel->item[0]->pubDate;
            $ts = strtotime($raw_date);
            if ($ts) $last_updated = date('Y-m-d H:i:s', $ts);
        }

        // Fallback: Use the last general build date of the feed itself.
        if (!$last_updated && isset($xml->channel->lastBuildDate)) {
            $ts = strtotime((string)$xml->channel->lastBuildDate);
            if ($ts) $last_updated = date('Y-m-d H:i:s', $ts);
        }

        // --- ATOM DETECTION ---
        // If RSS check failed, attempt to parse as an Atom feed.
        if (!$last_updated) {
            $namespaces = $xml->getNamespaces(true);
            if (isset($xml->entry[0])) {
                $ts = strtotime((string)$xml->entry[0]->updated);
                if ($ts) $last_updated = date('Y-m-d H:i:s', $ts);
            }
        }