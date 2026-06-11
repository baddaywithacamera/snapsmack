<?php
/**
 * SMACK CENTRAL - Self-updater
 *
 * Pulls the latest tagged release of smack-central/ from GitHub,
 * runs sc-schema.sql idempotently, and records the installed tag.
 *
 * Safe to run repeatedly. sc-config.php is never touched.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once __DIR__ . '/sc-auth.php';
require_once __DIR__ . '/sc-version.php';

// ── HTTP + GitHub helpers (guarded — sc-release.php may already define them) ──

if (!function_exists('sc_http_raw')) {
    function sc_http_raw(string $url, array $extra_headers = [], int $timeout = 120): string|false {
        if (function_exists('curl_init')) {
            $ch   = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'SnapSmack-SC/1.0',
            ];
            if ($extra_headers) $opts[CURLOPT_HTTPHEADER] = $extra_headers;
            curl_setopt_array($ch, $opts);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($body !== false && $code === 200) ? $body : false;
        }
        $ctx_opts = ['http' => ['timeout' => $timeout, 'user_agent' => 'SnapSmack-SC/1.0']];
        if ($extra_headers) $ctx_opts['http']['header'] = implode("\r\n", $extra_headers);
        return @file_get_contents($url, false, stream_context_create($ctx_opts));
    }
}

if (!function_exists('sc_github_get')) {
    function sc_github_get(string $endpoint): array|false {
        $headers = ['Accept: application/vnd.github.v3+json', 'User-Agent: SnapSmack-SC/1.0'];
        if (defined('SNAPSMACK_GITHUB_TOKEN') && SNAPSMACK_GITHUB_TOKEN) {
            $headers[] = 'Authorization: token ' . SNAPSMACK_GITHUB_TOKEN;
        }
        $body = sc_http_raw('https://api.github.com/' . ltrim($endpoint, '/'), $headers, 15);
        if ($body === false) return false;
        $data = json_decode($body, true);
        return is_array($data) ? $data : false;
    }
}

// ── Preflight: required constants ─────────────────────────────────────────────

$preflight_errors = [];
foreach (['SNAPSMACK_GITHUB_REPO', 'SMACK_RELEASE_PRIVKEY'] as $_const) {
    if (!defined($_const) || !constant($_const)) {
        $preflight_errors[] = "<code>{$_const}</code> is not defined in sc-config.php.";
    }
}

// ── Load settings ─────────────────────────────────────────────────────────────

$sc_db = sc_db();
$settings = $sc_db->query("SELECT setting_key, setting_val FROM sc_settings")
                   ->fetchAll(PDO::FETCH_KEY_PAIR);

$installed_ref = $settings['sc_installed_ref'] ?? '';  // stores tag name, e.g. "0.7.9e"

// ── Check latest tag on GitHub ────────────────────────────────────────────────

$latest_tag      = '';
$latest_tag_sha  = '';
$check_error     = '';

$tags_data = $preflight_errors ? false : sc_github_get('repos/' . SNAPSMACK_GITHUB_REPO . '/tags?per_page=20');
if (is_array($tags_data) && !empty($tags_data)) {
    // Filter out non-SnapSmack release tags. SYBU and other companion tools
    // tag with prefixes (vSYBU-*) that PHP's version_compare normalises in
    // ways that can sort them above the real release tags. Only consider
    // pure semver tags like v0.7.77 or 0.7.77.
    $tags_data = array_values(array_filter($tags_data, function($t) {
        $name = $t['name'] ?? '';
        return (bool) preg_match('/^v?\d+\.\d+\.\d+[a-z]?$/i', $name);
    }));
    if (empty($tags_data)) {
        // After filtering, no SnapSmack release tags found.
        // Fall through; the empty check below will surface a clear error.
    }
    // Sort tags by version descending — GitHub order is unreliable.
    // Normalises trailing patch letters (0.7.9k → 0.7.9.11) so version_compare works.
    $normalise_ver = function(string $v): string {
        $v = ltrim($v, 'v');
        if (preg_match('/^(\d+(?:\.\d+)*)([a-z])$/i', $v, $m)) {
            return $m[1] . '.' . (ord(strtolower($m[2])) - ord('a') + 1);
        }
        return $v . '.0';
    };
    usort($tags_data, function($a, $b) use ($normalise_ver) {
        return version_compare($normalise_ver($b['name']), $normalise_ver($a['name']));
    });
    $latest_tag     = $tags_data[0]['name']          ?? '';
    $latest_tag_sha = $tags_data[0]['commit']['sha'] ?? '';
} else {
    $check_error = 'Could not reach GitHub API. Check your token and network.';
}

$up_to_date = $latest_tag && $installed_ref && ($installed_ref === $latest_tag);

// ── Handle update POST ────────────────────────────────────────────────────────

$result_log  = [];
$result_ok   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pull') {

    if (!$latest_tag) {
        $result_log[] = ['err', 'No tags found on GitHub — cannot pull.'];
        $result_ok    = false;
    } else {

    $repo    = SNAPSMACK_GITHUB_REPO;
    $zip_url = "https://github.com/{$repo}/archive/refs/tags/{$latest_tag}.zip";
    $tmp_zip = sys_get_temp_dir() . '/sc_update_' . bin2hex(random_bytes(8)) . '.zip';
    $tmp_dir = sys_get_temp_dir() . '/sc_update_' . bin2hex(random_bytes(8)) . '/';

    try {
        // 1. Download repo zip ────────────────────────────────────────────────
        $headers = ['User-Agent: SnapSmack-SC/1.0'];
        if (defined('SNAPSMACK_GITHUB_TOKEN') && SNAPSMACK_GITHUB_TOKEN) {
            $headers[] = 'Authorization: token ' . SNAPSMACK_GITHUB_TOKEN;
        }
        $zip_bytes = sc_http_raw($zip_url, $headers, 120);
        if ($zip_bytes === false || strlen($zip_bytes) < 1000) {
            throw new RuntimeException('Failed to download repo zip from GitHub.');
        }
        file_put_contents($tmp_zip, $zip_bytes);
        $result_log[] = ['ok', "Downloaded {$latest_tag} zip (" . round(strlen($zip_bytes) / 1024) . ' KB)'];

        // 2. Extract ──────────────────────────────────────────────────────────
        mkdir($tmp_dir, 0755, true);
        $zip = new ZipArchive();
        if ($zip->open($tmp_zip) !== true) {
            throw new RuntimeException('Could not open zip archive.');
        }
        $zip->extractTo($tmp_dir);
        $zip->close();
        $result_log[] = ['ok', 'Extracted archive'];

        // Find wrapper prefix (e.g. snapsmack-master/)
        $entries = array_diff(scandir($tmp_dir), ['.', '..']);
        $wrapper = count($entries) === 1 ? $tmp_dir . reset($entries) . '/' : $tmp_dir;
        $sc_src  = $wrapper . 'smack-central/';

        if (!is_dir($sc_src)) {
            throw new RuntimeException('smack-central/ not found in extracted archive.');
        }

        // 3. Copy smack-central/ files into place ─────────────────────────────
        // sc-config.php: never touched — live server config.
        // sc-db.php: never touched — DB connection changes require manual deploy
        //   so a bad tag cannot silently drop sc_enemy_db() or sc_forum_db().
        $protected_files = ['sc-config.php', 'sc-db.php', 'sc-version.php'];
        $dest_dir        = __DIR__ . '/';
        $copied          = 0;
        $skipped         = 0;

        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sc_src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $item) {
            $rel  = $iter->getSubPathname();
            $dest = $dest_dir . $rel;

            if ($item->isDir()) {
                if (!is_dir($dest)) mkdir($dest, 0755, true);
                continue;
            }

            if (in_array(basename($rel), $protected_files, true)) {
                $skipped++;
                continue;
            }

            copy($item->getPathname(), $dest);
            $copied++;
        }
        $result_log[] = ['ok', "smack-central/: {$copied} files installed" . ($skipped ? ", {$skipped} protected skipped" : '')];

        // 3a. Write sc-version.php from the pulled tag ────────────────────────
        // sc-version.php in git is never updated (would require a commit per release).
        // We derive the version string from the tag and write it explicitly so
        // the Running row in sc-update.php reflects the actual installed version.
        $tag_version  = ltrim($latest_tag, 'v');   // "0.7.235D"
        // Fetch the codename from latest-dev.json on disk if available, else blank.
        $dev_json_path = dirname(__DIR__) . '/releases/latest-dev.json';
        $tag_codename  = '';
        if (file_exists($dev_json_path)) {
            $dev_json = json_decode(file_get_contents($dev_json_path), true);
            $tag_codename = $dev_json['codename'] ?? '';
        }
        $sc_ver_content = "<?php\n"
            . "// Auto-written by sc-update.php on pull — do not edit by hand.\n"
            . "define('SC_VERSION',  '" . addslashes($tag_version) . "');\n"
            . "define('SC_CODENAME', '" . addslashes($tag_codename) . "');\n"
            . "// ===== SNAPSMACK EOF =====\n";
        file_put_contents($dest_dir . 'sc-version.php', $sc_ver_content);
        $result_log[] = ['ok', "sc-version.php updated to {$tag_version}"];

        // 3b. Copy projects/snapsmack-ca/ files to web root ───────────────────
        // These are the public-facing SC API endpoints and snapsmack.ca assets.
        // Protected: release zips/manifests (managed by Release Packager),
        //   .gitignore, .sc-sessions/ session storage.
        $webroot_src = $wrapper . 'projects/snapsmack-ca/';

        if (is_dir($webroot_src)) {
            $webroot_dest    = dirname(__DIR__) . '/';
            $skip_dirs       = ['.sc-sessions', '.git'];
            $skip_extensions = ['zip'];
            $skip_files      = ['.gitignore'];
            $wr_copied       = 0;

            $wr_iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($webroot_src, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($wr_iter as $wr_item) {
                $rel  = $wr_iter->getSubPathname();
                $dest = $webroot_dest . $rel;

                // Skip protected directories (check any path segment)
                $skip = false;
                foreach (explode('/', str_replace('\\', '/', $rel)) as $seg) {
                    if (in_array($seg, $skip_dirs, true)) { $skip = true; break; }
                }
                if ($skip) continue;

                if ($wr_item->isDir()) {
                    if (!is_dir($dest)) mkdir($dest, 0755, true);
                    continue;
                }

                $fname = basename($rel);
                $ext   = strtolower(pathinfo($fname, PATHINFO_EXTENSION));

                if (in_array($fname, $skip_files, true)) continue;
                if (in_array($ext, $skip_extensions, true)) continue;

                copy($wr_item->getPathname(), $dest);
                $wr_copied++;
            }
            $result_log[] = ['ok', "snapsmack.ca web root: {$wr_copied} files installed"];
        } else {
            $result_log[] = ['warn', 'projects/snapsmack-ca/ not found in archive — web-root files not updated'];
        }

        // 4. Schema sync — smackcent canonical ────────────────────────────────
        // Use the canonical schema file (schemas/sc-smackcent-canonical.sql),
        // not the legacy sc-schema.sql stub. All statements use IF NOT EXISTS
        // guards so this is safe to run on every update.
        $canonical_path = __DIR__ . '/schemas/sc-smackcent-canonical.sql';
        if (file_exists($canonical_path)) {
            $schema_sql  = file_get_contents($canonical_path);
            $schema_errs = sc_apply_schema($sc_db, $schema_sql);
            if ($schema_errs) {
                $result_log[] = ['warn', 'Schema: ' . implode('; ', $schema_errs)];
            } else {
                $result_log[] = ['ok', 'Schema sync complete (sc-smackcent-canonical.sql)'];
            }
        } else {
            $result_log[] = ['warn', 'Canonical schema file not found — schema sync skipped'];
        }

        // 5. Record installed tag ─────────────────────────────────────────────
        $sc_db->prepare("
            INSERT INTO sc_settings (setting_key, setting_val)
            VALUES ('sc_installed_ref', ?)
            ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)
        ")->execute([$latest_tag]);
        $result_log[] = ['ok', "Recorded installed tag: {$latest_tag}"];

        $result_ok     = true;
        $installed_ref = $latest_tag;
        $up_to_date    = true;

    } catch (RuntimeException $e) {
        $result_log[] = ['err', $e->getMessage()];
        $result_ok    = false;
    } finally {
        // Clean up temp files
        if (file_exists($tmp_zip)) unlink($tmp_zip);
        if (is_dir($tmp_dir)) {
            $clean = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tmp_dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($clean as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($tmp_dir);
        }
    }
    } // end else ($latest_tag)
}

// ── Schema helper ─────────────────────────────────────────────────────────────

/**
 * Execute a SQL file statement-by-statement, ignoring errors that mean
 * "already applied": duplicate column (1060), table exists (1050),
 * duplicate key (1061), can't drop non-existent key (1091).
 *
 * Returns an array of unexpected error strings (empty = clean run).
 */
