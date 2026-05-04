<?php
/**
 * SNAPSMACK - Fingerprints & Ban Manager
 *
 * Admin interface for viewing browser fingerprints associated with comments,
 * reviewing ban patterns, and issuing/revoking bans by fingerprint, IP, or email hash.
 * Includes semantic analysis for detecting related accounts and keyword filtering.
 */

require_once 'core/auth.php';
require_once 'core/ban-check.php';
require_once 'core/semantic-analysis.php';
require_once 'core/keyword-check.php';

$page_title = 'Fingerprints & Bans';

// ── AJAX ENDPOINTS ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    $action = $_POST['action'];

    if ($action === 'add_ban') {
        $ban_type  = $_POST['ban_type'] ?? '';
        $ban_value = trim($_POST['ban_value'] ?? '');
        $reason    = trim($_POST['reason'] ?? '');

        if (!in_array($ban_type, ['fingerprint', 'ip', 'email_hash'])) {
            echo json_encode(['ok' => false, 'error' => 'Invalid ban type']);
            exit;
        }

        if (empty($ban_value)) {
            echo json_encode(['ok' => false, 'error' => 'Ban value required']);
            exit;
        }

        // If banning by email, hash it first
        if ($ban_type === 'email_hash') {
            $ban_value = hash('sha256', strtolower($ban_value));
        }

        $success = add_ban($pdo, $ban_type, $ban_value, $reason, $_SESSION['user_id'] ?? null);

        echo json_encode(['ok' => $success, 'error' => $success ? null : 'Ban already exists']);
        exit;
    }

    if ($action === 'remove_ban') {
        $ban_id = (int)($_POST['ban_id'] ?? 0);
        $success = remove_ban($pdo, $ban_id);
        echo json_encode(['ok' => $success]);
        exit;
    }

    if ($action === 'fetch_semantic') {
        $fingerprint = $_POST['fingerprint'] ?? '';
        if (empty($fingerprint)) {
            echo json_encode(['ok' => false, 'error' => 'Fingerprint required']);
            exit;
        }

        $similar = find_similar_fingerprints($pdo, $fingerprint, 0.55);
        echo json_encode(['ok' => true, 'similar' => $similar]);
        exit;
    }

    if ($action === 'fetch_keywords') {
        $keywords = get_all_keywords($pdo);
        echo json_encode(['ok' => true, 'keywords' => $keywords]);
        exit;
    }

    if ($action === 'add_keyword') {
        $keyword = trim($_POST['keyword'] ?? '');
        $match_type = $_POST['match_type'] ?? 'substring';
        $severity = $_POST['severity'] ?? 'flag';

        if (empty($keyword)) {
            echo json_encode(['ok' => false, 'error' => 'Keyword required']);
            exit;
        }

        $success = add_keyword($pdo, $keyword, $match_type, $severity, $_POST['reason'] ?? '', $_SESSION['user_id'] ?? null);
        echo json_encode(['ok' => $success, 'error' => $success ? null : 'Keyword already exists']);
        exit;
    }

    if ($action === 'remove_keyword') {
        $keyword = $_POST['keyword'] ?? '';
        $success = remove_keyword($pdo, $keyword);
        echo json_encode(['ok' => $success]);
        exit;
    }

    if ($action === 'lift_ip_ban') {
        $ip = trim($_POST['ip'] ?? '');
        if ($ip === '') { echo json_encode(['ok' => false, 'error' => 'No IP provided']); exit; }
        try {
            $pdo->prepare("DELETE FROM snap_ip_bans WHERE ip = ?")->execute([$ip]);
            echo json_encode(['ok' => true]);
        } catch (PDOException $e) {
            echo json_encode(['ok' => false, 'error' => 'DB error']);
        }
        exit;
    }

    if ($action === 'fetch_ip_bans') {
        try {
            $rows = $pdo->query(
                "SELECT ip, reason, banned_at, expires_at FROM snap_ip_bans
                 ORDER BY banned_at DESC LIMIT 200"
            )->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'bans' => $rows]);
        } catch (PDOException $e) {
            echo json_encode(['ok' => true, 'bans' => []]);
        }
        exit;
    }

    if ($action === 'fetch_shared_bans') {
        // Hub-only: fetch from snap_hub_shared_bans (table may not exist on spokes)
        $page     = max(1, (int)($_POST['page'] ?? 1));
        $per_page = 50;
        $offset   = ($page - 1) * $per_page;

        try {
            $total = (int)$pdo->query("SELECT COUNT(*) FROM snap_hub_shared_bans WHERE removed = 0")->fetchColumn();
            $rows  = $pdo->query("
                SELECT id, ban_type, ban_value, reason, reported_by,
                       first_seen, last_seen, report_count, removed
                FROM snap_hub_shared_bans
                WHERE removed = 0
                ORDER BY last_seen DESC
                LIMIT {$per_page} OFFSET {$offset}
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            echo json_encode(['ok' => false, 'error' => 'Table not available']);
            exit;
        }

        echo json_encode([
            'ok'       => true,
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => ceil($total / $per_page),
        ]);
        exit;
    }

    if ($action === 'remove_shared_ban') {
        // Hub-only: soft-delete (sets removed = 1, preserves audit trail)
        $ban_id = (int)($_POST['ban_id'] ?? 0);
        if (!$ban_id) {
            echo json_encode(['ok' => false, 'error' => 'Invalid ID']);
            exit;
        }
        try {
            $pdo->prepare("UPDATE snap_hub_shared_bans SET removed = 1 WHERE id = ?")->execute([$ban_id]);
            echo json_encode(['ok' => true]);
        } catch (PDOException $e) {
            echo json_encode(['ok' => false, 'error' => 'Update failed']);
        }
        exit;
    }

    if ($action === 'fetch_bans') {
        $page     = max(1, (int)($_POST['page'] ?? 1));
        $per_page = 50;
        $offset   = ($page - 1) * $per_page;
        // snap_ban_list contains only active bans — every row is a ban, no is_banned column
        $total = (int)$pdo->query("SELECT COUNT(*) FROM snap_ban_list")->fetchColumn();

        $stmt = $pdo->prepare("SELECT * FROM snap_ban_list ORDER BY banned_at DESC LIMIT $per_page OFFSET $offset");
        $stmt->execute();
        $bans = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'bans'     => $bans,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => ceil($total / $per_page)
        ]);
        exit;
    }

    if ($action === 'fetch_fingerprints') {
        $page     = max(1, (int)($_POST['page'] ?? 1));
        $per_page = 50;
        $offset   = ($page - 1) * $per_page;
        $search   = trim($_POST['search'] ?? '');

        $where = "1=1";
        $params = [];

        if ($search !== '') {
            // Search by fingerprint hash prefix or IP from comment
            $where = "(bl.ban_value LIKE ? OR EXISTS (SELECT 1 FROM snap_comments WHERE fp_hash LIKE ?))";
            $params[] = $search . '%';
            $params[] = $search . '%';
        }

        $total_sql = "SELECT COUNT(DISTINCT bl.ban_value) FROM snap_ban_list bl WHERE $where";
        $stmt = $pdo->prepare($total_sql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        // Fetch bans with comment counts
        $sql = "
            SELECT bl.*,
                   (SELECT COUNT(*) FROM snap_comments WHERE fp_hash = bl.ban_value AND bl.ban_type = 'fingerprint') as fp_comment_count,
                   (SELECT COUNT(*) FROM snap_comments WHERE comment_ip = bl.ban_value AND bl.ban_type = 'ip') as ip_comment_count
            FROM snap_ban_list bl
            WHERE $where
            ORDER BY bl.banned_at DESC
            LIMIT $per_page OFFSET $offset
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $bans = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'bans'     => $bans,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => ceil($total / $per_page)
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

// ── PAGE RENDER ──────────────────────────────────────────────────────────────
include 'core/admin-header.php';
include 'core/sidebar.php';
$total_bans  = (int)$pdo->query("SELECT COUNT(*) FROM snap_ban_list")->fetchColumn();
$active_bans = $total_bans; // All rows in snap_ban_list are active; deleted bans are removed entirely

$settings       = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$is_hub         = ($settings['multisite_role'] ?? '') === 'hub';
$ban_sync_on    = ($settings['hub_spoke_ban_sync'] ?? '0') === '1';
$shared_ban_count = 0;
if ($is_hub) {
    try {
        $shared_ban_count = (int)$pdo->query("SELECT COUNT(*) FROM snap_hub_shared_bans WHERE removed = 0")->fetchColumn();
    } catch (PDOException $e) {
        // Table may not exist on older installs
    }
}
?>

<div class="main">
    <div class="header-row header-row--ruled">
        <h2>FINGERPRINTS & BAN MANAGER</h2>
        <span class="dim"><?php echo $active_bans; ?> active bans (<?php echo $total_bans; ?> total)</span>
    </div>

    <!-- ── TAB SELECTOR ──────────────────────────────────────────────────────── -->
    <div class="tab-selector" id="tab-selector">
        <button class="tab-btn btn-smack tab-active" data-tab="banned">BANNED</button>
        <button class="tab-btn btn-smack btn-settings" data-tab="fingerprints">FINGERPRINTS</button>
        <button class="tab-btn btn-smack btn-settings" data-tab="semantic">SEMANTIC</button>
        <button class="tab-btn btn-smack btn-settings" data-tab="keywords">KEYWORDS</button>
        <button class="tab-btn btn-smack btn-settings" data-tab="add">ADD BAN</button>
        <button class="tab-btn btn-smack btn-settings" data-tab="ip-smacker">IP SMACKER</button>
        <?php if ($is_hub): ?>
        <button class="tab-btn btn-smack btn-settings" data-tab="shared-bans">SHARED BANS <?php if ($shared_ban_count > 0): ?><span style="margin-left:4px; font-size:0.75em; opacity:0.6;">(<?php echo $shared_ban_count; ?>)</span><?php endif; ?></button>
        <?php endif; ?>
    </div>

    <!-- ── TAB 1: BANNED ──────────────────────────────────────────────────────── -->
    <div class="tab-content tab-content--active" id="tab-banned">
        <div class="box">
            <h3>Active Bans</h3>
            <div id="banned-list"></div>
            <div id="banned-paginator" style="margin-top:20px; text-align:center;"></div>
        </div>
    </div>

    <!-- ── TAB 2: FINGERPRINTS ───────────────────────────────────────────────── -->
    <div class="tab-content" id="tab-fingerprints">
        <div class="box">
            <h3>Search Fingerprints</h3>
            <input type="text" id="fp-search" class="full-width-input" placeholder="Search by fingerprint hash or IP…" style="margin-bottom:12px;">
            <div id="fingerprint-list"></div>
            <div id="fp-paginator" style="margin-top:20px; text-align:center;"></div>
        </div>
    </div>

    <!-- ── TAB 3: SEMANTIC ANALYSIS ──────────────────────────────────────────── -->
    <div class="tab-content" id="tab-semantic">
        <div class="box">
            <h3>Semantic Similarity Analysis</h3>
            <p class="dim" style="margin-bottom:16px;">Find fingerprints with similar writing styles to detect related accounts.</p>
            <div class="meta-grid">
                <div class="lens-input-wrapper">
                    <label>FINGERPRINT TO ANALYZE</label>
                    <input type="text" id="semantic-fp" class="full-width-input" placeholder="Enter fingerprint hash">
                </div>
            </div>
            <button type="button" class="btn-smack" onclick="loadSemantic()" style="margin-top:12px;">Analyze</button>
            <div id="semantic-results" style="margin-top:20px;"></div>
        </div>
    </div>

    <!-- ── TAB 4: KEYWORDS ───────────────────────────────────────────────────── -->
    <div class="tab-content" id="tab-keywords">
        <div class="box">
            <h3>Banned Keywords & Phrases</h3>
            <p class="dim" style="margin-bottom:16px;">Auto-detect and flag/reject comments containing specific words or patterns.</p>
            <div class="meta-grid">
                <div class="lens-input-wrapper">
                    <label>KEYWORD / PHRASE</label>
                    <input type="text" id="keyword-value" class="full-width-input" placeholder="e.g., hate-speech or .*badword.*">
                </div>
                <div class="lens-input-wrapper">
                    <label>MATCH TYPE</label>
                    <select id="keyword-match" class="full-width-select">
                        <option value="substring">Substring (default)</option>
                        <option value="exact">Exact Word</option>
                        <option value="regex">Regex Pattern</option>
                    </select>
                </div>
                <div class="lens-input-wrapper">
                    <label>SEVERITY</label>
                    <select id="keyword-severity" class="full-width-select">
                        <option value="flag">Flag for Review</option>
                        <option value="reject">Silent Reject</option>
                    </select>
                </div>
                <div class="lens-input-wrapper">
                    <label>REASON (optional)</label>
                    <input type="text" id="keyword-reason" class="full-width-input" placeholder="Why this keyword">
                </div>
            </div>
            <button type="button" class="btn-smack" onclick="addKeyword()" style="margin-top:12px;">Add Keyword</button>
            <div id="keyword-list" style="margin-top:24px;"></div>
        </div>
    </div>

    <!-- ── TAB 5: ADD BAN ─────────────────────────────────────────────────────── -->
    <div class="tab-content" id="tab-add">
        <div class="box">
            <h3>Issue New Ban</h3>
            <div class="meta-grid">
                <div class="lens-input-wrapper">
                    <label>BAN TYPE</label>
                    <select id="ban-type" class="full-width-select">
                        <option value="fingerprint">Browser Fingerprint</option>
                        <option value="ip">IP Address</option>
                        <option value="email_hash">Email Address</option>
                    </select>
                </div>
                <div class="lens-input-wrapper">
                    <label>VALUE TO BAN</label>
                    <input type="text" id="ban-value" class="full-width-input" placeholder="Paste fingerprint, IP, or email">
                </div>
                <div class="lens-input-wrapper">
                    <label>REASON</label>
                    <input type="text" id="ban-reason" class="full-width-input" placeholder="e.g., Spam, Abuse, Sockpuppet">
                </div>
            </div>
            <button type="button" class="btn-smack" onclick="issueBan()" style="margin-top:12px;">Issue Ban</button>
        </div>
    </div>

    <!-- ── TAB: IP SMACKER — auto-ban list from brute-force detection ──────────── -->
    <div class="tab-content" id="tab-ip-smacker">
        <div class="box">
            <h3>IP SMACKER — Auto-Banned IPs</h3>
            <p class="dim" style="margin-bottom:16px;">
                IPs banned automatically by the login brute-force detector (5 failures in 10 minutes → 7-day ban).
                UA-filtered requests are silently dropped and not listed here.
                Lift a ban early if you blocked a legitimate user.
            </p>
            <div id="ip-smacker-list"><em style="opacity:0.5;">Loading…</em></div>
        </div>
    </div>

    <?php if ($is_hub): ?>
    <!-- ── TAB 6: SHARED BANS (hub only) ────────────────────────────────────── -->
    <div class="tab-content" id="tab-shared-bans">
        <div class="box">
            <h3>Hub Shared Ban Registry</h3>
            <?php if (!$ban_sync_on): ?>
            <div class="alert alert-info" style="margin-bottom:16px;">
                Ban sync is currently disabled. Enable it in <a href="smack-community-settings.php">Community Settings → Shield</a>.
            </div>
            <?php endif; ?>
            <p class="dim" style="margin-bottom:16px;">
                Consolidated ban hashes collected from all connected spokes. Only SHA-256 hashes are stored — no raw IPs or emails.
                Report count shows how many distinct spokes have reported the same identifier.
                Clearing a ban removes it from distribution but preserves the audit row (shown in grey).
            </p>
            <div id="shared-ban-list"></div>
            <div id="shared-ban-paginator" style="margin-top:20px; text-align:center;"></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
// ── TAB SWITCHING ───────────────────────────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const tab = this.getAttribute('data-tab');
        document.querySelectorAll('.tab-btn').forEach(b => {
            b.classList.remove('tab-active');
            b.classList.add('btn-settings');
        });
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('tab-content--active'));
        this.classList.add('tab-active');
        this.classList.remove('btn-settings');
        document.getElementById('tab-' + tab).classList.add('tab-content--active');

        if (tab === 'banned') loadBans(1);
        if (tab === 'fingerprints') loadFingerprints(1);
        if (tab === 'semantic') document.getElementById('semantic-fp').focus();
        if (tab === 'keywords') loadKeywords();
        if (tab === 'shared-bans') loadSharedBans(1);
        if (tab === 'ip-smacker') loadIpSmacker();
    });
});

