<?php
/**
 * SnapSmack - RSS Feed Fetcher
 * Version: 1.0
 * -------------------------------------------------------------------------
 * Registered and managed via the System Dashboard cron panel.
 * Runs hourly via cron. Fetches RSS feeds for all blogroll peers that have
 * a feed URL, stores the last updated date back to snap_blogroll.
 * -------------------------------------------------------------------------
 * DO NOT call this file from a browser directly in production.
 * -------------------------------------------------------------------------
 */

// CLI-only guard
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

// Bootstrap
define('SNAPSMACK_CRON', true);
$base = dirname(__FILE__);
require_once $base . '/core/db.php';

$log = function(string $msg) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
};

$log('RSS fetch started.');

// Fetch all peers with a feed URL
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

$update_stmt = $pdo->prepare("
    UPDATE snap_blogroll 
    SET rss_last_fetched = NOW(), rss_last_updated = ? 
    WHERE id = ?
");

foreach ($peers as $peer) {
    $log("Fetching: {$peer['peer_name']} — {$peer['peer_rss']}");

    try {
        // Fetch the feed with a 10 second timeout
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

        // Suppress XML parse errors
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_raw);
        libxml_clear_errors();

        if (!$xml) {
            $log("  SKIP: Could not parse feed XML.");
            continue;
        }

        // Try RSS 2.0 first, then Atom
        $last_updated = null;

        // RSS 2.0 — grab pubDate of first item
        if (isset($xml->channel->item[0]->pubDate)) {
            $raw_date = (string)$xml->channel->item[0]->pubDate;
            $ts = strtotime($raw_date);
            if ($ts) $last_updated = date('Y-m-d H:i:s', $ts);
        }

        // RSS 2.0 — fallback to lastBuildDate
        if (!$last_updated && isset($xml->channel->lastBuildDate)) {
            $ts = strtotime((string)$xml->channel->lastBuildDate);
            if ($ts) $last_updated = date('Y-m-d H:i:s', $ts);
        }

        // Atom — grab updated of first entry
        if (!$last_updated) {
            $namespaces = $xml->getNamespaces(true);
            if (isset($xml->entry[0])) {
                $ts = strtotime((string)$xml->entry[0]->updated);
                if ($ts) $last_updated = date('Y-m-d H:i:s', $ts);
            }
        }

        $update_stmt->execute([$last_updated, $peer['id']]);
        $log("  OK: Last updated = " . ($last_updated ?? 'unknown'));

    } catch (Exception $e) {
        $log("  ERROR: " . $e->getMessage());
    }
}

$log('RSS fetch complete.');
exit(0);
