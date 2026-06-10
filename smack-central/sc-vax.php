<?php
/**
 * SMACK CENTRAL — VAX Payload Generator
 *
 * Creates signed SQL payloads for delivery to SnapSmack spokes via
 * core/smack-vax.php. Use when a spoke is under SMACKBACK lockdown
 * and FTP of PHP files is not possible.
 *
 * Flow:
 *   1. Write the SQL you want to inject
 *   2. Set a pkg code (auto-generated or manual) and optional expiry
 *   3. Click CREATE — the .vax file and .vax.sig are written to releases/vax/
 *   4. Copy the one-time token shown on screen
 *   5. POST to the spoke: core/smack-vax.php?pkg=CODE with token in body
 *   6. Return here and delete the payload after confirmed injection
 */

require_once __DIR__ . '/sc-auth.php';
$sc_active_nav = 'sc-vax.php';
$sc_page_title = 'VAX Generator';

@set_time_limit(60);

// ── Directories ───────────────────────────────────────────────────────────────

define('VAX_DIR', rtrim(RELEASES_DIR, '/') . '/vax/');
define('VAX_URL', rtrim(defined('RELEASES_URL') ? RELEASES_URL : '', '/') . '/vax/');

// ── Preflight ─────────────────────────────────────────────────────────────────

$preflight = [];

if (!function_exists('sodium_crypto_sign_detached')) {
    $preflight[] = ['err', 'sodium extension not available.'];
}
if (!defined('SMACK_RELEASE_PRIVKEY') || strlen(SMACK_RELEASE_PRIVKEY) !== 128) {
    $preflight[] = ['err', 'SMACK_RELEASE_PRIVKEY not set or wrong length in sc-config.php.'];
}
if (!defined('RELEASES_DIR') || !is_dir(RELEASES_DIR)) {
    $preflight[] = ['err', 'RELEASES_DIR not configured or does not exist.'];
} else {
    if (!is_dir(VAX_DIR)) {
        if (!@mkdir(VAX_DIR, 0755, true)) {
            $preflight[] = ['err', 'Could not create ' . VAX_DIR . ' — check permissions.'];
        } else {
            $preflight[] = ['ok', 'Created releases/vax/ directory.'];
        }
    }
    if (is_dir(VAX_DIR) && !is_writable(VAX_DIR)) {
        $preflight[] = ['err', VAX_DIR . ' is not writable.'];
    }
}

$preflight_ok = empty(array_filter($preflight, fn($p) => $p[0] === 'err'));

// ── Helpers ───────────────────────────────────────────────────────────────────

function vax_gen_pkg(): string {
    return 'vax-' . date('Ymd') . '-' . bin2hex(random_bytes(2));
}

function vax_gen_token(int $bytes = 32): string {
    return bin2hex(random_bytes($bytes));
}

function vax_build_payload(string $pkg, string $token, int $expires, string $sql): string {
    $expires_str = $expires > 0 ? (string)$expires : '0';
    return "VAX-PKG: {$pkg}\n"
         . "VAX-TOKEN: {$token}\n"
         . "VAX-EXPIRES: {$expires_str}\n"
         . "----\n"
         . rtrim($sql) . "\n";
}

function vax_sign(string $payload): string|false {
    try {
        $privkey = sodium_hex2bin(SMACK_RELEASE_PRIVKEY);
        $sig     = sodium_crypto_sign_detached($payload, $privkey);
        return sodium_bin2hex($sig);
    } catch (SodiumException $e) {
        return false;
    }
}

function vax_list_payloads(): array {
    if (!is_dir(VAX_DIR)) return [];
    $out = [];
    foreach (glob(VAX_DIR . '*.vax') as $path) {
        $pkg     = basename($path, '.vax');
        $sig     = VAX_DIR . $pkg . '.vax.sig';
        $content = @file_get_contents($path);
        $token   = '';
        $expires = 0;
        if ($content !== false) {
            foreach (explode("\n", $content) as $line) {
                if (str_starts_with($line, 'VAX-TOKEN: '))   $token   = trim(substr($line, 11));
                if (str_starts_with($line, 'VAX-EXPIRES: ')) $expires = (int)trim(substr($line, 13));
                if (rtrim($line) === '----') break;
            }
        }
        $out[] = [
            'pkg'     => $pkg,
            'path'    => $path,
            'has_sig' => file_exists($sig),
            'size'    => file_exists($path) ? filesize($path) : 0,
            'mtime'   => filemtime($path),
            'token'   => $token,
            'expires' => $expires,
        ];
    }
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $out;
}

