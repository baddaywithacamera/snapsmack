<?php
$tmp = sys_get_temp_dir() . '/snapsmack-skin-evap-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
define('SKINS_DIR', $tmp);
require_once dirname(__DIR__) . '/core/skin-registry.php';
$GLOBALS['pdo'] = new class {
    public function prepare(string $sql): object {
        return new class { public function execute(array $params = []): bool { return true; } };
    }
};

$fail = static function (string $message) use ($tmp): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    if (is_dir($tmp)) _skin_rmdir_recursive($tmp);
    exit(1);
};

foreach (['parked', 'spare', 'active', 'mobile'] as $slug) {
    mkdir($tmp . '/' . $slug, 0700, true);
    file_put_contents($tmp . '/' . $slug . '/manifest.json', '{}');
}

$result = skin_registry_evaporate_inactive('active', ['mobile']);
if (empty($result['success']) || count($result['removed']) !== 2) $fail('inactive skins were not reported removed');
if (is_dir($tmp . '/parked')) $fail('parked skin code remains on disk');
if (is_dir($tmp . '/spare')) $fail('older inactive skin code remains on disk');
if (!is_dir($tmp . '/active')) $fail('active skin was removed');

$result = skin_registry_evaporate_parked('mobile', 'active', ['mobile']);
if (empty($result['success']) || !empty($result['removed']) || !is_dir($tmp . '/mobile')) $fail('required mobile skin was not retained');

$registry = (string)file_get_contents(dirname(__DIR__) . '/core/skin-registry.php');
$start = strpos($registry, 'function skin_registry_evaporate_parked');
$end = strpos($registry, '// --- INTERNAL HELPERS ---', $start ?: 0);
$evaporation = $start === false ? '' : substr($registry, $start, ($end === false ? null : $end - $start));
if (stripos($evaporation, 'DELETE FROM snap_settings') !== false) $fail('evaporation deletes retained skin settings');
$admin = (string)file_get_contents(dirname(__DIR__) . '/smack-skin.php');
if (substr_count($admin, 'skin_registry_evaporate_inactive(') < 2) $fail('one activation path does not evaporate inactive skins');

_skin_rmdir_recursive($tmp);
echo "PASS: parked skins evaporate while active and required skins remain.\n";
// ===== SNAPSMACK EOF =====
