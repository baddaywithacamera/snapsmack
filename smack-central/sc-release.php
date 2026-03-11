<?php
/**
 * SMACK CENTRAL - Release Packager
 * Alpha v0.7.1
 *
 * Fetches tags from GitHub, downloads the repo zip for the selected tag,
 * repackages it as a clean distributable zip (no local git or shell access
 * required), SHA-256 checksums it, signs the checksum with the Ed25519
 * private key, drops the zip in the releases directory, and publishes
 * releases/latest.json in the format the SnapSmack updater expects.
 */

require_once __DIR__ . '/sc-auth.php';
$sc_active_nav = 'sc-release.php';
$sc_page_title = 'Release Packager';

// ── GitHub config ─────────────────────────────────────────────────────────────
// Define SNAPSMACK_GITHUB_REPO / SNAPSMACK_GITHUB_TOKEN in sc-config.php to
// override these defaults. Token is optional but raises the rate limit from
// 60 → 5000 requests/hour.
if (!defined('SNAPSMACK_GITHUB_REPO'))  define('SNAPSMACK_GITHUB_REPO',  'baddaywithacamera/snapsmack');
if (!defined('SNAPSMACK_GITHUB_TOKEN')) define('SNAPSMACK_GITHUB_TOKEN', '');

// ── Preflight checks ──────────────────────────────────────────────────────────
$preflight = [];

if (!function_exists('sodium_crypto_sign_detached')) {
    $preflight[] = ['err', 'libsodium is not available. PHP 7.2+ with sodium extension required.'];
}
if (!class_exists('ZipArchive')) {
    $preflight[] = ['err', 'ZipArchive is not available. Install the php-zip extension.'];
}
if (!function_exists('curl_init') && !ini_get('allow_url_fopen')) {
    $preflight[] = ['err', 'No HTTP fetch method available. Enable curl or allow_url_fopen in php.ini.'];
}
if (!defined('SMACK_RELEASE_PRIVKEY') || strlen(SMACK_RELEASE_PRIVKEY) !== 128) {
    $preflight[] = ['err', 'SMACK_RELEASE_PRIVKEY is not set or is the wrong length. Check sc-config.php.'];
}
if (!is_dir(RELEASES_DIR)) {
    $preflight[] = ['warn', 'Releases directory does not exist: ' . RELEASES_DIR . '. Create it and make it web-accessible.'];
}

$preflight_ok = !array_filter($preflight, fn($p) => $p[0] === 'err');

// ── Helper: raw HTTP GET (curl preferred, file_get_contents fallback) ─────────
function sc_http_raw(string $url, array $extra_headers = []): string|false {
    if (function_exists('curl_init')) {
        $ch   = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'SnapSmack-SC/0.7.1',
        ];
        if ($extra_headers) $opts[CURLOPT_HTTPHEADER] = $extra_headers;
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code === 200) ? $body : false;
    }
    $ctx_opts = ['http' => ['timeout' => 120, 'user_agent' => 'SnapSmack-SC/0.7.1']];
    if ($extra_headers) $ctx_opts['http']['header'] = implode("\r\n", $extra_headers);
    return @file_get_contents($url, false, stream_context_create($ctx_opts));
}

// ── Helper: GitHub API GET → decoded array ────────────────────────────────────
function sc_github_get(string $endpoint): array|false {
    $headers = ['Accept: application/vnd.github.v3+json', 'User-Agent: SnapSmack-SC/0.7.1'];
    if (SNAPSMACK_GITHUB_TOKEN) {
        $headers[] = 'Authorization: token ' . SNAPSMACK_GITHUB_TOKEN;
    }
    $body = sc_http_raw('https://api.github.com/' . ltrim($endpoint, '/'), $headers);
    if ($body === false) return false;
    $data = json_decode($body, true);
    return is_array($data) ? $data : false;
}

// ── Helper: list tags from GitHub ─────────────────────────────────────────────
function sc_list_tags(): array {
    $data = sc_github_get('repos/' . SNAPSMACK_GITHUB_REPO . '/tags?per_page=30');
    if (!is_array($data)) return [];
    return array_column($data, 'name');
}

