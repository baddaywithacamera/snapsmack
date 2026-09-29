<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/core/skin-view-contract.php';

$response = [
    'status' => 200, 'kind' => 'post', 'item' => ['id' => 7, 'title' => 'Public'],
    'pdo' => 'secret', 'settings' => ['secret' => 'no'], 'request' => ['token' => 'no'],
];
$presentation = ['site_name' => 'Example', 'base_url' => '/', 'database_password' => 'no'];
$view = snapsmack_build_skin_view($response, $presentation);
if (isset($view['response']['pdo'], $view['response']['settings'], $view['response']['request'])
    || isset($view['site']['database_password'])) {
    throw new RuntimeException('Broad authority leaked into the view model.');
}
$reason = null;
if (!snapsmack_validate_skin_view($view, $reason)) throw new RuntimeException('Valid view model was rejected.');
$bad = $view; $bad['version'] = 999;
if (snapsmack_validate_skin_view($bad, $reason)) throw new RuntimeException('Unknown view-model version was accepted.');
$bad = $view; $bad['response']['object'] = new stdClass();
if (snapsmack_validate_skin_view($bad, $reason)) throw new RuntimeException('Object authority entered the view model.');
$fallback = snapsmack_safe_fallback_view();
if (($fallback['response']['kind'] ?? '') !== 'safe_fallback') throw new RuntimeException('Safe fallback model is absent.');

$source = (string)file_get_contents(dirname(__DIR__) . '/core/skin-view-contract.php');
foreach (['$pdo', '$_GET', '$_POST', '$_REQUEST', '$_SERVER', '$settings'] as $forbidden) {
    if (str_contains($source, $forbidden)) throw new RuntimeException("View contract owns forbidden ambient authority: {$forbidden}");
}
echo "Bounded skin view-model contract regression passed.\n";
