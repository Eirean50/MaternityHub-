<?php
// reject_patient.php
ini_set('display_errors', 0);
error_reporting(E_ALL);
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require 'db.php';
require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $patient_id = isset($_POST['patient_id']) ? trim($_POST['patient_id']) : '';
    $tenant_id = isset($_POST['tenant_id']) ? trim($_POST['tenant_id']) : '';
    $reject_reason = isset($_POST['reject_reason']) ? trim($_POST['reject_reason']) : 'Invalid or unreadable Guardian ID.';
    
    // Fallback UI variables
    $clinic_name = isset($_POST['clinic_name']) ? trim($_POST['clinic_name']) : 'MaternityHub Clinic';
    $theme_color = isset($_POST['theme_color']) ? trim($_POST['theme_color']) : '#BA1A1A'; // Red theme for rejection

    if (empty($patient_id) || empty($tenant_id)) {
        echo json_encode(["status" => "error", "message" => "Missing credentials."]);
        exit();
    }

    try {
        // 1. Fetch Patient Info for the Email
        $stmt = $pdo->prepare("SELECT full_name, email_address FROM patients WHERE patient_id = :pid AND TenantID = :tenant LIMIT 1");
        $stmt->execute(['pid' => $patient_id, 'tenant' => $tenant_id]);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$patient) {
            echo json_encode(["status" => "error", "message" => "Patient not found."]);
            exit();
        }

        $full_name = $patient['full_name'];
        $email = $patient['email_address'];

        // 2. Update Database
        $updateStmt = $pdo->prepare("UPDATE patients SET account_status = 'Rejected', reject_reason = :reason, reviewed_at = NOW() WHERE patient_id = :pid AND TenantID = :tenant");
        $updateStmt->execute(['reason' => $reject_reason, 'pid' => $patient_id, 'tenant' => $tenant_id]);

        // 3. Send Rejection Email
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();                                            
            $mail->Host       = 'smtp.gmail.com';                     
            $mail->SMTPAuth   = true;                                   
            $mail->Username   = 'ellisfordead1@gmail.com';   
            $mail->Password   = 'buqr ibjv iekk hnib'; 
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;            
            $mail->Port       = 465;                                    
            $mail->setFrom('ellisfordead1@gmail.com', $clinic_name); 
            $mail->addAddress($email, $full_name);     
            $mail->isHTML(true);                                  
            
            $mail->Subject = "$clinic_name - Action Required: ID Rejected";
            $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 500px; margin: 0 auto; border: 1px solid #e0e0e0; border-radius: 12px; overflow: hidden;'>
                <div style='background-color: {$theme_color}; padding: 25px; text-align: center; color: #ffffff;'>
                    <h1 style='margin: 0;'>Action Required</h1>
                </div>
                <div style='padding: 30px; color: #333333;'>
                    <p>Hello <strong>{$full_name}</strong>,</p>
                    <p>We reviewed your recent registration. Unfortunately, your Guardian ID could not be approved for the following reason:</p>
                    <p style='padding: 15px; background-color: #FFEBEE; color: #BA1A1A; border-radius: 8px; font-weight: bold;'>{$reject_reason}</p>
                    <p>Please open your mobile app, log in, and upload a new, clear Guardian ID to continue.</p>
                </div>
            </div>";
            $mail->send();

            echo json_encode(["status" => "success", "message" => "Patient rejected and email sent."]);

        } catch (Exception $e) {
            echo json_encode(["status" => "error", "message" => "DB updated, but email failed."]);
        }

    } catch(PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
    }
}
?>