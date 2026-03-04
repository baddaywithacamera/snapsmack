<?php
/**
 * SNAPSMACK — Cloud Backup Engine
 * v0.8
 *
 * Session-only OAuth integration for Google Drive and OneDrive.
 * Access tokens live in $_SESSION only — never persisted to the database.
 * Supports resumable upload (Google, 5MB chunks) and chunked upload
 * (OneDrive, 4MB chunks) for large recovery kit archives.
 *
 * See docs/DESIGN-backup-recovery-export.md for architecture.
 */

// =================================================================
// OAUTH FLOW MANAGER
// =================================================================

class SnapSmackCloudOAuth {

    // Provider constants
    const GOOGLE   = 'google';
    const ONEDRIVE = 'onedrive';

    // OAuth endpoints
    const ENDPOINTS = [
        'google' => [
            'auth'  => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token' => 'https://oauth2.googleapis.com/token',
            'scope' => 'https://www.googleapis.com/auth/drive.file',
        ],
        'onedrive' => [
            'auth'  => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'scope' => 'Files.ReadWrite',
        ],
    ];

    /**
     * Build the authorization URL for the given provider.
     * Stores a CSRF state token in the session.
     *
     * @return string The full authorization URL to redirect the user to
     */
    public static function getAuthorizationUrl(string $provider, string $clientId, string $redirectUri): string {
        $ep = self::ENDPOINTS[$provider] ?? null;
        if (!$ep) return '';

        // Generate CSRF state token
        $state = bin2hex(random_bytes(32));
        $_SESSION["oauth_state_{$provider}"] = $state;

        $params = [
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => $ep['scope'],
            'state'         => $state,
            'access_type'   => 'online', // Session-only, no refresh token
        ];

        // OneDrive-specific: use 'response_mode=query'
        if ($provider === self::ONEDRIVE) {
            $params['response_mode'] = 'query';
        }

        return $ep['auth'] . '?' . http_build_query($params);
    }

