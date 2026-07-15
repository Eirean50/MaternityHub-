<?php
// fetch_appointments.php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

date_default_timezone_set('Asia/Manila');
require 'db.php'; 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $patient_id = isset($_POST['patient_id']) ? trim($_POST['patient_id']) : '';
    $tenant_id = isset($_POST['tenant_id']) ? trim($_POST['tenant_id']) : '';
    $patient_name = isset($_POST['patient_name']) ? trim($_POST['patient_name']) : ''; 
    $filter = isset($_POST['filter']) ? trim($_POST['filter']) : 'upcoming';

    if (empty($patient_id) || empty($tenant_id)) {
        echo json_encode(["status" => "error", "message" => "Missing credentials."]);
        exit();
    }

    try {
        $final_data = [];

        if ($filter === 'upcoming') {
            // ✨ THE FIX: We now filter out anything where is_admitted is 1!
            $sql = "SELECT * FROM appointments 
                    WHERE TenantID = :tenant AND (patient_id = :pid OR full_name = :pname)
                    AND (is_admitted IS NULL OR is_admitted = 0)
                    AND COALESCE(LOWER(status), '') NOT IN ('cancelled', 'completed', 'finished', 'done', 'discharged', 'missed')
                    ORDER BY appointment_date ASC, appointment_time ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['tenant' => $tenant_id, 'pid' => $patient_id, 'pname' => $patient_name]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach($results as $row) {
                $midwife = trim((string)($row['assigned_midwife'] ?? $row['assigned_staff'] ?? 'Unassigned'));
                $pay_type = trim((string)($row['payment_type'] ?? ''));
                
                $final_data[] = [
                    "appointment_date" => $row['appointment_date'] ?? '',
                    "appointment_time" => $row['appointment_time'] ?? '',
                    "service_type" => $row['service'] ?? $row['reason'] ?? 'Unknown',
                    "payment_type" => $pay_type,
                    "remaining_balance" => $row['remaining_balance'] ?? '0',
                    "status" => $row['status'] ?? 'Pending',
                    "current_stage" => $row['stage'] ?? '',
                    "current_payment_method" => $pay_type,
                    "assigned_midwife" => $midwife,
                    "room_no" => "Not Assigned",
                    "is_admitted" => 0,
                    "is_followup" => (strtolower($pay_type) === 'follow-up' || strtolower($pay_type) === 'follow up') ? 1 : 0
                ];
            }

        } else if ($filter === 'admission') {
            // ✨ ADMISSION TAB: Unchanged, fetches directly from the admissions table
            $sql = "SELECT * FROM admissions
                    WHERE TenantID = :tenant AND (patient_id = :pid OR full_name = :pname)
                    AND COALESCE(LOWER(status), '') NOT IN ('discharged', 'completed', 'cancelled', 'done', 'finished')
                    AND COALESCE(LOWER(stage), '') NOT IN ('discharged', 'completed', 'done', 'finished')";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['tenant' => $tenant_id, 'pid' => $patient_id, 'pname' => $patient_name]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach($results as $row) {
                $rType = trim((string)($row['assigned_room_type'] ?? ''));
                $rId = trim((string)($row['assigned_room_id'] ?? ''));
                $bId = trim((string)($row['assigned_bed_id'] ?? ''));
                $room = (!empty($rId) || !empty($bId)) ? trim("$rType $rId" . (!empty($bId) ? " - Bed $bId" : "")) : trim((string)($row['room_no'] ?? $row['room'] ?? 'Not Assigned'));
                
                $midwife = trim((string)($row['assigned_staff'] ?? $row['assigned_midwife'] ?? 'Unassigned'));
                $pay_method = trim((string)($row['payment_method'] ?? $row['payment_type'] ?? ''));

                $final_data[] = [
                    "appointment_date" => $row['admission_date'] ?? '',
                    "appointment_time" => "", 
                    "service_type" => $row['reason'] ?? $row['service'] ?? 'Unknown',
                    "payment_type" => $pay_method,
                    "remaining_balance" => $row['remaining_balance'] ?? '0',
                    "status" => $row['status'] ?? 'Admitted',
                    "current_stage" => $row['stage'] ?? '',
                    "current_payment_method" => $pay_method,
                    "assigned_midwife" => $midwife,
                    "room_no" => $room,
                    "is_admitted" => 1,
                    "is_followup" => 0
                ];
            }
        }

        if (count($final_data) > 0) {
            echo json_encode(["status" => "success", "data" => $final_data]);
        } else {
            echo json_encode(["status" => "empty", "message" => "No items found."]);
        }

    } catch(PDOException $e) {
        echo json_encode(["status" => "error", "message" => "DB Error: " . $e->getMessage()]);
    }
}
$pdo = null;
?>