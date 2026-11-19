<?php
/**
 * SNAPSMACK.CA — Support and self-hosting guide
 *
 * Practical operator guidance for shared hosting, VPS and homelab installs.
 * Copy is deliberately neutral: it is project documentation, not Sean's voice.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$page_title       = 'Support — Run SNAPSMACK on shared hosting, a VPS or at home';
$page_description = 'Practical SNAPSMACK support: verified hosting requirements, VPS and homelab checklists, Cloudflare setup, trusted proxies, backups, restore limits and how to get help.';
$page_og_url      = 'https://snapsmack.ca/support.php';
$nav_active       = 'support';

$page_css = <<<'CSS'
.support-intro { max-width: 880px; padding: 24px 0 12px; }
.support-intro p { max-width: 74ch; margin-bottom: 1.25em; }
.support-jumps { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); border-top: 1px solid var(--border); border-left: 1px solid var(--border); margin-top: 18px; }
.support-jumps a { display: block; padding: 18px 20px; color: var(--black); background: var(--white); border-right: 1px solid var(--border); border-bottom: 1px solid var(--border); }
.support-jumps a:hover { text-decoration: none; box-shadow: inset 0 -4px 0 var(--red); }
.support-jumps strong { display: block; font: 900 .86rem/1.2 Arial Black, Arial, sans-serif; text-transform: uppercase; }
.support-jumps strong::after { content: " \2193"; color: var(--red); }
.support-jumps span { display: block; margin-top: 5px; color: var(--mid-grey); font-size: .82rem; line-height: 1.45; }
.support-section { padding: 58px 0 72px; border-top: 8px solid var(--black); scroll-margin-top: 78px; }
.support-section:nth-of-type(even) { background: var(--light-grey); }
.support-copy { max-width: 880px; }
.support-copy h2 { margin-bottom: 10px; }
.support-copy h3 { margin: 34px 0 12px; font-size: 1.05rem; color: var(--red); }
.support-copy p, .support-copy ul, .support-copy ol { max-width: 74ch; }
.support-copy li { margin-bottom: .7em; }
.support-note { max-width: 74ch; margin: 24px 0; padding: 20px 24px; background: var(--white); border-left: 4px solid var(--red); }
.support-note p:last-child { margin-bottom: 0; }
.support-code { max-width: 74ch; overflow-x: auto; padding: 18px 20px; color: #f4f4f4; background: #111; border-left: 4px solid var(--red); font: .86rem/1.55 Consolas, Monaco, monospace; white-space: pre; }
.support-checks { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; max-width: 880px; margin: 24px 0; }
.support-check { padding: 20px 22px; background: var(--white); border: 1px solid var(--border); }
.support-check h3 { margin: 0 0 10px; color: var(--black); }
.support-check p { margin: 0; font-size: .92rem; }
@media (max-width: 850px) { .support-jumps { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 700px) { .support-jumps, .support-checks { grid-template-columns: 1fr; } }
CSS;

require_once __DIR__ . '/includes/header.php';
?>

<main>
    <div class="page-header">
        <div class="wrap">
            <h1>SUPPORT.</h1>
            <p class="lede">Run it somewhere sensible. Keep it recoverable. Know where to ask when it bites.</p>
        </div>
    </div>

    <section>
        <div class="wrap">
            <div class="support-intro">
                <p>SNAPSMACK is self-hosted software. That gives you control of the site, the database and the photographs, but it also makes the hosting account part of the system. This guide covers the supported path on ordinary shared hosting, the extra work that comes with a VPS or homelab, and the information to collect before asking for help.</p>
                <p>The short version: keep the server current, put Cloudflare in front of it, run verified backups off the box, and do not treat a backup as real until you have tested a restore.</p>
            </div>
            <nav class="support-jumps" aria-label="Jump to a support section">
                <a href="#shared-hosting"><strong>Shared hosting</strong><span>Verified requirements and what to ask the host.</span></a>
                <a href="#vps"><strong>VPS</strong><span>Web server, PHP-FPM, database, TLS and firewall.</span></a>
                <a href="#homelab"><strong>Self-hosting</strong><span>Power, uptime and backups away from home.</span></a>
                <a href="#cloudflare"><strong>Cloudflare</strong><span>Free front door, safe cache rules and real client IPs.</span></a>
                <a href="#backups"><strong>Backups</strong><span>What to copy, where to keep it and what restore means today.</span></a>
                <a href="#help"><strong>Getting help</strong><span>The forum, useful evidence and the ticket path.</span></a>
            </nav>
        </div>
    </section>

    <section class="support-section" id="shared-hosting">
        <div class="wrap support-copy">
            <p class="site-discovery-kicker">The easiest route</p>
            <h2>Shared hosting</h2>
            <p>A normal Linux hosting account is the smallest supported setup. The installer enforces the requirements that matter to the running CMS:</p>
            <ul>
                <li><strong>PHP 8.3 or newer</strong>, with <strong>PDO MySQL</strong>, <strong>GD</strong>, <strong>EXIF</strong> and <strong>libsodium</strong>.</li>
                <li><strong>MySQL or MariaDB.</strong> The public requirements currently use MySQL 5.7 or the MariaDB equivalent as the floor. The installer does not enforce a database-server version, so use a currently supported release rather than relying on an old host that merely accepts the connection.</li>
                <li><strong>ZIP support</strong> for the browser bootstrap installer, plus either <strong>cURL</strong> or <code>allow_url_fopen</code> so it can fetch a signed release. Manual installation avoids the bootstrap download but not the running CMS requirements above.</li>
                <li><strong>Writable application files</strong> for installation and signed updates, and enough disk for the photograph archive. The photographs, not the CMS, are what consume space.</li>
                <li><strong>Cron and command-line PHP are strongly preferred.</strong> SNAPSMACK can self-register scheduled jobs when the account exposes <code>crontab</code>, PHP CLI and <code>exec()</code>. Some work falls back to page visits when cron is unavailable, but that is less reliable.</li>
            </ul>
            <div class="support-note">
                <p><strong>Ask the host:</strong> Can this account run PHP 8.3+, enable PDO MySQL, GD, EXIF, sodium and ZIP, make outbound HTTPS requests, and schedule per-account cron jobs with PHP CLI? If the answer is vague, ask for the exact PHP modules and limits in writing.</p>
            </div>
            <h3>Common shared-hosting failures</h3>
            <ul>
                <li>The control panel shows PHP 8.3, but cron still runs an older command-line PHP binary.</li>
                <li>The account can upload photographs but its memory, request-body or execution-time limit is too small for the largest originals.</li>
                <li>Outbound HTTPS is blocked, so signed updates, skin downloads or external services cannot be reached.</li>
                <li>The application directory is not writable by PHP, so installation or update replacement stops safely instead of completing halfway.</li>
            </ul>
        </div>
    </section>

    <section class="support-section" id="vps">
        <div class="wrap support-copy">
            <p class="site-discovery-kicker">More control, more responsibility</p>
            <h2>VPS checklist</h2>
            <div class="support-checks">
                <div class="support-check"><h3>Web stack</h3><p>Linux, Apache or nginx, PHP-FPM 8.3+, and a current MySQL or MariaDB release. Enable the same PHP extensions listed above.</p></div>
                <div class="support-check"><h3>TLS</h3><p>Issue a real origin certificate, redirect HTTP to HTTPS, and renew it automatically. Cloudflare Full (strict) still requires a valid certificate at the origin.</p></div>
                <div class="support-check"><h3>Updates</h3><p>Apply operating-system, web-server, PHP and database security updates. SNAPSMACK's updater covers SNAPSMACK, not the machine underneath it.</p></div>
                <div class="support-check"><h3>Firewall</h3><p>Expose only what the site needs. Keep database, management and hypervisor interfaces off the public internet.</p></div>
            </div>
            <h3>Reverse proxies and the real visitor address</h3>
            <p>Cloudflare needs no entry in SNAPSMACK's trusted-proxy setting; its published address ranges are built in. Any other reverse proxy &mdash; nginx in front of Apache, Traefik, HAProxy or a VPS load balancer &mdash; must be listed under <strong>Configuration &rarr; Trusted Proxies</strong> by exact IP or CIDR range.</p>
            <p>If a proxy is not trusted, SNAPSMACK deliberately ignores the forwarded-address header and treats the proxy as the client. That fails safely, but every visitor can then appear to share one address and one rate-limit bucket. Never solve that by trusting the whole internet.</p>
            <h3>nginx must deny database dumps itself</h3>
            <p>Apache installs receive a root <code>.htaccess</code> rule that blocks <code>.sql</code> files, including pre-update and pre-restore snapshots. nginx does not read <code>.htaccess</code>. Add an nginx rule before the general PHP handler and verify that a request for a made-up file under <code>/backups/</code> returns 404:</p>
            <pre class="support-code"><code>location ^~ /backups/ {
    return 404;
}

location ~* \.sql$ {
    return 404;
}</code></pre>
            <p>Reload nginx only after its configuration test passes. The exact file layout belongs in the server's configuration management, not in the public web directory.</p>
        </div>
    </section>

    <section class="support-section" id="homelab">
        <div class="wrap support-copy">
            <p class="site-discovery-kicker">The server lives with you</p>
            <h2>Self-hosting and homelab</h2>
            <p>A Proxmox VM or container can run SNAPSMACK well. It also makes the house, power supply, internet connection and hypervisor part of the publishing system.</p>
            <ul>
                <li>Give the site a stable internal address and place it behind a maintained reverse proxy. Do not expose Proxmox or database management to the public internet.</li>
                <li>Use a UPS if uptime matters, and make sure services recover after a power loss without manual login.</li>
                <li>Monitor disk space, certificate renewal, scheduled-job heartbeats and the public URL from outside the home connection.</li>
                <li>Keep at least one verified backup <strong>off the host and off the property</strong>. A snapshot on the same disk is convenient rollback, not disaster recovery.</li>
                <li>Document the DNS, tunnel or port-forward path. Future-you should not have to reconstruct the network while the site is down.</li>
            </ul>
        </div>
    </section>

    <section class="support-section" id="cloudflare">
        <div class="wrap support-copy">
            <p class="site-discovery-kicker">Free and strongly recommended</p>
            <h2>Put Cloudflare in front</h2>
            <ol>
                <li>Add the domain to a free Cloudflare account and replace the domain's nameservers with the assigned pair.</li>
                <li>Point the web records at the origin and turn the orange proxy cloud on.</li>
                <li>Set SSL/TLS mode to <strong>Full (strict)</strong>. Install a valid public or Cloudflare Origin certificate on the server; never use Flexible mode.</li>
                <li>Cache static assets such as CSS, JavaScript and images. Do not add a blanket &ldquo;cache everything&rdquo; rule for PHP or HTML.</li>
                <li>Explicitly bypass cache for admin, login, cron and API traffic, including <code>/smack-*</code>, <code>/snap-in.php</code> and authenticated requests. Challenges on API routes can also break desktop tools.</li>
                <li>Enable the free bot protection that fits the site. Use <strong>Under Attack</strong> mode for an active flood, not as a permanent setting that challenges every reader.</li>
                <li>After a deployment, purge Cloudflare's cached files and verify the visible version tag. A successful origin update is not proof that the edge stopped serving the old copy.</li>
            </ol>
            <div class="support-note">
                <p><strong>Real client IPs:</strong> SNAPSMACK recognizes Cloudflare's published proxy ranges and only accepts <code>CF-Connecting-IP</code> from a trusted peer. A visitor cannot make the site trust that header on a direct request.</p>
                <p>Cloudflare's built-in ranges are a maintained code dependency. Review them when Cloudflare publishes network changes, just as you would review any other proxy allowlist.</p>
            </div>
        </div>
    </section>

    <section class="support-section" id="backups">
        <div class="wrap support-copy">
            <p class="site-discovery-kicker">A copy is not yet a recovery</p>
            <h2>Backups and restore</h2>
            <p><a href="tool-suyb.php">SMACK UP YOUR BACKUP</a> is the desktop backup tool. Keep more than one generation and copy at least one to storage that is not the web server. A complete plan includes the database, photographs, media assets, branding and any site-specific files you would need after a blank-machine rebuild.</p>
            <p>The Recovery Kit restore path is being hardened. Current work is adding pre-restore snapshots, strict manifest and compatibility checks, step-up confirmation, schema synchronization and honest reporting of partial recovery. Until that work ships and passes a real round-trip test, do not describe the in-admin path as one-click disaster recovery.</p>
            <ul>
                <li>Run the backup and read its completion evidence; the presence of a ZIP alone does not prove verification or a configured cloud copy finished.</li>
                <li>Test restores on an isolated throwaway install. Do not discover that the credentials, database or photographs are incomplete while replacing the only live copy.</li>
                <li>Protect backup files as secrets. Database dumps can contain password hashes, API material and private configuration.</li>
                <li>Record what was tested, when, and against which backup. &ldquo;The job was green&rdquo; and &ldquo;the site was recovered&rdquo; are different facts.</li>
            </ul>
        </div>
    </section>

    <section class="support-section" id="help">
        <div class="wrap support-copy">
            <p class="site-discovery-kicker">Bring evidence, not guesswork</p>
            <h2>Getting help</h2>
            <p>The support forum is available from an authenticated SNAPSMACK administration. That keeps the help surface attached to a real installation instead of exposing another public registration target for bots.</p>
            <p>Before posting, collect the SNAPSMACK version, hosting type, PHP and database versions, the exact page or tool involved, the time of the failure, and the exact message shown. Say what changed immediately before it happened. Remove passwords, API keys, session cookies, database dumps and private paths from screenshots and logs.</p>
            <p>Reports become numbered tickets so the fault, evidence, workaround, repair and verification stay together. Security concerns should be reported privately first. If the site is sealed by SMACKBACK or the normal admin is unavailable, use the <a href="bugger.php">emergency help page</a>.</p>
            <div class="support-note">
                <p>SNAPSMACK is free software maintained without a commercial support contract or guaranteed response time. Clear evidence makes it much easier to separate a hosting fault, configuration problem and product defect &mdash; and to fix the right one.</p>
            </div>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
