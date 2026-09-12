<?php
/**
 * SNAPSMACK.CA - SMACK UP YOUR BACKUP (desktop tool page)
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
$page_title       = 'SMACK UP YOUR BACKUP - backup and recovery for SnapSmack';
$page_description = 'SMACK UP YOUR BACKUP backs up one SnapSmack site or a whole fleet to local storage and cloud providers, audits what exists where, and recovers a site even when the original installation is gone.';
$page_og_url      = 'https://snapsmack.ca/tool-suyb.php';
$nav_active       = 'tool-suyb';

$tool_name     = 'SMACK UP YOUR BACKUP';
$tool_short    = 'One site or a fleet, backed up to your disk and your cloud, audited, and recoverable from nothing.';
$tool_platform = 'Windows / Linux';
$tool_status   = 'Shipping';
$tool_group    = 'keep';
$tool_news     = '';
$tool_facts    = [
    ['What it is', 'Backup + recovery'],
    ['Targets', 'Local disk, cloud storage'],
    ['Scope', 'One site or the whole fleet'],
    ['Transfers', 'Checkpoint and resume']
];
$tool_body = <<<'HTML'
            <h2>What it does for your site</h2>
            <p>A backup you haven&rsquo;t tested is a hope. SMACK UP YOUR BACKUP takes complete recovery archives &mdash; files and database &mdash; of every site you run, puts them where you tell it (local, cloud, both), audits what actually exists in each place, and can rebuild a site from that archive on a fresh install when the original is gone.</p>
            <h2>How it works</h2>
            <ul>
                <li>Discover your fleet through SNAP HQ, or add sites by hand.</li>
                <li>Back up. Full archives, checkpointed, so a long transfer that&rsquo;s interrupted resumes instead of restarting.</li>
                <li>Audit. Which sites have a backup where, how old, how complete. Red where it&rsquo;s missing.</li>
                <li>Recover. Point it at an archive and a fresh SnapSmack install; it puts the site back.</li>
                <li>The site itself reports its backup status to its dashboard, so you can see from the admin whether it&rsquo;s covered.</li>
            </ul>
            <h2>The rules it follows</h2>
            <ul>
                <li>It backs up your <strong>site</strong>. Your originals are yours to archive, and it never quietly copies them.</li>
                <li>Cloud credentials are stored in the suite&rsquo;s protected vault, not in a text file.</li>
                <li>Recovery is a step-up action: password and 2FA before it will overwrite anything.</li>
            </ul>
            <p>Rebuilt in Qt in September 2026 to match the rest of the suite.</p>
HTML;
$tool_shots = [
    ['suyb-backupinprogress-01.png', 'Smack Up Your Backup transferring a photography site backup', 'A backup in progress: checkpointed, resumable.'],
    ['suyb-recovery-03.png', 'Smack Up Your Backup recovery screen', 'Recovery: archive in, site out, even on a fresh install.'],
    ['suyb-settings-02.png', 'Smack Up Your Backup settings', 'Where backups go, and how many to keep.']
];
require_once __DIR__ . '/includes/tool-page.php';
// ===== SNAPSMACK EOF =====
