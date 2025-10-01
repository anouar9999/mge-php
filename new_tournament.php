<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Function to safely encode JSON
function safe_json_encode($data)
{
    return json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');

// Only process POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo safe_json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

// Log received data for debugging
error_log("POST data: " . print_r($_POST, true));
error_log("FILES data: " . print_r($_FILES, true));

$db_config = require 'db_config.php';

try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Get form data
    $data = $_POST;
    
    // Fix for field names mismatch between frontend and backend
    // Map frontend field names to expected backend field names
    if (isset($data['name']) && !isset($data['nom_des_qualifications'])) {
        $data['nom_des_qualifications'] = $data['name'];
    }
    
    if (isset($data['description']) && !isset($data['description_des_qualifications'])) {
        $data['description_des_qualifications'] = $data['description'];
    }
    
    if (isset($data['bracket_type']) && !isset($data['format_des_qualifications'])) {
        $data['format_des_qualifications'] = $data['bracket_type'];
    }
    
    // Set default value for participation_type
    if (!isset($data['participation_type']) || empty($data['participation_type'])) {
        $data['participation_type'] = 'individual';
    }
    
    // Map old participation_type values to new ones
    if ($data['participation_type'] === 'participant') {
        $data['participation_type'] = 'individual';
    }
    
    // Validate participation_type
    $valid_participation_types = ['individual', 'team'];
    if (!in_array($data['participation_type'], $valid_participation_types)) {
        throw new Exception("Type de participation invalide. Doit être 'individual' ou 'team'");
    }

    // Validate required fields
    $required_fields = [
        'nom_des_qualifications', // will be mapped to name
        'nombre_maximum', // will be mapped to max_participants
        'start_date',
        'end_date',
        'competition_type' // will be mapped to game_id
    ];

    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            throw new Exception("Le champ {$field} est obligatoire");
        }
    }

    // Map old status values to new ones
    $status_mapping = [
        'Ouvert aux inscriptions' => 'registration_open',
        'En cours' => 'ongoing',
        'Terminé' => 'completed',
        'Annulé' => 'cancelled'
    ];
    
    $status = isset($data['status']) && isset($status_mapping[$data['status']]) 
        ? $status_mapping[$data['status']] 
        : 'registration_open'; // Default to registration_open

    // Validate maximum number
    if (!is_numeric($data['nombre_maximum'])) {
        throw new Exception("Le nombre maximum doit être un nombre");
    }
    
    if ($data['nombre_maximum'] < 2) {
        $type = $data['participation_type'] === 'team' ? "d'équipes" : "de participants";
        throw new Exception("Le nombre minimum $type doit être de 2");
    }

    // Validate dates
    $start_date = new DateTime($data['start_date']);
    $end_date = new DateTime($data['end_date']);
    if ($end_date < $start_date) {
        throw new Exception("La date de fin doit être postérieure à la date de début");
    }

    // Process registration dates if provided
    $registration_start = null;
    if (isset($data['registration_start']) && !empty($data['registration_start'])) {
        $registration_start = new DateTime($data['registration_start']);
    } else {
        $registration_start = new DateTime(); // Now
    }
    
    $registration_end = null;
    if (isset($data['registration_end']) && !empty($data['registration_end'])) {
        $registration_end = new DateTime($data['registration_end']);
    } else {
        $registration_end = clone $start_date; // Use tournament start date
    }
    
    // Validate registration dates
    if ($registration_end > $start_date) {
        throw new Exception("La période d'inscription doit se terminer avant le début du tournoi");
    }

    // Generate slug from nom_des_qualifications
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $data['nom_des_qualifications'])));

    // Handle file upload for featured_image
    $featured_image = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/tournaments/';
        if (!file_exists($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                throw new Exception("Impossible de créer le répertoire d'upload");
            }
        }

        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $file_type = $_FILES['image']['type'];
        if (!in_array($file_type, $allowed_types)) {
            throw new Exception("Type de fichier invalide. Seuls JPEG, PNG et GIF sont autorisés");
        }

        $file_name = uniqid() . '_' . basename($_FILES['image']['name']);
        $upload_path = $upload_dir . $file_name;

        if (!move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
            throw new Exception("Échec de l'upload de l'image");
        }

        $featured_image = '/uploads/tournaments/' . $file_name;
    }

    // Convert bracket type to new format

    if (isset($data['format_des_qualifications'])) {
        switch ($data['format_des_qualifications']) {
            case 'Single Elimination':
                $bracket_type = 'Single Elimination';
                break;
            case 'Double Elimination':
                $bracket_type = 'Double Elimination';
                break;
            case 'Round Robin':
                $bracket_type = 'Round Robin';
                break;
            case 'Battle Royale':
                $bracket_type = 'Battle Royale'; // Map to Single Elimination as Battle Royale is not in the enum
                break;
            default:
                $bracket_type = 'Single Elimination';
        }
    } elseif (isset($data['bracket_type'])) {
        // Use bracket_type if format_des_qualifications is not set
        switch ($data['bracket_type']) {
            case 'Single Elimination':
                $bracket_type = 'Single Elimination';
                break;
            case 'Double Elimination':
                $bracket_type = 'Double Elimination';
                break;
            case 'Round Robin':
                $bracket_type = 'Round Robin';
                break;
            default:
                $bracket_type = 'Single Elimination';
        }
    }

    // Get game_id from games table based on competition_type
    $game_id = 1; // Default in case not found
    if (isset($data['competition_type'])) {
        $game_query = "SELECT id FROM games WHERE name = :game_name LIMIT 1";
        $game_stmt = $pdo->prepare($game_query);
        $game_stmt->execute([':game_name' => $data['competition_type']]);
        $game_result = $game_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($game_result) {
            $game_id = $game_result['id'];
        } else {
            // If game doesn't exist, create it
            $game_insert = "INSERT INTO games (name, slug) VALUES (:name, :slug)";
            $game_insert_stmt = $pdo->prepare($game_insert);
            $game_slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $data['competition_type'])));
            $game_insert_stmt->execute([
                ':name' => $data['competition_type'],
                ':slug' => $game_slug
            ]);
            $game_id = $pdo->lastInsertId();
        }
    }

    // Organizer ID - assuming admin is the organizer
    $organizer_id = 1; // Default value
    if (isset($_SESSION['admin_id'])) {
        $organizer_id = $_SESSION['admin_id'];
    }

    // Format prize distribution as JSON
    $prize_distribution = null;
    if (isset($data['prize_pool']) && !empty($data['prize_pool']) && $data['prize_pool'] > 0) {
        // Create a default prize distribution based on participant count
        if ($data['nombre_maximum'] >= 8) {
            $prize_distribution = json_encode([
                "1st" => 50,
                "2nd" => 25,
                "3rd-4th" => 10,
                "5th-8th" => 5
            ]);
        } else {
            $prize_distribution = json_encode([
                "1st" => 60,
                "2nd" => 30,
                "3rd" => 10
            ]);
        }
    }

    // Get the highest ID from the tournaments table and increment it
    $id_query = "SELECT MAX(id) as max_id FROM tournaments";
    $id_stmt = $pdo->query($id_query);
    $id_result = $id_stmt->fetch(PDO::FETCH_ASSOC);
    
    $next_id = 1; // Default if table is empty
    if ($id_result && $id_result['max_id'] !== null) {
        $next_id = $id_result['max_id'] + 1;
    }

    // Prepare SQL statement for the new schema with explicit ID
    $sql = "INSERT INTO tournaments (
        id,
        name,
        slug,
        game_id,
        organizer_id,
        description,
        rules,
        bracket_type,
        participation_type,
        max_participants,
        min_team_size,
        max_team_size,
        status,
        registration_start,
        registration_end,
        start_date,
        end_date,
        prize_pool,
        prize_distribution,
        stream_url,
        featured_image,
        match_format,
        password,
        timezone,
        created_at,
        updated_at,
        created_by
    ) VALUES (
        :id,
        :name,
        :slug,
        :game_id,
        :organizer_id,
        :description,
        :rules,
        :bracket_type,
        :participation_type,
        :max_participants,
        :min_team_size,
        :max_team_size,
        :status,
        :registration_start,
        :registration_end,
        :start_date,
        :end_date,
        :prize_pool,
        :prize_distribution,
        :stream_url,
        :featured_image,
        :match_format,
        :password,
        :timezone,
        NOW(),
        NOW(),
        :created_by
    )";

    $stmt = $pdo->prepare($sql);

    // Format numeric values
    $max_participants = filter_var($data['nombre_maximum'], FILTER_VALIDATE_INT);
    $prize_pool = isset($data['prize_pool']) && !empty($data['prize_pool']) ? 
        filter_var($data['prize_pool'], FILTER_VALIDATE_FLOAT) : 0.00;
    
    // Team size values
    $min_team_size = isset($data['min_team_size']) && is_numeric($data['min_team_size']) ? 
        (int)$data['min_team_size'] : 5;
    
    $max_team_size = isset($data['max_team_size']) && is_numeric($data['max_team_size']) ? 
        (int)$data['max_team_size'] : 7;
    
    // Password handling
    $password = isset($data['password']) && !empty($data['password']) ? 
        password_hash($data['password'], PASSWORD_DEFAULT) : null;
    
    // Timezone handling
    $timezone = isset($data['timezone']) && !empty($data['timezone']) ? 
        $data['timezone'] : 'UTC';
    
    // Match format handling
    $match_format = null;
    if (isset($data['match_format']) && !empty($data['match_format'])) {
        $match_format = $data['match_format'];
    } elseif (isset($data['type_de_match']) && !empty($data['type_de_match'])) {
        $match_format = $data['type_de_match'];
    }
    
    // Stream URL handling
    $stream_url = isset($data['stream_url']) && !empty($data['stream_url']) ? 
        $data['stream_url'] : null;

    // Execute statement with data
    $stmt->execute([
        ':id' => $next_id,
        ':name' => $data['nom_des_qualifications'],
        ':slug' => $slug,
        ':game_id' => $game_id,
        ':organizer_id' => $organizer_id,
        ':description' => $data['description_des_qualifications'] ?? null,
        ':rules' => $data['rules'] ?? null,
        ':bracket_type' => $bracket_type,
        ':participation_type' => $data['participation_type'],
        ':max_participants' => $max_participants,
        ':min_team_size' => $min_team_size,
        ':max_team_size' => $max_team_size,
        ':status' => $status,
        ':registration_start' => $registration_start->format('Y-m-d H:i:s'),
        ':registration_end' => $registration_end->format('Y-m-d H:i:s'),
        ':start_date' => $start_date->format('Y-m-d H:i:s'),
        ':end_date' => $end_date->format('Y-m-d H:i:s'),
        ':prize_pool' => $prize_pool,
        ':prize_distribution' => $prize_distribution,
        ':stream_url' => $stream_url,
        ':featured_image' => $featured_image,
        ':match_format' => $match_format,
        ':password' => $password,
        ':timezone' => $timezone,
        ':created_by' => $organizer_id
    ]);

    // Return success response
    echo safe_json_encode([
        'success' => true,
        'message' => 'Tournoi créé avec succès',
        'tournament_id' => $next_id,
        'data' => [
            'slug' => $slug,
            'featured_image' => $featured_image,
            'participation_type' => $data['participation_type'],
            'game_id' => $game_id,
            'max_participants' => $max_participants,
            'registration_start' => $registration_start->format('Y-m-d H:i:s'),
            'registration_end' => $registration_end->format('Y-m-d H:i:s'),
            'start_date' => $start_date->format('Y-m-d H:i:s'),
            'end_date' => $end_date->format('Y-m-d H:i:s')
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    error_log("Database error: " . $e->getMessage());
    echo safe_json_encode([
        'success' => false,
        'message' => 'Erreur de base de données: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    error_log("Application error: " . $e->getMessage());
    echo safe_json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}