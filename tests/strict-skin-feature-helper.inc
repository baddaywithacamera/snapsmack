<?php
declare(strict_types=1);
function snapsmack_assert_strict_skin_feature(string $slug): void {
    $root=dirname(__DIR__);$dir=$root.'/skins/'.$slug;$manifest=json_decode((string)file_get_contents($dir.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
    if(($manifest['schema_version']??0)!==2||($manifest['security_policy']??0)!==2||!in_array($manifest['cms_controller']??'',['public','smacktalk'],true))throw new RuntimeException("{$slug} is outside the strict CMS controller boundary.");
    $php=glob($dir.'/*.php')?:[];if(count($php)!==1||basename($php[0])!=='layout.php')throw new RuntimeException("{$slug} retains feature PHP outside its layout.");
    $inventory=require $root.'/core/manifest-inventory.php';require_once $root.'/core/asset-registry.php';$publicAssets=snapsmack_asset_registry();foreach(($manifest['require_scripts']??[]) as $handle)if(empty($inventory['scripts'][$handle]['path'])&&empty($publicAssets[$handle]['path']))throw new RuntimeException("{$slug} requests unknown CMS engine {$handle}.");
    $cmsComponents=[
        '50-shades-of-noah-grey'=>'50-shades-page-component.php',
        'chaplin'=>'chaplin-page-component.php',
        'galleria'=>'galleria-page-component.php',
        'impact-printer'=>'impact-printer-page-component.php',
    ];
    $layout=(string)file_get_contents(isset($cmsComponents[$slug])
        ? $root.'/core/'.$cmsComponents[$slug]
        : $dir.'/layout.php');
    if(!str_contains($layout,"snap_render_component('registered-assets'")&&!str_contains($layout,"snap_render_component('public-page'")&&!str_contains($layout,"snap_render_component('smacktalk-page'"))throw new RuntimeException("{$slug} does not mount its CMS-registered behavior assets.");
    if(preg_match('/<script\b(?![^>]*\bsrc=)/i',$layout))throw new RuntimeException("{$slug} embeds behavior in its presentation template.");
}