    /**
     * Exchange an authorization code for an access token.
     * Stores the token in $_SESSION['cloud_tokens'][$provider].
     *
     * @return array{success: bool, message: string}
     */
    public static function exchangeAuthCode(
        string $provider,
        string $code,
        string $clientId,
        string $clientSecret,
        string $redirectUri
    ): array {
        $ep = self::ENDPOINTS[$provider] ?? null;
        if (!$ep) return ['success' => false, 'message' => 'Unknown provider.'];

        $postFields = [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ];

        $response = self::curlPost($ep['token'], [
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query($postFields));

        if ($response['status'] !== 200) {
            $body = json_decode($response['body'], true);
            $error = $body['error_description'] ?? $body['error'] ?? 'Token exchange failed (HTTP ' . $response['status'] . ')';
            return ['success' => false, 'message' => $error];
        }

        $tokenData = json_decode($response['body'], true);
        if (empty($tokenData['access_token'])) {
            return ['success' => false, 'message' => 'No access token in response.'];
        }

        // Store token in session
        if (!isset($_SESSION['cloud_tokens'])) {
            $_SESSION['cloud_tokens'] = [];
        }

        $_SESSION['cloud_tokens'][$provider] = [
            'access_token' => $tokenData['access_token'],
            'token_type'   => $tokenData['token_type'] ?? 'Bearer',
            'expires_in'   => $tokenData['expires_in'] ?? 3600,
            'obtained_at'  => time(),
        ];

        return ['success' => true, 'message' => 'Authorized successfully.'];
    }

    /**
     * Verify the CSRF state parameter from an OAuth callback.
     */
    public static function verifyState(string $provider, string $state): bool {
        $expected = $_SESSION["oauth_state_{$provider}"] ?? '';
        if (empty($expected) || !hash_equals($expected, $state)) {
            return false;
        }
        // One-time use
        unset($_SESSION["oauth_state_{$provider}"]);
        return true;
    }

    /**
     * Check if an active (non-expired) token exists in the session.
     */
    public static function hasActiveToken(string $provider): bool {
        $token = $_SESSION['cloud_tokens'][$provider] ?? null;
        if (!$token || empty($token['access_token'])) return false;

        // Check expiry (with 60-second buffer)
        $elapsed = time() - ($token['obtained_at'] ?? 0);
        $expiresIn = $token['expires_in'] ?? 3600;

        return $elapsed < ($expiresIn - 60);
    }

    /**
     * Get the access token for a provider from the session.
     */
    public static function getAccessToken(string $provider): string {
        return $_SESSION['cloud_tokens'][$provider]['access_token'] ?? '';
    }

    /**
     * Clear the token for a provider (disconnect).
     */
    public static function clearToken(string $provider): void {
        unset($_SESSION['cloud_tokens'][$provider]);
    }

    /**
     * Retrieve and decrypt stored OAuth credentials from snap_settings.
     *
     * @return array{client_id: string, client_secret: string}
     */
    public static function getStoredCredentials(string $provider, array $settings, string $salt): array {
        $clientId = $settings["{$provider}_client_id"] ?? '';
        $clientSecretEnc = $settings["{$provider}_client_secret"] ?? '';

        $clientSecret = '';
        if (!empty($clientSecretEnc)) {
            // Reuse the FTP engine's AES-256-CBC decryption
            require_once __DIR__ . '/ftp-engine.php';
            $clientSecret = SnapSmackFTP::decryptPassword($clientSecretEnc, $salt);
        }

        return [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
        ];
    }

    /**
     * Check if a provider is configured (has non-empty client_id).
     */
    public static function isProviderConfigured(string $provider, array $settings): bool {
        return !empty($settings["{$provider}_client_id"]);
    }

    /**
     * Check if cURL is available (required for all cloud operations).
     */
    public static function isAvailable(): bool {
        return function_exists('curl_init');
    }

    // =================================================================
    // CURL HELPERS
    // =================================================================

    /**
     * POST request with form data or raw body.
     *
     * @return array{status: int, body: string, headers: array}
     */
    public static function curlPost(string $url, array $headers, string $body): array {
        return self::curlRequest('POST', $url, $headers, $body);
    }

    /**
     * General cURL request. Matches pattern from core/updater.php.
     *
     * @return array{status: int, body: string, headers: array}
     */
    public static function curlRequest(string $method, string $url, array $headers = [], ?string $body = null): array {
        $ch = curl_init($url);

        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 300, // 5 min for large uploads
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'SnapSmack/0.8',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HEADERFUNCTION => function($ch, $header) use (&$responseHeaders) {
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($header);
            },
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['status' => 0, 'body' => "cURL error: {$error}", 'headers' => []];
        }

        curl_close($ch);
        return ['status' => $status, 'body' => $responseBody, 'headers' => $responseHeaders];
    }

    /**
     * PUT request with raw binary body from a file handle.
     * Used for chunked uploads.
     *
     * @return array{status: int, body: string, headers: array}
     */
    public static function curlPutChunk(string $url, array $headers, string $chunkData): array {
        $ch = curl_init($url);

        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'SnapSmack/0.8',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $chunkData,
            CURLOPT_HEADERFUNCTION => function($ch, $header) use (&$responseHeaders) {
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($header);
            },
        ]);

        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['status' => 0, 'body' => "cURL error: {$error}", 'headers' => []];
        }

        curl_close($ch);
        return ['status' => $status, 'body' => $responseBody, 'headers' => $responseHeaders];
    }
}


// =================================================================
// CLOUD FILE UPLOADER
// =================================================================

class SnapSmackCloudUploader {

    private string $provider;
    private string $accessToken;
    /** @var callable|null */
    private $progressFn;

    // Chunk sizes
    const GOOGLE_CHUNK_SIZE   = 5 * 1024 * 1024;  // 5 MB
    const ONEDRIVE_CHUNK_SIZE = 4 * 1024 * 1024;   // 4 MB (must be multiple of 320KB)

