<?php
/**
 * SNAPSMACK.CA - SNAP HQ (desktop tool page)
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
$page_title       = 'SNAP HQ - the SnapSmack desktop headquarters';
$page_description = 'SNAP HQ is the local headquarters for the SnapSmack desktop suite: launch every tool from one place, discover the sites in your fleet, and share protected profiles, credentials, libraries, and prompts between tools.';
$page_og_url      = 'https://snapsmack.ca/tool-snap-hq.php';
$nav_active       = 'tool-snap-hq';

$tool_name     = 'SNAP HQ';
$tool_short    = 'One place to launch the suite, find your sites, and keep the profiles, keys, and prompts every tool needs.';
$tool_platform = 'Windows';
$tool_status   = 'Closed beta';
$tool_group    = 'keep';
$tool_news     = '';
$tool_facts    = [
    ['What it is', 'Suite launcher + shared setup'],
    ['Discovers', 'Every site in your fleet'],
    ['Shares', 'Profiles, protected credentials, libraries, prompts'],
    ['Devices', 'Ed25519 device authorisation, four-device cap']
];
$tool_body = <<<'HTML'
            <h2>What it does for your site</h2>
            <p>Six desktop tools that each want your site&rsquo;s address, an API key, an AI key, and a prompt is five too many places to type them. SNAP HQ holds that once, in a protected local vault, and hands it to whichever tool you launch. Add a site in one place; every tool knows it.</p>
            <h2>How it works</h2>
            <ul>
                <li>Install SNAP HQ. Authorise the device &mdash; a signed entitlement, up to four devices per owner.</li>
                <li>Add your sites, or let it discover the fleet from your hub.</li>
                <li>Launch SNAP SLAPPER, COLD SNAP, SMACK YOUR BATCH UP, GET YOUR SHIT SORTED, SMACK UP YOUR BACKUP, and the importers from one window. Updates to the tools arrive through it.</li>
                <li>Set your per-site AI prompt once. Every tool that writes for that site uses it.</li>
            </ul>
            <h2>The rules it follows</h2>
            <ul>
                <li>Credentials live in the vault, encrypted, and are never written to the tools&rsquo; config files in the clear.</li>
                <li>A wrong-site push is the failure the suite guards hardest against. Profiles are named, shown, and confirmed.</li>
                <li>Uninstall it and the tools still run; you just type more.</li>
            </ul>
HTML;
$tool_shots = [
    ['snap-hq.png', 'SNAP HQ launcher and shared setup', 'The launcher: every tool, every site, one window.'],
    ['the-hub.png', 'SNAP HQ fleet view', 'The fleet as SNAP HQ sees it.']
];
require_once __DIR__ . '/includes/tool-page.php';
// ===== SNAPSMACK EOF =====
