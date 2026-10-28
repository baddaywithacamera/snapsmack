<?php
// SNAPSMACK_EOF_HEADER
declare(strict_types=1);
require_once dirname(__DIR__) . '/core/skin-presentation.php';
$cases = [];
foreach ([5, 8, 12, 15] as $width) {
    $presentation = snapsmack_skin_presentation([
        'jt_border_width' => (string)$width, 'jt_border_speed' => '0',
    ], 'jive-turkey');
    $style = (string)$presentation['style'];
    if (!str_contains($style, '--tile-bw:' . $width . 'px;')) throw new RuntimeException('Photo inset lost selected width');
    if (!str_contains($style, '--tile-radius:' . (int)round($width * 1.4) . 'px;')) throw new RuntimeException('Historical corner radius lost');
    if ($presentation['jive']['border_width'] !== $width) throw new RuntimeException('Ring and photo widths differ');
    if ($presentation['jive']['border_speed'] !== 0) throw new RuntimeException('Slowest speed changed');
    $cases[] = ['width'=>$width, 'style'=>$style, 'jive'=>$presentation['jive']];
}
echo in_array('--json', $argv ?? [], true) ? json_encode($cases, JSON_THROW_ON_ERROR) : "PASS: JIVE ring/photo width and historical radius agree; zero speed preserved.\n";
// ===== SNAPSMACK EOF =====
