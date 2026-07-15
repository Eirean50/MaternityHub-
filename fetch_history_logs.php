<?php
// fetch_history_logs.php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

date_default_timezone_set('Asia/Manila');
require 'db.php'; 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $patient_id = isset($_POST['patient_id']) ? trim($_POST['patient_id']) : '';
    $tenant_id = isset($_POST['tenant_id']) ? trim($_POST['tenant_id']) : '';
    $patient_name = isset($_POST['patient_name']) ? trim($_POST['patient_name']) : ''; 

    if (empty($patient_id) || empty($tenant_id)) {
        echo json_encode(["status" => "error", "message" => "Missing credentials."]);
        exit();
    }

    function getSafeTs($dateStr, $timeStr = '') {
        $dateStr = trim((string)$dateStr);
        $timeStr = trim((string)$timeStr);
        if (empty($dateStr) || $dateStr === '0000-00-00' || strpos($dateStr, '0000-00-00') !== false) return false;
        
        $full = empty($timeStr) ? $dateStr : "$dateStr $timeStr";
        $ts = strtotime($full);
        return ($ts !== false && $ts > 0) ? $ts : false;
    }

    $logs = [];

    // ✨ REAL TIME + MICRO-TIEBREAKER ENGINE
    function addLog(&$logs, $real_ts, $micro_offset, $title, $details, $type) {
        $logs[] = [
            'timestamp' => $real_ts + $micro_offset, 
            'datetime_display' => strtoupper(date('M d, Y • h:i A', $real_ts)), 
            'title' => $title,
            'details' => $details,
            'type' => $type
        ];
    }

    try {
        // ==========================================
        // 1. ADMISSIONS (Fetched first for chronological locking)
        // ==========================================
        $stmtAdm = $pdo->prepare("SELECT * FROM admissions WHERE TenantID = :tenant AND (patient_id = :pid OR full_name = :pname)");
        $stmtAdm->execute(['tenant' => $tenant_id, 'pid' => $patient_id, 'pname' => $patient_name]);
        $admissions = $stmtAdm->fetchAll(PDO::FETCH_ASSOC);

        // ==========================================
        // 2. APPOINTMENTS (Booked, Cancelled)
        // ==========================================
        $stmtAppt = $pdo->prepare("SELECT * FROM appointments WHERE TenantID = :tenant AND (patient_id = :pid OR full_name = :pname)");
        $stmtAppt->execute(['tenant' => $tenant_id, 'pid' => $patient_id, 'pname' => $patient_name]);
        $appointments = $stmtAppt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($appointments as $appt) {
            $service = trim($appt['service'] ?? $appt['reason'] ?? 'Checkup');
            $svc_lower = strtolower($service);

            // Deliveries are Walk-in Only. NO "Booked" logs for them.
            if (strpos($svc_lower, 'delivery') !== false || strpos($svc_lower, 'cesarean') !== false) {
                continue; 
            }

            $target_ts = getSafeTs($appt['appointment_date'], $appt['appointment_time']);
            $display_target = $target_ts ? date('M d, Y • h:i A', $target_ts) : 'Pending Schedule';

            $created_ts = getSafeTs($appt['created_at'] ?? $appt['date_created'] ?? '');
            
            // ✨ THE FIX: The Chronological Lock!
            // Search for a matching admission. If one exists, the booking CANNOT be newer than the admission!
            $matching_adm_ts = false;
            foreach ($admissions as $adm) {
                if (strtolower(trim($adm['reason'] ?? '')) === $svc_lower) {
                    $adm_ts = getSafeTs($adm['admission_date']);
                    if ($adm_ts) {
                        $matching_adm_ts = $adm_ts;
                        // If it's the exact same day, lock it in immediately
                        if ($target_ts && date('Y-m-d', $adm_ts) === date('Y-m-d', $target_ts)) break;
                    }
                }
            }

            // Force the Booked bubble to securely sit 1 minute below the Admission bubble!
            if ($matching_adm_ts && (!$created_ts || $created_ts >= $matching_adm_ts)) {
                $created_ts = $matching_adm_ts - 60; 
            } else if (!$created_ts) {
                $created_ts = $target_ts ? $target_ts - 86400 : time() - 86400; 
            }

            $is_followup = (strtolower(trim($appt['payment_type'] ?? '')) === 'follow-up' || strtolower(trim($appt['payment_type'] ?? '')) === 'follow up');

            if ($is_followup) {
                addLog($logs, $created_ts, 0, "Booked Follow-up", "Scheduled for: $display_target\nFor: $service", "gray");
            } else {
                addLog($logs, $created_ts, 0, "Appointment Booked", "Target Date: $display_target\nFor: $service", "gray");
            }

            $status = strtolower(trim($appt['status'] ?? ''));
            if (in_array($status, ['cancelled', 'missed'])) {
                addLog($logs, $target_ts ?: time(), 6, "Appointment Cancelled", "Service: $service", "red");
            }
        }

        // ==========================================
        // 3. PROCESS ADMISSIONS LOGS
        // ==========================================
        foreach ($admissions as $adm) {
            $service = trim($adm['reason'] ?? 'Admission');
            $svc_lower = strtolower($service);
            $adm_ts = getSafeTs($adm['admission_date']) ?: time();

            $rType = trim($adm['assigned_room_type'] ?? '');
            $rId = trim($adm['assigned_room_id'] ?? '');
            $room = (!empty($rId)) ? "\nLocation: $rType $rId" : "";

            addLog($logs, $adm_ts, 1, "Admitted to Clinic", "Reason: $service" . $room, "orange");

            // DYNAMIC SPAWN: Labor & Delivery
            if (strpos($svc_lower, 'delivery') !== false || strpos($svc_lower, 'cesarean') !== false) {
                addLog($logs, $adm_ts + 1800, 2, "Labor In Progress", "Patient is in active labor.", "orange");
                addLog($logs, $adm_ts + 3600, 3, "Successful Delivery", "Baby has been delivered safely.", "green");
            }

            $status = strtolower(trim($adm['status'] ?? $adm['stage'] ?? ''));
            if (in_array($status, ['discharged', 'completed', 'done', 'finished']) || !empty($adm['discharge_date'])) {
                $dis_ts = getSafeTs($adm['discharge_date']) ?: $adm_ts + 7200;
                addLog($logs, $dis_ts, 5, "Patient Discharged", "Successfully discharged from clinic.\nFor: $service", "green");
            }
        }

        // ==========================================
        // 4. PAYMENTS (Paid, PhilHealth, Ghosts Filtered)
        // ==========================================
        $stmtPay = $pdo->prepare("SELECT * FROM payments WHERE TenantID = :tenant AND (patient_id = :pid OR full_name = :pname) AND LOWER(status) = 'paid'");
        $stmtPay->execute(['tenant' => $tenant_id, 'pid' => $patient_id, 'pname' => $patient_name]);
        $payments = $stmtPay->fetchAll(PDO::FETCH_ASSOC);

        foreach ($payments as $pay) {
            $pay_ts = getSafeTs($pay['payment_date'] ?? $pay['created_at']) ?: time();
            $amount = (float)($pay['amount'] ?? 0);
            $isPhilhealth = (int)($pay['is_philhealth'] ?? 0);
            $service = trim($pay['service'] ?? 'Medical Services');

            $desc = trim($pay['description'] ?? '');
            $desc_lower = strtolower($desc);

            if ($amount <= 0 && $isPhilhealth !== 1 && strpos($desc_lower, 'philhealth') === false) continue;

            if ($isPhilhealth === 1 || strpos($desc_lower, 'philhealth') !== false) {
                addLog($logs, $pay_ts, 4, "PhilHealth Coverage Applied", "Zero Balance\nCovered by PhilHealth\nFor: $service", "green");
            } else {
                $method = "Cash"; 
                if (strpos($desc_lower, 'online') !== false || strpos($desc_lower, 'app') !== false || strpos($desc_lower, 'paymongo') !== false) $method = "Online / App";
                elseif (strpos($desc_lower, 'gcash') !== false) $method = "GCash";
                elseif (strpos($desc_lower, 'card') !== false) $method = "Card";
                elseif (preg_match('/\((.*?)\)/', $desc, $matches)) $method = trim($matches[1]);

                addLog($logs, $pay_ts, 4, "Payment Processed", "Paid ₱" . number_format($amount, 2) . " via $method\nFor: $service", "green");
            }
        }

        // ==========================================
        // 5. FINAL SORTING (Newest at Top)
        // ==========================================
        usort($logs, function($a, $b) {
            return $b['timestamp'] <=> $a['timestamp'];
        });

        if (count($logs) > 0) {
            echo json_encode(["status" => "success", "data" => array_values($logs)]);
        } else {
            echo json_encode(["status" => "empty", "message" => "No history logs found."]);
        }

    } catch(Throwable $e) {
        echo json_encode(["status" => "error", "message" => "Server Crash: " . $e->getMessage()]);
    }
}
$pdo = null;
?>