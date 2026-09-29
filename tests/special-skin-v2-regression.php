<?php
declare(strict_types=1);
define('SNAPSMACK_SKIN_RENDER', true);
require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';

$root=dirname(__DIR__);$inventory=require $root.'/core/manifest-inventory.php';
foreach(['photogram','onyx'] as $skin){
 $dir=$root.'/skins/'.$skin;$m=json_decode((string)file_get_contents($dir.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
 if(($m['schema_version']??0)!==2||($m['security_policy']??0)!==2||($m['cms_controller']??'')!=='public')throw new RuntimeException("{$skin} boundary failed.");
 $php=glob($dir.'/*.php')?:[];if(count($php)!==1||basename($php[0])!=='layout.php')throw new RuntimeException("{$skin} retains legacy PHP.");
 $view=snapsmack_build_skin_view(['status'=>200,'kind'=>'landing','items'=>[],'navigation'=>[]],['site_name'=>'Example','language'=>'en','direction'=>'ltr','skin_style_url'=>'/skins/'.$skin.'/style.css']);
 ob_start();$ok=snapsmack_render_strict_skin_template($dir,'layout.php',$view);ob_end_clean();if(!$ok)throw new RuntimeException("{$skin} failed strict rendering.");
}
$photogram=json_decode((string)file_get_contents($root.'/skins/photogram/manifest.json'),true,512,JSON_THROW_ON_ERROR);
if(empty($photogram['features']['mobile_only']))throw new RuntimeException('PHOTOGRAM lost its mobile role.');
foreach(['smack-photogram','smack-photogram-feed'] as $handle)if(empty($inventory['scripts'][$handle]['path']))throw new RuntimeException("{$handle} is not CMS registered.");
$onyx=json_decode((string)file_get_contents($root.'/skins/onyx/manifest.json'),true,512,JSON_THROW_ON_ERROR);
if(empty($onyx['features']['fedistructure_only']))throw new RuntimeException('ONYX lost its service role.');
$legacy=require $root.'/core/skin-security-legacy.php';if($legacy!==[])throw new RuntimeException('Legacy skin inventory is not empty.');
echo "Special-role schema-v2 skin regression passed.\n";