// ── Actions ───────────────────────────────────────────────────────────────────

$action_result = null; // ['type' => ok|err, 'msg' => '', 'token' => '', 'pkg' => '', 'url' => '']

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $preflight_ok) {

    $action = $_POST['action'] ?? '';

    // ── CREATE payload ────────────────────────────────────────────────────────
    if ($action === 'create') {

        $pkg = trim($_POST['pkg'] ?? '');
        if ($pkg === '') $pkg = vax_gen_pkg();

        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $pkg)) {
            $action_result = ['type' => 'err', 'msg' => 'Invalid pkg code. Use letters, numbers, hyphens, underscores, max 64 chars.'];
        } elseif (file_exists(VAX_DIR . $pkg . '.vax')) {
            $action_result = ['type' => 'err', 'msg' => "Payload {$pkg} already exists. Choose a different pkg code or delete the existing one first."];
        } else {
            $sql = trim($_POST['sql'] ?? '');
            if ($sql === '') {
                $action_result = ['type' => 'err', 'msg' => 'SQL cannot be empty.'];
            } else {
                $expiry_input = trim($_POST['expiry'] ?? '');
                $expires      = 0;
                if ($expiry_input !== '') {
                    $ts = strtotime($expiry_input);
                    if ($ts === false || $ts <= time()) {
                        $action_result = ['type' => 'err', 'msg' => 'Expiry date is invalid or in the past.'];
                    } else {
                        $expires = $ts;
                    }
                }

                if (!isset($action_result)) {
                    $token   = vax_gen_token(32); // 64-char hex
                    $payload = vax_build_payload($pkg, $token, $expires, $sql);
                    $sig     = vax_sign($payload);

                    if ($sig === false) {
                        $action_result = ['type' => 'err', 'msg' => 'Signing failed. Check SMACK_RELEASE_PRIVKEY.'];
                    } else {
                        $vax_path = VAX_DIR . $pkg . '.vax';
                        $sig_path = VAX_DIR . $pkg . '.vax.sig';

                        if (file_put_contents($vax_path, $payload) === false
                            || file_put_contents($sig_path, $sig) === false) {
                            $action_result = ['type' => 'err', 'msg' => 'Failed to write payload files. Check permissions on ' . VAX_DIR];
                        } else {
                            $action_result = [
                                'type'  => 'ok',
                                'msg'   => "Payload {$pkg} created and signed.",
                                'pkg'   => $pkg,
                                'token' => $token,
                                'url'   => VAX_URL . rawurlencode($pkg) . '.vax',
                            ];
                        }
                    }
                }
            }
        }
    }

    // ── DELETE payload ────────────────────────────────────────────────────────
    if ($action === 'delete') {
        $pkg = trim($_POST['pkg'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $pkg)) {
            $action_result = ['type' => 'err', 'msg' => 'Invalid pkg code.'];
        } else {
            $vax_path = VAX_DIR . $pkg . '.vax';
            $sig_path = VAX_DIR . $pkg . '.vax.sig';
            $deleted  = [];
            $failed   = [];
            foreach ([$vax_path, $sig_path] as $f) {
                if (file_exists($f)) {
                    if (@unlink($f)) $deleted[] = basename($f);
                    else             $failed[]  = basename($f);
                }
            }
            if (!empty($failed)) {
                $action_result = ['type' => 'err', 'msg' => 'Could not delete: ' . implode(', ', $failed)];
            } else {
                $action_result = ['type' => 'ok', 'msg' => 'Deleted: ' . implode(', ', $deleted)];
            }
        }
    }
}

// ── Active payloads (after any mutations) ─────────────────────────────────────

$active_payloads = $preflight_ok ? vax_list_payloads() : [];

// ── Output ────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/sc-layout-top.php';
?>

<main class="sc-main">
<div class="sc-page-header">
  <h1 class="sc-page-title">VAX Generator</h1>
  <p class="sc-dim">Create signed SQL payloads for emergency DB injection via <code>core/smack-vax.php</code>.</p>
</div>

