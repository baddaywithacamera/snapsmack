<?php
/**
 * SNAPSMACK - SMACKVERSE - Federation
 *
 * One of the three pages split out of the old monolithic SMACKVERSE page
 * (0.7.405). Shares core/smackverse-admin-shared.php for settings, POST
 * handlers and render state; this page renders only its own sections.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
require_once 'core/auth-smack.php';
require_once 'core/smackverse.php';
require_once 'core/smackverse-admin-shared.php';

$page_title = 'SMACKVERSE - Federation';
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">

    <div class="header-row header-row--ruled">
        <h2>SMACKVERSE &mdash; FEDERATION</h2>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success">&gt; <?php echo htmlspecialchars($_GET['msg']); ?></div>
    <?php endif; ?>
    <?php if ($msg): ?>
        <div class="alert alert-warn">&gt; <?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <!-- FEDERATION SWITCH -->
    <div class="box mb-20">
        <h3>FEDERATION SWITCH</h3>
        <?php if ($sv_on): ?>
            <div class="alert alert-success">&#10003; SMACKVERSE is ON. The blog is discoverable and followable at <code><?php echo htmlspecialchars($sv_address); ?></code>.</div>
            <form method="post" action="">
                <input type="hidden" name="action" value="disable_smackverse">
                <button type="submit" class="btn-smack">DISABLE FEDERATION</button>
            </form>
            <p class="dim" style="margin-top:10px;">
                Disabling 404s every federation endpoint immediately. Followers are kept and
                pick back up if you re-enable.
            </p>
        <?php else: ?>
            <p class="dim mb-20">
                Enabling opens public federation endpoints on this site (discovery documents
                plus a signature-verified inbox — rate-limited, and unverified requests change
                nothing). It is still a new public surface, so turning it ON requires your
                password and 2FA code. New posts start federating from the moment you enable;
                nothing already published is pushed out.
            </p>
            <form method="post" action="">
                <input type="hidden" name="action" value="enable_smackverse">
                <div class="reauth-row">
                    <div class="lens-input-wrapper">
                        <label>PASSWORD</label>
                        <input type="password" name="reauth_password" autocomplete="off">
                    </div>
                    <div class="lens-input-wrapper">
                        <label>2FA CODE (IF ENABLED)</label>
                        <input type="text" name="reauth_totp" inputmode="numeric" autocomplete="off" class="input-code">
                    </div>
                </div>
                <div class="lens-input-wrapper" style="margin-top:14px;">
                    <label style="display:flex; gap:10px; align-items:flex-start; cursor:pointer; font-weight:normal;">
                        <input type="checkbox" name="participation_ack" value="1" style="margin-top:3px; flex:0 0 auto;">
                        <span class="dim">
                            <strong>The fediverse is a community, not a broadcast channel.</strong> Federating means
                            you show up: read replies, answer the people who signal on your work, follow and boost
                            others. An account that only fires images out and never engages is spam &mdash; and
                            instances defederate it. I understand participation is expected: I'm here to take part,
                            not just push pictures.
                        </span>
                    </label>
                </div>
                <button type="submit" class="master-update-btn">ENABLE SMACKVERSE</button>
            </form>
        <?php endif; ?>
    </div>

    <!-- IDENTITY -->
    <div class="box mb-20">
        <h3>FEDIVERSE HANDLE</h3>
        <p class="dim mb-20">
            The name this blog answers to. Letters, numbers, and underscores; the domain comes
            from your Site URL. <strong>Changing the handle after people follow you strands
            every follower</strong> — their apps know you by the old address.
        </p>
        <form method="post" action="">
            <input type="hidden" name="action" value="save_handle">
            <div class="lens-input-wrapper">
                <label>HANDLE</label>
                <input type="text" id="sv-handle-input" name="sv_handle" maxlength="60"
                       value="<?php echo htmlspecialchars($sv_handle); ?>" autocomplete="off">
            </div>
            <p>Will answer as:
                <code id="sv-handle-preview" data-sv-domain="<?php echo htmlspecialchars($sv_dom); ?>"><?php echo htmlspecialchars($sv_address); ?></code>
            </p>
            <?php if ($sv_on && $sv_follower_count > 0): ?>
            <label style="display:block; margin-bottom:10px;">
                <input type="checkbox" name="confirm_rename" value="1">
                I understand this strands all <?php echo (int)$sv_follower_count; ?> follower(s).
            </label>
            <?php endif; ?>
            <button type="submit" class="btn-smack">SAVE HANDLE</button>
        </form>
    </div>

    <!-- PROFILE (federated display name / website / pronouns) -->
    <div class="box mb-20">
        <h3>PROFILE</h3>
        <p class="skin-desc-text">How your blog presents as a fediverse account — the display name, website link and pronouns Pixelfed and Mastodon show on your profile. Separate from the @handle above; leave DISPLAY NAME blank to use your Site Name, PRONOUNS blank to hide them.</p>
        <form method="post" action="">
            <input type="hidden" name="action" value="save_profile">
            <div class="lens-input-wrapper">
                <label>DISPLAY NAME</label>
                <input type="text" name="sv_display_name" maxlength="120"
                       value="<?php echo htmlspecialchars((string)($sv_settings['smackverse_display_name'] ?? '')); ?>"
                       placeholder="<?php echo htmlspecialchars((string)($sv_settings['site_name'] ?? 'Your blog')); ?>" autocomplete="off">
            </div>
            <div class="lens-input-wrapper">
                <label>WEBSITE</label>
                <input type="text" name="sv_website" maxlength="200"
                       value="<?php echo htmlspecialchars((string)($sv_settings['smackverse_website'] ?? '')); ?>"
                       placeholder="<?php echo htmlspecialchars((string)($sv_settings['site_url'] ?? 'https://your.site')); ?>" autocomplete="off">
            </div>
            <div class="lens-input-wrapper">
                <label>PRONOUNS</label>
                <input type="text" name="sv_pronouns" maxlength="40"
                       value="<?php echo htmlspecialchars((string)($sv_settings['smackverse_pronouns'] ?? '')); ?>"
                       placeholder="e.g. she/her — leave blank to hide" autocomplete="off">
            </div>
            <button type="submit" class="btn-smack">SAVE PROFILE</button>
        </form>
    </div>

    <!-- ROLL CALL — fediverse.info people directory -->
    <div class="box mb-20">
        <h3>ROLL CALL &mdash; GET LISTED ON FEDIVERSE.INFO</h3>
        <?php
            $rc_on     = ($sv_settings['smackverse_rollcall'] ?? '0') === '1';
            $rc_topics = (string)($sv_settings['smackverse_rollcall_topics'] ?? 'photography');
        ?>
        <p class="dim mb-20">
            <a href="https://fediverse.info/people?topics=photography" target="_blank" rel="noopener nofollow">fediverse.info</a>
            runs a consent-first people directory &mdash; the cure for the empty-feed problem. You appear
            there <strong>only</strong> because your bio carries the <code>#fedi22</code> tag and you asked to be
            listed; drop the tag and you're gone. Flip this ON and SnapSmack adds <code>#fedi22</code> plus
            your topic tags to this blog's fediverse bio, refreshes it on the remotes, and submits your
            handle to the directory for you &mdash; they verify the tag straight off your profile. A switch
            you flip, never a default we flip.
        </p>
        <?php if (!$sv_on): ?>
            <p class="dim">Enable SMACKVERSE above first.</p>
        <?php else: ?>
            <form method="post" action="">
                <input type="hidden" name="action" value="rollcall_save">
                <label class="dim mb-20" style="display:flex; gap:10px; align-items:flex-start; cursor:pointer;">
                    <input type="checkbox" name="rollcall_enabled" value="1" <?php echo $rc_on ? 'checked' : ''; ?> style="margin-top:3px; flex:0 0 auto;">
                    <span>CARRY THE DIRECTORY TAGS IN MY BIO (<code>#fedi22</code> + topics below)</span>
                </label>
                <div class="lens-input-wrapper">
                    <label>TOPICS (COMMA-SEPARATED &mdash; THE DIRECTORY FILES YOU UNDER THESE)</label>
                    <input type="text" name="rollcall_topics" maxlength="200"
                           value="<?php echo htmlspecialchars($rc_topics); ?>"
                           placeholder="photography, nature, landscape" autocomplete="off">
                </div>
                <button type="submit" class="btn-smack">SAVE ROLL CALL</button>
            </form>
            <?php if ($rc_on): ?>
            <p class="dim" style="margin-top:14px;">
                Your bio carries the tags and your handle
                (<code><?php echo htmlspecialchars($sv_address); ?></code>) was submitted when you saved.
                Check yourself on the roll at
                <a href="https://fediverse.info/people?topics=photography" target="_blank" rel="noopener nofollow">fediverse.info/people</a>.
                If the auto-submit ever fails (it's their private endpoint &mdash; it can change), pasting your
                handle into their <strong>ADD ME</strong> box does the same thing. Flip this OFF to delist:
                SnapSmack pulls the tags, refreshes your profile, and sends the remove request too.
            </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- NETWORK RELAY -->
    <div class="box mb-20">
        <h3>SMACKVERSE NETWORK</h3>
        <?php
            $relay_joined = ($sv_settings['smackverse_relay_joined'] ?? '0') === '1';
            $relay_host   = parse_url(sv_relay_actor_url($sv_settings), PHP_URL_HOST) ?: 'smackverse.snapsmack.ca';
        ?>
        <p class="dim mb-20">Join the SnapSmack network relay and this blog's home reader fills with public posts from every participating SnapSmack site — no following each one by hand. No images are stored on the relay (photos load from the origin blog), and you keep federating directly regardless, so the relay is never a single point of failure.</p>
        <?php if (!$sv_on): ?>
            <p class="dim">Enable SMACKVERSE above first.</p>
        <?php elseif ($relay_joined): ?>
            <p>Connected to <code><?php echo htmlspecialchars($relay_host); ?></code>.</p>
            <form method="POST" onsubmit="return confirm('Leave the SMACKVERSE network relay?');">
                <input type="hidden" name="action" value="relay_leave">
                <button type="submit" class="btn-smack btn-danger">LEAVE NETWORK</button>
            </form>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="action" value="relay_join">
                <button type="submit" class="master-update-btn">JOIN NETWORK</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="box">
        <h3>PIGGYBACK SEARCH ACCOUNTS</h3>
        <p class="dim mb-20">
            The fediverse has no global index and no unauthenticated account search — a bare word can only
            find <em>hashtags</em>. Give the blog a <strong>read-only</strong> account + token on one instance
            you trust and the client can proxy that instance's authenticated search for real
            <strong>accounts and full text</strong>. Generate a token in that instance's own settings
            (read scopes are enough); it is stored <strong>encrypted</strong> and never leaves the server.
            Remove it any time here, or revoke it on the instance.
        </p>
        <?php $sv_search_accounts = function_exists('sv_list_search_accounts') ? sv_list_search_accounts($pdo) : []; ?>
        <?php if ($sv_search_accounts): ?>
        <table class="dim" style="width:100%; margin-bottom:18px; border-collapse:collapse;">
            <?php foreach ($sv_search_accounts as $sa): ?>
            <tr style="border-bottom:1px solid var(--border,#333);">
                <td style="padding:8px 6px;">
                    <strong><?php echo htmlspecialchars($sa['instance_host']); ?></strong><?php echo !empty($sa['username']) ? ' &middot; @' . htmlspecialchars($sa['username']) : ''; ?>
                </td>
                <td style="padding:8px 6px; text-align:right;">
                    <form method="post" action="" style="display:inline; margin-right:6px;">
                        <input type="hidden" name="action" value="test_search_account">
                        <input type="hidden" name="sa_id" value="<?php echo (int)$sa['id']; ?>">
                        <button type="submit" class="btn-smack">TEST</button>
                    </form>
                    <form method="post" action="" style="display:inline;"
                          onsubmit="return confirm('Remove this search account? The stored token is deleted — revoke it on the instance too if you want it dead there.');">
                        <input type="hidden" name="action" value="delete_search_account">
                        <input type="hidden" name="sa_id" value="<?php echo (int)$sa['id']; ?>">
                        <button type="submit" class="btn-smack">REMOVE</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
        <form method="post" action="" autocomplete="off">
            <input type="hidden" name="action" value="add_search_account">
            <label class="dim" style="display:block; margin-bottom:12px;">
                Instance host:
                <input type="text" name="sa_host" placeholder="pixelfed.social" style="width:220px; margin-left:6px;">
            </label>
            <label class="dim" style="display:block; margin-bottom:12px;">
                Username on that instance (optional label):
                <input type="text" name="sa_username" placeholder="yourname" style="width:220px; margin-left:6px;">
            </label>
            <label class="dim" style="display:block; margin-bottom:12px;">
                Access token:
                <input type="password" name="sa_token" placeholder="paste a read-scope token" style="width:320px; margin-left:6px;" autocomplete="new-password">
            </label>
            <div class="reauth-row" style="margin:14px 0;">
                <label class="dim" style="display:block; margin-bottom:8px;">Confirm password:
                    <input type="password" name="reauth_password" autocomplete="off" style="margin-left:6px;">
                </label>
                <label class="dim" style="display:block;">2FA code:
                    <input type="text" name="reauth_totp" inputmode="numeric" autocomplete="off" class="input-code" style="margin-left:6px;">
                </label>
            </div>
            <button type="submit" class="btn-smack">ADD SEARCH ACCOUNT</button>
        </form>
    </div>

</div>

<script src="assets/js/ss-engine-smackverse-admin.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>"></script>
<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
