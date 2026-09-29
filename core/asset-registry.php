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