// ── FETCH AND RENDER BANS ───────────────────────────────────────────────────
let currentBanPage = 1;
function loadBans(page) {
    currentBanPage = page;
    const fd = new FormData();
    fd.append('action', 'fetch_bans');
    fd.append('page', page);
    fd.append('banned_only', '1');

    fetch('smack-fingerprints.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            let html = '<table class="admin-table" style="width:100%;"><tbody>';
            data.bans.forEach(ban => {
                html += `<tr>
                    <td><code>${ban.ban_value.substring(0, 16)}...</code></td>
                    <td><span class="dim">${ban.ban_type}</span></td>
                    <td>${ban.reason || '(no reason given)'}</td>
                    <td style="text-align:right;"><small>${new Date(ban.banned_at).toLocaleDateString()}</small></td>
                    <td style="text-align:right;"><button class="btn-smack btn-smack--dim btn-smack--sm" onclick="removeBan(${ban.id})">Unban</button></td>
                </tr>`;
            });
            html += '</tbody></table>';
            document.getElementById('banned-list').innerHTML = html;

            // Paginator
            let pag = '';
            for (let i = 1; i <= data.pages; i++) {
                pag += `<button class="btn-smack btn-smack--sm" onclick="loadBans(${i})" ${i === page ? 'disabled' : ''}>${i}</button> `;
            }
            document.getElementById('banned-paginator').innerHTML = pag;
        });
}

