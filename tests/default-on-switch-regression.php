<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A switch that reads "Yes" must behave as Yes.
 *
 * 20 October 2026. Sean had GALLERIA's Show Filmstrip set to Yes on
 * hekeepsdroningon.ca and no filmstrip on the page. I told him twice it was
 * his setting. It was not.
 *
 * The controller asked `($settings['htbs_show_filmstrip'] ?? '1') === '1'`.
 * That is true only for the exact string "1" -- so ANY other stored value
 * switched the feature off, including an empty string. Meanwhile Smooth Your
 * Skin renders a <select> whose options are 1 and 0: given '' it matches
 * neither, so the browser shows the FIRST option, which is "Yes". The admin
 * said Yes, the site behaved as No, and nothing reconciled them.
 *
 * A default-ON switch must be off only when the owner explicitly chose 0.
 * The same shape gated the comment system on two sites.
 */

$root = dirname(__DIR__);
$fail = [];
$check = static function (bool $ok, string $what) use (&$fail): void {
    if (!$ok) $fail[] = $what;
};

// ── the behaviour, in the form the controller uses ──────────────────────────
$on = static fn(array $s): bool => (string)($s['k'] ?? '1') !== '0';
foreach ([
    [[],            'unset -> on (the documented default)',                 true],
    [['k' => '1'],  'explicit 1 -> on',                                     true],
    [['k' => '0'],  'explicit 0 -> OFF, because the owner said so',         false],
    [['k' => ''],   'empty string -> on, since the admin shows Yes for it', true],
    [['k' => 'on'], 'an unexpected value -> on, not silently off',          true],
] as [$settings, $what, $expected]) {
    $check($on($settings) === $expected, $what);
}

// ── and that no default-on switch is read with a strict ==='1' again ────────
// The strict test is what made an empty value mean "off" while the admin went
// on reporting "Yes".
$files = array_merge(glob($root . '/core/*.php') ?: [], [$root . '/index.php']);
$offenders = [];
foreach ($files as $file) {
    $src = (string)file_get_contents($file);
    if (preg_match_all('/\(\$settings\[.([a-z_]+).\] \?\? .1.\) === .1./', $src, $m)) {
        foreach ($m[1] as $key) $offenders[] = basename($file) . ':' . $key;
    }
}
$check($offenders === [],
    'default-on switches still read with a strict === \'1\', so an empty value '
    . 'silently turns them off: ' . implode(', ', $offenders));

// ── the one that started it ─────────────────────────────────────────────────
$controller = (string)file_get_contents($root . '/core/public-controller.php');
$check(str_contains($controller, "(string)(\$settings['htbs_show_filmstrip'] ?? '1') !== '0'"),
    'the filmstrip switch is strict again, so Yes can mean No');

if ($fail) {
    fwrite(STDERR, "FAIL: default-on switches\n  - " . implode("\n  - ", $fail) . "\n");
    exit(1);
}
echo "PASS: a switch that reads Yes behaves as Yes; off only when explicitly 0.\n";
// ===== SNAPSMACK EOF =====
