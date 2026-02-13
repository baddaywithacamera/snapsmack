<?php
/**
 * SnapSmack - System Backup & Recovery
 * Version: 4.5 - Schema & Layout Patch
 * -------------------------------------------------------------------------
 * - ADDED: Schema-Only Export (Structure without data).
 * - FIXED: Layout reorganized into 3-block top row and 2-block asset row.
 * - MASTER DIRECTIVE: Full file return. No truncation. No inline CSS.
 * - COLORS: Teal, Purple, Gold for logic; Blue, Orange for assets.
 * -------------------------------------------------------------------------
 */

require_once 'core/auth.php';

$msg = "";
$timestamp = date('Y-m-d_His');

// --- 1. DATABASE BACKUP & RECOVERY LOGIC ---
if (isset($_GET['action']) && ($_GET['action'] === 'db' || $_GET['action'] === 'users' || $_GET['action'] === 'schema')) {
    $type = $_GET['action'];
    
    // Set headers and define filenames
    header('Content-Type: application/octet-stream');
    
    if ($type === 'users') {
        $filename = 'snapsmack_RECOVERY_users_' . $timestamp . '.sql';
        $tables = ['snap_users'];
        echo "-- SnapSmack EMERGENCY ACCESS RECOVERY\n";
        echo "-- Purpose: Restore administrative access credentials.\n";
    } elseif ($type === 'schema') {
        $filename = 'snapsmack_SCHEMA_ONLY_' . $timestamp . '.sql';
        $tables = ['snap_users', 'snap_images', 'snap_categories', 'snap_image_cat_map', 'snap_settings', 'snap_comments', 'snap_pages', 'snap_assets'];
        echo "-- SnapSmack ENGINE SCHEMA ONLY\n";
        echo "-- Purpose: Clone system architecture without existing content.\n";
    } else {
        $filename = 'snapsmack_full_db_' . $timestamp . '.sql';
        $tables = ['snap_users', 'snap_images', 'snap_categories', 'snap_image_cat_map', 'snap_settings', 'snap_comments', 'snap_pages', 'snap_assets'];
        echo "-- SnapSmack Full Database Dump\n";
    }

    header('Content-Disposition: attachment; filename=' . $filename);
    echo "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";

    foreach ($tables as $table) {
        // 1. Structure (Always Exported)
        echo "DROP TABLE IF EXISTS `$table`;\n";
        try {
            $create_stmt = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
            echo $create_stmt['Create Table'] . ";\n\n";
        } catch (PDOException $e) {
            continue; // Skip if table doesn't exist
        }

        // 2. Data (Skipped for 'schema' type)
        if ($type !== 'schema') {
            $query = "SELECT * FROM `$table`";
            $rows = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
            
            if ($rows) {
                $columns = array_map(function($k){ return "`$k`"; }, array_keys($rows[0]));
                echo "INSERT INTO `$table` (" . implode(', ', $columns) . ") VALUES \n";
                
                $count = count($rows);
                foreach ($rows as $i => $row) {
                    $vals = array_map(function($v) use ($pdo) { 
                        return $v === null ? 'NULL' : $pdo->quote($v); 
                    }, array_values($row));
                    
                    echo "(" . implode(', ', $vals) . ")";
                    echo ($i === $count - 1) ? ";\n\n" : ",\n";
                }
            }
        }
    }
    exit;
}

// --- 2. FILE SYSTEM BACKUP LOGIC ---
if (isset($_GET['action']) && ($_GET['action'] === 'images' || $_GET['action'] === 'site')) {
    $type = $_GET['action'];
    $archive_name = "snapsmack_{$type}_{$timestamp}.tar.gz";
    
    if ($type === 'images') {
        $cmd = "tar -czf " . escapeshellarg($archive_name) . " img_uploads/ media/";
    } else {
        $cmd = "tar -czf " . escapeshellarg($archive_name) . " --exclude='./img_uploads' --exclude='./media' --exclude='./*.tar.gz' .";
    }

    system($cmd);
    
    if (file_exists($archive_name)) {
        header('Content-Type: application/x-gzip');
        header('Content-Disposition: attachment; filename=' . $archive_name);
        header('Content-Length: ' . filesize($archive_name));
        readfile($archive_name);
        unlink($archive_name);
        exit;
    } else {
        $msg = "CRITICAL: Archive generation failed. Check server permissions.";
    }
}

$page_title = "System Backup";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>System Backup & Recovery</h2>
    
    <?php if ($msg): ?>
        <div class="alert alert-error">
            > <?php echo $msg; ?>
        </div>
    <?php endif; ?>

    <div class="dash-grid">
        
        <div class="box">
            <h3>Full Database (The Brain)</h3>
            <p class="maint-desc">
                Exports system architecture, settings, and content. This SQL file is formatted for direct import via phpMyAdmin or the SnapSmack CLI.
            </p>
            <button onclick="runBackup(this, 'db')" class="btn-teal">GET FULL SQL DUMP</button>
        </div>

        <div class="box">
            <h3>Emergency Access (The Keys)</h3>
            <p class="maint-desc">
                Extracts only the user table and hashes. Essential for regaining entry to the system if a database becomes corrupted.
            </p>
            <button onclick="runBackup(this, 'users')" class="btn-purple">GET RECOVERY SQL</button>
        </div>

        <div class="box">
            <h3>Engine Schema (The DNA)</h3>
            <p class="maint-desc">
                Exports the structure and table architecture without any content or user data. Perfect for Git commits or cloning to new domains.
            </p>
            <button onclick="runBackup(this, 'schema')" class="btn-green">GET SCHEMA ONLY</button>
        </div>

    </div>

    <div class="dash-grid">

        <div class="box">
            <h3>Media Library (The Assets)</h3>
            <p class="maint-desc">
                Archives all original photographs, thumbnails, and media uploads into a single compressed package. Excludes system source code.
            </p>
            <button onclick="runBackup(this, 'images')" class="btn-blue">GET MEDIA ARCHIVE</button>
        </div>

        <div class="box">
            <h3>Site Source (The Engine)</h3>
            <p class="maint-desc">
                Archives core PHP logic, CSS, and system scripts. This excludes large media directories to keep the source backup lightweight.
            </p>
            <button onclick="runBackup(this, 'site')" class="btn-orange">GET CODE SOURCE</button>
        </div>

    </div>
</div>

<script>
/**
 * Visual feedback for long-running archive tasks.
 */
function runBackup(btn, action) {
    const originalText = btn.innerText;
    btn.innerText = "GENERATING...";
    btn.disabled = true;
    
    window.location.href = `?action=${action}`;
    
    // Reset button after estimated completion
    setTimeout(() => {
        btn.innerText = originalText;
        btn.disabled = false;
    }, 5000);
}
</script>

<?php include 'core/admin-footer.php'; ?>