<?php
/**
 * SNAPSMACK — FTP Backup Engine
 * v0.8
 *
 * Lightweight FTP push client for backing up images and recovery kits
 * to a user-configured remote server. Uses PHP's native ftp_* functions
 * (available on virtually all shared hosts).
 *
 * See docs/DESIGN-backup-recovery-export.md for architecture.
 */

class SnapSmackFTP {

    private string $host;
    private int    $port;
    private string $user;
    private string $pass;
    private string $remoteDir;
    private bool   $useSsl;
    private bool   $passive;

    /** @var resource|false */
    private $conn = false;

    private int $uploaded   = 0;
    private int $skipped    = 0;
    private int $failed     = 0;
    private int $bytesTotal = 0;

    public function __construct(array $config) {
        $this->host      = $config['host'] ?? '';
        $this->port      = (int)($config['port'] ?? 21);
        $this->user      = $config['user'] ?? '';
        $this->pass      = $config['pass'] ?? '';
        $this->remoteDir = rtrim($config['remote_dir'] ?? '/', '/');
        $this->useSsl    = (bool)($config['use_ssl'] ?? false);
        $this->passive   = (bool)($config['passive'] ?? true);
    }

    // =================================================================
    // CONNECTION
    // =================================================================

    /**
     * Connect and authenticate to the FTP server.
     * @return array{success: bool, message: string}
     */
    public function connect(): array {
        if (empty($this->host)) {
            return ['success' => false, 'message' => 'FTP host not configured.'];
        }

        // Establish connection
        if ($this->useSsl && function_exists('ftp_ssl_connect')) {
            $this->conn = @ftp_ssl_connect($this->host, $this->port, 15);
        } else {
            $this->conn = @ftp_connect($this->host, $this->port, 15);
        }

        if (!$this->conn) {
            return ['success' => false, 'message' => "Cannot connect to {$this->host}:{$this->port}"];
        }

        // Authenticate
        if (!@ftp_login($this->conn, $this->user, $this->pass)) {
            $this->disconnect();
            return ['success' => false, 'message' => 'FTP login failed. Check username/password.'];
        }

        // Passive mode (required by most shared hosts and firewalls)
        if ($this->passive) {
            ftp_pasv($this->conn, true);
        }

        // Ensure remote directory exists
        if ($this->remoteDir !== '/' && $this->remoteDir !== '') {
            $this->ensureRemoteDir($this->remoteDir);
        }

        return ['success' => true, 'message' => "Connected to {$this->host}"];
    }

    /**
     * Test the connection — connect, list remote dir, disconnect.
     * @return array{success: bool, message: string, files: int}
     */
    public function testConnection(): array {
        $result = $this->connect();
        if (!$result['success']) return $result + ['files' => 0];

        $listing = @ftp_nlist($this->conn, $this->remoteDir ?: '.');
        $count = is_array($listing) ? count($listing) : 0;

        $this->disconnect();
        return [
            'success' => true,
            'message' => "Connected. Remote directory has {$count} entries.",
            'files'   => $count,
        ];
    }

    /**
     * Disconnect from the FTP server.
     */
    public function disconnect(): void {
        if ($this->conn) {
            @ftp_close($this->conn);
            $this->conn = false;
        }
    }

    // =================================================================
    // PUSH OPERATIONS
    // =================================================================

    /**
     * Push a single file to the remote server.
     * @return bool Success
     */
    public function pushFile(string $localPath, string $remotePath): bool {
        if (!$this->conn || !file_exists($localPath)) return false;

        // Ensure remote directory exists
        $remoteDir = dirname($remotePath);
        if ($remoteDir !== '.' && $remoteDir !== '/') {
            $this->ensureRemoteDir($remoteDir);
        }

        $mode = $this->isTextFile($localPath) ? FTP_ASCII : FTP_BINARY;

        if (@ftp_put($this->conn, $remotePath, $localPath, $mode)) {
            $this->uploaded++;
            $this->bytesTotal += filesize($localPath);
            return true;
        }

        $this->failed++;
        return false;
    }

    /**
     * Push an entire local directory to the remote server.
     * Uses delta comparison — skips files that already exist with the same size.
     *
     * @param string      $localDir   Absolute path to local directory
     * @param string      $remoteDir  Remote directory path
     * @param callable|null $progressFn  Optional callback: fn(string $message, string $status)
     * @return array{uploaded: int, skipped: int, failed: int, bytes: int}
     */
    public function pushDirectory(string $localDir, string $remoteDir, ?callable $progressFn = null): array {
        $this->uploaded = 0;
        $this->skipped  = 0;
        $this->failed   = 0;
        $this->bytesTotal = 0;

        if (!is_dir($localDir)) {
            return $this->getStats();
        }

        // Get remote listing for delta comparison
        $remoteListing = $this->getRemoteListing($remoteDir);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($localDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = substr($item->getRealPath(), strlen($localDir) + 1);
            $relativePath = str_replace('\\', '/', $relativePath);
            $fullRemotePath = $remoteDir . '/' . $relativePath;

            if ($item->isDir()) {
                $this->ensureRemoteDir($fullRemotePath);
                continue;
            }

            // Skip if remote file exists with same size (delta)
            $localSize = $item->getSize();
            if (isset($remoteListing[$relativePath]) && $remoteListing[$relativePath] === $localSize) {
                $this->skipped++;
                if ($progressFn) $progressFn("SKIP (unchanged): {$relativePath}", 'info');
                continue;
            }

            if ($this->pushFile($item->getRealPath(), $fullRemotePath)) {
                if ($progressFn) $progressFn("UPLOADED: {$relativePath} (" . $this->formatBytes($localSize) . ")", 'ok');
            } else {
                if ($progressFn) $progressFn("FAILED: {$relativePath}", 'error');
            }
        }

        return $this->getStats();
    }