// ── FETCH AND RENDER FINGERPRINTS ───────────────────────────────────────────
let currentFpPage = 1;
function loadFingerprints(page) {
    currentFpPage = page;
    const search = document.getElementById('fp-search').value;
    const fd = new FormData();
    fd.append('action', 'fetch_fingerprints');
    fd.append('page', page);
    fd.append('search', search);

    fetch('smack-fingerprints.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            let html = '<table class="admin-table" style="width:100%;"><tbody>';
            data.bans.forEach(ban => {
                html += `<tr>
                    <td><code>${ban.ban_value.substring(0, 24)}...</code></td>
                    <td>${ban.ban_type}</td>
                    <td><strong>${ban.fp_comment_count || ban.ip_comment_count || 0}</strong> comments</td>
                    <td><span style="color:var(--danger,#cc4444);">⚫ BANNED</span></td>
                    <td style="text-align:right;"><button class="btn-smack btn-smack--dim btn-smack--sm" onclick="banFingerprint('${ban.ban_value}', '${ban.ban_type}')">Ban</button></td>
                </tr>`;
            });
            html += '</tbody></table>';
            document.getElementById('fingerprint-list').innerHTML = html;

            let pag = '';
            for (let i = 1; i <= data.pages; i++) {
                pag += `<button class="btn-smack btn-smack--sm" onclick="loadFingerprints(${i})" ${i === page ? 'disabled' : ''}>${i}</button> `;
            }
            document.getElementById('fp-paginator').innerHTML = pag;
        });
}

