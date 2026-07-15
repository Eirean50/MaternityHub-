<?php
// update_password.php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
require 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $patient_id = $_POST['patient_id'] ?? '';
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';

    if (empty($patient_id) || empty($current_password) || empty($new_password)) {
        echo json_encode(["status" => "error", "message" => "Missing required fields."]);
        exit();
    }

    try {
        // ✨ BUG FIX: Changed 'id' to 'patient_id' to match what the Android app sends!
        $stmt = $pdo->prepare("SELECT password FROM patients WHERE patient_id = :pid LIMIT 1");
        $stmt->execute(['pid' => $patient_id]);

        if ($stmt->rowCount() > 0) {
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Verify that the "Current Password" they typed actually matches what we have saved
            if (password_verify($current_password, $user['password'])) {
                
                // It matches! Hash the NEW password and update the database
                $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);

                // ✨ BUG FIX: Also changed 'id' to 'patient_id' here!
                $updateStmt = $pdo->prepare("UPDATE patients SET password = :newpass WHERE patient_id = :pid");
                if ($updateStmt->execute(['newpass' => $hashed_new_password, 'pid' => $patient_id])) {
                    echo json_encode(["status" => "success", "message" => "Password updated!"]);
                } else {
                    echo json_encode(["status" => "error", "message" => "Failed to update password."]);
                }

            } else {
                // The current password they typed was wrong
                echo json_encode(["status" => "error", "message" => "Incorrect current password."]);
            }
        } else {
            echo json_encode(["status" => "error", "message" => "User not found."]);
        }
    } catch(PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}
$pdo = null;
?>