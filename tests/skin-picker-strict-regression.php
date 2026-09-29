<?php
declare(strict_types=1);
$root=dirname(__DIR__);$page=(string)file_get_contents($root.'/smack-skin.php');$manifest=(string)file_get_contents($root.'/core/skin-manifest.php');
if(str_contains($page,'(legacy — update it from the gallery)')||str_contains($manifest,"'skin_preload'"))throw new RuntimeException('Legacy skin compatibility remains selectable.');
require_once $root.'/core/skin-manifest.php';
$legacy=snapsmack_normalize_skin_manifest(['schema_version'=>1,'require_scripts'=>['smack-game-on']],'legacy');if(($legacy['schema_version']??null)!==1||($legacy['require_scripts']??[])!==['smack-game-on'])throw new RuntimeException('Installed schema-v1 manifest lost its inert runtime declarations.');
$bad=snapsmack_normalize_skin_manifest(['schema_version'=>99],'future');if(($bad['schema_version']??null)!==0)throw new RuntimeException('Unknown manifest schema was normalized as usable.');
foreach(glob($root.'/skins/*/manifest.json')?:[] as $path){$m=json_decode((string)file_get_contents($path),true);if(($m['schema_version']??0)!==2)throw new RuntimeException(basename(dirname($path)).' is not schema v2.');}
echo "Strict skin picker and manifest regression passed.\n";
