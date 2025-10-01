<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, Cache-Control");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
   http_response_code(204);
   exit();
}

$db_config = require 'db_config.php';
try {
   $conn = new PDO(
       "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
       $db_config['user'],
       $db_config['pass'],
   );
   $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
   http_response_code(500);
   echo json_encode(["message" => "Database connection failed: " . $e->getMessage()]);
   exit();
}

// Optimized check if admin table is empty
function isAdminsTableEmpty($conn) {
   $stmt = $conn->prepare("SELECT 1 FROM admin LIMIT 1");
   $stmt->execute();
   return $stmt->rowCount() === 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
   // Check if admin table is empty
   $isEmpty = isAdminsTableEmpty($conn);
   echo json_encode(["isEmpty" => $isEmpty]);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
   // If admin table is not empty, deny access
   if (!isAdminsTableEmpty($conn)) {
       http_response_code(403);
       echo json_encode(["message" => "Admin already exists. Cannot create a new admin."]);
       exit();
   }

   // Process the POST request to create an admin
   $data = json_decode(file_get_contents("php://input"));

   if (!isset($data->username) || !isset($data->password) || !isset($data->email) || !isset($data->full_name)) {
       http_response_code(400);
       echo json_encode(["message" => "Missing required fields"]);
       exit();
   }

   $username = $data->username;
   $password = password_hash($data->password, PASSWORD_DEFAULT);
   $email = $data->email;
   $full_name = $data->full_name;

   $stmt = $conn->prepare("INSERT INTO admin (username, password, email, full_name) VALUES (:username, :password, :email, :full_name)");
   $stmt->bindParam(":username", $username);
   $stmt->bindParam(":password", $password);
   $stmt->bindParam(":email", $email);
   $stmt->bindParam(":full_name", $full_name);

   if ($stmt->execute()) {
       http_response_code(201);
       echo json_encode(["message" => "Admin created successfully"]);
   } else {
       http_response_code(500);
       echo json_encode(["message" => "Failed to create admin"]);
   }
} else {
   http_response_code(405);
   echo json_encode(["message" => "Method not allowed"]);
}