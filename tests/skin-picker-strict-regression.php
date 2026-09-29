<?php
declare(strict_types=1);
$root=dirname(__DIR__);$page=(string)file_get_contents($root.'/smack-skin.php');$manifest=(string)file_get_contents($root.'/core/skin-manifest.php');
if(str_contains($page,'(legacy — update it from the gallery)')||str_contains($manifest,"'skin_preload'"))throw new RuntimeException('Legacy skin compatibility remains selectable.');
require_once $root.'/core/skin-manifest.php';
$bad=snapsmack_normalize_skin_manifest(['schema_version'=>1],'legacy');if(($bad['schema_version']??null)!==0)throw new RuntimeException('Schema-v1 manifest was normalized as usable.');
foreach(glob($root.'/skins/*/manifest.json')?:[] as $path){$m=json_decode((string)file_get_contents($path),true);if(($m['schema_version']??0)!==2)throw new RuntimeException(basename(dirname($path)).' is not schema v2.');}
echo "Strict skin picker and manifest regression passed.\n";