// ── Helper: file changes between two tags via GitHub compare API ──────────────
function sc_file_changes(string $from_tag, string $to_tag): array {
    $from = urlencode($from_tag);
    $to   = urlencode($to_tag);
    $data = sc_github_get('repos/' . SNAPSMACK_GITHUB_REPO . "/compare/{$from}...{$to}");
    $added = $modified = $removed = [];
    if (!is_array($data) || empty($data['files'])) {
        return ['added' => [], 'modified' => [], 'removed' => []];
    }
    foreach ($data['files'] as $f) {
        $name   = $f['filename'] ?? '';
        $status = $f['status']   ?? '';
        match ($status) {
            'added'    => $added[]    = $name,
            'modified' => $modified[] = $name,
            'removed'  => $removed[]  = $name,
            'renamed'  => $modified[] = $name, // treat renames as modified (new path)
            default    => null,
        };
    }
    return ['added' => $added, 'modified' => $modified, 'removed' => $removed];
}

// ── Helper: download GitHub tag zip and repackage as clean release zip ────────
// GitHub zips include a wrapper directory (e.g. "snapsmack-0.7.1/"). This
// function strips that prefix so the release zip contains files at their
// actual paths, matching what git archive would have produced.
function sc_build_release_zip(string $tag, string $zip_dest): array {
    $url  = 'https://github.com/' . SNAPSMACK_GITHUB_REPO . '/archive/refs/tags/' . urlencode($tag) . '.zip';
    $data = sc_http_raw($url);
    if ($data === false) {
        return ['ok' => false, 'msg' => 'Could not download zip from GitHub. Check outbound HTTPS access.'];
    }

    $tmp_src = sys_get_temp_dir() . '/sc_gh_' . time() . '_' . rand(1000, 9999) . '.zip';
    file_put_contents($tmp_src, $data);
    $dl_kb = round(strlen($data) / 1024);
    unset($data);

    $src = new ZipArchive();
    if ($src->open($tmp_src) !== true) {
        @unlink($tmp_src);
        return ['ok' => false, 'msg' => 'Could not open downloaded zip — file may be corrupt.'];
    }

    // Detect the wrapper prefix: find the first top-level directory entry.
    // GitHub names it "{reponame}-{tagname-without-v}/" e.g. "snapsmack-0.7.1/"
    $prefix = '';
    for ($i = 0; $i < $src->numFiles; $i++) {
        $n = $src->getNameIndex($i);
        if (str_ends_with($n, '/') && substr_count(rtrim($n, '/'), '/') === 0) {
            $prefix = $n;
            break;
        }
    }

    $dst = new ZipArchive();
    if ($dst->open($zip_dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        $src->close();
        @unlink($tmp_src);
        return ['ok' => false, 'msg' => 'Could not create release zip at ' . $zip_dest . ' — check directory permissions.'];
    }

    $count = 0;
    for ($i = 0; $i < $src->numFiles; $i++) {
        $name = $src->getNameIndex($i);
        $rel  = $prefix ? substr($name, strlen($prefix)) : $name;
        if ($rel === '' || str_ends_with($rel, '/')) continue;
        if (str_contains($rel, '..') || str_starts_with($rel, '/')) continue;
        $content = $src->getFromIndex($i);
        if ($content === false) continue;
        $dst->addFromString($rel, $content);
        $count++;
    }

    $src->close();
    $dst->close();
    @unlink($tmp_src);

    return [
        'ok'  => true,
        'msg' => "Downloaded {$dl_kb} KB from GitHub, packaged {$count} files (prefix stripped: \"{$prefix}\")",
    ];
}

// ── POST: Build release ───────────────────────────────────────────────────────
$action       = $_POST['action'] ?? '';
$build_error  = '';
$build_log    = [];
$build_result = null;

// Retrieve flash result from session after redirect.
if (isset($_SESSION['sc_release_built'])) {
    $build_result = $_SESSION['sc_release_built'];
    unset($_SESSION['sc_release_built']);
}

if ($action === 'build' && $preflight_ok) {

    $tag            = trim($_POST['tag']            ?? '');
    $version        = trim($_POST['version']        ?? '');
    $version_full   = trim($_POST['version_full']   ?? '');
    $released       = trim($_POST['released']       ?? date('Y-m-d'));
    $requires_php   = trim($_POST['requires_php']   ?? '8.0');
    $requires_mysql = trim($_POST['requires_mysql'] ?? '5.7');
    $schema_changes = !empty($_POST['schema_changes']);
    $changelog_raw  = trim($_POST['changelog'] ?? '');
    $changelog      = array_values(array_filter(array_map('trim', explode("\n", $changelog_raw))));

    // Validate
    if (!preg_match('/^[a-zA-Z0-9._\-]+$/', $tag)) {
        $build_error = 'Invalid tag format.';
    } elseif ($version === '' || $version_full === '') {
        $build_error = 'Version and Version Full are required.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $released)) {
        $build_error = 'Invalid release date.';
    }

    if (!$build_error) {
        $zip_name = 'snapsmack-' . preg_replace('/[^a-zA-Z0-9._\-]/', '', $version) . '.zip';
        $zip_dest = rtrim(RELEASES_DIR, '/') . '/' . $zip_name;

        // Step 1: Download from GitHub + repackage as clean zip
        $build_log[] = "→ Downloading tag {$tag} from GitHub…";
        $zip_result  = sc_build_release_zip($tag, $zip_dest);
        $build_log[] = '→ ' . $zip_result['msg'];

        if (!$zip_result['ok']) {
            $build_error = $zip_result['msg'];
        } else {
            // Step 2: SHA-256
            $checksum    = hash_file('sha256', $zip_dest);
            $build_log[] = "→ SHA-256: {$checksum}";

            // Step 3: Sign
            try {
                $privkey     = sodium_hex2bin(SMACK_RELEASE_PRIVKEY);
                $sig         = sodium_crypto_sign_detached($checksum, $privkey);
                $sig_hex     = sodium_bin2hex($sig);
                $build_log[] = "→ Signed OK";
            } catch (SodiumException $e) {
                $build_error = 'Signing failed: ' . $e->getMessage();
            }

            if (!$build_error) {
                $file_size    = filesize($zip_dest);
                $download_url = rtrim(RELEASES_URL, '/') . '/' . $zip_name;
                $build_log[]  = "→ Zip saved: {$zip_dest} (" . number_format($file_size / 1024, 1) . " KB)";

                // Step 4: File changes via GitHub compare API
                $file_changes = ['added' => [], 'modified' => [], 'removed' => []];
                try {
                    $prev = sc_db()->query("SELECT git_tag FROM sc_releases ORDER BY id DESC LIMIT 1")->fetch();
                    if ($prev && $prev['git_tag'] !== $tag) {
                        $build_log[]  = "→ Comparing {$prev['git_tag']}…{$tag} via GitHub API…";
                        $file_changes = sc_file_changes($prev['git_tag'], $tag);
                        $total        = count($file_changes['added']) + count($file_changes['modified']) + count($file_changes['removed']);
                        $build_log[]  = "→ File changes vs {$prev['git_tag']}: {$total} files";
                    } else {
                        $build_log[] = "→ No previous release to diff against; file_changes will be empty.";
                    }
                } catch (Exception $e) {
                    $build_log[] = "→ Could not compute file_changes: " . $e->getMessage();
                }

                // Step 4b: Auto-detect schema_changes from diff
                $diff_all = array_merge($file_changes['added'] ?? [], $file_changes['modified'] ?? []);
                foreach ($diff_all as $_df) {
                    if (str_starts_with($_df, 'migrations/')) {
                        $schema_changes = true;
                        $build_log[]    = "→ schema_changes: true (migration file detected in diff)";
                        break;
                    }
                }

                // Step 5: Write latest.json
                $manifest = [
                    'version'         => $version,
                    'version_full'    => $version_full,
                    'released'        => $released,
                    'download_url'    => $download_url,
                    'checksum_sha256' => $checksum,
                    'signature'       => $sig_hex,
                    'changelog'       => $changelog,
                    'file_changes'    => $file_changes,
                    'schema_changes'  => $schema_changes,
                    'requires_php'    => $requires_php,
                    'requires_mysql'  => $requires_mysql,
                    'download_size'   => $file_size,
                ];
                $json_path = rtrim(RELEASES_DIR, '/') . '/latest.json';
                file_put_contents($json_path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $build_log[] = "→ latest.json written";

                // Step 6: Persist to DB
                try {
                    $db = sc_db();
                    $db->exec("UPDATE sc_releases SET is_latest = 0");
                    $stmt = $db->prepare(
                        "INSERT INTO sc_releases
                            (version, version_full, git_tag, checksum_sha256, signature,
                             download_url, download_size, schema_changes, requires_php,
                             requires_mysql, changelog, file_changes, released_at, is_latest)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)"
                    );
                    $stmt->execute([
                        $version, $version_full, $tag, $checksum, $sig_hex,
                        $download_url, $file_size, $schema_changes ? 1 : 0,
                        $requires_php, $requires_mysql,
                        $changelog_raw,
                        json_encode($file_changes),
                        $released,
                    ]);
                    $build_log[] = "→ Release record saved (ID " . $db->lastInsertId() . ")";
                } catch (Exception $e) {
                    $build_log[] = "→ DB write failed: " . $e->getMessage();
                }

                // Store result and redirect to avoid form resubmission
                $_SESSION['sc_release_built'] = [
                    'version'      => $version_full,
                    'tag'          => $tag,
                    'checksum'     => $checksum,
                    'signature'    => $sig_hex,
                    'download_url' => $download_url,
                    'file_size'    => $file_size,
                    'log'          => $build_log,
                ];
                header('Location: sc-release.php?built=1');
                exit;
            }
        }
    }
}

// ── Fetch data for the form ───────────────────────────────────────────────────
$tags = [];
if ($preflight_ok) {
    $tags = sc_list_tags();
}

// Previous releases for the history table
$releases = [];
try {
    $releases = sc_db()->query(
        "SELECT version_full, git_tag, released_at, download_url, download_size,
                schema_changes, is_latest, created_at
         FROM sc_releases ORDER BY id DESC LIMIT 20"
    )->fetchAll();
} catch (Exception $e) {}

// Auto-fill version from latest tag if possible
$latest_tag      = $tags[0] ?? '';
$default_version = ltrim(preg_replace('/^v/i', '', $latest_tag), '');

require __DIR__ . '/sc-layout-top.php';
?>

<div class="sc-page-header">
  <span class="sc-page-title">Release Packager</span>
  <span class="sc-dim">github → zip → sha256 → sign → publish</span>
</div>

<?php foreach ($preflight as [$level, $msg]): ?>
<div class="sc-alert sc-alert--<?php echo $level === 'err' ? 'error' : 'warn'; ?>">
  <?php echo htmlspecialchars($msg); ?>
</div>
<?php endforeach; ?>

<?php if ($build_result): ?>
<!-- ── Build success result ─────────────────────────────────────────────── -->
<div class="sc-alert sc-alert--success">
  Release <?php echo htmlspecialchars($build_result['version']); ?> packaged and published successfully.
</div>
<div class="sc-release-result">
  <div class="sc-release-result__row">
    <span class="sc-release-result__key">Version</span>
    <span class="sc-release-result__val"><?php echo htmlspecialchars($build_result['version']); ?></span>
  </div>
  <div class="sc-release-result__row">
    <span class="sc-release-result__key">Tag</span>
    <span class="sc-release-result__val"><?php echo htmlspecialchars($build_result['tag']); ?></span>
  </div>
  <div class="sc-release-result__row">
    <span class="sc-release-result__key">Download</span>
    <span class="sc-release-result__val">
      <a href="<?php echo htmlspecialchars($build_result['download_url']); ?>" target="_blank">
        <?php echo htmlspecialchars($build_result['download_url']); ?>
      </a>
      &nbsp;(<?php echo number_format($build_result['file_size'] / 1024, 1); ?> KB)
    </span>
  </div>
  <div class="sc-release-result__row">
    <span class="sc-release-result__key">SHA-256</span>
    <span class="sc-release-result__val"><?php echo htmlspecialchars($build_result['checksum']); ?></span>
  </div>
  <div class="sc-release-result__row">
    <span class="sc-release-result__key">Signature</span>
    <span class="sc-release-result__val"><?php echo htmlspecialchars($build_result['signature']); ?></span>
  </div>
  <?php if (!empty($build_result['log'])): ?>
  <details style="margin-top:12px;">
    <summary style="cursor:pointer; font-size:var(--sc-size-label); text-transform:uppercase; letter-spacing:.8px; color:var(--sc-text-dim);">Build Log</summary>
    <div class="sc-build-log"><?php echo htmlspecialchars(implode("\n", $build_result['log'])); ?></div>
  </details>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="sc-grid-2">

  <!-- ── Left: Build form ──────────────────────────────────────────────────── -->
  <div>
    <div class="sc-box">
      <div class="sc-box-header">
        <span class="sc-box-title">Build New Release</span>
        <span class="sc-dim" style="font-size:var(--sc-size-label);">
          <?php echo htmlspecialchars(SNAPSMACK_GITHUB_REPO); ?>
        </span>
      </div>
      <div class="sc-box-body">

        <?php if ($build_error): ?>
        <div class="sc-alert sc-alert--error"><?php echo htmlspecialchars($build_error); ?></div>
        <?php if (!empty($build_log)): ?>
        <div class="sc-build-log"><?php echo htmlspecialchars(implode("\n", $build_log)); ?></div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (!$preflight_ok): ?>
        <p class="sc-dim">Fix the errors above before building a release.</p>

        <?php elseif (empty($tags)): ?>
        <div class="sc-alert sc-alert--warn">
          Could not fetch tags from GitHub. Check that this server has outbound HTTPS access
          and that <code><?php echo htmlspecialchars(SNAPSMACK_GITHUB_REPO); ?></code> is public.
          <?php if (!SNAPSMACK_GITHUB_TOKEN): ?>
          If you're hitting the rate limit (60 req/hr), add
          <code>define('SNAPSMACK_GITHUB_TOKEN', 'your-token');</code> to sc-config.php.
          <?php endif; ?>
        </div>

        <?php else: ?>
        <form method="post" action="sc-release.php">
          <input type="hidden" name="action" value="build">

          <div class="sc-field">
            <label>Git Tag</label>
            <select name="tag" class="sc-select" id="tag-select">
              <?php foreach ($tags as $t): ?>
              <option value="<?php echo htmlspecialchars($t); ?>"
                      data-version="<?php echo htmlspecialchars(ltrim(preg_replace('/^v/i', '', $t), '')); ?>">
                <?php echo htmlspecialchars($t); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="sc-grid-2" style="gap:12px;">
            <div class="sc-field">
              <label>Version</label>
              <input type="text" name="version" id="version-input"
                     placeholder="0.8" value="<?php echo htmlspecialchars($default_version); ?>">
              <span class="sc-hint">Short string used in filename and update check.</span>
            </div>
            <div class="sc-field">
              <label>Version Full</label>
              <input type="text" name="version_full" id="version-full-input"
                     placeholder="Alpha 0.8" value="Alpha <?php echo htmlspecialchars($default_version); ?>">
            </div>
          </div>

          <div class="sc-grid-2" style="gap:12px;">
            <div class="sc-field">
              <label>Release Date</label>
              <input type="date" name="released" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div style="display:flex; gap:12px;">
              <div class="sc-field" style="flex:1;">
                <label>Min PHP</label>
                <input type="text" name="requires_php" value="8.0" style="width:100%;">
              </div>
              <div class="sc-field" style="flex:1;">
                <label>Min MySQL</label>
                <input type="text" name="requires_mysql" value="5.7" style="width:100%;">
              </div>
            </div>
          </div>

          <div class="sc-checkbox-row">
            <input type="checkbox" name="schema_changes" id="schema_changes" value="1">
            <label for="schema_changes">Schema changes in this release</label>
          </div>
          <p class="sc-hint" style="margin-top:-8px; margin-bottom:12px;">
            Auto-detected if any <code>migrations/</code> file is in the diff — override here if needed.
          </p>

          <div class="sc-field">
            <label>Changelog</label>
            <textarea name="changelog" rows="8"
                      placeholder="One changelog entry per line. Plain text — will be presented to admins in the update notification."
                      style="font-family:var(--sc-font-mono); font-size:0.78rem;"></textarea>
            <span class="sc-hint">One item per line. No bullets or markdown — just the text.</span>
          </div>

          <div class="sc-btn-row" style="justify-content:flex-end; margin-top:8px;">
            <button type="submit" class="sc-btn sc-btn--primary"
                    onclick="return confirm('Package and publish release from tag: ' + document.getElementById('tag-select').value + '?');">
              BUILD &amp; PUBLISH
            </button>
          </div>
        </form>
        <?php endif; ?>

      </div>
    </div><!-- /.sc-box -->
  </div>

  <!-- ── Right: Release history ────────────────────────────────────────────── -->
  <div>
    <div class="sc-box">
      <div class="sc-box-header"><span class="sc-box-title">Release History</span></div>
      <div class="sc-box-body">
        <?php if (empty($releases)): ?>
        <p class="sc-dim">No releases shipped yet.</p>
        <?php else: ?>
        <table class="sc-table">
          <thead>
            <tr>
              <th>Version</th>
              <th>Tag</th>
              <th>Date</th>
              <th>Size</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($releases as $rel): ?>
          <tr>
            <td>
              <?php if ($rel['is_latest']): ?>
                <span class="sc-dot sc-dot--live" title="Current latest"></span>
              <?php endif; ?>
              <a href="<?php echo htmlspecialchars($rel['download_url']); ?>" target="_blank">
                <?php echo htmlspecialchars($rel['version_full']); ?>
              </a>
              <?php if ($rel['schema_changes']): ?>
                <span style="font-size:0.65rem; color:var(--sc-warn); margin-left:4px; text-transform:uppercase; letter-spacing:.5px;">DB</span>
              <?php endif; ?>
            </td>
            <td class="sc-dim sc-mono" style="font-size:0.72rem;"><?php echo htmlspecialchars($rel['git_tag']); ?></td>
            <td class="sc-dim"><?php echo htmlspecialchars($rel['released_at']); ?></td>
            <td class="sc-dim" style="white-space:nowrap;">
              <?php echo $rel['download_size'] ? number_format($rel['download_size'] / 1024, 0) . ' KB' : '—'; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <?php
    // Show current latest.json if it exists
    $json_path = rtrim(RELEASES_DIR, '/') . '/latest.json';
    if (file_exists($json_path)):
        $json_preview = file_get_contents($json_path);
    ?>
    <div class="sc-box">
      <div class="sc-box-header">
        <span class="sc-box-title">Current latest.json</span>
        <a href="<?php echo rtrim(RELEASES_URL, '/'); ?>/latest.json" target="_blank" class="sc-btn sc-btn--sm">View Live</a>
      </div>
      <div class="sc-box-body">
        <div class="sc-code-block" style="max-height:280px; overflow-y:auto;"><?php echo htmlspecialchars($json_preview); ?></div>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /.sc-grid-2 -->

<script>
// Auto-fill version fields when tag selection changes.
(function () {
    var sel  = document.getElementById('tag-select');
    var vinp = document.getElementById('version-input');
    var vfull = document.getElementById('version-full-input');
    if (!sel) return;
    sel.addEventListener('change', function () {
        var opt = sel.options[sel.selectedIndex];
        var v   = opt.dataset.version || '';
        if (vinp  && !vinp.dataset.userEdited)  vinp.value  = v;
        if (vfull && !vfull.dataset.userEdited)  vfull.value = 'Alpha ' + v;
    });
    if (vinp)  vinp.addEventListener('input',  function () { vinp.dataset.userEdited  = '1'; });
    if (vfull) vfull.addEventListener('input', function () { vfull.dataset.userEdited = '1'; });
}());
</script>

<?php require __DIR__ . '/sc-layout-bottom.php'; ?>
