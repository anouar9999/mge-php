<?php
// CORS Test File - Place this in your /var/www/mge-php/api/ directory
header("Access-Control-Allow-Origin: https://user.mgexpo.ma");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

echo json_encode(['success' => true, 'message' => 'CORS is working!']);
?>
