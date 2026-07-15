<?php
// Turn on Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

require 'db.php'; // Ensure this matches your DB file

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $patient_id = $_POST['patient_id'] ?? '';
    $image_base64 = $_POST['image'] ?? '';

    if (empty($patient_id) || empty($image_base64)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing data.']);
        exit;
    }

    // 1. Check folder permissions safely
    $upload_dir = 'uploads/profile_pics/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    // 2. Safer Base64 decoding
    if (strpos($image_base64, ',') !== false) {
        $image_parts = explode(',', $image_base64);
        $image_base64 = $image_parts[1];
    }

    $image_data = base64_decode($image_base64);
    if ($image_data === false) {
        echo json_encode(['status' => 'error', 'message' => 'Base64 decode failed on server.']);
        exit;
    }

    $file_name = 'patient_' . $patient_id . '_' . time() . '.jpg';
    $file_path = $upload_dir . $file_name;

    // 3. Save the file to your server folder
    if (file_put_contents($file_path, $image_data)) {
        
        $full_url = "https://maternityhub.alwaysdata.net/" . $file_path;
        
        // 4. Update Database securely using PDO!
        try {
            $stmt = $pdo->prepare("UPDATE patients SET profile_pic_url = ? WHERE patient_id = ?");
            
            // PDO uses execute with an array of values instead of bind_param
            if ($stmt->execute([$full_url, $patient_id])) {
                echo json_encode(['status' => 'success', 'message' => 'Profile picture updated!', 'url' => $full_url]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'DB Update Failed.']);
            }
        } catch (PDOException $e) {
            // Catch any PDO specific errors
            echo json_encode(['status' => 'error', 'message' => 'SQL Error: ' . $e->getMessage()]);
        }
        
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to write image file to FileZilla folder. Check 777 permissions.']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
}
?>