function sc_apply_schema(PDO $pdo, string $sql): array {
    $idempotent = [1050, 1060, 1061, 1091];
    $errors     = [];

    // Strip comments and split on semicolons
    $sql   = preg_replace('/--[^\n]*\n/', "\n", $sql);
    $sql   = preg_replace('#/\*.*?\*/#s', '', $sql);
    $stmts = array_filter(array_map('trim', explode(';', $sql)));

    foreach ($stmts as $stmt) {
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            $code = (int)($e->errorInfo[1] ?? 0);
            if (!in_array($code, $idempotent, true)) {
                $errors[] = $e->getMessage();
            }
        }
    }
    return $errors;
}

// ── Page ──────────────────────────────────────────────────────────────────────

$sc_page_title = 'Update Smack Central';
$sc_active_nav = 'sc-update.php';
include __DIR__ . '/sc-layout-top.php';
?>

<div class="sc-page-header">
    <h1 class="sc-page-title">Update Smack Central</h1>
    <p class="sc-page-sub">Pulls the latest tagged release from GitHub. sc-config.php is never touched.</p>
</div>

<?php if ($preflight_errors): ?>
    <div class="sc-card" style="margin-bottom:24px;border-color:#e05555;">
        <h2 class="sc-card-title" style="color:#e05555;">Configuration incomplete</h2>
        <p style="margin-bottom:12px;color:var(--sc-text-muted,#888);font-size:0.88rem;">
            Add the following to <code>sc-config.php</code> on the server:
        </p>
        <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:6px;font-size:0.85rem;font-family:monospace;">
            <?php foreach ($preflight_errors as $e): ?>
                <li style="color:#e05555;">✗ <?php echo $e; ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($result_log): ?>
    <div class="sc-card" style="margin-bottom:24px;">
        <h2 class="sc-card-title">Update <?php echo $result_ok ? 'Complete' : 'Failed'; ?></h2>
        <ul class="sc-update-log">
            <?php foreach ($result_log as [$status, $msg]): ?>
                <li class="sc-log-<?php echo $status; ?>">
                    <?php echo $status === 'ok' ? '✓' : ($status === 'warn' ? '⚠' : '✗'); ?>
                    <?php echo htmlspecialchars($msg); ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="sc-card">
    <h2 class="sc-card-title">Status</h2>

    <table class="sc-update-status-table">
        <tr>
            <th>Running</th>
            <td>
                <?php echo htmlspecialchars(SC_VERSION); ?>
                <span class="sc-muted">(<?php echo htmlspecialchars(SC_CODENAME); ?>)</span>
            </td>
        </tr>
        <tr>
            <th>Installed</th>
            <td>
                <?php if ($installed_ref): ?>
                    <code><?php echo htmlspecialchars($installed_ref); ?></code>
                <?php else: ?>
                    <span class="sc-muted">Not recorded — run an update to set baseline.</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>Latest release</th>
            <td>
                <?php if ($check_error): ?>
                    <span class="sc-warn"><?php echo htmlspecialchars($check_error); ?></span>
                <?php elseif ($latest_tag): ?>
                    <code><?php echo htmlspecialchars($latest_tag); ?></code>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>State</th>
            <td>
                <?php if ($check_error): ?>
                    <span class="sc-muted">Unknown</span>
                <?php elseif ($up_to_date): ?>
                    <span class="sc-ok">Up to date</span>
                <?php else: ?>
                    <span class="sc-warn">Update available</span>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <?php if (!$check_error && $latest_tag): ?>
        <form method="post" action="sc-update.php" style="margin-top:20px;"
              onsubmit="return confirm('Pull <?php echo htmlspecialchars($latest_tag, ENT_QUOTES); ?> from GitHub and apply?\n\nsc-config.php will not be touched.');">
            <input type="hidden" name="action" value="pull">
            <button type="submit" class="sc-btn sc-btn-primary">
                <?php echo $up_to_date ? 'Re-pull ' . htmlspecialchars($latest_tag) : 'Pull ' . htmlspecialchars($latest_tag); ?>
            </button>
        </form>
    <?php endif; ?>
</div>

<style>
.sc-update-log {
    list-style: none;
    margin: 12px 0 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 0.85rem;
    font-family: monospace;
}
.sc-log-ok   { color: #6fcf6f; }
.sc-log-warn { color: #e6a817; }
.sc-log-err  { color: #e05555; }
.sc-update-status-table { border-collapse: collapse; width: 100%; font-size: 0.88rem; }
.sc-update-status-table th,
.sc-update-status-table td { padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--sc-border, #2a2a2a); }
.sc-update-status-table th { width: 160px; color: var(--sc-text-muted, #888); font-weight: 500; }
.sc-ok   { color: #6fcf6f; font-weight: 500; }
.sc-warn { color: #e6a817; font-weight: 500; }
.sc-muted { color: var(--sc-text-muted, #888); }
</style>

<?php include __DIR__ . '/sc-layout-bottom.php'; ?>
<?php // ===== SNAPSMACK EOF =====
