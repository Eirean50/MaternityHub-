<?php
// fetch_feedback_history.php
ini_set('display_errors', 0);
error_reporting(E_ALL);
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

date_default_timezone_set('Asia/Manila');
require 'db.php'; 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $patient_id = isset($_POST['patient_id']) ? trim($_POST['patient_id']) : '';
    $tenant_id = isset($_POST['tenant_id']) ? trim($_POST['tenant_id']) : '';
    // Adding patient_name as a fallback just in case some older reviews don't have the ID saved yet!
    $patient_name = isset($_POST['patient_name']) ? trim($_POST['patient_name']) : ''; 

    if (empty($tenant_id) || empty($patient_id)) {
        echo json_encode(["status" => "error", "message" => "Missing credentials."]);
        exit();
    }

    try {
        // Fetch the feedback history for this specific patient
        // ✨ THE FIX: Changed 'patient_name' to 'full_name' to perfectly match your database schema!
        // We order by 'id DESC' so the most recent reviews stay at the top of the list
        $sql = "SELECT * FROM feedbacks 
                WHERE TenantID = :tenant 
                AND (patient_id = :pid OR full_name = :pname)
                ORDER BY id DESC"; 

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'tenant' => $tenant_id,
            'pid' => $patient_id,
            'pname' => $patient_name
        ]);
        
        $feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $historyList = [];

        foreach ($feedbacks as $fb) {
            // Safely parse the date. If your DB uses 'created_at', we use that. 
            // If the column doesn't exist yet, we default to today's date so the app doesn't crash.
            $raw_date = $fb['created_at'] ?? $fb['date_submitted'] ?? date('Y-m-d'); 
            $display_date = strtoupper(date('M d, Y', strtotime($raw_date))); // Uppercase for sleek UI

            $historyList[] = [
                "display_date" => $display_date,
                "service_name" => trim($fb['service_name'] ?? 'General Service'),
                
                // ✨ SAFETY FALLBACK: If it's an old review, it uses the old 'rating' column!
                "service_rating" => (float)($fb['service_rating'] ?? $fb['rating'] ?? 0), 
                "staff_rating" => (float)($fb['staff_rating'] ?? $fb['rating'] ?? 0), // Fallback staff rating to main rating too
                
                "comments" => trim($fb['comments'] ?? ''),
                "is_anonymous" => (int)($fb['is_anonymous'] ?? 0)
            ];
        }

        if (count($historyList) > 0) {
            echo json_encode(["status" => "success", "data" => $historyList]);
        } else {
            echo json_encode(["status" => "empty", "message" => "No feedback history found."]);
        }

    } catch(PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}
$pdo = null;
?>