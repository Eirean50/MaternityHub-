<?php
// submit_feedback.php
ini_set('display_errors', 0);
error_reporting(E_ALL);
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

date_default_timezone_set('Asia/Manila');
require 'db.php'; 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $tenant_id = isset($_POST['tenant_id']) ? trim($_POST['tenant_id']) : '';
    $patient_id = isset($_POST['patient_id']) ? trim($_POST['patient_id']) : '';
    $appointment_id = isset($_POST['appointment_id']) ? trim($_POST['appointment_id']) : ''; // This is now the admissions ID
    
    $patient_name = isset($_POST['patient_name']) ? trim($_POST['patient_name']) : '';
    $service_name = isset($_POST['service_name']) ? trim($_POST['service_name']) : '';
    
    $service_rating = isset($_POST['service_rating']) ? (int)trim($_POST['service_rating']) : 5;
    $staff_rating = isset($_POST['staff_rating']) ? (int)trim($_POST['staff_rating']) : 5;
    
    // ✨ THE FIX: Catch the new Doctor/Midwife rating from Android
    $doctor_rating = isset($_POST['doctor_rating']) ? (int)trim($_POST['doctor_rating']) : 5;
    
    $comments = isset($_POST['comments']) ? trim($_POST['comments']) : '';
    $is_anonymous = isset($_POST['is_anonymous']) ? trim($_POST['is_anonymous']) : '0';

    if (empty($tenant_id) || empty($appointment_id)) {
        echo json_encode(["status" => "error", "message" => "Missing required data."]);
        exit();
    }

    try {
        // Ensure columns exist in feedbacks table (Failsafe)
        try {
            $pdo->exec("ALTER TABLE feedbacks ADD COLUMN appointment_id VARCHAR(50) DEFAULT NULL AFTER patient_id");
            $pdo->exec("ALTER TABLE feedbacks ADD COLUMN service_rating INT(11) DEFAULT 5 AFTER rating");
            $pdo->exec("ALTER TABLE feedbacks ADD COLUMN staff_rating INT(11) DEFAULT 5 AFTER service_rating");
            $pdo->exec("ALTER TABLE feedbacks ADD COLUMN doctor_rating INT(11) DEFAULT 0 AFTER staff_rating");
        } catch (PDOException $e) {}

        // ✨ THE FIX: Update the average calculation to include all 3 ratings!
        $avg_rating = round(($service_rating + $staff_rating + $doctor_rating) / 3);

        // 1. Save the stars and comments to the feedbacks table
        $sql = "INSERT INTO feedbacks (TenantID, patient_id, appointment_id, full_name, service_name, rating, service_rating, staff_rating, doctor_rating, comments, is_anonymous) 
                VALUES (:tenant, :pid, :appid, :pname, :service, :avg_rating, :service_rating, :staff_rating, :doctor_rating, :comments, :is_anon)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'tenant' => $tenant_id,
            'pid' => $patient_id,
            'appid' => $appointment_id,
            'pname' => $patient_name,
            'service' => $service_name,
            'avg_rating' => $avg_rating,
            'service_rating' => $service_rating,
            'staff_rating' => $staff_rating,
            'doctor_rating' => $doctor_rating, // ✨ Bind the new value!
            'comments' => $comments,
            'is_anon' => $is_anonymous
        ]);

        // 2. UPDATE THE ADMISSIONS TABLE TO 1
        $stmtUpd = $pdo->prepare("UPDATE admissions SET have_feedback = 1 WHERE id = :appid AND TenantID = :tenant");
        $stmtUpd->execute(['appid' => $appointment_id, 'tenant' => $tenant_id]);

        echo json_encode(["status" => "success", "message" => "Feedback submitted successfully!"]);

    } catch(PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}
$pdo = null;
?>