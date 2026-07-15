<?php
// verify_email_change.php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

date_default_timezone_set('Asia/Manila');
require 'db.php'; 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    $patient_id = isset($_POST['patient_id']) ? trim($_POST['patient_id']) : '';
    $tenant_id  = isset($_POST['tenant_id']) ? trim($_POST['tenant_id']) : '';
    $new_email  = isset($_POST['new_email']) ? trim($_POST['new_email']) : '';
    $otp_code   = isset($_POST['otp_code']) ? trim($_POST['otp_code']) : '';

    if (empty($patient_id) || empty($tenant_id) || empty($new_email) || empty($otp_code)) {
        echo json_encode(["status" => "error", "message" => "Missing required fields."]);
        exit();
    }

    try {
        // 1. ✨ THE FIX: Check BOTH the numeric ID and the string patient_id
        $stmt = $pdo->prepare("
            SELECT id 
            FROM patients 
            WHERE (id = :pid OR patient_id = :pid) 
            AND TenantID = :tenant 
            AND verification_code = :code 
            LIMIT 1
        ");
        
        $stmt->execute([
            'pid' => $patient_id,
            'tenant' => $tenant_id,
            'code' => $otp_code
        ]);

        if ($stmt->rowCount() > 0) {
            // 2. ✨ THE FIX: Apply the same bulletproof check to the update command
            $updateStmt = $pdo->prepare("
                UPDATE patients 
                SET email_address = :email, 
                    verification_code = NULL 
                WHERE (id = :pid OR patient_id = :pid) 
                AND TenantID = :tenant
            ");
            
            $updateStmt->execute([
                'email' => $new_email,
                'pid' => $patient_id,
                'tenant' => $tenant_id
            ]);

            echo json_encode(["status" => "success", "message" => "Email address updated successfully!"]);
            
        } else {
            // 3. Code is actually wrong (or expired)
            echo json_encode(["status" => "error", "message" => "Invalid or expired verification code."]);
        }

    } catch(PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}

$pdo = null;
?>