<?php // ── Preflight ─────────────────────────────────────────────────────── ?>
<?php if (!empty($preflight)): ?>
<div class="sc-box" style="margin-bottom:20px;">
  <div class="sc-box-header"><span class="sc-box-title">Preflight</span></div>
  <div class="sc-box-body">
    <?php foreach ($preflight as [$level, $msg]): ?>
      <div class="sc-alert sc-alert--<?php echo $level === 'err' ? 'error' : ($level === 'warn' ? 'warn' : 'success'); ?>"
           style="margin-bottom:8px;">
        <?php echo htmlspecialchars($msg); ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php // ── Action result ─────────────────────────────────────────────────── ?>
<?php if ($action_result): ?>
  <?php if ($action_result['type'] === 'ok' && !empty($action_result['token'])): ?>
    <div class="sc-box" style="margin-bottom:20px; border-color: var(--sc-success);">
      <div class="sc-box-header" style="border-color: var(--sc-success);">
        <span class="sc-box-title" style="color: var(--sc-success);">✓ PAYLOAD CREATED — COPY TOKEN NOW</span>
      </div>
      <div class="sc-box-body">
        <p class="sc-dim" style="margin-bottom:12px;">
          This token will not be shown again. Copy it and communicate it to the site admin out-of-band.
        </p>
        <div style="margin-bottom:16px;">
          <div class="sc-label">pkg</div>
          <code class="sc-mono" style="display:block; padding:8px; background:var(--sc-bg-raised); border-radius:4px; margin-top:4px; user-select:all;">
            <?php echo htmlspecialchars($action_result['pkg']); ?>
          </code>
        </div>
        <div style="margin-bottom:16px;">
          <div class="sc-label">token <span class="sc-dim">(POST body — never in URL)</span></div>
          <code class="sc-mono" style="display:block; padding:8px; background:var(--sc-bg-raised); border-radius:4px; margin-top:4px; font-size:1rem; letter-spacing:.05em; user-select:all; word-break:break-all;">
            <?php echo htmlspecialchars($action_result['token']); ?>
          </code>
        </div>
        <div style="margin-bottom:16px;">
          <div class="sc-label">curl command</div>
          <code class="sc-mono" style="display:block; padding:8px; background:var(--sc-bg-raised); border-radius:4px; margin-top:4px; font-size:.75rem; word-break:break-all; user-select:all;">
            curl -X POST "https://SITE/core/smack-vax.php?pkg=<?php echo rawurlencode($action_result['pkg']); ?>" \<br>
            &nbsp;&nbsp;--data-urlencode "token=<?php echo htmlspecialchars($action_result['token']); ?>"
          </code>
        </div>
        <div>
          <div class="sc-label">payload URL (for reference)</div>
          <code class="sc-mono" style="display:block; padding:8px; background:var(--sc-bg-raised); border-radius:4px; margin-top:4px; font-size:.8rem; user-select:all;">
            <?php echo htmlspecialchars($action_result['url']); ?>
          </code>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="sc-alert sc-alert--<?php echo $action_result['type'] === 'ok' ? 'success' : 'error'; ?>"
         style="margin-bottom:20px;">
      <?php echo htmlspecialchars($action_result['msg']); ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($preflight_ok): ?>

<?php // ── Create form ───────────────────────────────────────────────────── ?>
<div class="sc-box" style="margin-bottom:20px;">
  <div class="sc-box-header"><span class="sc-box-title">New Payload</span></div>
  <div class="sc-box-body">
    <form method="POST" action="sc-vax.php">
      <input type="hidden" name="action" value="create">

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
        <div>
          <label class="sc-label" for="vax-pkg">pkg code</label>
          <input type="text" id="vax-pkg" name="pkg" class="sc-input"
                 placeholder="auto-generated if blank"
                 pattern="[a-zA-Z0-9_-]{1,64}"
                 style="margin-top:4px;"
                 value="<?php echo isset($_POST['pkg']) ? htmlspecialchars($_POST['pkg']) : ''; ?>">
          <div class="sc-dim" style="margin-top:4px;">Letters, numbers, hyphens, underscores. Max 64 chars.</div>
        </div>
        <div>
          <label class="sc-label" for="vax-expiry">expiry <span class="sc-dim">(optional)</span></label>
          <input type="datetime-local" id="vax-expiry" name="expiry" class="sc-input"
                 style="margin-top:4px;"
                 value="<?php echo isset($_POST['expiry']) ? htmlspecialchars($_POST['expiry']) : ''; ?>">
          <div class="sc-dim" style="margin-top:4px;">Leave blank for no expiry.</div>
        </div>
      </div>

      <div style="margin-bottom:20px;">
        <label class="sc-label" for="vax-sql">SQL payload</label>
        <textarea id="vax-sql" name="sql" class="sc-textarea"
                  rows="12"
                  placeholder="-- Use defensive patterns: IF NOT EXISTS, MODIFY only safe columns&#10;ALTER TABLE snap_posts ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0;"
                  style="margin-top:4px; font-family:monospace; font-size:.85rem;"
                  spellcheck="false"><?php echo isset($_POST['sql']) ? htmlspecialchars($_POST['sql']) : ''; ?></textarea>
        <div class="sc-dim" style="margin-top:4px;">
          Pure SQL only. No PHP. Use <code>IF NOT EXISTS</code> and safe <code>MODIFY</code> so payloads are re-runnable.
          Entire payload executes as a single <code>$pdo->exec()</code> call.
        </div>
      </div>

      <div class="sc-btn-row">
        <button type="submit" class="sc-btn sc-btn--primary">CREATE &amp; SIGN PAYLOAD</button>
      </div>
    </form>
  </div>
