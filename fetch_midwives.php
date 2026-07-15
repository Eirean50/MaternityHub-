<?php
// fetch_midwives.php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require 'db.php'; 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    $tenant_id = isset($_POST['tenant_id']) ? trim($_POST['tenant_id']) : '';
    $date = isset($_POST['date']) ? trim($_POST['date']) : '';
    $time = isset($_POST['time']) ? trim($_POST['time']) : '';

    if (empty($tenant_id)) {
        echo json_encode(["status" => "error", "message" => "Missing clinic context."]);
        exit();
    }

    try {
        if (!empty($date) && !empty($time)) {
            // ✨ THE FIX: We use IN to include both Midwife and Owner/Midwife!
            $sql = "SELECT id, TRIM(CONCAT(first_name, ' ', last_name)) AS full_name 
                    FROM clinic_staff 
                    WHERE TenantID = :tenant 
                    AND LOWER(role) IN ('midwife', 'owner/midwife') 
                    AND TRIM(CONCAT(first_name, ' ', last_name)) NOT IN (
                        SELECT assigned_midwife FROM appointments 
                        WHERE TenantID = :tenant 
                        AND appointment_date = :date 
                        AND appointment_time = :time 
                        AND assigned_midwife IS NOT NULL 
                        AND status NOT IN ('Cancelled', 'Declined', 'Completed', 'Finished')
                    )";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'tenant' => $tenant_id,
                'date' => $date,
                'time' => $time
            ]);
        } else {
            // ✨ Fallback query also updated to accept both roles
            $sql = "SELECT id, TRIM(CONCAT(first_name, ' ', last_name)) AS full_name 
                    FROM clinic_staff 
                    WHERE TenantID = :tenant 
                    AND LOWER(role) IN ('midwife', 'owner/midwife')"; 
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['tenant' => $tenant_id]);
        }
        
        $midwives = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($midwives) > 0) {
            echo json_encode(["status" => "success", "data" => $midwives]);
        } else {
            echo json_encode(["status" => "empty", "message" => "No staff available for this time"]);
        }

    } catch(PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}
$pdo = null;
?>