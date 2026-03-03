<?php
/**
 * SNAPSMACK - File integrity verification
 * Alpha v0.7
 *
 * Lightweight replacement for the old media manifest export. Checks that files
 * referenced in the database exist on disk and optionally verifies SHA-256
 * checksums. Does NOT walk the filesystem — reads only from DB records.
 */

require_once 'core/auth.php';

$page_title = "Verify Integrity";
$running = isset($_POST['run_verify']);
$verify_checksums = isset($_POST['verify_checksums']);

if (!$running) {
    include 'core/admin-header.php';
    include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>FILE INTEGRITY VERIFICATION</h2>
    </div>
    <div class="box">
        <p class="skin-desc-text">
            Checks every image and media asset referenced in the database to verify the files still exist on disk.
            Optionally verifies SHA-256 checksums (slower but confirms file integrity). This replaces the old
            media manifest export with a lightweight, database-driven approach.
        </p>
        <form method="POST" style="margin-top:20px;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:20px;">
                <input type="checkbox" name="verify_checksums" value="1">
                <span>Also verify SHA-256 checksums (slower — hashes each file on disk)</span>
            </label>
            <button type="submit" name="run_verify" value="1" class="btn-smack">RUN VERIFICATION</button>
        </form>
    </div>
</div>

<?php
    include 'core/admin-footer.php';
    exit;
}

// --- STREAMING VERIFICATION OUTPUT ---
header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html><head><title>SnapSmack Integrity Check</title>";
echo "<style>body{background:#1a1a1a;color:#ccc;font-family:monospace;padding:20px;font-size:13px;line-height:1.6;}";
echo ".ok{color:#39FF14;} .info{color:#00bfff;} .warn{color:#ffaa00;} .fail{color:#ff6b6b;}";
echo "h2{color:#a0ff90;letter-spacing:2px;} h3{color:#eee;margin-top:24px;} hr{border-color:#333;margin:16px 0;}";
echo ".summary{background:#222;border:1px solid #333;padding:16px;margin-top:20px;border-radius:4px;}";
echo "a{color:#a0ff90;}</style></head><body>";
echo "<h2>INTEGRITY VERIFICATION</h2>";
echo "<p class='info'>Checking database records against physical files...</p><hr>";
flush();

// =====================================================================
// PHASE 1: snap_images
// =====================================================================
echo "<h3>IMAGES (snap_images)</h3>";

$images = $pdo->query("SELECT id, img_file, img_thumb_square, img_thumb_aspect, img_checksum FROM snap_images ORDER BY id")->fetchAll();

$img_ok       = 0;
$img_missing  = 0;
$img_thumb_miss = 0;
$img_checksum_fail = 0;
$img_no_checksum   = 0;

foreach ($images as $img) {
    $file = $img['img_file'];
    $problems = [];

    // Check main image file
    if (!file_exists($file)) {
        echo "<span class='fail'>MISSING:</span> " . htmlspecialchars($file) . " (id:{$img['id']})<br>";
        $img_missing++;
        flush();
        continue;
    }

    // Derive path info for fallback checks
    $pi = pathinfo($file);

    // Check square thumbnail
    $sq = $img['img_thumb_square'];
    if ($sq && !file_exists($sq)) {
        $problems[] = "t_ thumb missing";
        $img_thumb_miss++;
    } elseif (!$sq) {
        $derived_sq = $pi['dirname'] . '/thumbs/t_' . $pi['basename'];
        if (!file_exists($derived_sq)) {
            $problems[] = "t_ thumb missing (derived)";
            $img_thumb_miss++;
        }
    }

    // Check aspect thumbnail
    $asp = $img['img_thumb_aspect'];
    if ($asp && !file_exists($asp)) {
        $problems[] = "a_ thumb missing";
        $img_thumb_miss++;
    } elseif (!$asp) {
        $derived_a = $pi['dirname'] . '/thumbs/a_' . $pi['basename'];
        if (!file_exists($derived_a)) {
            $problems[] = "a_ thumb missing (derived)";
            $img_thumb_miss++;
        }
    }

    // Verify checksum if requested and available
    if ($verify_checksums) {
        if ($img['img_checksum']) {
            $actual = hash_file('sha256', $file);
            if ($actual !== $img['img_checksum']) {
                $problems[] = "CHECKSUM MISMATCH";
                $img_checksum_fail++;
            }
        } else {
            $img_no_checksum++;
        }
    }

    if (empty($problems)) {
        $img_ok++;
    } else {
        echo "<span class='warn'>ISSUES:</span> " . htmlspecialchars(basename($file)) . " (id:{$img['id']}) — " . implode(', ', $problems) . "<br>";
    }
    flush();
}

