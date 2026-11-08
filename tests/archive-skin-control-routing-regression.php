<?php
// SNAPSMACK_EOF_HEADER: preserve the final EOF marker.
declare(strict_types=1);
require_once dirname(__DIR__) . '/core/skin-settings.php';
$src = file_get_contents(dirname(__DIR__) . '/smack-appearance-archive.php');
$manifest = json_decode(file_get_contents(dirname(__DIR__) . '/skins/new-horizon/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$archive_manifest_opts = array_filter($manifest['options'], static fn($m) => ($m['admin_page'] ?? '') === 'archive');
$pass = 0;
function check(bool $ok, string $message): void {
    global $pass;
    if (!$ok) throw new RuntimeException($message);
    $pass++;
    echo "PASS {$message}\n";
}
check(isset($archive_manifest_opts['archive_thumb_size'], $archive_manifest_opts['archive_gap']), 'existing size and gap controls route to Archive Appearance');
// Execute actual POST validation, without database or web-side effects.
$start = strpos($src, '    $archive_skin_values = [];');
$end = strpos($src, '    if ($archive_skin_values) {', $start);
$validate = substr($src, $start, $end - $start);
foreach ([
    [['archive_thumb_size'=>'350','archive_gap'=>'50'], ['archive_thumb_size'=>'350','archive_gap'=>'50']],
    [['archive_thumb_size'=>'999','archive_gap'=>'-10'], ['archive_thumb_size'=>'400','archive_gap'=>'0']],
    [['active_skin'=>'x','archive_thumb_size'=>['x'],'archive_gap'=>'1px'], []],
    ['malformed', []],
] as [$posted, $expected]) {
    $_POST = ['archive_skin_opt'=>$posted];
    eval($validate);
    check($archive_skin_values === $expected, 'POST validation preserves valid values, clamps ranges and rejects undeclared/malformed input');
}
// Render the actual Archive Display fragment using the owner's scoped values.
$settings = ['new-horizon__archive_thumb_size'=>'350','new-horizon__archive_gap'=>'50'];
snapsmack_apply_skin_settings($settings, 'new-horizon');
$start = strpos($src, '    <?php if (!empty($archive_manifest_opts)): ?>');
$end = strpos($src, '    <!-- ── CALENDAR SIDEBAR', $start);
ob_start();
eval('?>' . substr($src, $start, $end - $start));
$html = ob_get_clean();
check((bool)preg_match('/name="archive_skin_opt\[archive_thumb_size\]"[\s\S]*?value="350"/', $html), 'rendered size slider retains saved 350');
check((bool)preg_match('/name="archive_skin_opt\[archive_gap\]"[\s\S]*?value="50"/', $html), 'rendered gap slider retains saved 50');
check(substr_count($html, 'name="archive_skin_opt[archive_thumb_size]"') === 1, 'size slider rendered once');
check(strpos($src, 'skin_registry_verify_installed_provenance($pdo, $active_skin, $reason)') < strpos($src, "foreach (\$_POST['settings']"), 'provenance refusal precedes all existing settings writes');
check(str_contains($src, "\$active_skin . '__' . \$key"), 'skin values retain scoped storage');
check(str_contains($src, 'snapsmack_recompile_public_skin_css($pdo, $active_skin);'), 'save invalidates compiled presentation through existing compiler');
// Run the actual handler against an isolated SQLite settings store. Only the
// SQL dialect and external provenance/compiler boundaries are test doubles.
class ArchiveTestPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $query = str_replace('ON DUPLICATE KEY UPDATE setting_val = ?', 'ON CONFLICT(setting_key) DO UPDATE SET setting_val = ?', $query);
        return parent::prepare($query, $options);
    }
}
function skin_registry_verify_installed_provenance(PDO $pdo, string $skin, ?string &$reason = null): bool {
    $reason = 'test provenance refusal';
    return $GLOBALS['archive_test_provenance'];
}
function snapsmack_recompile_public_skin_css(PDO $pdo, string $skin): void {
    $GLOBALS['archive_test_compiles'][] = $skin;
}
function archive_test_header(string $header): void {}
$pdo = new ArchiveTestPDO('sqlite::memory:');
$pdo->exec('CREATE TABLE snap_settings(setting_key TEXT PRIMARY KEY, setting_val TEXT)');
$pdo->exec("INSERT INTO snap_settings VALUES ('new-horizon__archive_thumb_size','350'),('new-horizon__archive_gap','50')");
$active_skin = 'new-horizon';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION = [];
$archive_test_compiles = [];
$handlerStart = strpos($src, "if (\$_SERVER['REQUEST_METHOD'] === 'POST'");
$handlerEnd = strpos($src, '$page_title =', $handlerStart);
$handler = substr($src, $handlerStart, $handlerEnd - $handlerStart);
$handler = str_replace(['header(', 'exit;'], ['archive_test_header(', "throw new RuntimeException('test redirect');"], $handler);
foreach ([false, true] as $allowed) {
    $archive_test_provenance = $allowed;
    $_POST = ['save_archive_appearance'=>'1', 'archive_skin_opt'=>['archive_thumb_size'=>'275','archive_gap'=>'24']];
    try { eval($handler); } catch (RuntimeException $e) { if ($e->getMessage() !== 'test redirect') throw $e; }
    $fresh = $pdo->query('SELECT setting_key,setting_val FROM snap_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    snapsmack_apply_skin_settings($fresh, $active_skin);
    check($fresh['archive_thumb_size'] === ($allowed ? '275' : '350') && $fresh['archive_gap'] === ($allowed ? '24' : '50'),
          $allowed ? 'actual save followed by fresh reload retains changed scoped values' : 'provenance refusal preserves all stored values');
    check(count($archive_test_compiles) === ($allowed ? 1 : 0), 'compiler invoked only after an authorised scoped save');
}
check(!array_key_exists('archive_thumb_size', $pdo->query('SELECT setting_key,setting_val FROM snap_settings')->fetchAll(PDO::FETCH_KEY_PAIR)), 'save creates no bare global size key');
echo "{$pass} checks passed; installed admin-save roundtrip NOT CHECKED.\n";
// ===== SNAPSMACK EOF =====
