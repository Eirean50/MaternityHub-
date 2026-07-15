<?php
// fetch_time_slots.php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require 'db.php'; 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $tenant_id = isset($_POST['tenant_id']) ? trim($_POST['tenant_id']) : '';
    $date = isset($_POST['date']) ? trim($_POST['date']) : '';

    if (empty($tenant_id) || empty($date)) {
        echo json_encode(["status" => "error", "message" => "Missing data."]);
        exit();
    }

    try {
        // 1. Check Staff Capacity
        $stmtStaff = $pdo->prepare("SELECT COUNT(*) as staff_count FROM clinic_staff WHERE TenantID = :tenant AND status = 'Active'");
        $stmtStaff->execute(['tenant' => $tenant_id]);
        $staffData = $stmtStaff->fetch(PDO::FETCH_ASSOC);
        
        $maxCapacity = (int)$staffData['staff_count'];
        if ($maxCapacity <= 0) $maxCapacity = 1;

        // 2. ✨ THE FIX: We are now correctly targeting the 'tenants' table!
        $open_time_str = "08:00:00"; // Default Fallback
        $close_time_str = "17:00:00"; // Default Fallback

        try {
            $stmtHours = $pdo->prepare("SELECT opening_time, closing_time FROM tenants WHERE TenantID = :tenant LIMIT 1");
            $stmtHours->execute(['tenant' => $tenant_id]);
            if ($stmtHours->rowCount() > 0) {
                $hoursData = $stmtHours->fetch(PDO::FETCH_ASSOC);
                if (!empty($hoursData['opening_time'])) $open_time_str = $hoursData['opening_time'];
                if (!empty($hoursData['closing_time'])) $close_time_str = $hoursData['closing_time'];
            }
        } catch (Exception $e) {
            // Safe fallback if there's an error reading the columns
        }

        // 3. Fetch Already Booked Times
        $stmtBookings = $pdo->prepare("SELECT appointment_time FROM appointments 
                                       WHERE TenantID = :tenant 
                                       AND appointment_date = :date 
                                       AND LOWER(status) NOT IN ('cancelled', 'missed', 'completed')");
        $stmtBookings->execute(['tenant' => $tenant_id, 'date' => $date]);
        $bookedTimes = $stmtBookings->fetchAll(PDO::FETCH_ASSOC);

        $slotCounts = [];
        foreach ($bookedTimes as $booking) {
            $time = trim($booking['appointment_time']);
            if (!isset($slotCounts[$time])) {
                $slotCounts[$time] = 0;
            }
            $slotCounts[$time]++;
        }

        // 4. Generate the Dynamic Slots
        $start_time = strtotime($open_time_str);
        $end_time = strtotime($close_time_str);
        $available_slots = [];

        // Safety check to prevent infinite loops
        if ($start_time > 0 && $end_time > $start_time) {
            while ($start_time < $end_time) {
                $dbFormatTime = date("H:i:s", $start_time);
                $currentCount = isset($slotCounts[$dbFormatTime]) ? $slotCounts[$dbFormatTime] : 0;
                
                if ($currentCount < $maxCapacity) {
                    $end_slot = strtotime('+1 hour', $start_time);
                    
                    // Don't generate a slot that goes past the specific clinic's closing time!
                    if ($end_slot <= $end_time) {
                        $available_slots[] = date("g:i A", $start_time) . " - " . date("g:i A", $end_slot);
                    }
                }
                
                $start_time = strtotime('+1 hour', $start_time);
            }
        }

        if (count($available_slots) > 0) {
            echo json_encode(["status" => "success", "data" => $available_slots, "staff_capacity" => $maxCapacity]);
        } else {
            echo json_encode(["status" => "empty", "message" => "No slots available.", "staff_capacity" => $maxCapacity]);
        }

    } catch(PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Database error."]);
    }
}
$pdo = null;
?>