// ── BAN / UNBAN ─────────────────────────────────────────────────────────────
function banFingerprint(value, type) {
    const reason = prompt('Ban reason (optional):');
    if (reason === null) return;
    issueBanDirect(type, value, reason);
}

function issueBan() {
    const type = document.getElementById('ban-type').value;
    const value = document.getElementById('ban-value').value;
    const reason = document.getElementById('ban-reason').value;
    if (!value) { alert('Enter a value to ban'); return; }
    issueBanDirect(type, value, reason);
}

function issueBanDirect(type, value, reason) {
    const fd = new FormData();
    fd.append('action', 'add_ban');
    fd.append('ban_type', type);
    fd.append('ban_value', value);
    fd.append('reason', reason);

    fetch('smack-fingerprints.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                alert('Ban issued.');
                loadBans(1);
                loadFingerprints(1);
                document.getElementById('ban-value').value = '';
                document.getElementById('ban-reason').value = '';
            } else {
                alert('Error: ' + (data.error || 'Unknown error'));
            }
        });
}

function removeBan(banId) {
    if (!confirm('Unban this user?')) return;
    const fd = new FormData();
    fd.append('action', 'remove_ban');
    fd.append('ban_id', banId);

    fetch('smack-fingerprints.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                alert('Ban removed.');
                loadBans(1);
            } else {
                alert('Error removing ban.');
            }
        });
}