    public function __construct(string $provider, string $accessToken, ?callable $progressFn = null) {
        $this->provider    = $provider;
        $this->accessToken = $accessToken;
        $this->progressFn  = $progressFn;
    }

    /**
     * Upload a file to the "SnapSmack Backups" folder on the cloud provider.
     *
     * @param string $localPath  Absolute path to the local file
     * @param string $filename   The filename to use on the cloud drive
     * @return array{success: bool, message: string, file_id: string|null}
     */
    public function uploadFile(string $localPath, string $filename): array {
        if (!file_exists($localPath)) {
            return ['success' => false, 'message' => 'Local file not found.', 'file_id' => null];
        }

        $this->progress("Preparing upload: {$filename} (" . $this->formatBytes(filesize($localPath)) . ")", 'info');

        // Ensure the backup folder exists
        $folderId = $this->ensureBackupFolder();
        if (!$folderId) {
            return ['success' => false, 'message' => 'Could not create or find backup folder.', 'file_id' => null];
        }

        $this->progress("Target folder: SnapSmack Backups (ID: " . substr($folderId, 0, 12) . "...)", 'info');

        // Route to provider-specific upload
        if ($this->provider === SnapSmackCloudOAuth::GOOGLE) {
            return $this->uploadToGoogleDrive($localPath, $filename, $folderId);
        } elseif ($this->provider === SnapSmackCloudOAuth::ONEDRIVE) {
            return $this->uploadToOneDrive($localPath, $filename, $folderId);
        }

        return ['success' => false, 'message' => 'Unknown provider.', 'file_id' => null];
    }

    // =================================================================
    // BACKUP FOLDER MANAGEMENT
    // =================================================================

    /**
     * Ensure "SnapSmack Backups" folder exists, create if needed.
     * Caches folder ID in session to avoid repeated lookups.
     *
     * @return string|null Folder ID or null on failure
     */
    private function ensureBackupFolder(): ?string {
        $cacheKey = "cloud_folder_{$this->provider}";

        // Check session cache first
        if (!empty($_SESSION[$cacheKey])) {
            return $_SESSION[$cacheKey];
        }

        if ($this->provider === SnapSmackCloudOAuth::GOOGLE) {
            $folderId = $this->ensureGoogleFolder();
        } elseif ($this->provider === SnapSmackCloudOAuth::ONEDRIVE) {
            $folderId = $this->ensureOneDriveFolder();
        } else {
            return null;
        }

        if ($folderId) {
            $_SESSION[$cacheKey] = $folderId;
        }

        return $folderId;
    }

    /**
     * Find or create "SnapSmack Backups" folder on Google Drive.
     */
    private function ensureGoogleFolder(): ?string {
        // Search for existing folder
        $query = urlencode("name='SnapSmack Backups' and mimeType='application/vnd.google-apps.folder' and trashed=false");
        $response = SnapSmackCloudOAuth::curlRequest('GET',
            "https://www.googleapis.com/drive/v3/files?q={$query}&fields=files(id,name)",
            ["Authorization: Bearer {$this->accessToken}"]
        );

        if ($response['status'] === 200) {
            $data = json_decode($response['body'], true);
            if (!empty($data['files'][0]['id'])) {
                $this->progress("Found existing backup folder on Google Drive.", 'ok');
                return $data['files'][0]['id'];
            }
        }

        // Create folder
        $metadata = json_encode([
            'name'     => 'SnapSmack Backups',
            'mimeType' => 'application/vnd.google-apps.folder',
        ]);

        $response = SnapSmackCloudOAuth::curlRequest('POST',
            'https://www.googleapis.com/drive/v3/files',
            [
                "Authorization: Bearer {$this->accessToken}",
                'Content-Type: application/json',
            ],
            $metadata
        );

        if ($response['status'] === 200 || $response['status'] === 201) {
            $data = json_decode($response['body'], true);
            $this->progress("Created backup folder on Google Drive.", 'ok');
            return $data['id'] ?? null;
        }

        $this->progress("Failed to create Google Drive folder (HTTP {$response['status']}).", 'error');
        return null;
    }

