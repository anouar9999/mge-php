case 'POST':
            error_log('Processing team request with data: ' . json_encode($requestBody));

            if (!isset($requestBody['request_id'], $requestBody['action'])) {
                sendResponse(false, null, 'Missing required fields', 400);
            }

            $pdo->beginTransaction();
            try {
                // Get request details
                $requestQuery = "
                    SELECT tjr.*, u.avatar
                    FROM team_join_requests tjr
                    LEFT JOIN users u ON tjr.name = u.username
                    WHERE tjr.id = ? AND tjr.status = 'pending'
                ";
                $requestStmt = $pdo->prepare($requestQuery);
                $requestStmt->execute([$requestBody['request_id']]);
                $request = $requestStmt->fetch(PDO::FETCH_ASSOC);

                if (!$request) {
                    throw new Exception('Request not found or already processed');
                }
                
                error_log('Found request details: ' . json_encode($request));

                if ($requestBody['action'] === 'accepted') {
                    // Check if already a member
                    $memberCheckQuery = "
                        SELECT id FROM team_members
                        WHERE team_id = ? AND name = ?
                    ";
                    $memberCheckStmt = $pdo->prepare($memberCheckQuery);
                    $memberCheckStmt->execute([$request['team_id'], $request['name']]);

                    if ($memberCheckStmt->rowCount() > 0) {
                        throw new Exception('User is already a team member');
                    }

                    // Add to team members
                    $addMemberQuery = "
                        INSERT INTO team_members (
                            team_id,
                            name,
                            role,
                            `rank`,
                            status,
                            created_at
                        ) VALUES (?, ?, ?, ?, 'online', NOW())
                        ";

$memberParams = [
    $request['team_id'],
    $request['name'],
    $request['role'],
    $request['rank']
];

error_log('Adding member with params: ' . json_encode($memberParams));

$addMemberStmt = $pdo->prepare($addMemberQuery);
if (!$addMemberStmt->execute($memberParams)) {
    error_log('SQL Error: ' . json_encode($addMemberStmt->errorInfo()));
    throw new Exception('Failed to add member to team');
}
}

// Update request status
$status = $requestBody['action'] === 'accepted' ? 'accepted' : 'rejected';
$updateQuery = "
UPDATE team_join_requests
SET status = ?
WHERE id = ?
";
$updateStmt = $pdo->prepare($updateQuery);

if (!$updateStmt->execute([$status, $requestBody['request_id']])) {
                    error_log('SQL Error: ' . json_encode($updateStmt->errorInfo()));
                    throw new Exception('Failed to update request status');
                }

                $pdo->commit();
                sendResponse(true, null, 'Request ' . $status . ' successfully');

            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('Error in request processing: ' . $e->getMessage());
                sendResponse(false, null, $e->getMessage(), 500);
            }
            break;

        default:
            sendResponse(false, null, 'Method not allowed', 405);
    }