// ── SEARCH DEBOUNCE ─────────────────────────────────────────────────────────
let searchTimer;
document.getElementById('fp-search').addEventListener('input', function() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadFingerprints(1), 300);
});

// ── SEMANTIC ANALYSIS ───────────────────────────────────────────────────────
function loadSemantic() {
    const fp = document.getElementById('semantic-fp').value.trim();
    if (!fp) {
        alert('Enter a fingerprint hash');
        return;
    }

    const fd = new FormData();
    fd.append('action', 'fetch_semantic');
    fd.append('fingerprint', fp);

    fetch('smack-fingerprints.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            let html = '';
            if (!data.ok || data.similar.length === 0) {
                html = '<p class="dim">No similar fingerprints found (threshold: 55%).</p>';
            } else {
                html = '<table class="admin-table" style="width:100%;"><tbody>';
                html += '<tr style="font-weight:bold;"><td>Fingerprint</td><td>Similarity</td><td>Comments</td><td></td></tr>';
                data.similar.forEach(s => {
                    html += `<tr>
                        <td><code>${s.fingerprint.substring(0, 24)}...</code></td>
                        <td><strong>${Math.round(s.similarity * 100)}%</strong></td>
                        <td>${s.comment_count}</td>
                        <td style="text-align:right;"><button class="btn-smack btn-smack--dim btn-smack--sm" onclick="banSimilar('${s.fingerprint}')">Ban</button></td>
                    </tr>`;
                });
                html += '</tbody></table>';
            }
            document.getElementById('semantic-results').innerHTML = html;
        });
}

