<?php
// fetch_medical_records.php
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
        // 1. Fetch ALL Discharged Admissions for this specific patient
        $sqlAdm = "SELECT * FROM admissions 
                   WHERE patient_id = :pid AND TenantID = :tenant 
                   AND (LOWER(status) = 'discharged' OR LOWER(stage) = 'discharged')";
        $stmtAdm = $pdo->prepare($sqlAdm);
        $stmtAdm->execute(['pid' => $patient_id, 'tenant' => $tenant_id]);
        $admissions = $stmtAdm->fetchAll(PDO::FETCH_ASSOC);

        // 2. Fetch Payments to link PhilHealth via admission_id
        $sqlPay = "SELECT * FROM payments WHERE patient_id = :pid AND TenantID = :tenant";
        $stmtPay = $pdo->prepare($sqlPay);
        $stmtPay->execute(['pid' => $patient_id, 'tenant' => $tenant_id]);
        $payments = $stmtPay->fetchAll(PDO::FETCH_ASSOC);

        // Create a quick lookup dictionary for payments based on admission_id
        $payments_lookup = [];
        foreach ($payments as $pay) {
            if (!empty($pay['admission_id'])) {
                $payments_lookup[$pay['admission_id']] = $pay;
            }
        }

        $final_records = [];

        foreach ($admissions as $adm) {
            $adm_id = $adm['id'];
            $service = trim($adm['reason'] ?? 'Unknown Service');

            // Determine the best date to display (Discharge Date > Admission Date)
            $display_date = $adm['discharge_date'];
            if (empty($display_date)) {
                $display_date = $adm['admission_date'];
            }

            // Check our lookup dictionary to see if this admission has a linked payment record
            $is_philhealth = 0;
            if (isset($payments_lookup[$adm_id])) {
                $is_philhealth = (int)($payments_lookup[$adm_id]['is_philhealth'] ?? 0);
            }

            // Room Formatting
            $rType = trim($adm['assigned_room_type'] ?? '');
            $rId = trim($adm['assigned_room_id'] ?? '');
            $bId = trim($adm['assigned_bed_id'] ?? '');
            $room_no = trim($adm['room_no'] ?? trim($adm['room'] ?? 'Not Assigned'));

            if (!empty($rId) || !empty($bId)) {
                $roomString = trim("$rType $rId");
                if (!empty($bId)) $roomString .= " - Bed $bId";
                $room_no = trim($roomString);
            }

            $final_records[] = [
                "id" => $adm_id,
                "admission_date" => $display_date, 
                "reason" => $service,
                "assigned_staff" => $adm['assigned_staff'] ?? 'N/A',
                "room_no" => $room_no,
                "bp" => $adm['bp'] ?? '--',
                "temp" => $adm['temp'] ?? '--',
                "weight" => $adm['weight'] ?? '--',
                "pulse" => $adm['pulse'] ?? '--',
                "spo2" => $adm['spo2'] ?? '--',
                "payment_method" => $adm['payment_method'] ?? 'Walk-in',
                "is_early" => (isset($adm['is_early_admission']) && $adm['is_early_admission'] == 1),
                "is_philhealth" => $is_philhealth 
            ];
        }

        // Sort from Newest to Oldest
        usort($final_records, function($a, $b) {
            $timeA = strtotime($a['admission_date']);
            $timeB = strtotime($b['admission_date']);
            return $timeB <=> $timeA;
        });

        if (count($final_records) > 0) {
            echo json_encode(["status" => "success", "data" => $final_records]);
        } else {
            echo json_encode(["status" => "empty", "message" => "No medical records found."]);
        }
    } catch(PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}
$pdo = null;
?>