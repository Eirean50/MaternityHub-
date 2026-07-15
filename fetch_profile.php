<?php
// fetch_profile.php
ini_set('display_errors', 0);
error_reporting(E_ALL);
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require 'db.php'; 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $patient_id = isset($_POST['patient_id']) ? trim($_POST['patient_id']) : '';

    if (empty($patient_id)) {
        echo json_encode(array("status" => "error", "message" => "Missing Patient ID."));
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM patients WHERE patient_id = :pid LIMIT 1");
        $stmt->execute(['pid' => $patient_id]);
        
        if ($stmt->rowCount() > 0) {
            $profileData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // ✨ SECURITY: Remove the hashed password so hackers can't intercept it!
            unset($profileData['password']);

            // ✨ THE FIX: Automatically convert all relative database paths to absolute Web URLs!
            $baseUrl = "https://maternityhub.alwaysdata.net/";

            // 1. Format Profile Picture URL
            if (!empty($profileData['profile_pic_url']) && strpos($profileData['profile_pic_url'], 'http') !== 0) {
                $cleanPath = ltrim($profileData['profile_pic_url'], '/');
                $profileData['profile_pic_url'] = $baseUrl . $cleanPath;
            }

            // 2. Format PhilHealth Front ID URL
            $front = !empty($profileData['philhealth_id_pic_front']) ? $profileData['philhealth_id_pic_front'] : '';
            if ($front !== '' && strpos($front, 'http') !== 0) {
                $front = $baseUrl . ltrim($front, '/');
            }
            $profileData['philhealth_id_pic_front'] = $front;

            // 3. Format PhilHealth Back ID URL
            $back = !empty($profileData['philhealth_id_pic_back']) ? $profileData['philhealth_id_pic_back'] : '';
            if ($back !== '' && strpos($back, 'http') !== 0) {
                $back = $baseUrl . ltrim($back, '/');
            }
            $profileData['philhealth_id_pic_back'] = $back;

            // ✨ CRASH PREVENTION: Ensure status and optional dates are never NULL
            $profileData['philhealth_status'] = !empty($profileData['philhealth_status']) ? $profileData['philhealth_status'] : 'No';
            $profileData['last_menstrual_period'] = !empty($profileData['last_menstrual_period']) ? $profileData['last_menstrual_period'] : '';
            $profileData['delivery_date'] = !empty($profileData['delivery_date']) ? $profileData['delivery_date'] : '';

            echo json_encode(array(
                "status" => "success", 
                "message" => "Profile data loaded.",
                "data" => $profileData
            ));
        } else {
            echo json_encode(array("status" => "error", "message" => "Patient record not found."));
        }

    } catch(PDOException $e) {
        echo json_encode(array("status" => "error", "message" => "Database error: " . $e->getMessage()));
    }
} else {
    echo json_encode(array("status" => "error", "message" => "Invalid request method."));
}
$pdo = null;
?>