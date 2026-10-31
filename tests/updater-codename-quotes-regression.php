<?php
// SNAPSMACK_EOF_HEADER: keep the canonical EOF marker as the final line.
// SS-1127: exercise the production rewrite against disposable constants files.
$root = dirname(__DIR__);
$source = file_get_contents($root . '/core/updater.php');
if (!preg_match('/function updater_set_version\(.*?\n\}/s', $source, $match)) {
    fwrite(STDERR, "Cannot extract production updater_set_version\n");
    exit(1);
}
$scratch = sys_get_temp_dir() . '/snapsmack-codename-' . bin2hex(random_bytes(8));
mkdir($scratch);
file_put_contents($scratch . '/rewrite.php', "<?php\n" . $match[0]);
require $scratch . '/rewrite.php';
class CodenameStatement extends PDOStatement {
    public function execute(?array $params = null): bool { return true; }
}
class CodenameDatabase extends PDO {
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new CodenameStatement();
    }
}
$fail = 0;
$pass = 0;
$check = function (bool $ok, string $label) use (&$fail, &$pass): void {
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
};
$db = new CodenameDatabase();
$prefix = "<?php\ndefine('SNAPSMACK_VERSION', 'Alpha 0.7.973D');\n"
    . "define('SNAPSMACK_VERSION_SHORT', '0.7.973D');\n";
$fixtures = [
    'single quotes' => "define('SNAPSMACK_VERSION_CODENAME', 'OLD');",
    'double quotes (shipping form)' => 'define("SNAPSMACK_VERSION_CODENAME", "OLD");',
    'mixed quotes and spacing' => 'define ( "SNAPSMACK_VERSION_CODENAME" , \'OLD\' );',
    'no codename yet' => '',
];
try {
    foreach ($fixtures as $label => $definition) {
        file_put_contents($scratch . '/constants.php', $prefix . $definition . "\n// ===== SNAPSMACK EOF =====\n");
        foreach (['FIRST', 'SECOND'] as $name) {
            $check(updater_set_version($db, '0.7.974D', 'Alpha 0.7.974D', $name), "$label: update succeeds");
            $result = file_get_contents($scratch . '/constants.php');
            $check(substr_count($result, 'SNAPSMACK_VERSION_CODENAME') === 1, "$label: exactly one codename after $name");
            $check(str_contains($result, "define('SNAPSMACK_VERSION_CODENAME', '$name');"), "$label: current codename after $name");
            $check(str_contains($result, "define('SNAPSMACK_VERSION_SHORT', '0.7.974D');"), "$label: version also updated");
            $check(str_ends_with(trim($result), '// ===== SNAPSMACK EOF ====='), "$label: file ending preserved");
        }
    }
    updater_set_version($db, '0.7.974D', 'Alpha 0.7.974D', "SAFE'; bad(); //");
    $result = file_get_contents($scratch . '/constants.php');
    $check(str_contains($result, "define('SNAPSMACK_VERSION_CODENAME', 'SAFE bad ');"), 'codename input remains sanitized');
} finally {
    unlink($scratch . '/rewrite.php');
    unlink($scratch . '/constants.php');
    rmdir($scratch);
}
echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
// ===== SNAPSMACK EOF =====
