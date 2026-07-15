<?php
// update_admission_stage.php
ini_set('display_errors', 0);
error_reporting(E_ALL);
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

date_default_timezone_set('Asia/Manila');
require 'db.php'; 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $tenant_id = isset($_POST['tenant_id']) ? trim($_POST['tenant_id']) : '';
    $patient_id = isset($_POST['patient_id']) ? trim($_POST['patient_id']) : '';
    $service = isset($_POST['service']) ? trim($_POST['service']) : '';
    $stage = isset($_POST['stage']) ? trim($_POST['stage']) : '';

    if (empty($tenant_id) || empty($patient_id) || empty($service) || empty($stage)) {
        echo json_encode(["status" => "error", "message" => "Missing data payload."]);
        exit();
    }

    try {
        // ✨ THE FIX: Keep your exact TRIM/LOWER matching, but automatically add 
        // the official discharge status and timestamp if the app passes "Discharged"
        $status_update = (strtolower($stage) === 'discharged') ? ", status = 'Discharged', discharge_date = NOW()" : "";

        $sql = "UPDATE admissions 
                SET stage = :stage, remaining_balance = 0 $status_update
                WHERE TRIM(TenantID) = :tenant 
                AND TRIM(patient_id) = :pid 
                AND TRIM(LOWER(reason)) = TRIM(LOWER(:service)) 
                AND TRIM(LOWER(stage)) = 'payment'";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'stage' => $stage,
            'tenant' => $tenant_id,
            'pid' => $patient_id,
            'service' => $service
        ]);

        // If the update was successful, trigger the email!
        if ($stmt->rowCount() > 0) {
            
            // 1. Fetch Patient Email and Clinic Name
            $stmtInfo = $pdo->prepare("
                SELECT p.email_address, p.full_name, t.clinic_name 
                FROM patients p 
                JOIN tenants t ON t.TenantID = :tenant 
                WHERE p.patient_id = :pid LIMIT 1
            ");
            $stmtInfo->execute(['tenant' => $tenant_id, 'pid' => $patient_id]);
            $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);

            // 2. Generate and Send HTML Email Receipt
            if ($info && !empty($info['email_address'])) {
                $to = $info['email_address'];
                $subject = "Payment Receipt - " . $info['clinic_name'];
                $date_str = date('F j, Y, g:i A');
                $service_display = !empty($service) ? $service : "Medical Services";

                $message = "
                <html>
                <head>
                    <title>Payment Receipt</title>
                </head>
                <body style='font-family: Helvetica, Arial, sans-serif; color: #333; line-height: 1.6; background-color: #f4f4f4; padding: 20px;'>
                    <div style='max-width: 500px; margin: 0 auto; background-color: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.1);'>
                        <div style='background-color: #2E7D32; color: #ffffff; padding: 20px; text-align: center;'>
                            <h2 style='margin: 0; font-size: 24px;'>Payment Successful</h2>
                        </div>
                        <div style='padding: 30px;'>
                            <p style='font-size: 16px; margin-top: 0;'>Hi <strong>{$info['full_name']}</strong>,</p>
                            <p>Thank you! Your online payment via the mobile app was successfully processed and you are now officially cleared.</p>

                            <div style='background-color: #f9f9f9; padding: 15px; border-radius: 8px; border: 1px solid #e0e0e0; margin: 25px 0;'>
                                <table style='width: 100%; font-size: 14px;'>
                                    <tr>
                                        <td style='padding: 5px 0; color: #666;'>Clinic:</td>
                                        <td style='padding: 5px 0; font-weight: bold; text-align: right;'>{$info['clinic_name']}</td>
                                    </tr>
                                    <tr>
                                        <td style='padding: 5px 0; color: #666;'>Service:</td>
                                        <td style='padding: 5px 0; font-weight: bold; text-align: right;'>{$service_display}</td>
                                    </tr>
                                    <tr>
                                        <td style='padding: 5px 0; color: #666;'>Date:</td>
                                        <td style='padding: 5px 0; font-weight: bold; text-align: right;'>{$date_str}</td>
                                    </tr>
                                    <tr>
                                        <td style='padding: 5px 0; border-top: 1px solid #ddd; margin-top: 10px; color: #666;'>Payment Method:</td>
                                        <td style='padding: 5px 0; border-top: 1px solid #ddd; margin-top: 10px; font-weight: bold; text-align: right; color: #2E7D32;'>Online / App</td>
                                    </tr>
                                </table>
                            </div>

                            <p style='font-size: 14px; color: #555;'>If you have any further questions, please contact the clinic directly.</p>
                        </div>
                        <div style='background-color: #f1f1f1; padding: 15px; text-align: center; border-top: 1px solid #e0e0e0;'>
                            <p style='color: #888; font-size: 12px; margin: 0;'>This is an automated receipt from the Maternity Hub App. Please do not reply to this email.</p>
                        </div>
                    </div>
                </body>
                </html>
                ";

                $headers = "MIME-Version: 1.0" . "\r\n";
                $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
                $headers .= "From: receipts@maternityhub.alwaysdata.net" . "\r\n"; 

                @mail($to, $subject, $message, $headers);
            }

            echo json_encode(["status" => "success", "message" => "Stage updated to Discharged and receipt sent."]);
        } else {
            echo json_encode(["status" => "error", "message" => "Could not find active admission in Payment stage."]);
        }
    } catch(PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}
$pdo = null;
?>