<?php
/**
 * BIGGIE layout output reaches the CMS parser intact.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

require_once __DIR__ . '/../core/parser.php';

$pdo = new class extends PDO {
    public function __construct() {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        throw new PDOException('No database needed for this parser regression.');
    }
};
$parser = new SnapSmack($pdo);

$html = $parser->parseContent(
    '[columns=2 ratio=1-2]' . "\n"
    . '[dropcap]N[/dropcap]arrow side.' . "\n\n[col]\n\n"
    . '<h2>Wide side</h2>' . "\n\n"
    . '[pullquote]A real pullquote.[/pullquote]' . "\n"
    . '[/columns]'
);

$checks = [
    'ratio class' => 'snapsmack-columns cols-2 ratio-1-2',
    'two cells' => 'snapsmack-col',
    'dropcap remains paragraph formatting' => '<span class="dropcap">N</span>arrow side.',
    'heading survives inside a column' => '<h2>Wide side</h2>',
    'pullquote renders semantically' => '<blockquote class="ss-pullquote">A real pullquote.</blockquote>',
];

foreach ($checks as $label => $needle) {
    if (strpos($html, $needle) === false) {
        fwrite(STDERR, "FAIL: {$label}\n{$html}\n");
        exit(1);
    }
}
if (substr_count($html, 'class="snapsmack-col"') !== 2) {
    fwrite(STDERR, "FAIL: expected exactly two column cells\n{$html}\n");
    exit(1);
}

$one = $parser->parseContent('[columns=1]one[/columns]');
if (strpos($one, 'snapsmack-columns cols-1') === false) {
    fwrite(STDERR, "FAIL: one-column container was not preserved\n{$one}\n");
    exit(1);
}

echo "PASS: BIGGIE column and pullquote parser regression\n";
// ===== SNAPSMACK EOF =====
