<?php
// cancel_appointment.php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
require 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $patient_id = $_POST['patient_id'] ?? '';
    $tenant_id = $_POST['tenant_id'] ?? '';
    $raw_date = $_POST['appointment_date'] ?? '';
    $raw_time = $_POST['appointment_time'] ?? ''; // e.g., "3:00 PM - 4:00 PM"

    if (empty($patient_id) || empty($tenant_id) || empty($raw_date) || empty($raw_time)) {
        echo json_encode(["status" => "error", "message" => "Missing appointment details."]);
        exit();
    }

    try {
        // Translate the date ("April 30, 2026" -> "2026-04-30")
        $db_formatted_date = date('Y-m-d', strtotime($raw_date));
        
        // ✨ THE FIX: Slice the string to get the start time, then convert to 24-hour database format!
        $time_parts = explode('-', $raw_time);
        $start_time_raw = trim($time_parts[0]); // Extracts just "3:00 PM"
        $db_formatted_time = date('H:i:s', strtotime($start_time_raw)); // Converts to "15:00:00"

        // We also added "AND status != 'Cancelled'" to prevent ghost updates
        $stmt = $pdo->prepare("
            UPDATE appointments 
            SET status = 'Cancelled' 
            WHERE patient_id = :pid 
            AND TenantID = :tenant 
            AND appointment_date = :date 
            AND appointment_time = :time
            AND status != 'Cancelled'
        ");

        $stmt->execute([
            'pid' => $patient_id, 
            'tenant' => $tenant_id, 
            'date' => $db_formatted_date, 
            'time' => $db_formatted_time
        ]);

        if ($stmt->rowCount() > 0) {
            echo json_encode(["status" => "success", "message" => "Appointment cancelled successfully."]);
        } else {
            echo json_encode(["status" => "error", "message" => "Failed to cancel. Appointment not found or already cancelled."]);
        }
    } catch(PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}
$pdo = null;
?>