    /**
     * Find or create "SnapSmack Backups" folder on OneDrive.
     */
    private function ensureOneDriveFolder(): ?string {
        // Check if folder exists
        $response = SnapSmackCloudOAuth::curlRequest('GET',
            'https://graph.microsoft.com/v1.0/me/drive/root:/SnapSmack%20Backups',
            ["Authorization: Bearer {$this->accessToken}"]
        );

        if ($response['status'] === 200) {
            $data = json_decode($response['body'], true);
            if (!empty($data['id'])) {
                $this->progress("Found existing backup folder on OneDrive.", 'ok');
                return $data['id'];
            }
        }

        // Create folder
        $metadata = json_encode([
            'name'   => 'SnapSmack Backups',
            'folder' => (object)[],
            '@microsoft.graph.conflictBehavior' => 'fail',
        ]);

        $response = SnapSmackCloudOAuth::curlRequest('POST',
            'https://graph.microsoft.com/v1.0/me/drive/root/children',
            [
                "Authorization: Bearer {$this->accessToken}",
                'Content-Type: application/json',
            ],
            $metadata
        );

        if ($response['status'] === 201 || $response['status'] === 200) {
            $data = json_decode($response['body'], true);
            $this->progress("Created backup folder on OneDrive.", 'ok');
            return $data['id'] ?? null;
        }

        // 409 Conflict = folder already exists, try to fetch it again
        if ($response['status'] === 409) {
            $response = SnapSmackCloudOAuth::curlRequest('GET',
                'https://graph.microsoft.com/v1.0/me/drive/root:/SnapSmack%20Backups',
                ["Authorization: Bearer {$this->accessToken}"]
            );
            if ($response['status'] === 200) {
                $data = json_decode($response['body'], true);
                return $data['id'] ?? null;
            }
        }

        $this->progress("Failed to create OneDrive folder (HTTP {$response['status']}).", 'error');
        return null;
    }

    // =================================================================
    // GOOGLE DRIVE UPLOAD
    // =================================================================

    /**
     * Upload a file to Google Drive using resumable upload.
     */
    private function uploadToGoogleDrive(string $localPath, string $filename, string $folderId): array {
        $fileSize = filesize($localPath);

        // Step 1: Initiate resumable upload session
        $this->progress("Initiating Google Drive resumable upload...", 'info');

        $metadata = json_encode([
            'name'    => $filename,
            'parents' => [$folderId],
        ]);

        $response = SnapSmackCloudOAuth::curlRequest('POST',
            'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable',
            [
                "Authorization: Bearer {$this->accessToken}",
                'Content-Type: application/json; charset=UTF-8',
                'X-Upload-Content-Type: application/gzip',
                "X-Upload-Content-Length: {$fileSize}",
            ],
            $metadata
        );

        if ($response['status'] !== 200) {
            return $this->handleApiError('Google Drive', $response);
        }

        $sessionUri = $response['headers']['location'] ?? '';
        if (empty($sessionUri)) {
            return ['success' => false, 'message' => 'No upload session URI returned.', 'file_id' => null];
        }

        // Step 2: Upload in chunks
        $this->progress("Upload session started. Sending {$this->formatBytes($fileSize)} in " . ceil($fileSize / self::GOOGLE_CHUNK_SIZE) . " chunks...", 'info');

        $handle = fopen($localPath, 'rb');
        if (!$handle) {
            return ['success' => false, 'message' => 'Cannot open local file for reading.', 'file_id' => null];
        }

        $offset = 0;
        $chunkNum = 0;
        $totalChunks = ceil($fileSize / self::GOOGLE_CHUNK_SIZE);

        while ($offset < $fileSize) {
            $chunkNum++;
            $chunkSize = min(self::GOOGLE_CHUNK_SIZE, $fileSize - $offset);
            $chunkData = fread($handle, $chunkSize);
            $rangeEnd  = $offset + $chunkSize - 1;

            $response = SnapSmackCloudOAuth::curlPutChunk($sessionUri, [
                "Content-Length: {$chunkSize}",
                "Content-Range: bytes {$offset}-{$rangeEnd}/{$fileSize}",
            ], $chunkData);

            // 308 Resume Incomplete = chunk accepted, continue
            // 200/201 = upload complete
            if ($response['status'] === 308) {
                $pct = round(($offset + $chunkSize) / $fileSize * 100);
                $this->progress("Chunk {$chunkNum}/{$totalChunks}: {$this->formatBytes($offset + $chunkSize)} / {$this->formatBytes($fileSize)} ({$pct}%)", 'ok');
                $offset += $chunkSize;
                continue;
            }

            if ($response['status'] === 200 || $response['status'] === 201) {
                fclose($handle);
                $data = json_decode($response['body'], true);
                $fileId = $data['id'] ?? 'unknown';
                $this->progress("Upload complete. File ID: {$fileId}", 'ok');
                return ['success' => true, 'message' => 'File uploaded to Google Drive.', 'file_id' => $fileId];
            }

            // Error
            fclose($handle);
            return $this->handleApiError('Google Drive', $response);
        }

        fclose($handle);

        // If we finished the loop without a 200/201, something went wrong
        return ['success' => false, 'message' => 'Upload loop ended without completion.', 'file_id' => null];
    }

