<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/** SS-1553 / SS-1554 — save, reload and render Email + external Pixelfed. */

$root = dirname(__DIR__);
$failures = [];
$check = static function (string $what, bool $ok) use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if (!$ok) $failures[] = $what;
};

final class SocialDockFakeStatement {
    public function __construct(private array &$rows) {}
    public function execute(array $values): bool {
        $this->rows[(string)$values[0]] = (string)$values[1];
        return true;
    }
}

final class SocialDockFakeResult {
    public function __construct(private array $rows) {}
    public function fetchAll(int $mode): array { return $this->rows; }
}

final class SocialDockFakePdo {
    public array $rows = [];
    public function prepare(string $sql): SocialDockFakeStatement {
        return new SocialDockFakeStatement($this->rows);
    }
    public function query(string $sql): SocialDockFakeResult {
        return new SocialDockFakeResult($this->rows);
    }
}

$admin = (string)file_get_contents($root . '/smack-social-dock.php');
preg_match('~// --- FORM SUBMISSION ---\R(.*?)\R// Load settings~s', $admin, $match);
$saveBlock = $match[1] ?? '';
$check('the regression executes the real admin save block', $saveBlock !== '');

$pdo = new SocialDockFakePdo();
$_POST = [
    'save_social_dock' => '1',
    'social_dock_enabled' => '1',
    'social_dock_pixelfed' => 'https://pixelfed.example/@owner',
    'social_dock_email' => 'owner@example.test',
];
eval($saveBlock);

$check('admin save stores the independent Pixelfed profile',
       ($pdo->rows['social_dock_pixelfed'] ?? '') === 'https://pixelfed.example/@owner');
$check('admin save stores Email',
       ($pdo->rows['social_dock_email'] ?? '') === 'owner@example.test');

// A new query is the fresh admin request: no POST values are reused.
$_POST = [];
$settings = $pdo->query('SELECT setting_key, setting_val FROM snap_settings')
                ->fetchAll(PDO::FETCH_KEY_PAIR);
$check('fresh admin reload reads the stored Pixelfed value',
       ($settings['social_dock_pixelfed'] ?? '') === 'https://pixelfed.example/@owner');
$check('fresh admin reload reads the stored Email value',
       ($settings['social_dock_email'] ?? '') === 'owner@example.test');
$check('fresh admin fields bind those stored values',
       str_contains($admin, "name=\"social_dock_pixelfed\" value=\"<?php echo htmlspecialchars(\$settings['social_dock_pixelfed'] ?? ''); ?>\"")
       && str_contains($admin, "name=\"social_dock_email\" value=\"<?php echo htmlspecialchars(\$settings['social_dock_email'] ?? ''); ?>\""));

define('BASE_URL', 'https://site.example/');
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/indieweb.php';
require_once $root . '/core/skin-view-model.php';

$build = static function (bool $federationEnabled) use ($settings): array {
    $db = new PDO('sqlite::memory:');
    $db->exec('CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)');
    $insert = $db->prepare('INSERT INTO snap_settings(setting_key, setting_val) VALUES(?, ?)');
    foreach ($settings + [
        'fediverse_enabled' => $federationEnabled ? '1' : '0',
        'social_dock_own_fedi' => $federationEnabled ? '1' : '0',
    ] as $key => $value) {
        $insert->execute([$key, $value]);
    }
    $manifest = ['social_dock' => ['position' => 'inline']];
    $crop = snapsmack_prepare_crop_circles($db, [], $manifest, []);
    $model = snapsmack_social_dock_model($crop, $manifest);
    $html = (string)snap_render_component('social-dock', ['model' => $model]);
    return [$crop, $html];
};

[$offModel, $offHtml] = $build(false);
[$onModel, $onHtml] = $build(true);
foreach (['federation off' => [$offModel, $offHtml], 'federation on' => [$onModel, $onHtml]] as $state => [$model, $html]) {
    $byLabel = [];
    foreach ($model['social'] as $item) $byLabel[$item['label']] = $item['url'];
    $check("Pixelfed remains independent with {$state}",
           ($byLabel['Pixelfed'] ?? '') === 'https://pixelfed.example/@owner');
    $check("Email remains independent with {$state}",
           ($byLabel['Email'] ?? '') === 'mailto:owner@example.test');
    $check("both links render with {$state}",
           str_contains($html, 'https://pixelfed.example/@owner')
           && str_contains($html, 'mailto:owner@example.test'));
}

$check('admin help states the strict-skin artwork boundary',
       str_contains($admin, 'Strict-skin docks always use SnapSmack\'s single outline glyph family'));
$appearanceDoc = (string)file_get_contents($root . '/core/social-dock-appearance.php');
$check('the manifest key description states the strict-skin artwork boundary',
       str_contains($appearanceDoc, 'MANIFEST KEY DESCRIPTION — default_icon_style')
       && str_contains($appearanceDoc, 'does not switch their artwork'));

if ($failures) {
    fwrite(STDERR, "SS-1553/1554 admin persistence FAILED:\n  - " . implode("\n  - ", $failures) . "\n");
    exit(1);
}
echo "PASS: Email and independent Pixelfed save, persist, reload and render with federation off or on.\n";

// ===== SNAPSMACK EOF =====
