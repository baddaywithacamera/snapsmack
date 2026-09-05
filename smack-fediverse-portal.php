<?php
/**
 * SNAPSMACK - FEDIVERSE - Federation
 *
 * One of the three pages split out of the old monolithic FEDIVERSE page
 * (0.7.405). Shares core/fediverse-admin-shared.php for settings, POST
 * handlers and render state; this page renders only its own sections.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
require_once 'core/auth-smack.php';
require_once 'core/fediverse.php';
require_once 'core/fediverse-admin-shared.php';

$page_title = 'Fediverse - Portal';
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">

    <div class="header-row header-row--ruled">
        <h2>FEDIVERSE &mdash; PORTAL</h2>
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
            <div class="alert alert-success">&#10003; Fediverse is ON. The blog is discoverable and followable at <code><?php echo htmlspecialchars($sv_address); ?></code>.</div>
            <form method="post" action="">
                <input type="hidden" name="action" value="disable_fediverse">
                <button type="submit" class="btn-smack">DISABLE FEDERATION</button>
            </form>
            <p class="dim mt-10">
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
                <input type="hidden" name="action" value="enable_fediverse">
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
                <div class="lens-input-wrapper mt-14">
                    <label style="display:flex; gap:10px; align-items:flex-start; cursor:pointer; font-weight:normal;">
                        <input type="checkbox" name="participation_ack" value="1" class="mt-3 flex-none">
                        <span class="dim">
                            <strong>The fediverse is a community, not a broadcast channel.</strong> Federating means
                            you show up: read replies, answer the people who signal on your work, follow and boost
                            others. An account that only fires images out and never engages is spam &mdash; and
                            instances defederate it. I understand participation is expected: I'm here to take part,
                            not just push pictures.
                        </span>
                    </label>
                </div>
                <button type="submit" class="master-update-btn">ENABLE FEDIVERSE</button>
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
                       value="<?php echo htmlspecialchars($sv_handle_raw); ?>" autocomplete="off"
                       required placeholder="choose a handle — e.g. relay">
            </div>
            <p>Will answer as:
                <code id="sv-handle-preview" data-sv-domain="<?php echo htmlspecialchars($sv_dom); ?>"><?php echo htmlspecialchars($sv_handle_raw !== '' ? $sv_address : '@…@' . $sv_dom); ?></code>
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
        <p class="skin-desc-text">How your blog presents as a fediverse account — the display name, website link and pronouns Pixelfed and Mastodon show on your profile. Separate from the @handle above; leave DISPLAY NAME blank to use your Site Name, PRONOUNS blank to hide them. Your <strong>avatar</strong> comes from Pimp Your Ride &rarr; Smooth Your Skin and your <strong>bio</strong> from your site description in Settings — this box only sets the fediverse-specific fields, it doesn't duplicate them.</p>
        <form method="post" action="">
            <input type="hidden" name="action" value="save_profile">
            <div class="lens-input-wrapper">
                <label>DISPLAY NAME</label>
                <input type="text" name="sv_display_name" maxlength="120"
                       value="<?php echo htmlspecialchars((string)($sv_settings['fediverse_display_name'] ?? '')); ?>"
                       placeholder="<?php echo htmlspecialchars((string)($sv_settings['site_name'] ?? 'Your blog')); ?>" autocomplete="off">
            </div>
            <div class="lens-input-wrapper">
                <label>WEBSITE</label>
                <input type="text" name="sv_website" maxlength="200"
                       value="<?php echo htmlspecialchars((string)($sv_settings['fediverse_website'] ?? '')); ?>"
                       placeholder="<?php echo htmlspecialchars((string)($sv_settings['site_url'] ?? 'https://your.site')); ?>" autocomplete="off">
            </div>
            <div class="lens-input-wrapper">
                <label>PRONOUNS</label>
                <input type="text" name="sv_pronouns" maxlength="40"
                       value="<?php echo htmlspecialchars((string)($sv_settings['fediverse_pronouns'] ?? '')); ?>"
                       placeholder="e.g. she/her — leave blank to hide" autocomplete="off">
            </div>
            <button type="submit" class="btn-smack">SAVE PROFILE</button>
        </form>
        <!-- Same action the Followers page offers. It belongs HERE too: this is
             the box that edits the profile, and its save message tells you to
             push — the button must be on the page that says so. Pushes display
             name, bio AND avatar to every follower's server, right now. -->
        <form method="post" action="" class="mt-8">
            <input type="hidden" name="action" value="push_profile_update">
            <button type="submit" class="btn-smack">REFRESH PROFILE ON REMOTES (NAME, BIO &amp; AVATAR)</button>
        </form>
    </div>

    <!-- ROLL CALL — fediverse.info people directory -->
    <div class="box mb-20">
        <h3>ROLL CALL &mdash; GET LISTED ON FEDIVERSE.INFO</h3>
        <?php
            $rc_on     = ($sv_settings['fediverse_rollcall'] ?? '0') === '1';
            $rc_topics = (string)($sv_settings['fediverse_rollcall_topics'] ?? 'photography');
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
            <p class="dim">Enable Fediverse above first.</p>
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
            <p class="dim mt-14">
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
        <h3>FEDIVERSE NETWORK</h3>
        <?php
            $relay_joined = ($sv_settings['photoblogs_relay_joined'] ?? '0') === '1';
            $relay_host   = parse_url(sv_relay_actor_url($sv_settings), PHP_URL_HOST) ?: 'photoblogs.fyi';
        ?>
        <p class="dim mb-20">Join the SnapSmack network relay and this blog's LOCAL reader fills with public posts from every participating SnapSmack site — no following each one by hand. HOME remains the people this blog follows directly. No images are stored on the relay (photos load from the origin blog), and you keep federating directly regardless, so the relay is never a single point of failure.</p>
        <?php if (!$sv_on): ?>
            <p class="dim">Enable Fediverse above first.</p>
        <?php elseif ($relay_joined): ?>
            <p>Connected to <code><?php echo htmlspecialchars($relay_host); ?></code>.</p>
            <form method="POST" onsubmit="return confirm('Leave the Fediverse network relay?');">
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

    <?php if ($sc_is_hub_install): ?>
    <div class="box mb-20">
        <h3>SMACKCAST HUB</h3>
        <p class="dim">Relay and seven-day missed-notification recovery are
            <strong><?php echo (($sv_settings['smackcast_relay_enabled'] ?? '0') === '1') ? 'ENABLED' : 'DISABLED'; ?></strong>.
            Enabling is deliberate; admission remains allowlist/pending by default.</p>
        <form method="POST">
            <input type="hidden" name="action" value="smackcast_toggle">
            <input type="hidden" name="enabled" value="<?php echo (($sv_settings['smackcast_relay_enabled'] ?? '0') === '1') ? '0' : '1'; ?>">
            <input type="password" name="reauth_password" placeholder="Password" autocomplete="off" required>
            <input type="text" name="reauth_totp" placeholder="2FA code" inputmode="numeric" autocomplete="off">
            <button type="submit" class="btn-smack"><?php echo (($sv_settings['smackcast_relay_enabled'] ?? '0') === '1') ? 'DISABLE RELAY' : 'ENABLE RELAY'; ?></button>
        </form>
        <h4 style="margin-top:18px;">MEMBERS</h4>
        <?php if (!$sc_subscribers): ?><p class="dim">No relay members yet.</p><?php endif; ?>
        <?php foreach ($sc_subscribers as $member): ?>
            <form method="POST" style="display:flex;gap:8px;align-items:center;margin:8px 0;flex-wrap:wrap;">
                <input type="hidden" name="action" value="smackcast_member">
                <input type="hidden" name="subscriber_id" value="<?php echo (int)$member['id']; ?>">
                <code><?php echo htmlspecialchars($member['actor_url']); ?></code>
                <strong><?php echo htmlspecialchars(strtoupper($member['state'])); ?></strong>
                <select name="member_state"><option value="active">Approve</option><option value="blocked">Block</option><option value="left">Remove</option></select>
                <input type="password" name="reauth_password" placeholder="Password" autocomplete="off" required>
                <input type="text" name="reauth_totp" placeholder="2FA" inputmode="numeric" autocomplete="off">
                <button type="submit" class="btn-smack">APPLY</button>
            </form>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- FLEET RELAY JOIN — any hub with connected multisite spokes -->
    <?php if ($sc_fleet_spoke_count > 0): ?>
    <div class="box mb-20">
        <h3>FLEET</h3>
        <p class="dim mb-20">Join every connected spoke to the relay in one pass. Each blog is reviewed
            first: one with federation off, no chosen handle, or the old domain-as-handle default is
            flagged for fixing FIRST — it never joins as-is. Joining needs your password and 2FA code.</p>

        <?php if ($sc_fleet_results): ?>
            <table class="data-table w-100 mb-20">
                <thead><tr><th>Blog</th><th>Result</th><th>Detail</th></tr></thead>
                <tbody>
                <?php foreach ($sc_fleet_results as $fr): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars((string)$fr['site']); ?></strong></td>
                        <td><strong><?php echo !empty($fr['ok']) ? '&#10003; JOINED' : '&#10007; NOT JOINED'; ?></strong></td>
                        <td><?php echo htmlspecialchars((string)$fr['msg']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($sc_fleet_review === null): ?>
            <a class="btn-smack" href="<?php echo htmlspecialchars($sv_self); ?>?fleet_review=1">REVIEW FLEET (<?php echo (int)$sc_fleet_spoke_count; ?> SPOKES)</a>
        <?php elseif (!$sc_fleet_review): ?>
            <p class="dim">No connected spokes found.</p>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="action" value="fleet_join">
                <table class="data-table w-100 mb-20">
                    <thead><tr><th>Join</th><th>Blog</th><th>Handle</th><th>State</th></tr></thead>
                    <tbody>
                    <?php $ftarget_host = strtolower((string)(parse_url($sc_fleet_relay_target, PHP_URL_HOST) ?: ''));
                    foreach ($sc_fleet_review as $frow):
                        $fnode    = $frow['node'];
                        $fstatus  = $frow['status'];
                        $fname    = (string)($fnode['site_name'] ?: $fnode['site_url']);
                        $fhandle  = is_array($fstatus) ? trim((string)($fstatus['handle'] ?? '')) : '';
                        $fdomain  = is_array($fstatus) ? (string)($fstatus['domain'] ?? '') : '';
                        // Joined/pending count ONLY against the relay this hub is
                        // joining to — a blog sitting on some other relay is not
                        // "already on" this one.
                        $frelay_host = is_array($fstatus) ? strtolower((string)(parse_url((string)($fstatus['relay_url'] ?? ''), PHP_URL_HOST) ?: '')) : '';
                        $fsame_relay = $frelay_host !== '' && $frelay_host === $ftarget_host;
                        $fstate      = is_array($fstatus) ? strtolower((string)($fstatus['join_state'] ?? '')) : '';
                        $fjoined  = $fsame_relay && !empty($fstatus['relay_joined'])
                                    && in_array($fstate, ['accepted', 'active'], true);
                        $fpending = $fsame_relay && !$fjoined && !empty($fstatus['relay_joined'])
                                    && $fstate === 'pending';
                        $fready   = is_array($fstatus) && !$frow['problems'] && !$fjoined && !$fpending;
                        $ffix_url = rtrim((string)$fnode['site_url'], '/') . '/smack-fediverse-portal.php';
                    ?>
                    <tr>
                        <td>
                            <?php if ($fready): ?>
                                <label><input type="checkbox" name="spoke_ids[]" value="<?php echo (int)$fnode['id']; ?>" checked> JOIN</label>
                            <?php elseif ($fjoined || $fpending): ?>
                                &#10003;
                            <?php else: ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                        <td><strong><?php echo htmlspecialchars($fname); ?></strong><br>
                            <a href="<?php echo htmlspecialchars($ffix_url); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars((string)(parse_url((string)$fnode['site_url'], PHP_URL_HOST) ?: $fnode['site_url'])); ?></a></td>
                        <td><code><?php echo $fhandle !== '' ? htmlspecialchars('@' . $fhandle . '@' . $fdomain) : '&mdash;'; ?></code></td>
                        <td>
                            <?php if ($fjoined): ?>
                                Already on the relay (<?php echo htmlspecialchars($frelay_host); ?>)
                            <?php elseif ($fpending): ?>
                                Join sent &mdash; awaiting the relay's Accept (no need to rejoin)
                            <?php elseif ($frow['problems']): ?>
                                &#9888; <?php echo htmlspecialchars(implode('; ', $frow['problems'])); ?>
                                &mdash; <a href="<?php echo htmlspecialchars($ffix_url); ?>" target="_blank" rel="noopener"><strong>FIX IT</strong></a>, then review again
                            <?php elseif (!empty($fstatus['relay_joined']) && $frelay_host !== '' && !$fsame_relay): ?>
                                Ready &mdash; currently on a different relay (<?php echo htmlspecialchars($frelay_host); ?>); joining moves it here
                            <?php else: ?>
                                Ready
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($sc_fleet_join_allowed): ?>
                <p class="dim">Ticked blogs join <code><?php echo htmlspecialchars($sc_fleet_relay_target); ?></code><?php echo $sc_is_hub_install ? ' (this blog is the relay)' : ' (the relay this blog is joined to)'; ?>.
                    Each blog is re-checked at join time; a flagged blog is refused even if submitted.</p>
                <div class="reauth-row">
                    <div class="lens-input-wrapper">
                        <label>PASSWORD</label>
                        <input type="password" name="reauth_password" autocomplete="off" required>
                    </div>
                    <div class="lens-input-wrapper">
                        <label>2FA CODE</label>
                        <input type="text" name="reauth_totp" inputmode="numeric" autocomplete="off" class="input-code">
                    </div>
                </div>
                <button type="submit" class="master-update-btn">JOIN SELECTED TO NETWORK</button>
                <?php else: ?>
                <p class="dim">&#9888; No valid relay is configured for this hub, so there is nothing to join to.</p>
                <?php endif; ?>
                <a class="btn-smack" href="<?php echo htmlspecialchars($sv_self); ?>?fleet_review=1">RE-REVIEW</a>
            </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($sc_is_hub_install):
        $curator_on = ($sv_settings['curator_directory_enabled'] ?? '0') === '1';
        $curator_identity_ok = function_exists('sc_curator_is_hub') && sc_curator_is_hub($sv_settings);
        $curator_counts = [];
        $curator_rows = [];
        if (function_exists('sc_curator_ensure_tables')) {
            try {
                sc_curator_ensure_tables($pdo);
                $curator_counts = $pdo->query("SELECT state,COUNT(*) n FROM snap_curator_directory GROUP BY state")
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
                $curator_rows = $pdo->query("SELECT acct,actor_url,state,last_seen_at,last_checked_at,last_error
                    FROM snap_curator_directory ORDER BY first_seen_at,id LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {}
        }
    ?>
    <div class="box mb-20">
        <h3>PHOTOGRAPHY CURATOR</h3>
        <p class="dim mb-20">
            <code>@curator@photoblogs.fyi</code> follows people who explicitly opted into the
            <a href="https://fediverse.info/people?topics=photography" target="_blank" rel="noopener nofollow">fediverse.info photography directory</a>.
            This uses their public JSON directory, not page scraping. Intake is limited to one
            follow every 15 minutes, with no more than one follow per destination server per hour.
            The current list takes at least three days and may take longer when many people share
            one server. Connected hub sites
            are excluded. A complete monthly rescan unfollows directory-managed accounts that
            disappeared, and three failed actor checks retire dead accounts. Manual follows are
            never removed or claimed by this worker.
        </p>
        <?php if (!$curator_identity_ok): ?>
            <div class="alert alert-warn">BLOCKED — the secondary curator identity is available only on the photoblogs.fyi hub.</div>
        <?php else: ?>
            <p><strong><?php echo $curator_on ? 'RUNNING' : 'PAUSED'; ?></strong>
                &middot; discovered <?php echo (int)($curator_counts['discovered'] ?? 0); ?>
                &middot; following/pending <?php echo (int)($curator_counts['following'] ?? 0); ?>
                &middot; healthy <?php echo (int)($curator_counts['followed'] ?? 0); ?>
                &middot; excluded hub members <?php echo (int)($curator_counts['excluded'] ?? 0); ?>
                &middot; removed/invalid/rejected <?php echo (int)(($curator_counts['removed'] ?? 0) + ($curator_counts['invalid'] ?? 0) + ($curator_counts['rejected'] ?? 0)); ?></p>
            <p class="dim">Last complete directory scan:
                <strong><?php echo htmlspecialchars((string)($sv_settings['curator_scan_completed_at'] ?? 'never')); ?></strong>
                <?php if (!empty($sv_settings['curator_last_error'])): ?>&middot; Last error:
                    <?php echo htmlspecialchars((string)$sv_settings['curator_last_error']); ?><?php endif; ?></p>
            <form method="POST" style="display:inline-block;margin-right:12px;">
                <input type="hidden" name="action" value="curator_toggle">
                <input type="hidden" name="enabled" value="<?php echo $curator_on ? '0' : '1'; ?>">
                <input type="password" name="reauth_password" placeholder="Password" autocomplete="off" required>
                <input type="text" name="reauth_totp" placeholder="2FA" inputmode="numeric" autocomplete="off">
                <button type="submit" class="btn-smack"><?php echo $curator_on ? 'PAUSE CURATOR' : 'START CURATOR'; ?></button>
            </form>
            <?php if ($curator_on): ?>
            <form method="POST" style="display:inline-block;">
                <input type="hidden" name="action" value="curator_run">
                <input type="password" name="reauth_password" placeholder="Password" autocomplete="off" required>
                <input type="text" name="reauth_totp" placeholder="2FA" inputmode="numeric" autocomplete="off">
                <button type="submit" class="btn-smack">RUN ONE PACED STEP</button>
            </form>
            <?php endif; ?>
            <details style="margin-top:18px;">
                <summary>SHOW STORED ACCOUNTS (<?php echo count($curator_rows); ?>)</summary>
                <div style="overflow:auto;max-height:430px;margin-top:12px;">
                <table class="dim" style="width:100%;border-collapse:collapse;">
                    <thead><tr><th style="text-align:left;">ACCOUNT</th><th style="text-align:left;">STATE</th><th style="text-align:left;">LAST SEEN</th><th style="text-align:left;">LAST CHECK</th><th style="text-align:left;">DETAIL</th></tr></thead>
                    <tbody><?php foreach ($curator_rows as $cr): ?><tr class="border-b">
                        <td class="p-8-6"><?php if (!empty($cr['actor_url'])): ?><a href="<?php echo htmlspecialchars($cr['actor_url']); ?>" target="_blank" rel="noopener nofollow"><?php echo htmlspecialchars($cr['acct']); ?></a><?php else: ?><?php echo htmlspecialchars($cr['acct']); ?><?php endif; ?></td>
                        <td class="p-8-6"><strong><?php echo htmlspecialchars($cr['state']); ?></strong></td>
                        <td class="p-8-6"><?php echo htmlspecialchars($cr['last_seen_at'] ?? ''); ?></td>
                        <td class="p-8-6"><?php echo htmlspecialchars($cr['last_checked_at'] ?? 'not yet'); ?></td>
                        <td class="p-8-6"><?php echo htmlspecialchars($cr['last_error'] ?? ''); ?></td>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
            </details>
        <?php endif; ?>
    </div>
    <?php endif; ?>

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
            <tr class="border-b">
                <td class="p-8-6">
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
            <label class="dim d-block mb-12">
                Instance host:
                <input type="text" name="sa_host" placeholder="pixelfed.social" style="width:220px; margin-left:6px;">
            </label>
            <label class="dim d-block mb-12">
                Username on that instance (optional label):
                <input type="text" name="sa_username" placeholder="yourname" style="width:220px; margin-left:6px;">
            </label>
            <label class="dim d-block mb-12">
                Access token:
                <input type="password" name="sa_token" placeholder="paste a read-scope token" style="width:320px; margin-left:6px;" autocomplete="new-password">
            </label>
            <div class="reauth-row" style="margin:14px 0;">
                <label class="dim" style="display:block; margin-bottom:8px;">Confirm password:
                    <input type="password" name="reauth_password" autocomplete="off" class="ml-6">
                </label>
                <label class="dim" style="display:block;">2FA code:
                    <input type="text" name="reauth_totp" inputmode="numeric" autocomplete="off" class="input-code ml-6">
                </label>
            </div>
            <button type="submit" class="btn-smack">ADD SEARCH ACCOUNT</button>
        </form>
    </div>

</div>

<script src="assets/js/ss-engine-fediverse-admin.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>"></script>
<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
