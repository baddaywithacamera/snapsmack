<?php
/**
 * SnapSmack - System Backup & Recovery
 * Version: 3.5 - TarGz Engine & Media Manifest
 */
require_once 'core/auth.php';

// --- THE ENGINE: EXTRACTION LOGIC ---
if (isset($_POST['action']) && $_POST['action'] === 'export') {
    $type = $_POST['type'];

    // SQL EXTRACTION
    if (in_array($type, ['full', 'schema', 'keys'])) {
        $filename = "snapsmack_" . $type . "_" . date('Y-m-d_H-i') . ".sql";
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = "-- SnapSmack Backup Service\n-- Type: " . strtoupper($type) . "\n-- Date: " . date('Y-m-d H:i:s') . "\n\n";
        $tables = ($type === 'keys') ? ['snap_users'] : ['snap_images', 'snap_categories', 'snap_image_cat_map', 'snap_comments', 'snap_users', 'snap_settings', 'snap_pages', 'snap_blogroll'];
        
        foreach ($tables as $table) {
            $res = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_ASSOC);
            $output .= "DROP TABLE IF EXISTS `$table`;\n" . $res['Create Table'] . ";\n\n";
            
            if ($type !== 'schema') {
                $rows = $pdo->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $keys = array_map(function($k) { return "`$k`"; }, array_keys($row));
                    $vals = array_map(function($v) use ($pdo) { return $v === null ? "NULL" : $pdo->quote($v); }, array_values($row));
                    $output .= "INSERT INTO `$table` (" . implode(', ', $keys) . ") VALUES (" . implode(', ', $vals) . ");\n";
                }
                $output .= "\n";
            }
        }
        echo $output;
        exit;
    }

    // MEDIA MANIFEST & RECOVERY (THE MAP)
    if ($type === 'media') {
        $filename = "snapsmack_media_manifest_" . date('Y-m-d_H-i') . ".txt";
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $rootPath = realpath(__DIR__);
        $media_dirs = ['assets/img', 'media', 'uploads']; 
        
        echo "SNAPSMACK MEDIA MANIFEST & RECOVERY MAP\n";
        echo "Generated: " . date('Y-m-d H:i:s') . "\n";
        echo "--------------------------------------------------\n\n";
        echo "SECTION 1: PHYSICAL FILE TREE (WITH SHA-256 HASH)\n\n";

        $total_size = 0;
        $file_count = 0;

        foreach ($media_dirs as $dir) {
            $dirPath = $rootPath . '/' . $dir;
            if (!is_dir($dirPath)) continue;

            echo "[DIR] /$dir\n";
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dirPath, RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file->isDir()) continue;
                
                $size = $file->getSize();
                $total_size += $size;
                $file_count++;
                
                $filePath = $file->getRealPath();
                $rel = str_replace($rootPath, '', $filePath);
                $hash = hash_file('sha256', $filePath);
                
                echo sprintf("  |- %-45s | %10s bytes | %s\n", $rel, number_format($size), $hash);
            }
        }

        echo "\nTOTALS: $file_count files | " . number_format($total_size / 1048576, 2) . " MB\n\n";
        echo "--------------------------------------------------\n";
        echo "SECTION 2: SQL RECOVERY (snap_images)\n\n";

        $rows = $pdo->query("SELECT * FROM snap_images")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $keys = array_map(function($k) { return "`$k`"; }, array_keys($row));
            $vals = array_map(function($v) use ($pdo) { return $v === null ? "NULL" : $pdo->quote($v); }, array_values($row));
            echo "INSERT INTO `snap_images` (" . implode(', ', $keys) . ") VALUES (" . implode(', ', $vals) . ");\n";
        }
        
        echo "\n--------------------------------------------------\n";
        echo "SECTION 3: SQL RECOVERY (snap_image_cat_map)\n\n";

        $rows_map = $pdo->query("SELECT * FROM snap_image_cat_map")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows_map as $row) {
            $keys = array_map(function($k) { return "`$k`"; }, array_keys($row));
            $vals = array_map(function($v) use ($pdo) { return $v === null ? "NULL" : $pdo->quote($v); }, array_values($row));
            echo "INSERT INTO `snap_image_cat_map` (" . implode(', ', $keys) . ") VALUES (" . implode(', ', $vals) . ");\n";
        }

        exit;
    }

    // TAR.GZ ARCHIVE EXTRACTION (SOURCE CODE ONLY)
    if ($type === 'source') {
        $filename = "snapsmack_source_" . date('Y-m-d_H-i') . ".tar";
        $tempPath = sys_get_temp_dir() . '/' . $filename;
        
        try {
            $a = new PharData($tempPath);
            $rootPath = realpath(__DIR__);
            $media_dirs = ['assets/img', 'media', 'uploads', 'skins'];

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($rootPath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $name => $file) {
                if ($file->isDir()) continue;
                
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($rootPath) + 1);
                $relativePath = str_replace('\\', '/', $relativePath);

                $isMedia = false;
                foreach ($media_dirs as $dir) {
                    if (strpos($relativePath, $dir . '/') === 0) {
                        $isMedia = true;
                        break;
                    }
                }

                if (!$isMedia && !str_contains($relativePath, '.tar')) {
                    $a->addFile($filePath, $relativePath);
                }
            }

            // Compress to gz and swap the pointer
            $gzPath = $a->compress(Phar::GZ)->getPath();
            
            // Clean up the uncompressed tar
            unset($a); 
            unlink($tempPath);

            header('Content-Type: application/x-gzip');
            header('Content-Disposition: attachment; filename="' . basename($gzPath) . '"');
            header('Content-Length: ' . filesize($gzPath));
            readfile($gzPath);
            unlink($gzPath);
            exit;
            
        } catch (Exception $e) {
            die("RECOVERY_ENGINE_CRITICAL: " . $e->getMessage());
        }
    }
}

$page_title = "Backup & Recovery";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>SYSTEM BACKUP & RECOVERY</h2>