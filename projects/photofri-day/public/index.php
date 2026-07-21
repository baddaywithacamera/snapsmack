<?php
// SNAPSMACK_EOF_HEADER: this file MUST end with // ===== SNAPSMACK EOF =====
/**
 * PHOTOFRI.DAY — public front controller. Routes the ActivityPub surface for
 * @participate (WebFinger, actor, inbox, collections) and serves the human
 * landing for everything else. Docroot = this public/ dir; .htaccess sends any
 * non-file request here.
 */
require_once __DIR__ . '/../lib/participants.php';
try { pfd_ensure_schema(); } catch (Throwable $e) { /* first-hit self-heal; ignore */ }

function pfd_json_out(array $doc, string $ctype = 'application/activity+json'): void {
    header('Content-Type: ' . $ctype . '; charset=utf-8');
    echo json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$path = strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?');
if ($path !== '/') $path = rtrim($path, '/');

if ($path === '/.well-known/webfinger') {
    $doc = pfd_webfinger_doc((string)($_GET['resource'] ?? ''));
    if ($doc === null) { http_response_code(404); exit; }
    pfd_json_out($doc, 'application/jrd+json');
}

if ($path === '/actor') { pfd_json_out(pfd_actor_doc()); }

if ($path === '/inbox') { pfd_inbox(); /* verifies method + signature, then exits */ }

if ($path === '/followers' || $path === '/following' || $path === '/outbox') {
    $name = ltrim($path, '/');
    $count = 0;
    if ($name !== 'outbox') {
        try { $count = (int)pfd_db()->query("SELECT COUNT(*) FROM pfd_participants WHERE state='active'")->fetchColumn(); }
        catch (Throwable $e) {}
    }
    // Count only (the participant list itself is not published here) — good citizen, privacy-safe.
    pfd_json_out([
        '@context'     => 'https://www.w3.org/ns/activitystreams',
        'id'           => pfd_base() . $name,
        'type'         => 'OrderedCollection',
        'totalItems'   => $count,
        'orderedItems' => [],
    ]);
}

// Human landing (the existing designed page) for / and anything else.
$landing = __DIR__ . '/index.html';
header('Content-Type: text/html; charset=utf-8');
if (is_file($landing)) { readfile($landing); exit; }
echo "<!doctype html><meta charset=utf-8><title>PHOTOFRI.DAY</title><h1>PHOTOFRI.DAY</h1><p>A weekly, whole-fediverse Photo Friday. Coming soon.</p>";
exit;
// ===== SNAPSMACK EOF =====
