<?php
/**
 * SNAPSMACK - Companion Tools
 * Alpha v0.7.9a
 *
 * Lists available companion desktop tools with download links.
 */

require_once 'core/auth.php';

// --- TOOL REGISTRY ---
// Add new companion tools here.
$tools = [
    [
        'name'         => 'Fix Your Batch Up',
        'version'      => '0.7.6',
        'platform'     => 'Windows (64-bit)',
        'download_url' => 'https://snapsmack.ca/tools/fixyourbatchup.zip',
        'description'  => 'Recovery tool for images posted without a Google Drive link. Pulls the list of affected records directly from your site, then matches your local original files against the server-side copies using two-stage image matching (pHash pre-filter + SIFT feature matching). Processes in batches of 10 using up to 75% of available CPU cores. Review each match at your own pace — upload one image at a time, pick a different original via the Windows file browser, or skip. Reuses the Drive credentials and token from Smack Your Batch Up automatically.',
        'requires'     => 'Windows 10/11 only · Google Drive credentials JSON (same credentials.json as Smack Your Batch Up)',
    ],
    [
        'name'         => 'Smack Your Batch Up',
        'version'      => '0.7.7a-04',
        'platform'     => 'Windows (64-bit)',
        'download_url' => 'https://snapsmack.ca/tools/smackyourbatchup.zip',
        'description'  => 'Desktop tool for bulk-posting images to SnapSmack. Loads manifest files, embeds EXIF copyright metadata via piexif (pure Python, no external dependencies), resizes to web dimensions, and posts the batch to SnapSmack. Optionally uploads originals to Google Drive for high-res download links. Borrows the active admin colour scheme on connect. Drag to reorder, per-row category and album, accumulate multiple manifests before posting. OneDrive and Dropbox download links can be added manually via the CMS post editor.',
        'requires'     => 'Windows 10/11 only (macOS/Linux not currently supported) · Google Drive credentials JSON (optional — not needed if you don\'t use Drive downloads)',
    ],
    [
        'name'         => 'Smack Up Your Backup',
        'version'      => '0.1.0',
        'platform'     => 'Windows / macOS / Linux (64-bit)',
        'download_url' => 'https://snapsmack.ca/tools/smackupyourbackup.zip',
        'description'  => 'Backup and restore tool for SnapSmack sites. Pulls the full recovery kit from your site on a schedule, packages it as a versioned ZIP, and pushes it to Google Drive or OneDrive. Restore from any saved backup — locally, from cloud, or directly from a recovery kit. Includes a three-way audit that cross-references the manifest, live FTP filesystem, and database image records to surface missing, orphaned, and misplaced files. Profiles store all connection details for each site; cloud state files enable cold-start recovery on a new machine with no local config.',
        'requires'     => 'Windows 10/11 · macOS 12+ · Linux · Python 3.11+ (source) or standalone exe · FTP access to your server · Google Drive or OneDrive credentials (optional)',
    ],
];

$page_title = 'Companion Tools';
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row header-row--ruled">
        <h2>COMPANION TOOLS</h2>
    </div>

    <div class="box">
        <p class="dim tool-intro-text">
            Companion tools are standalone desktop applications that work alongside SnapSmack.
        </p>
    </div>

    <?php foreach ($tools as $tool): ?>
        <div class="box mt-15">
            <div class="box-header">
                <span class="box-title"><?php echo htmlspecialchars($tool['name']); ?></span>
                <code class="slug-display tool-version-badge">v<?php echo htmlspecialchars($tool['version']); ?></code>
                <code class="slug-display tool-platform-badge"><?php echo htmlspecialchars($tool['platform']); ?></code>
            </div>

            <div class="tool-details">
                <p class="tool-desc">
                    <?php echo htmlspecialchars($tool['description']); ?>
                </p>
                <p class="dim tool-requires">
                    <strong>Requires:</strong> <?php echo htmlspecialchars($tool['requires']); ?>
                </p>

                <div class="tool-download-row">
                    <a href="<?php echo htmlspecialchars($tool['download_url']); ?>"
                       class="btn-smack">
                        ↓ DOWNLOAD
                    </a>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php include 'core/admin-footer.php'; ?>
