<?php
// upload_philhealth.php
ini_set('display_errors', 0);
error_reporting(E_ALL);
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require 'db.php'; 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $patient_id = isset($_POST['patient_id']) ? trim($_POST['patient_id']) : '';
    $tenant_id = isset($_POST['tenant_id']) ? trim($_POST['tenant_id']) : '';
    
    // Catch both Base64 strings from Android
    $front_base64 = isset($_POST['front_id_base64']) ? $_POST['front_id_base64'] : '';
    $back_base64 = isset($_POST['back_id_base64']) ? $_POST['back_id_base64'] : '';

    if (empty($patient_id) || empty($tenant_id) || empty($front_base64) || empty($back_base64)) {
        echo json_encode(["status" => "error", "message" => "Missing required fields or images."]);
        exit();
    }

    try {
        // 1. Create the PhilHealth folder securely if it doesn't exist
        $dir = "uploads/philhealth/";
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }

        // 2. Decode and save Front ID
        $front_data = base64_decode($front_base64);
        $front_filename = "PHIL_FRONT_" . time() . "_" . uniqid() . ".jpg";
        file_put_contents($dir . $front_filename, $front_data);

        // 3. Decode and save Back ID
        $back_data = base64_decode($back_base64);
        $back_filename = "PHIL_BACK_" . time() . "_" . uniqid() . ".jpg";
        file_put_contents($dir . $back_filename, $back_data);

        // 4. Update the database! Status becomes 'Yes'
        $stmt = $pdo->prepare("
            UPDATE patients 
            SET philhealth_status = 'Yes', 
                philhealth_id_pic_front = :front_pic, 
                philhealth_id_pic_back = :back_pic 
            WHERE patient_id = :pid AND TenantID = :tenant
        ");

        $stmt->execute([
            'front_pic' => $front_filename,
            'back_pic' => $back_filename,
            'pid' => $patient_id,
            'tenant' => $tenant_id
        ]);

        if ($stmt->rowCount() > 0) {
            echo json_encode(["status" => "success", "message" => "PhilHealth IDs uploaded successfully!"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Failed to update database. Patient not found."]);
        }

    } catch(PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}
$pdo = null;
?>