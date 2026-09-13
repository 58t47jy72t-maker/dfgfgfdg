<?php
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'registered' => true,
    'success' => true,
    'sn' => $_GET['sn'] ?? ''
]);