function banSimilar(fp) {
    const reason = prompt('Ban reason (optional):');
    if (reason === null) return;
    issueBanDirect('fingerprint', fp, reason);
}

// ── KEYWORD MANAGEMENT ──────────────────────────────────────────────────────
function addKeyword() {
    const keyword = document.getElementById('keyword-value').value.trim();
    const match = document.getElementById('keyword-match').value;
    const severity = document.getElementById('keyword-severity').value;
    const reason = document.getElementById('keyword-reason').value;

    if (!keyword) {
        alert('Enter a keyword');
        return;
    }

    const fd = new FormData();
    fd.append('action', 'add_keyword');
    fd.append('keyword', keyword);
    fd.append('match_type', match);
    fd.append('severity', severity);
    fd.append('reason', reason);

    fetch('smack-fingerprints.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                alert('Keyword added.');
                document.getElementById('keyword-value').value = '';
                document.getElementById('keyword-reason').value = '';
                loadKeywords();
            } else {
                alert('Error: ' + (data.error || 'Unknown error'));
            }
        });
}

function removeKeyword(keyword) {
    if (!confirm('Remove this keyword?')) return;

    const fd = new FormData();
    fd.append('action', 'remove_keyword');
    fd.append('keyword', keyword);

    fetch('smack-fingerprints.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                loadKeywords();
            } else {
                alert('Error removing keyword.');
            }
        });
}

