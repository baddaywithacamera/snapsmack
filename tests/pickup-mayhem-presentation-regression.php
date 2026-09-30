<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/skin-presentation.php';

$presentation = snapsmack_skin_presentation([
    'mayhem_initial_count' => '222',
    'mayhem_max_width' => '355',
    'mayhem_overlap_max' => '73',
    'mayhem_drift' => '0',
    'mayhem_warp' => '1',
], '52-card-pickup');

$expected = ['api_url'=>'?ajax=mayhem', 'initial_count'=>222, 'max_width'=>355,
    'overlap_max'=>'0.73', 'drift'=>'0', 'warp'=>'1'];
if (($presentation['mayhem'] ?? null) !== $expected) {
    throw new RuntimeException('52 Card Pickup mayhem controls did not survive the CMS presentation boundary.');
}

$bounded = snapsmack_skin_presentation([
    'mayhem_initial_count'=>'9999', 'mayhem_max_width'=>'1', 'mayhem_overlap_max'=>'2',
], '52-card-pickup')['mayhem'];
if ($bounded['initial_count'] !== 400 || $bounded['max_width'] !== 120 || $bounded['overlap_max'] !== '0.40') {
    throw new RuntimeException('52 Card Pickup mayhem controls are not bounded by the CMS.');
}

echo "52 Card Pickup mayhem presentation regression passed.\n";
