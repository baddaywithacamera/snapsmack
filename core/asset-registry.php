<?php
declare(strict_types=1);

/** Canonical registry for every shared executable/style asset. */
function snapsmack_asset_registry(): array
{
    static $registry=null;
    if($registry!==null)return $registry;
    $catalog=json_decode((string)file_get_contents(dirname(__DIR__).'/assets/ASSET-INVENTORY.json'),true);
    $registry=[];
    foreach(['javascript','css'] as $group)foreach(($catalog[$group]??[]) as $entry){
        if(!is_array($entry)||!is_string($entry['file']??null)||!is_string($entry['scope']??null))continue;
        $handle='asset:'.$entry['scope'].':'.pathinfo($entry['file'],PATHINFO_FILENAME);
        $registry[$handle]=['path'=>$entry['file'],'scope'=>$entry['scope'],'type'=>$group==='javascript'?'script':'style'];
    }
    return $registry;
}

function snapsmack_asset_by_handle(string $handle): ?array
{
    return snapsmack_asset_registry()[$handle]??null;
}

/** Resolve a skin's declarative legacy handles to bounded same-origin asset URLs. */
function snapsmack_skin_declared_assets(array $manifest): array
{
    $inventory=require __DIR__.'/manifest-inventory.php';$base=defined('BASE_URL')?rtrim((string)BASE_URL,'/').'/':'/';$scripts=[];$styles=[];
    foreach(($manifest['require_scripts']??[]) as $handle){$entry=$inventory['scripts'][$handle]??null;if(!is_array($entry))continue;if(is_string($entry['path']??null))$scripts[]=$base.ltrim($entry['path'],'/');if(is_string($entry['css']??null))$styles[]=$base.ltrim($entry['css'],'/');}
    foreach(($manifest['require_styles']??[]) as $handle){$entry=snapsmack_asset_by_handle((string)$handle);if(($entry['scope']??'')==='public'&&($entry['type']??'')==='style')$styles[]=$base.ltrim($entry['path'],'/');}
    return ['scripts'=>array_values(array_unique($scripts)),'styles'=>array_values(array_unique($styles))];
}