    // =================================================================
    // ONEDRIVE UPLOAD
    // =================================================================

    /**
     * Upload a file to OneDrive using chunked upload session.
     */
    private function uploadToOneDrive(string $localPath, string $filename, string $folderId): array {
        $fileSize = filesize($localPath);

        // For small files (<4MB), use simple PUT
        if ($fileSize < self::ONEDRIVE_CHUNK_SIZE) {
            return $this->uploadToOneDriveSimple($localPath, $filename);
        }

        // Step 1: Create upload session
        $this->progress("Creating OneDrive upload session...", 'info');

        $sessionBody = json_encode([
            'item' => [
                '@microsoft.graph.conflictBehavior' => 'replace',
                'name' => $filename,
            ],
        ]);

        $encodedFilename = rawurlencode($filename);
        $response = SnapSmackCloudOAuth::curlRequest('POST',
            "https://graph.microsoft.com/v1.0/me/drive/items/{$folderId}:/{$encodedFilename}:/createUploadSession",
            [
                "Authorization: Bearer {$this->accessToken}",
                'Content-Type: application/json',
            ],
            $sessionBody
        );

        if ($response['status'] !== 200) {
            return $this->handleApiError('OneDrive', $response);
        }

        $sessionData = json_decode($response['body'], true);
        $uploadUrl = $sessionData['uploadUrl'] ?? '';
        if (empty($uploadUrl)) {
            return ['success' => false, 'message' => 'No upload URL returned from OneDrive.', 'file_id' => null];
        }

        // Step 2: Upload in chunks
        $this->progress("Upload session started. Sending {$this->formatBytes($fileSize)} in " . ceil($fileSize / self::ONEDRIVE_CHUNK_SIZE) . " chunks...", 'info');

        $handle = fopen($localPath, 'rb');
        if (!$handle) {
            return ['success' => false, 'message' => 'Cannot open local file for reading.', 'file_id' => null];
        }

        $offset = 0;
        $chunkNum = 0;
        $totalChunks = ceil($fileSize / self::ONEDRIVE_CHUNK_SIZE);

        while ($offset < $fileSize) {
            $chunkNum++;
            $chunkSize = min(self::ONEDRIVE_CHUNK_SIZE, $fileSize - $offset);
            $chunkData = fread($handle, $chunkSize);
            $rangeEnd  = $offset + $chunkSize - 1;

            // OneDrive chunked upload uses PUT with Content-Range
            $response = SnapSmackCloudOAuth::curlPutChunk($uploadUrl, [
                "Content-Length: {$chunkSize}",
                "Content-Range: bytes {$offset}-{$rangeEnd}/{$fileSize}",
            ], $chunkData);

            // 202 Accepted = chunk accepted, continue
            // 200/201 = upload complete
            if ($response['status'] === 202) {
                $pct = round(($offset + $chunkSize) / $fileSize * 100);
                $this->progress("Chunk {$chunkNum}/{$totalChunks}: {$this->formatBytes($offset + $chunkSize)} / {$this->formatBytes($fileSize)} ({$pct}%)", 'ok');
                $offset += $chunkSize;
                continue;
            }

            if ($response['status'] === 200 || $response['status'] === 201) {
                fclose($handle);
                $data = json_decode($response['body'], true);
                $fileId = $data['id'] ?? 'unknown';
                $this->progress("Upload complete. File ID: {$fileId}", 'ok');
                return ['success' => true, 'message' => 'File uploaded to OneDrive.', 'file_id' => $fileId];
            }

            // Error
            fclose($handle);
            return $this->handleApiError('OneDrive', $response);
        }

        fclose($handle);
        return ['success' => false, 'message' => 'Upload loop ended without completion.', 'file_id' => null];
    }

