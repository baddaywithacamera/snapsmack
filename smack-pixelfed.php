<?php
/**
 * SNAPSMACK — SMACKVERSE : Pixelfed Client
 *
 * A faithful-to-pixelfed.ca client that lives INSIDE the admin, so the blog is
 * run as a fediverse actor without ever leaving the CMS. Geometry mirrors
 * Pixelfed exactly; colours are inherited from the active admin skin (CSS vars
 * in ss-pixelfed-client.css) so it always matches the current theme — no
 * separate palette, no light/dark toggle.
 *
 * Tabs: Home (accounts we follow) · Local · Global · Notifications · Profile ·
 * Search (any @user@host, rendered from their crawled outbox). The feed/
 * notification/profile data endpoints (?ajax=<panel>) are wired in the reader
 * phase; this file already serves the shell and a safe empty JSON so the client
 * degrades gracefully until each panel lights up.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once 'core/auth-smack.php';
require_once 'core/smackverse.php';

$sv_settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                   ->fetchAll(PDO::FETCH_KEY_PAIR);
sv_ensure_tables($pdo);
$sv_on = sv_enabled($sv_settings);
// GRAMOFSMACK-first: the two-way client is gated to carousel mode while we
// prove it out, then widens to the other install modes. (core/header.php:41)
$sv_mode = (string)($sv_settings['site_mode'] ?? 'photoblog');
$sv_gram = ($sv_mode === 'carousel');

// ── POST interactions (JSON) — follow / unfollow / like / reply ─────────────
// CSRF is already enforced globally in core/auth-smack.php before we get here.
// Every branch returns JSON and exits before any chrome is emitted.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sspf_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = (string)$_POST['sspf_action'];

    if (!$sv_on) {
        echo json_encode(['ok' => false, 'msg' => 'SMACKVERSE is off — flip it on in Federation first.']);
        exit;
    }

    $ok = false; $msg = 'Unknown action.'; $extra = [];
    switch ($act) {
        case 'follow':
            list($ok, $msg) = sv_follow_actor($pdo, $sv_settings, (string)($_POST['target'] ?? ''));
            if ($ok) {
                $resolved = (stripos((string)($_POST['target'] ?? ''), 'https://') === 0)
                    ? (string)$_POST['target']
                    : (string)sv_webfinger_lookup((string)($_POST['target'] ?? ''));
                list($st, $rid) = sv_following_state($pdo, $resolved);
                $extra = ['state' => $st, 'row_id' => $rid];
            }
            break;

        case 'unfollow':
            list($ok, $msg) = sv_unfollow_actor($pdo, $sv_settings, (int)($_POST['row_id'] ?? 0));
            if ($ok) $extra = ['state' => '', 'row_id' => 0];
            break;

        case 'like':
            list($ok, $msg) = sv_like_remote($pdo, $sv_settings,
                (string)($_POST['object'] ?? ''), (string)($_POST['actor'] ?? ''));
            break;

        case 'reply':
            list($ok, $msg) = sv_reply_remote($pdo, $sv_settings,
                (string)($_POST['object'] ?? ''), (string)($_POST['actor'] ?? ''),
                (string)($_POST['content'] ?? ''));
            break;

        case 'unlike':
            list($ok, $msg) = sv_unlike_remote($pdo, $sv_settings,
                (string)($_POST['object'] ?? ''), (string)($_POST['actor'] ?? ''));
            break;

        case 'boost':
            list($ok, $msg) = sv_boost_remote($pdo, $sv_settings, (string)($_POST['object'] ?? ''));
            break;

        case 'mark_read':
            sv_mark_notifications_read($pdo);
            $ok = true; $msg = ''; $extra = ['unread' => 0];
            break;
    }
    echo json_encode(array_merge(['ok' => $ok, 'msg' => $msg], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

// ── AJAX (JSON) — must return BEFORE any chrome is emitted ──────────────────
// profile/search are LIVE: they webfinger → crawl the actor's outbox (0.7.360
// paginated collection) → return a Pixelfed-style profile + photo grid with the
// blog's follow-state so the client can offer Follow / Unfollow / Like /
// Reply. The reader timelines (home/local/global/notifications) land in the
// next phase and return a safe empty payload so those panels stay quiet.
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $panel = preg_replace('/[^a-z]/', '', (string)$_GET['ajax']);

    if ($panel === 'home') {
        if (!$sv_on) { echo json_encode(['ok' => true, 'items' => []]); exit; }
        // Real reader: the ingested inbound timeline (posts from accounts we
        // follow, pushed to our inbox). Seed with a live crawl until inbound
        // Creates accrue, so Home is never empty right after you follow someone.
        $items = sv_home_timeline($pdo, 60);
        if (!$items) { @set_time_limit(30); $items = sv_home_feed($pdo, 6, 40); }
        echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($panel === 'notifications') {
        if (!$sv_on) { echo json_encode(['ok' => true, 'items' => [], 'unread' => 0]); exit; }
        echo json_encode([
            'ok'     => true,
            'items'  => sv_notifications($pdo, 60),
            'unread' => sv_unread_count($pdo),
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($panel === 'local' || $panel === 'global') {
        if (!$sv_on) { echo json_encode(['ok' => true, 'items' => []]); exit; }
        // Discovery: a chosen instance's public timeline. Use the configured
        // home instance, else the host of an account we already follow.
        $host = trim((string)($sv_settings['smackverse_home_instance'] ?? ''));
        if ($host === '') {
            try {
                $h = (string)$pdo->query("SELECT actor_url FROM snap_ap_following WHERE state='accepted' ORDER BY followed_at DESC LIMIT 1")->fetchColumn();
                if ($h !== '') $host = parse_url($h, PHP_URL_HOST) ?: '';
            } catch (Exception $e) { /* none yet */ }
        }
        $host = preg_replace('/[^a-z0-9.\-]/i', '', $host);
        if ($host === '') {
            echo json_encode(['ok' => true, 'items' => [],
                'msg' => 'Follow someone (or set a home instance) and the ' . $panel . ' timeline lights up.']);
            exit;
        }
        @set_time_limit(30);
        echo json_encode(['ok' => true, 'items' => sv_public_timeline($host, $panel === 'local', 30)], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($panel === 'profile' || $panel === 'search') {
        // profile with no handle = this blog's own actor.
        $target = trim((string)($_GET['handle'] ?? ''));
        if ($target === '' && $panel === 'profile') {
            $target = sv_actor_url($sv_settings);
        }
        if ($target === '') {
            echo json_encode(['ok' => false, 'msg' => 'Type a handle (@user@host) or a #hashtag.']);
            exit;
        }

        // SEARCH query taxonomy (the Profile panel is always an actor lookup):
        //   • @user@host / user@host / https://…  → resolve THAT actor's profile
        //   • #tag, or any plain word              → hashtag PHOTO search
        // The fediverse has no unauthenticated full-text ACCOUNT search (you
        // need an account on an instance), so a bare word is treated as a
        // hashtag — the useful "find photos about X" default. Handles still
        // resolve exactly. Home instance = configured, else a followed host.
        if ($panel === 'search') {
            $looks_handle = (strpos($target, '@') !== false && strpos($target, ' ') === false)
                          || stripos($target, 'https://') === 0;

            // Bare word + a piggyback account → authenticated ACCOUNT search on
            // that instance (the fediverse has no public account search). Take
            // the top hit and resolve it to a profile below, reusing the profile
            // render. Explicit #tags and handles skip this; no account = old path.
            if ($target !== '' && $target[0] !== '#' && !$looks_handle
                && function_exists('sv_authed_search') && sv_pick_search_account($pdo)) {
                $sr = sv_authed_search($pdo, $sv_settings, $target, 'accounts');
                if (is_array($sr) && !empty($sr['accounts'][0])) {
                    $top  = $sr['accounts'][0];
                    $acct = (string)($top['acct'] ?? '');
                    $url  = (string)($top['url'] ?? '');
                    if ($acct !== '' && strpos($acct, '@') !== false) { $target = '@' . ltrim($acct, '@'); $looks_handle = true; }
                    elseif ($url !== '')                              { $target = $url;                     $looks_handle = true; }
                }
            }

            if ($target[0] === '#' || !$looks_handle) {
                $tag = preg_replace('/[^a-z0-9_]/i', '', ltrim($target, '#'));
                if ($tag === '') { echo json_encode(['ok' => false, 'msg' => 'Enter a hashtag like #sunset.']); exit; }
                $host = trim((string)($sv_settings['smackverse_home_instance'] ?? ''));
                if ($host === '') {
                    try {
                        $h = (string)$pdo->query("SELECT actor_url FROM snap_ap_following WHERE state='accepted' ORDER BY followed_at DESC LIMIT 1")->fetchColumn();
                        if ($h !== '') $host = parse_url($h, PHP_URL_HOST) ?: '';
                    } catch (Exception $e) { /* none yet */ }
                }
                $host = preg_replace('/[^a-z0-9.\-]/i', '', $host);
                if ($host === '') {
                    echo json_encode(['ok' => false, 'msg' => 'Follow someone (or set a home instance) so hashtag search has a server to ask.']);
                    exit;
                }
                @set_time_limit(30);
                echo json_encode([
                    'ok'    => true,
                    'mode'  => 'feed',
                    'title' => '#' . $tag,
                    'items' => sv_hashtag_timeline($host, $tag, 40),
                ], JSON_UNESCAPED_SLASHES);
                exit;
            }
        }

        $actor = sv_crawl_actor($target);
        if ($actor === null) {
            echo json_encode(['ok' => false, 'msg' => 'Could not resolve "' . $target
                . '" — check the handle (format: @user@host), or try a #hashtag to search photos.']);
            exit;
        }
        $is_self = ($actor['id'] === sv_actor_url($sv_settings));
        // Own profile shows your WHOLE feed; a remote profile is capped so we
        // don't hammer someone else's server (a "load more" is the future lift).
        $posts = sv_fetch_gallery($actor, $is_self ? 500 : 36);
        list($state, $row_id) = sv_following_state($pdo, $actor['id']);
        echo json_encode([
            'ok'        => true,
            'mode'      => 'profile',
            'actor'     => $actor,
            'posts'     => $posts,
            'state'     => $state,
            'row_id'    => $row_id,
            'is_self'   => $is_self,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Reader timelines — not wired yet.
    echo json_encode(['panel' => $panel, 'items' => [], 'wired' => false], JSON_UNESCAPED_SLASHES);
    exit;
}

// Blog actor handle for the top bar (@user@host), read from the actor document.
$sv_handle = '';
try {
    $actor_doc = sv_actor_doc($pdo, $sv_settings);
    $sv_user   = $actor_doc['preferredUsername'] ?? 'blog';
    $sv_host   = parse_url($actor_doc['id'] ?? sv_actor_url($sv_settings), PHP_URL_HOST) ?: '';
    if ($sv_host !== '') $sv_handle = '@' . $sv_user . '@' . $sv_host;
} catch (Throwable $e) { /* handle stays blank rather than break the page */ }

$sv_unread = ($sv_on && $sv_gram) ? sv_unread_count($pdo) : 0;

$page_title = "SMACKVERSE — Pixelfed";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>
<link rel="stylesheet" href="assets/css/ss-pixelfed-client.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">

<div class="main">
    <div class="header-row">
        <h2>SMACKVERSE</h2>
        <div class="header-actions">
            <div class="status-pill <?php echo $sv_on ? 'status-online' : 'status-offline'; ?>">
                <?php echo $sv_on ? 'FEDERATING' : 'OFF'; ?>
            </div>
        </div>
    </div>

    <?php if (!$sv_on): ?>
        <div class="box">
            <p>SMACKVERSE is switched off — nothing federates in or out until you flip it on in
               <a href="smack-smackverse.php">Federation</a>. The client below still loads, but stays quiet.</p>
        </div>
    <?php endif; ?>

    <?php if (!$sv_gram): ?>
        <div class="box">
            <p>The SMACKVERSE client is <strong>GRAMOFSMACK-only</strong> for now while we prove it out — your
               install mode is <strong><?php echo htmlspecialchars($sv_mode); ?></strong>. Federation itself works
               in every mode from <a href="smack-smackverse.php">Federation</a>; the interactive client widens to
               the other install modes soon.</p>
        </div>
    <?php else: ?>
    <div class="sspf-app"
         data-actor="<?php echo htmlspecialchars($sv_handle, ENT_QUOTES); ?>"
         data-enabled="<?php echo $sv_on ? '1' : '0'; ?>"
         data-unread="<?php echo (int)$sv_unread; ?>"
         data-default-panel="home">

        <div class="sspf-topbar">
            <span class="sspf-logo">SMACKVERSE</span>
            <div class="sspf-search">
                <input type="text" placeholder="@user@host  ·  #hashtag" aria-label="Search the fediverse by handle or hashtag">
            </div>
            <div class="sspf-topbar-right">
                <?php if ($sv_handle !== ''): ?>
                    <span class="sspf-actor"><?php echo htmlspecialchars($sv_handle); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="sspf-body">
            <nav class="sspf-nav">
                <a data-panel="home" class="active"><span class="sspf-ico">&#8962;</span> Home</a>
                <a data-panel="local"><span class="sspf-ico">&#9711;</span> Local</a>
                <a data-panel="global"><span class="sspf-ico">&#9673;</span> Global</a>
                <a data-panel="notifications"><span class="sspf-ico">&#9829;</span> Notifications<?php if ($sv_unread > 0): ?> <span class="sspf-badge"><?php echo (int)$sv_unread; ?></span><?php endif; ?></a>
                <a data-panel="profile"><span class="sspf-ico">&#9673;</span> Profile</a>
            </nav>

            <div class="sspf-content">
                <section class="sspf-panel active" data-panel="home">
                    <h3 class="sspf-panel-title">Home</h3>
                    <div class="sspf-panel-body">
                        <div class="sspf-note">Loading the latest photos from accounts <strong>SMACKVERSE follows</strong>…</div>
                    </div>
                </section>

                <section class="sspf-panel" data-panel="local">
                    <h3 class="sspf-panel-title">Local</h3>
                    <div class="sspf-panel-body">
                        <div class="sspf-note">Your chosen Pixelfed instance's local timeline. Wiring in progress.</div>
                    </div>
                </section>

                <section class="sspf-panel" data-panel="global">
                    <h3 class="sspf-panel-title">Global</h3>
                    <div class="sspf-panel-body">
                        <div class="sspf-note">The federated firehose. Wiring in progress.</div>
                    </div>
                </section>

                <section class="sspf-panel" data-panel="notifications">
                    <h3 class="sspf-panel-title">Notifications</h3>
                    <div class="sspf-panel-body">
                        <div class="sspf-note">Follows, likes and replies aimed at you land here once notifications ingest is wired.</div>
                    </div>
                </section>

                <section class="sspf-panel" data-panel="profile">
                    <h3 class="sspf-panel-title">Profile</h3>
                    <div class="sspf-panel-body">
                        <div class="sspf-note">Loading your blog actor <strong><?php echo htmlspecialchars($sv_handle ?: 'this blog'); ?></strong> as the fediverse sees it — rendered from your own outbox…</div>
                    </div>
                </section>

                <section class="sspf-panel" data-panel="search">
                    <h3 class="sspf-panel-title">Search</h3>
                    <div class="sspf-panel-body">
                        <div class="sspf-note">Search the bar above two ways: a handle like <strong>@user@host</strong> renders that account's real profile and posts (with follow / like / reply), or a <strong>#hashtag</strong> (or any word) pulls recent photos tagged that way from your home instance.</div>
                    </div>
                </section>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="assets/js/ss-pixelfed-client.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>" defer></script>
<?php
include 'core/admin-footer.php';
// ===== SNAPSMACK EOF =====
