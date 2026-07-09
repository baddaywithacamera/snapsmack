<?php
// SNAPSMACK_EOF_HEADER: this file MUST end with // ===== SNAPSMACK EOF =====
/**
 * SMACKVERSE Relay — front controller. All public routes dispatch here.
 *   /actor  /.well-known/webfinger  /.well-known/nodeinfo  /nodeinfo/2.0
 *   /inbox  /followers  /following  /outbox  /
 */

require_once __DIR__ . '/../lib/relay.php';

try { relay_ensure_schema(); } catch (Throwable $e) { /* deploy may pre-load schema.sql */ }

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path !== '/') $path = rtrim($path, '/');

function relay_out($doc, string $ctype = 'application/activity+json'): void {
    header('Content-Type: ' . $ctype . '; charset=utf-8');
    echo json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function relay_collection(string $seg, int $total): array {
    return [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id'       => relay_base() . $seg,
        'type'     => 'OrderedCollection',
        'totalItems' => $total,
        'orderedItems' => [],
    ];
}

switch ($path) {
    case '/actor':
        relay_out(relay_actor_doc());

    case '/.well-known/webfinger':
        $doc = relay_webfinger_doc((string)($_GET['resource'] ?? ''));
        if ($doc === null) { http_response_code(404); exit; }
        relay_out($doc, 'application/jrd+json');

    case '/.well-known/nodeinfo':
        relay_out([
            'links' => [[
                'rel'  => 'http://nodeinfo.diaspora.software/ns/schema/2.0',
                'href' => relay_base() . 'nodeinfo/2.0',
            ]],
        ], 'application/json');

    case '/nodeinfo/2.0':
        $n = (int)relay_db()->query("SELECT COUNT(*) FROM relay_subscribers WHERE state='active'")->fetchColumn();
        relay_out([
            'version'           => '2.0',
            'software'          => ['name' => 'smackverse-relay', 'version' => '1.0'],
            'protocols'         => ['activitypub'],
            'services'          => ['inbound' => [], 'outbound' => []],
            'openRegistrations' => (relay_setting('open_mode', 'allowlist') === 'open'),
            'usage'             => ['users' => ['total' => $n]],
            'metadata'          => [
                'nodeName'        => 'SMACKVERSE Relay',
                'nodeDescription' => 'SnapSmack network relay — fan-out only, no image storage.',
            ],
        ], 'application/json');

    case '/followers':
    case '/following':
    case '/outbox':
        $total = 0;
        if ($path === '/followers') {
            $total = (int)relay_db()->query("SELECT COUNT(*) FROM relay_subscribers WHERE state='active'")->fetchColumn();
        }
        relay_out(relay_collection(ltrim($path, '/'), $total));

    case '/inbox':
        relay_inbox();

    case '/':
        header('Content-Type: text/plain; charset=utf-8');
        echo "SMACKVERSE Relay. ActivityPub actor at /actor. No image storage — media loads from origin blogs.\n";
        exit;

    default:
        http_response_code(404);
        exit;
}
// ===== SNAPSMACK EOF =====
