<?php
// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker.
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$output = $argv[1] ?? '';
if ($output === '') {
    fwrite(STDERR, "Pass an output directory.\n");
    exit(2);
}
if (!is_dir($output) && !mkdir($output, 0777, true) && !is_dir($output)) {
    fwrite(STDERR, "Cannot create {$output}.\n");
    exit(2);
}

require_once $root . '/core/skin-settings.php';
require_once $root . '/core/skin-presentation.php';

final class NewHorizonColourPDO extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace(
            'ON DUPLICATE KEY UPDATE setting_val = ?',
            'ON CONFLICT(setting_key) DO UPDATE SET setting_val = ?',
            $query
        );
        return parent::prepare($query, $options);
    }
}

function nh_colour_save(PDO $pdo, string $handler, array $values): array
{
    $_requested_skin = 'new-horizon';
    $_POST = ['skin_opt' => $values];
    eval($handler);
    $fresh = $pdo->query('SELECT setting_key, setting_val FROM snap_settings ORDER BY setting_key')
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    snapsmack_apply_skin_settings($fresh, 'new-horizon');
    return $fresh;
}

function nh_colour_fixture(
    string $root,
    string $output,
    string $variant,
    string $state,
    array $settings
): void {
    $resolved = snapsmack_declared_skin_options($settings, 'new-horizon');
    $dynamic = snapsmack_declared_skin_style('new-horizon', $resolved);
    $css = (string)file_get_contents($root . '/skins/new-horizon/variant-' . $variant . '.css')
        . "\n"
        . (string)file_get_contents($root . '/skins/new-horizon/style.css');
    $html = '<!doctype html><html><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<style>' . $css . '</style>' . $dynamic . '</head>'
        . '<body class="new-horizon-v2 static-transmission"><div id="page-wrapper">'
        . '<header id="header"><div class="inside"><div class="logo-area">'
        . '<a href="#"><span class="site-title-text">Curb Appeal</span></a>'
        . '<span class="site-tagline-text">One photograph at a time</span>'
        . '</div></div></header><div id="scroll-stage">'
        . '<main class="static-content"><h1 class="static-page-title">About Curb Appeal</h1>'
        . '<p id="body-copy">Body copy uses the configured body role.</p>'
        . '<p class="description">Description copy uses the configured body override.</p></main>'
        . '<footer id="system-footer"><div class="inside"><p id="sig-text">Footer copy</p></div></footer>'
        . '</div></div></body></html>';
    file_put_contents($output . '/' . $variant . '-' . $state . '.html', $html);
}

$source = (string)file_get_contents($root . '/smack-skin.php');
$start = strpos($source, '    // 4a. Persistence:');
$end = strpos($source, '    // MOBILE SKIN:', $start);
if ($start === false || $end === false || $end <= $start) {
    throw new RuntimeException('Could not isolate the real Skin Admin persistence block.');
}
$handler = substr($source, $start, $end - $start);

$manifest = json_decode(
    (string)file_get_contents($root . '/skins/new-horizon/manifest.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$changed = [
    'header_title_color' => '#c13b4a',
    'tagline_color' => '#d49a2a',
];
$expectedRows = [];
foreach (['dark', 'light'] as $variant) {
    $pdo = new NewHorizonColourPDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE snap_settings(setting_key TEXT PRIMARY KEY, setting_val TEXT)');

    $default = [];
    snapsmack_apply_skin_settings($default, 'new-horizon');
    nh_colour_fixture($root, $output, $variant, 'default', $default);

    $variantChanged = $changed;
    foreach (['heading', 'body', 'footer'] as $role) {
        $variantChanged['nh_' . $role . '_colour_' . $variant] = match ($role) {
            'heading' => '#2fa36b',
            'body' => '#3977c5',
            default => '#8a5ac2',
        };
    }
    $freshChanged = nh_colour_save($pdo, $handler, $variantChanged);
    nh_colour_fixture($root, $output, $variant, 'changed', $freshChanged);

    $reset = [
        'header_title_color' => (string)$manifest['options']['header_title_color']['default'],
        'tagline_color' => (string)$manifest['options']['tagline_color']['default'],
    ];
    foreach (['heading', 'body', 'footer'] as $role) {
        $key = 'nh_' . $role . '_colour_' . $variant;
        $reset[$key] = (string)$manifest['options'][$key]['default'];
    }
    $freshReset = nh_colour_save($pdo, $handler, $reset);
    nh_colour_fixture($root, $output, $variant, 'reset', $freshReset);

    foreach (array_keys($variantChanged) as $key) {
        $scoped = 'new-horizon__' . $key;
        $expectedRows[] = [
            'variant' => $variant,
            'key' => $key,
            'changed_saved' => (string)($freshChanged[$scoped] ?? ''),
            'reset_saved' => (string)($freshReset[$scoped] ?? ''),
            'expected_changed' => (string)$variantChanged[$key],
            'expected_reset' => (string)$reset[$key],
            'pass' => isset($freshChanged[$scoped], $freshReset[$scoped])
                && strcasecmp((string)$freshChanged[$scoped], (string)$variantChanged[$key]) === 0
                && strcasecmp((string)$freshReset[$scoped], (string)$reset[$key]) === 0,
        ];
    }
}

file_put_contents(
    $output . '/save-reload.json',
    json_encode($expectedRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);
$failed = array_filter($expectedRows, static fn(array $row): bool => !$row['pass']);
if ($failed) {
    fwrite(STDERR, 'Save/reload failures: ' . json_encode(array_values($failed)) . "\n");
    exit(1);
}
echo 'PASS: real Skin Admin persistence block saved and freshly reloaded '
    . count($expectedRows) . " scoped colour values.\n";

// ===== SNAPSMACK EOF =====