</div>

<?php // ── Active payloads ───────────────────────────────────────────────── ?>
<div class="sc-box">
  <div class="sc-box-header">
    <span class="sc-box-title">Active Payloads</span>
    <span class="sc-dim" style="font-size:.75rem;"><?php echo count($active_payloads); ?> in releases/vax/</span>
  </div>
  <?php if (empty($active_payloads)): ?>
    <div class="sc-box-body">
      <p class="sc-dim">No active payloads.</p>
    </div>
  <?php else: ?>
    <div class="sc-box-body sc-box-body--flush">
      <table class="sc-table" style="width:100%;">
        <thead>
          <tr>
            <th>pkg</th>
            <th>token</th>
            <th>expires</th>
            <th>sig</th>
            <th>created</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($active_payloads as $p): ?>
            <tr>
              <td><code class="sc-mono" style="font-size:.8rem;"><?php echo htmlspecialchars($p['pkg']); ?></code></td>
              <td>
                <?php if ($p['token']): ?>
                  <span class="vax-token-preview sc-mono" style="font-size:.75rem; cursor:pointer;"
                        data-full="<?php echo htmlspecialchars($p['token']); ?>"
                        title="Click to reveal/hide full token">
                    <?php echo htmlspecialchars(substr($p['token'], 0, 16)); ?>…
                  </span>
                <?php else: ?>
                  <span class="sc-dim">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($p['expires'] > 0): ?>
                  <?php $exp_diff = $p['expires'] - time(); ?>
                  <span class="<?php echo $exp_diff < 0 ? 'sc-alert sc-alert--warn' : ''; ?>"
                        style="font-size:.8rem;">
                    <?php echo $exp_diff < 0 ? 'EXPIRED ' : ''; ?>
                    <?php echo date('Y-m-d H:i', $p['expires']); ?>
                  </span>
                <?php else: ?>
                  <span class="sc-dim">none</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($p['has_sig']): ?>
                  <span style="color:var(--sc-success);">✓</span>
                <?php else: ?>
                  <span style="color:var(--sc-danger);">✗ missing</span>
                <?php endif; ?>
              </td>
              <td class="sc-dim" style="font-size:.75rem;"><?php echo date('Y-m-d H:i', $p['mtime']); ?></td>
              <td class="sc-td-right">
                <form method="POST" action="sc-vax.php"
                      onsubmit="return confirm('Delete <?php echo htmlspecialchars(addslashes($p['pkg'])); ?>? This cannot be undone.');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="pkg" value="<?php echo htmlspecialchars($p['pkg']); ?>">
                  <button type="submit" class="sc-btn sc-btn--danger sc-btn--sm">DELETE</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php endif; // preflight_ok ?>

</main>

<script>
document.querySelectorAll('.vax-token-preview').forEach(function(el) {
    var full    = el.dataset.full;
    var preview = el.textContent.trim();
    var showing = false;
    el.addEventListener('click', function() {
        showing = !showing;
        el.textContent = showing ? full : preview;
        el.style.color = showing ? 'var(--sc-warn)' : '';
    });
});
</script>

<?php require_once __DIR__ . '/sc-layout-bottom.php'; ?>
<?php // ===== SNAPSMACK EOF =====