echo "<div class='summary'>";
echo "<span class='ok'>Verified: {$img_ok}</span> | ";
echo "<span class='" . ($img_missing ? 'fail' : 'ok') . "'>Missing files: {$img_missing}</span> | ";
echo "<span class='" . ($img_thumb_miss ? 'warn' : 'ok') . "'>Missing thumbs: {$img_thumb_miss}</span>";
if ($verify_checksums) {
    echo " | <span class='" . ($img_checksum_fail ? 'fail' : 'ok') . "'>Checksum failures: {$img_checksum_fail}</span>";
    echo " | <span class='info'>No checksum stored: {$img_no_checksum}</span>";
}
echo "</div>";
flush();

// =====================================================================
// PHASE 2: snap_assets
// =====================================================================
echo "<h3>MEDIA ASSETS (snap_assets)</h3>";

try {
    $assets = $pdo->query("SELECT id, asset_path, asset_checksum FROM snap_assets ORDER BY id")->fetchAll();
} catch (PDOException $e) {
    echo "<span class='warn'>snap_assets table not found — skipping.</span><br>";
    $assets = [];
}

$asset_ok      = 0;
$asset_missing = 0;
$asset_cs_fail = 0;

foreach ($assets as $asset) {
    if (!file_exists($asset['asset_path'])) {
        echo "<span class='fail'>MISSING:</span> " . htmlspecialchars($asset['asset_path']) . " (id:{$asset['id']})<br>";
        $asset_missing++;
        flush();
        continue;
    }

    if ($verify_checksums && $asset['asset_checksum']) {
        $actual = hash_file('sha256', $asset['asset_path']);
        if ($actual !== $asset['asset_checksum']) {
            echo "<span class='fail'>CHECKSUM MISMATCH:</span> " . htmlspecialchars(basename($asset['asset_path'])) . " (id:{$asset['id']})<br>";
            $asset_cs_fail++;
            flush();
            continue;
        }
    }

    $asset_ok++;
}

echo "<div class='summary'>";
echo "<span class='ok'>Verified: {$asset_ok}</span> | ";
echo "<span class='" . ($asset_missing ? 'fail' : 'ok') . "'>Missing: {$asset_missing}</span>";
if ($verify_checksums) {
    echo " | <span class='" . ($asset_cs_fail ? 'fail' : 'ok') . "'>Checksum failures: {$asset_cs_fail}</span>";
}
echo "</div>";

// =====================================================================
// PHASE 3: Branding assets (from snap_settings)
// =====================================================================
echo "<h3>BRANDING ASSETS</h3>";

$branding_keys = ['header_logo_url', 'favicon_url', 'site_logo'];
$brand_ok = 0;
$brand_missing = 0;

foreach ($branding_keys as $key) {
    $val = $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key = " . $pdo->quote($key))->fetchColumn();
    if (empty($val)) continue;

    // Normalize path — some have leading slash, some don't
    $path = ltrim($val, '/');
    if (file_exists($path)) {
        $brand_ok++;
    } else {
        echo "<span class='fail'>MISSING:</span> {$key} → " . htmlspecialchars($path) . "<br>";
        $brand_missing++;
    }
}

echo "<div class='summary'>";
echo "<span class='ok'>Verified: {$brand_ok}</span> | ";
echo "<span class='" . ($brand_missing ? 'fail' : 'ok') . "'>Missing: {$brand_missing}</span>";
echo "</div>";

// =====================================================================
// COMPLETION
// =====================================================================
$total_issues = $img_missing + $img_thumb_miss + $img_checksum_fail + $asset_missing + $asset_cs_fail + $brand_missing;

echo "<hr>";
if ($total_issues === 0) {
    echo "<h3 class='ok'>ALL CLEAR — No issues detected.</h3>";
} else {
    echo "<h3 class='warn'>FOUND {$total_issues} ISSUE(S)</h3>";
    echo "<p>Run <code>backfill-checksums.php</code> to populate missing recovery metadata.</p>";
    echo "<p>Run <code>backfill-thumbs.php</code> to regenerate missing thumbnails.</p>";
}
echo "<p style='margin-top:16px;'><a href='smack-backup.php'>← Back to Backup & Recovery</a></p>";
echo "</body></html>";
?>
