<?php
require_once __DIR__ . '/sc-config.php';
if (session_status() === PHP_SESSION_NONE) { session_name(SC_SESSION_NAME); session_start(); }
$_SESSION = [];
session_destroy();
header('Location: sc-login.php');
exit;
