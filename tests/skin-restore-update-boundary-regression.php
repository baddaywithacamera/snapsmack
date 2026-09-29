<?php
declare(strict_types=1);
$root=dirname(__DIR__);$export=(string)file_get_contents($root.'/core/export-engine.php');$recovery=(string)file_get_contents($root.'/core/recovery-engine.php');$updater=(string)file_get_contents($root.'/core/updater.php');$protected=json_decode((string)file_get_contents($root.'/protected_paths.json'),true,512,JSON_THROW_ON_ERROR);
if(str_contains($export,"inventoryDirectory(\$skinDir")||str_contains($export,"'skin_files'"))throw new RuntimeException('Backup still inventories executable skin code.');
if(!str_contains($recovery,"str_starts_with(\$relTarget, 'skins/')")||!str_contains($recovery,'Rejected skin code in recovery archive'))throw new RuntimeException('Recovery can restore skin code.');
if(!in_array('skins/',$protected['protected']??[],true)||!str_contains($updater,"str_starts_with(\$relative_path, 'skins/')"))throw new RuntimeException('Core updater can overwrite skin packages.');
echo "Skin restore/update authority boundary regression passed.\n";
