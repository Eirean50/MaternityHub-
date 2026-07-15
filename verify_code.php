<?php
// verify_code.php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require 'db.php'; 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // 1. Catch the data sent from the Android App (Global - no tenant_id needed!)
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $code = isset($_POST['code']) ? trim($_POST['code']) : '';

    // 2. Removed the empty($tenant_id) check!
    if (empty($email) || empty($code)) {
        echo json_encode(array("status" => "error", "message" => "Missing required information."));
        exit();
    }

    try {
        // 3. Check if the code matches the specific patient globally (using ONLY email and code)
        $stmt = $pdo->prepare("SELECT id FROM patients WHERE email_address = :email AND verification_code = :code");
        $stmt->execute(['email' => $email, 'code' => $code]);

        if ($stmt->rowCount() > 0) {
            // 4. Code is correct! Update the database to mark them as verified.
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
            $patient_db_id = $patient['id'];

            // We set is_email_verified to 1, and clear the verification code so it can't be used again
            $updateStmt = $pdo->prepare("UPDATE patients SET is_email_verified = 1, verification_code = NULL WHERE id = :id");
            $updateStmt->execute(['id' => $patient_db_id]);

            echo json_encode(array("status" => "success", "message" => "Account successfully verified!"));
        } else {
            // Code was wrong
            echo json_encode(array("status" => "error", "message" => "Invalid verification code. Please try again."));
        }

    } catch(PDOException $e) {
        echo json_encode(array("status" => "error", "message" => "Database error: " . $e->getMessage()));
    }

} else {
    echo json_encode(array("status" => "error", "message" => "Invalid request method."));
}

$pdo = null;
?>