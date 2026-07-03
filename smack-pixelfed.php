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

// ── AJAX (JSON) — must return BEFORE any chrome is emitted ──────────────────
// Phase 1: the reader endpoints aren't wired yet, so every panel returns a safe
// empty payload. The client treats "not wired" as a static placeholder, so this
// never errors; each panel goes live as its endpoint lands.
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $panel = preg_replace('/[^a-z]/', '', (string)$_GET['ajax']);
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

    <div class="sspf-app"
         data-actor="<?php echo htmlspecialchars($sv_handle, ENT_QUOTES); ?>"
         data-enabled="<?php echo $sv_on ? '1' : '0'; ?>"
         data-default-panel="home">

        <div class="sspf-topbar">
            <span class="sspf-logo">SMACKVERSE</span>
            <div class="sspf-search">
                <input type="text" placeholder="Search people — @user@host" aria-label="Search the fediverse">
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
                <a data-panel="notifications"><span class="sspf-ico">&#9829;</span> Notifications</a>
                <a data-panel="profile"><span class="sspf-ico">&#9673;</span> Profile</a>
                <a data-panel="search"><span class="sspf-ico">&#9906;</span> Search</a>
            </nav>

            <div class="sspf-content">
                <section class="sspf-panel active" data-panel="home">
                    <h3 class="sspf-panel-title">Home</h3>
                    <div class="sspf-panel-body">
                        <div class="sspf-note">Posts from accounts <strong>SMACKVERSE follows</strong> appear here once the reader ingest lands (next phase).</div>
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
                        <div class="sspf-note">Follows, applause and replies aimed at you land here once notifications ingest is wired.</div>
                    </div>
                </section>

                <section class="sspf-panel" data-panel="profile">
                    <h3 class="sspf-panel-title">Profile</h3>
                    <div class="sspf-panel-body">
                        <div class="sspf-note">Your blog actor <strong><?php echo htmlspecialchars($sv_handle ?: 'this blog'); ?></strong> as the fediverse sees it — rendered from your own outbox. Wiring in progress.</div>
                    </div>
                </section>

                <section class="sspf-panel" data-panel="search">
                    <h3 class="sspf-panel-title">Search</h3>
                    <div class="sspf-panel-body">
                        <div class="sspf-note">Type a handle like <strong>@user@host</strong> above. Their real Pixelfed profile and posts render here from a crawl of their outbox — with follow / like / reply — replacing the old follow box. Wiring in progress.</div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/ss-pixelfed-client.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>" defer></script>
<?php
include 'core/admin-footer.php';
// ===== SNAPSMACK EOF =====