    /**
     * Simple PUT upload for small files (<4MB) on OneDrive.
     */
    private function uploadToOneDriveSimple(string $localPath, string $filename): array {
        $this->progress("Small file — using simple upload...", 'info');

        $encodedFilename = rawurlencode($filename);
        $fileData = file_get_contents($localPath);

        $response = SnapSmackCloudOAuth::curlPutChunk(
            "https://graph.microsoft.com/v1.0/me/drive/root:/SnapSmack%20Backups/{$encodedFilename}:/content",
            [
                "Authorization: Bearer {$this->accessToken}",
                'Content-Type: application/octet-stream',
            ],
            $fileData
        );

        if ($response['status'] === 200 || $response['status'] === 201) {
            $data = json_decode($response['body'], true);
            $fileId = $data['id'] ?? 'unknown';
            $this->progress("Upload complete. File ID: {$fileId}", 'ok');
            return ['success' => true, 'message' => 'File uploaded to OneDrive.', 'file_id' => $fileId];
        }

        return $this->handleApiError('OneDrive', $response);
    }

    // =================================================================
    // ERROR HANDLING
    // =================================================================

    /**
     * Parse API error responses into user-friendly messages.
     */
    private function handleApiError(string $provider, array $response): array {
        $status = $response['status'];
        $body = json_decode($response['body'], true);

        // Extract error message from provider-specific format
        if ($provider === 'Google Drive') {
            $errorMsg = $body['error']['message'] ?? $body['error_description'] ?? '';
        } else {
            $errorMsg = $body['error']['message'] ?? '';
        }

        $message = match(true) {
            $status === 0   => "Network error: {$response['body']}",
            $status === 401 => "Authorization expired. Please reconnect via Cloud Backup settings.",
            $status === 403 && str_contains($response['body'], 'storageQuota')
                            => "Cloud storage quota exceeded. Free up space and try again.",
            $status === 403 => "Access denied. You may have revoked access. Please reconnect.",
            $status === 404 => "Upload endpoint not found. The upload session may have expired.",
            $status === 429 => "Rate limited by {$provider}. Wait a moment and try again.",
            $status >= 500  => "{$provider} server error (HTTP {$status}). Try again later.",
            default         => "{$provider} error (HTTP {$status}): " . ($errorMsg ?: 'Unknown error.'),
        };

        $this->progress($message, 'error');
        return ['success' => false, 'message' => $message, 'file_id' => null];
    }

    // =================================================================
    // HELPERS
    // =================================================================

    /**
     * Send a progress message via the callback.
     */
    private function progress(string $message, string $status = 'info'): void {
        if ($this->progressFn) {
            ($this->progressFn)($message, $status);
        }
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
}