    /**
     * Push a recovery kit .tar.gz file.
     */
    public function pushRecoveryKit(string $archivePath, ?callable $progressFn = null): bool {
        $remotePath = $this->remoteDir . '/' . basename($archivePath);

        if ($progressFn) {
            $progressFn("Uploading recovery kit: " . basename($archivePath) . " (" . $this->formatBytes(filesize($archivePath)) . ")", 'info');
        }

        $success = $this->pushFile($archivePath, $remotePath);

        if ($progressFn) {
            if ($success) {
                $progressFn("Recovery kit uploaded successfully.", 'ok');
            } else {
                $progressFn("Failed to upload recovery kit.", 'error');
            }
        }

        return $success;
    }

    // =================================================================
    // REMOTE LISTING (for delta comparison)
    // =================================================================

    /**
     * Get a recursive listing of remote files with their sizes.
     * Returns array of [relative_path => size].
     */
    public function getRemoteListing(string $dir): array {
        $listing = [];
        $this->walkRemoteDir($dir, $dir, $listing);
        return $listing;
    }

    private function walkRemoteDir(string $baseDir, string $currentDir, array &$listing): void {
        if (!$this->conn) return;

        $rawList = @ftp_rawlist($this->conn, $currentDir);
        if (!is_array($rawList)) return;

        foreach ($rawList as $line) {
            // Parse standard Unix FTP listing format
            // drwxr-xr-x  2 user group  4096 Mar  4 15:00 dirname
            // -rw-r--r--  1 user group 12345 Mar  4 15:00 filename.jpg
            $parts = preg_split('/\s+/', $line, 9);
            if (count($parts) < 9) continue;

            $perms    = $parts[0];
            $size     = (int) $parts[4];
            $filename = $parts[8];

            if ($filename === '.' || $filename === '..') continue;

            $fullPath = $currentDir . '/' . $filename;
            $relPath  = ltrim(substr($fullPath, strlen($baseDir)), '/');

            if (str_starts_with($perms, 'd')) {
                // Directory — recurse
                $this->walkRemoteDir($baseDir, $fullPath, $listing);
            } else {
                $listing[$relPath] = $size;
            }
        }
    }

    // =================================================================
    // PASSWORD ENCRYPTION
    // =================================================================

    /**
     * Encrypt a password for storage in snap_settings.
     * Uses AES-256-CBC with key derived from the site's download_salt.
     */
    public static function encryptPassword(string $plaintext, string $salt): string {
        $key = hash('sha256', $salt, true);
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt a password from snap_settings.
     */
    public static function decryptPassword(string $ciphertext, string $salt): string {
        $key = hash('sha256', $salt, true);
        $data = base64_decode($ciphertext);
        if ($data === false || strlen($data) < 17) return '';
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return ($decrypted !== false) ? $decrypted : '';
    }

    // =================================================================
    // HELPERS
    // =================================================================

    /**
     * Ensure a remote directory exists, creating it recursively if needed.
     */
    private function ensureRemoteDir(string $dir): void {
        if (!$this->conn) return;

        // Try to change to the directory — if it works, it exists
        $original = @ftp_pwd($this->conn);
        if (@ftp_chdir($this->conn, $dir)) {
            @ftp_chdir($this->conn, $original);
            return;
        }

        // Create recursively
        $parts = explode('/', trim($dir, '/'));
        $buildPath = (str_starts_with($dir, '/')) ? '' : $original;

        foreach ($parts as $part) {
            if (empty($part)) continue;
            $buildPath .= '/' . $part;
            if (!@ftp_chdir($this->conn, $buildPath)) {
                @ftp_mkdir($this->conn, $buildPath);
            }
        }

        @ftp_chdir($this->conn, $original);
    }

    /**
     * Determine if a file is text (for FTP ASCII mode).
     */
    private function isTextFile(string $path): bool {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['sql', 'txt', 'css', 'js', 'php', 'html', 'xml', 'json', 'md', 'csv']);
    }

    /**
     * Format bytes into human-readable string.
     */
    private function formatBytes(int $bytes): string {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
        if ($bytes >= 1048576)    return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024)       return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }

    /**
     * Get push statistics.
     */
    private function getStats(): array {
        return [
            'uploaded' => $this->uploaded,
            'skipped'  => $this->skipped,
            'failed'   => $this->failed,
            'bytes'    => $this->bytesTotal,
        ];
    }

    /**
     * Check if PHP has FTP support available.
     */
    public static function isAvailable(): bool {
        return function_exists('ftp_connect');
    }

    /**
     * Check if FTPS (SSL) is available.
     */
    public static function isSslAvailable(): bool {
        return function_exists('ftp_ssl_connect');
    }
}
