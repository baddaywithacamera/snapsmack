<?php
/**
 * SNAPSMACK - Companion Tools
 *
 * Lists available companion desktop tools with download links.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/auth-smack.php';

// --- TOOL REGISTRY ---
// Add new companion tools here.
$tools = [
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
    [
        'name'         => 'Take Your Shit With You',
        'version'      => '0.1.0',
        'platform'     => 'Windows (64-bit)',
        'download_url' => 'https://snapsmack.ca/tools/tyswy.zip',
        // The zip is uploaded when the release goes out. Until then the button
        // says so instead of handing the owner a 404 — a dead download link on
        // the page that is supposed to guarantee they can leave is the worst
        // possible place for one. Flip this to true (or drop the line) once
        // tyswy.zip is on snapsmack.ca/tools/.
        'available'    => false,
        'description'  => 'Portable export. Downloads a complete, readable copy of everything you published — the photographs, the words, the dates, the categories, the comments — into a plain folder on your own computer, then proves nothing went missing by comparing its own ledger against the site\'s counts. Readable JSON sits beside each photograph, so the archive opens with a file manager and a text editor and needs no SnapSmack and no tool to read. Optionally builds a WordPress import package, with every mapping WordPress cannot make written down rather than quietly dropped. Stopping is safe: records resume from the last verified row and files resume mid-download. This is portability, not backup — for putting a broken site back, use Smack Up Your Backup.',
        'requires'     => 'Windows 10/11 · a read-only TYSWY export key (Boring Ass Stuff → API Keys) · an HTTPS site · enough free disk for your archive',
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
                    <?php if (($tool['available'] ?? true)): ?>
                        <a href="<?php echo htmlspecialchars($tool['download_url']); ?>"
                           class="btn-smack">
                            ↓ DOWNLOAD
                        </a>
                    <?php else: ?>
                        <button type="button" class="btn-smack" disabled>NOT YET RELEASED</button>
                        <p class="dim tool-requires">
                            Built and tested, waiting on the release upload. It will
                            appear here — nothing else to do.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
