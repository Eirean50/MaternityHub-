<?php
// fetch_pending_feedback.php
ini_set('display_errors', 0);
error_reporting(E_ALL);
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

date_default_timezone_set('Asia/Manila');
require 'db.php'; 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $patient_id = isset($_POST['patient_id']) ? trim($_POST['patient_id']) : '';
    $tenant_id = isset($_POST['tenant_id']) ? trim($_POST['tenant_id']) : '';

    if (empty($tenant_id) || empty($patient_id)) {
        echo json_encode(["status" => "error", "message" => "Missing credentials."]);
        exit();
    }

    try {
        // ✨ THE BRILLIANT FIX: Automatically add your new have_feedback column to the database!
        try {
            $pdo->exec("ALTER TABLE admissions ADD COLUMN have_feedback INT(1) DEFAULT 0");
        } catch (PDOException $e) {
            // Ignore if it already exists
        }

        // Strictly read from admissions where Discharged AND have_feedback is 0
        $sql = "SELECT * FROM admissions 
                WHERE TenantID = :tenant 
                AND patient_id = :pid 
                AND (LOWER(status) = 'discharged' OR LOWER(stage) = 'discharged')
                AND (have_feedback = 0 OR have_feedback IS NULL)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['tenant' => $tenant_id, 'pid' => $patient_id]);
        $admissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pending = [];

        foreach ($admissions as $adm) {
            $raw_date = !empty($adm['discharge_date']) ? $adm['discharge_date'] : ($adm['admission_date'] ?? date('Y-m-d'));
            $display_date = date('F j, Y', strtotime($raw_date));
            $reason = trim($adm['reason'] ?? 'General Admission');
            
            $doctor = trim($adm['assigned_staff'] ?? 'General Staff');
            if (empty($doctor)) $doctor = 'General Staff';

            $pending[] = [
                "appointment_id" => $adm['id'], // We send the exact ID of the admission!
                "raw_timestamp" => strtotime($raw_date),
                "display_date" => strtoupper($display_date),
                "service_name" => $reason,
                "doctor_name" => $doctor
            ];
        }

        // Sort newest first
        usort($pending, function($a, $b) {
            return $b['raw_timestamp'] <=> $a['raw_timestamp'];
        });

        if (count($pending) > 0) {
            echo json_encode(["status" => "success", "data" => array_values($pending)]);
        } else {
            echo json_encode(["status" => "empty", "message" => "No pending reviews found."]);
        }

    } catch(PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Database Crash: " . $e->getMessage()]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}
$pdo = null;
?>