<?php
// api/data.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$data = [
  'message' => 'Hello from PHP!',
  'timestamp' => time()
];

echo json_encode($data);