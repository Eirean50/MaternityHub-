<?php
// reset_password.php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require 'db.php'; 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $code = isset($_POST['code']) ? trim($_POST['code']) : '';
    $new_password = isset($_POST['new_password']) ? $_POST['new_password'] : '';

    if (empty($email) || empty($code) || empty($new_password)) {
        echo json_encode(array("status" => "error", "message" => "Missing required fields."));
        exit();
    }

    try {
        // ✨ BUG FIX: We are now looking at your existing `verification_code` column!
        $stmt = $pdo->prepare("
            SELECT id 
            FROM patients 
            WHERE email_address = :email 
            AND verification_code = :code 
        ");
        $stmt->execute(['email' => $email, 'code' => $code]);

        if ($stmt->rowCount() > 0) {
            
            // The code is correct! Hash the new password securely.
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

            // Update the password and clear the verification_code so it can't be reused
            $updateStmt = $pdo->prepare("
                UPDATE patients 
                SET password = :newpass, 
                    verification_code = NULL 
                WHERE email_address = :email
            ");
            
            $updateStmt->execute([
                'newpass' => $hashed_password,
                'email' => $email
            ]);

            echo json_encode(array("status" => "success", "message" => "Password updated successfully."));
            
        } else {
            // They typed the wrong 6-digit code
            echo json_encode(array("status" => "error", "message" => "Invalid reset code. Please check your email and try again."));
        }

    } catch(PDOException $e) {
        echo json_encode(array("status" => "error", "message" => "Database error: " . $e->getMessage()));
    }

} else {
    echo json_encode(array("status" => "error", "message" => "Invalid request method."));
}

$pdo = null;
?>