function loadKeywords() {
    const fd = new FormData();
    fd.append('action', 'fetch_keywords');

    fetch('smack-fingerprints.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            let html = '';
            if (!data.ok || data.keywords.length === 0) {
                html = '<p class="dim">No banned keywords yet.</p>';
            } else {
                html = '<table class="admin-table" style="width:100%;"><tbody>';
                html += '<tr style="font-weight:bold;"><td>Keyword</td><td>Type</td><td>Severity</td><td>Reason</td><td></td></tr>';
                data.keywords.forEach(kw => {
                    const severity_color = kw.severity === 'reject' ? 'color:var(--danger,#cc4444)' : '';
                    html += `<tr>
                        <td><code>${kw.keyword}</code></td>
                        <td><span class="dim">${kw.match_type}</span></td>
                        <td style="${severity_color}"><strong>${kw.severity}</strong></td>
                        <td>${kw.reason || '(none)'}</td>
                        <td style="text-align:right;"><button class="btn-smack btn-smack--dim btn-smack--sm" onclick="removeKeyword('${kw.keyword.replace(/'/g, "\\'")}')">Remove</button></td>
                    </tr>`;
                });
                html += '</tbody></table>';
            }
            document.getElementById('keyword-list').innerHTML = html;
        });
}

// ── SHARED BANS (hub only) ──────────────────────────────────────────────────
<?php if ($is_hub): ?>
let currentSharedPage = 1;
function loadSharedBans(page) {
    currentSharedPage = page;
    const fd = new FormData();
    fd.append('action', 'fetch_shared_bans');
    fd.append('page', page);

    fetch('smack-fingerprints.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            const el = document.getElementById('shared-ban-list');
            if (!data.ok) {
                el.innerHTML = '<p class="dim">' + (data.error || 'Could not load shared bans.') + '</p>';
                return;
            }
            if (!data.rows.length) {
                el.innerHTML = '<p class="dim">No shared bans yet. Bans will appear here once spokes complete a sync.</p>';
                document.getElementById('shared-ban-paginator').innerHTML = '';
                return;
            }

            const typeLabel = { fingerprint: 'Fingerprint', ip: 'IP', email_hash: 'Email' };
            let html = '<table class="admin-table" style="width:100%;"><tbody>';
            html += '<tr style="font-weight:bold;"><td>Hash</td><td>Type</td><td>Reason</td><td>Reported By</td><td style="text-align:center;">Reports</td><td>Last Seen</td><td></td></tr>';
            data.rows.forEach(row => {
                const shortHash = row.ban_value.substring(0, 16) + '…';
                const reporter  = row.reported_by ? (new URL(row.reported_by).hostname) : '—';
                const lastSeen  = new Date(row.last_seen).toLocaleDateString();
                const countBadge = row.report_count >= 5
                    ? `<strong style="color:var(--danger,#cc4444);">${row.report_count}</strong>`
                    : row.report_count >= 3
                        ? `<strong style="color:var(--warn,#d4a030);">${row.report_count}</strong>`
                        : row.report_count;
                html += `<tr>
                    <td><code title="${row.ban_value}">${shortHash}</code></td>
                    <td><span class="dim">${typeLabel[row.ban_type] || row.ban_type}</span></td>
                    <td>${row.reason || '<span class="dim">(none)</span>'}</td>
                    <td><span class="dim" title="${row.reported_by}">${reporter}</span></td>
                    <td style="text-align:center;">${countBadge}</td>
                    <td><small>${lastSeen}</small></td>
                    <td style="text-align:right;"><button class="btn-smack btn-smack--dim btn-smack--sm" onclick="removeSharedBan(${row.id})">Clear</button></td>
                </tr>`;
            });
            html += '</tbody></table>';
            html += `<p class="dim" style="margin-top:10px; font-size:0.85em;">${data.total} shared ban${data.total !== 1 ? 's' : ''} total</p>`;
            el.innerHTML = html;

            let pag = '';
            for (let i = 1; i <= data.pages; i++) {
                pag += `<button class="btn-smack btn-smack--sm" onclick="loadSharedBans(${i})" ${i === page ? 'disabled' : ''}>${i}</button> `;
            }
            document.getElementById('shared-ban-paginator').innerHTML = pag;
        });
}

