<?php
// fetch_record_details.php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require 'db.php'; 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $admission_id = isset($_POST['admission_id']) ? trim($_POST['admission_id']) : '';
    $tenant_id = isset($_POST['tenant_id']) ? trim($_POST['tenant_id']) : '';
    $patient_name = isset($_POST['patient_name']) ? trim($_POST['patient_name']) : ''; 

    if (empty($admission_id) || empty($tenant_id)) {
        echo json_encode(["status" => "error", "message" => "Missing data."]);
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM admissions WHERE id = :id AND TenantID = :tenant");
        $stmt->execute(['id' => $admission_id, 'tenant' => $tenant_id]);
        $admission = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admission) {
            echo json_encode(["status" => "error", "message" => "Record not found."]);
            exit();
        }

        $service = trim($admission['reason']);
        $adm_date = date('Y-m-d', strtotime($admission['admission_date']));

        // ✨ THE FIX: We now use the exact admission_id to find the payment instantly! No more date-guessing!
        $stmtPay = $pdo->prepare("SELECT SUM(amount) as total_paid, MAX(receipt) as receipt_link, MAX(is_philhealth) as is_philhealth
                                  FROM payments 
                                  WHERE TenantID = :tenant 
                                  AND admission_id = :adm_id");
        $stmtPay->execute([
            'tenant' => $tenant_id,
            'adm_id' => $admission_id
        ]);
        $paymentInfo = $stmtPay->fetch(PDO::FETCH_ASSOC);

        // ✨ THE RECEIPT SPLIT LOGIC
        $raw_receipt = trim($paymentInfo['receipt_link'] ?? '');
        $receipt_url = "";

        if (!empty($raw_receipt)) {
            $parts = explode('|', $raw_receipt);
            
            // If it has a "|", grab the second half and attach the domain!
            if (count($parts) > 1) {
                $filePath = trim($parts[1]); 
                $receipt_url = "https://maternityhub.alwaysdata.net/" . $filePath;
            } 
            // Failsafe: if no "|" exists but it's an uploads link
            else if (strpos($raw_receipt, 'http') === 0 || strpos($raw_receipt, 'uploads') === 0) {
                 $receipt_url = (strpos($raw_receipt, 'http') === 0) ? $raw_receipt : "https://maternityhub.alwaysdata.net/" . $raw_receipt;
            }
        }

       // ✨ THE CORRECTED ULTRASOUND LOGIC
$ultrasound_url = "";
$lower_service = strtolower($service);

// Just grab the raw path from the DB
$trans_path = trim($admission['lab_transvaginal'] ?? '');
$pelvic_path = trim($admission['lab_pelvic'] ?? '');

if (strpos($lower_service, 'transvaginal') !== false && !empty($trans_path)) {
     $ultrasound_url = "https://maternityhub.alwaysdata.net/" . $trans_path;
} 
else if (strpos($lower_service, 'pelvic') !== false && !empty($pelvic_path)) {
     $ultrasound_url = "https://maternityhub.alwaysdata.net/" . $pelvic_path;
}
else if (strpos($lower_service, 'ultrasound') !== false) {
     // Failsafe: check whichever one isn't empty
     $final_path = !empty($trans_path) ? $trans_path : $pelvic_path;
     if (!empty($final_path)) {
         $ultrasound_url = "https://maternityhub.alwaysdata.net/" . $final_path;
     }
}

        // Vitals Failsafe
        $bp = trim($admission['bp'] ?? '');
        if (!empty($bp) && $bp !== '--' && stripos($bp, 'mmHg') === false) { $bp .= ' mmHg'; }
        
        $weight = trim($admission['weight'] ?? '');
        if (!empty($weight) && $weight !== '--' && stripos($weight, 'kg') === false) { $weight .= ' kg'; }

        // ✨ ROOM ASSIGNMENT LOGIC
        $rType = trim($admission['assigned_room_type'] ?? '');
        $rId = trim($admission['assigned_room_id'] ?? '');
        $bId = trim($admission['assigned_bed_id'] ?? '');
        
        $room_no = trim($admission['room_no'] ?? trim($admission['room'] ?? 'Not Assigned'));
        if (!empty($rId) || !empty($bId)) {
            $roomString = trim("$rType $rId");
            if (!empty($bId)) $roomString .= " - Bed $bId";
            $room_no = trim($roomString);
        }

        $response = [
            "date" => $admission['admission_date'],
            "service" => $service,
            "staff" => $admission['assigned_staff'] ?? 'Not Assigned',
            "room_no" => $room_no, // Passed to UI
            "bp" => $bp,
            "temp" => $admission['temp'] ?? '--',
            "weight" => $weight,
            "pulse" => $admission['pulse'] ?? '--',
            "spo2" => $admission['spo2'] ?? '--',
            "payment_type" => $admission['payment_type'] ?? 'Walk-in',
            "payment_method" => $admission['payment_method'] ?? 'Cash',
            "total_paid" => (float)($paymentInfo['total_paid'] ?? 0),
            "is_philhealth" => (int)($paymentInfo['is_philhealth'] ?? 0), // Passed to UI
            "receipt_url" => $receipt_url,
            "ultrasound_url" => $ultrasound_url
        ];

        echo json_encode(["status" => "success", "data" => $response]);

    } catch(PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}
$pdo = null;
?>