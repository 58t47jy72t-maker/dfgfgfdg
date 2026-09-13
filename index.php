<?php
header('Content-Type: application/json; charset=utf-8');
$prd = $_GET['prd'] ?? 'iPhone';
$prd = str_replace(',', '-', $prd);
$version = $_GET['ios_version'] ?? '18.0';
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$baseUrl = "$protocol://{$_SERVER['HTTP_HOST']}";
echo json_encode([
    'success' => true,
    'links' => [
        'step1_fixedfile' => "$baseUrl/Maker/$prd/$version/com.apple.mobilegestalt.plist"
    ]
]);
