<?php

$api = (string)file_get_contents(__DIR__ . '/../core/multisite-api.php');

if (!str_contains($api, '$_heartbeat_skins = snapsmack_skins_for_distribution($_heartbeat_skins);')) {
    throw new RuntimeException('Fleet heartbeat does not apply the FEDISTRUCTURE skin boundary.');
}

if (!str_contains($api, 'foreach ($_heartbeat_skins as $_slug => $_meta)')) {
    throw new RuntimeException('Fleet heartbeat still reports the unfiltered local skin inventory.');
}

echo "fedistructure ONYX fleet boundary regression passed\n";
