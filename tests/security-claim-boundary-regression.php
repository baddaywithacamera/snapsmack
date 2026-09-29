<?php
declare(strict_types=1);
$root=dirname(__DIR__);$bugger=(string)file_get_contents($root.'/projects/snapsmack-ca/bugger.php');$news=(string)file_get_contents($root.'/projects/snapsmack-ca/wotcha.php');$ledger=(string)file_get_contents($root.'/projects/snapsmack-ca/buzzers.php');
if(str_contains($bugger,'The design side works: the controls panel, the live preview, the push-to-blog pipeline'))throw new RuntimeException('OH SNAP is still claimed working despite its disabled push boundary.');
foreach(['direct skin push is disabled','may not upload PHP, JavaScript, routes, or data access'] as $claim)if(!str_contains($bugger,$claim))throw new RuntimeException("OH SNAP limitation missing: {$claim}");
foreach(['no skin may ship PHP behaviour or JavaScript','no “Allow Custom Skin JS” bypass'] as $claim)if(!str_contains($news,$claim))throw new RuntimeException("Historical skin-JS claim lacks correction: {$claim}");
if(!str_contains($ledger,'SnapSmack is not bulletproof'))throw new RuntimeException('Public security ledger lost its limits statement.');
echo "Public skin-security claim boundary regression passed.\n";