function removeSharedBan(banId) {
    if (!confirm('Clear this ban from distribution? The audit row will be preserved but it will no longer be sent to spokes.')) return;
    const fd = new FormData();
    fd.append('action', 'remove_shared_ban');
    fd.append('ban_id', banId);

    fetch('smack-fingerprints.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                loadSharedBans(currentSharedPage);
            } else {
                alert('Error: ' + (data.error || 'Could not clear ban.'));
            }
        });
}
<?php endif; ?>

// ── IP SMACKER ────────────────────────────────────────────────────────────────
function loadIpSmacker() {
    const el = document.getElementById('ip-smacker-list');
    el.innerHTML = '<em style="opacity:0.5;">Loading…</em>';
    const fd = new FormData();
    fd.append('action', 'fetch_ip_bans');
    fetch('smack-fingerprints.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.ok || !data.bans.length) {
                el.innerHTML = '<p class="dim">No active auto-bans. Good.</p>';
                return;
            }
            let html = '<table class="admin-table" style="width:100%;"><tbody>';
            html += '<tr style="font-weight:bold;font-size:0.78rem;"><td>IP</td><td>Reason</td><td>Banned At</td><td>Expires</td><td></td></tr>';
            data.bans.forEach(row => {
                const banned  = new Date(row.banned_at).toLocaleString();
                const expires = new Date(row.expires_at).toLocaleString();
                const expired = new Date(row.expires_at) < new Date();
                html += `<tr${expired ? ' style="opacity:0.45;"' : ''}>
                    <td><code>${row.ip}</code></td>
                    <td><span class="dim">${row.reason}</span></td>
                    <td><small>${banned}</small></td>
                    <td><small>${expires}${expired ? ' <em>(expired)</em>' : ''}</small></td>
                    <td style="text-align:right;">
                        <button class="btn-smack btn-smack--dim btn-smack--sm"
                                onclick="liftIpBan('${row.ip.replace(/'/g,'')}')">Lift</button>
                    </td>
                </tr>`;
            });
            html += '</tbody></table>';
            el.innerHTML = html;
        });
}

function liftIpBan(ip) {
    if (!confirm('Lift ban for ' + ip + '?')) return;
    const fd = new FormData();
    fd.append('action', 'lift_ip_ban');
    fd.append('ip', ip);
    fetch('smack-fingerprints.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) loadIpSmacker();
            else alert('Error: ' + (data.error || 'Could not lift ban.'));
        });
}

// ── INIT ────────────────────────────────────────────────────────────────────
loadBans(1);
loadKeywords();
</script>

<?php include 'core/admin-footer.php'; ?>
<?php // EOF
