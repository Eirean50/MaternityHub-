<?php
// fetch_dashboard.php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

date_default_timezone_set('Asia/Manila');
require 'db.php'; 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $patient_id = isset($_POST['patient_id']) ? trim($_POST['patient_id']) : '';
    $tenant_id = isset($_POST['tenant_id']) ? trim($_POST['tenant_id']) : '';

    if (empty($patient_id) || empty($tenant_id)) {
        echo json_encode(["status" => "error", "message" => "Missing credentials."]);
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM patients WHERE patient_id = :pid AND TenantID = :tenant LIMIT 1");
        $stmt->execute(['pid' => $patient_id, 'tenant' => $tenant_id]);
        
        if ($stmt->rowCount() > 0) {
            $dashboardData = $stmt->fetch(PDO::FETCH_ASSOC);
            $patient_name = trim($dashboardData['full_name'] ?? '');

            // ✨ THE FIX: Updated the array keys to match the newly renamed database column "estimated_delivery_date"
            // If the web admins haven't set it yet, it defaults to an empty string to prevent Android crashes.
            $dashboardData['estimated_delivery_date'] = !empty($dashboardData['estimated_delivery_date']) ? $dashboardData['estimated_delivery_date'] : '';

            $today_manila = date('Y-m-d');

            // ✨ THE FIX: We added the is_admitted = 0 filter so the Home screen ignores admitted patients!
            $stmtAppt = $pdo->prepare("
                SELECT appointment_date, appointment_time, service, payment_type, remaining_balance, assigned_midwife 
                FROM appointments 
                WHERE TenantID = :tenant 
                AND (patient_id = :pid OR full_name LIKE :pname)
                AND appointment_date >= :today 
                AND (is_admitted IS NULL OR is_admitted = 0)
                AND LOWER(status) NOT IN ('cancelled', 'completed', 'finished', 'done', 'discharged', 'missed')
                ORDER BY appointment_date ASC, appointment_time ASC
                LIMIT 1
            ");
            $stmtAppt->execute([
                'tenant' => $tenant_id, 
                'pid' => $patient_id, 
                'pname' => "%" . $patient_name . "%",
                'today' => $today_manila
            ]);

            if ($stmtAppt->rowCount() > 0) {
                $apptData = $stmtAppt->fetch(PDO::FETCH_ASSOC);
                $dashboardData['checkup_date'] = $apptData['appointment_date'];
                $dashboardData['checkup_time'] = $apptData['appointment_time'];
                $dashboardData['service_type'] = $apptData['service'] ?? 'Not Specified';
                $dashboardData['payment_type'] = $apptData['payment_type'] ?? '';
                $dashboardData['remaining_balance'] = $apptData['remaining_balance'] ?? '0';
                $dashboardData['assigned_midwife'] = !empty($apptData['assigned_midwife']) ? trim($apptData['assigned_midwife']) : 'Unassigned';
            } else {
                // Return explicitly empty so the app knows to show "There is no Upcoming Visits"
                $dashboardData['checkup_date'] = "Not Scheduled"; 
                $dashboardData['checkup_time'] = "";
                $dashboardData['service_type'] = "";
                $dashboardData['payment_type'] = "";
                $dashboardData['remaining_balance'] = "";
                $dashboardData['assigned_midwife'] = "";
            }

            $dashboardData['bp'] = "";
            $dashboardData['weight'] = "";
            try {
                $stmtVitals = $pdo->prepare("SELECT bp, weight FROM checkups WHERE patient_id = :pid OR full_name LIKE :pname ORDER BY checkup_date DESC, id DESC LIMIT 1");
                $stmtVitals->execute(['pid' => $patient_id, 'pname' => "%" . $patient_name . "%"]);
                if ($stmtVitals->rowCount() > 0) {
                    $vitalsData = $stmtVitals->fetch(PDO::FETCH_ASSOC);
                    $dashboardData['bp'] = $vitalsData['bp'];
                    $dashboardData['weight'] = $vitalsData['weight'];
                }
            } catch (PDOException $e) {}
            
            echo json_encode(["status" => "success", "data" => $dashboardData]);
        } else {
            echo json_encode(["status" => "error", "message" => "Patient records not found."]);
        }

    } catch(PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
    }

} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}
$pdo = null;
?>