<?php
// save_appointment.php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
require 'db.php';

// ✨ NEW: Bring in PHPMailer for the receipts!
require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 1. Catch the Booking Details
    $tenant_id = $_POST['tenant_id'] ?? '';
    $patient_id = $_POST['patient_id'] ?? '';
    $date = $_POST['date'] ?? '';
    $time = $_POST['time'] ?? '';
    
    // 2. Catch the Payment & Service Details
    $service_type = $_POST['service_type'] ?? 'General Checkup';
    $payment_type = $_POST['payment_type'] ?? 'Full Payment';
    $remaining_balance = $_POST['remaining_balance'] ?? '0';
    $assigned_midwife = $_POST['assigned_midwife'] ?? 'Unassigned';

    // ✨ NEW: Catching data specifically for the Email Receipt!
    $amount_paid = $_POST['amount_paid'] ?? '0'; 
    $clinic_name = $_POST['clinic_name'] ?? 'MaternityHub Clinic';
    $theme_color = $_POST['theme_color'] ?? '#394b3b';

    if(empty($tenant_id) || empty($patient_id) || empty($date) || empty($time)) {
         echo json_encode(["status" => "error", "message" => "Missing booking data."]);
         exit;
    }

    try {
        // Step A: Save the booking to the database
        $stmt = $pdo->prepare("
            INSERT INTO appointments (
                TenantID, patient_id, full_name, email_address, birthday, age, 
                address, contact_number, menarche, civil_status, religion, occupation, 
                mother_name, father_name, husband_name, spouse_phone,
                appointment_date, appointment_time, status, service, payment_type, remaining_balance, assigned_midwife
            )
            SELECT 
                TenantID, patient_id, full_name, email_address, birthday, age, 
                address, contact_number, menarche, civil_status, religion, occupation, 
                mother_name, father_name, husband_name, spouse_phone,
                :date, :time, 'Confirmed', :service, :ptype, :bal, :midwife
            FROM patients 
            WHERE patient_id = :pid AND TenantID = :tenant
            LIMIT 1
        ");
        
        if ($stmt->execute([
            'date' => $date, 
            'time' => $time, 
            'service' => $service_type, 
            'ptype' => $payment_type, 
            'bal' => $remaining_balance,
            'midwife' => $assigned_midwife, 
            'pid' => $patient_id, 
            'tenant' => $tenant_id
        ])) {
            
            // ✨ Step B: Fetch the patient's email address to send the receipt
            $stmtPatient = $pdo->prepare("SELECT full_name, email_address FROM patients WHERE patient_id = :pid AND TenantID = :tenant LIMIT 1");
            $stmtPatient->execute(['pid' => $patient_id, 'tenant' => $tenant_id]);
            
            if ($stmtPatient->rowCount() > 0) {
                $patientData = $stmtPatient->fetch(PDO::FETCH_ASSOC);
                $email = $patientData['email_address'];
                $full_name = $patientData['full_name'];
                
                // Format dates and money so they look nice in the email
                $displayDate = date("F j, Y", strtotime($date));
                $displayTime = date("h:i A", strtotime($time));
                $displayPaid = number_format((float)$amount_paid, 2);
                $displayBalance = number_format((float)$remaining_balance, 2);
                
                // ✨ Step C: Send the Email!
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
                    $mail->Subject = "$clinic_name - Appointment Confirmed!";
                    
                    // The beautiful custom HTML Email Receipt
                    $mail->Body = "
                    <div style='font-family: Arial, sans-serif; max-width: 500px; margin: 0 auto; border: 1px solid #e0e0e0; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.05);'>
                        <div style='background-color: {$theme_color}; padding: 25px; text-align: center; color: #ffffff;'>
                            <h1 style='margin: 0; font-size: 24px;'>Appointment Confirmed</h1>
                        </div>
                        <div style='padding: 30px; color: #333333; line-height: 1.6;'>
                            <p style='font-size: 16px; margin-top: 0;'>Hello <strong>{$full_name}</strong>,</p>
                            <p style='font-size: 16px;'>Your appointment at <strong>{$clinic_name}</strong> has been successfully scheduled. Here is your official booking receipt:</p>
                            
                            <div style='background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin: 25px 0; border: 1px solid #eeeeee;'>
                                <h3 style='margin-top: 0; color: {$theme_color}; border-bottom: 2px solid {$theme_color}; padding-bottom: 5px; display: inline-block;'>Visit Details</h3>
                                <p style='margin: 5px 0;'><strong>Service:</strong> {$service_type}</p>
                                <p style='margin: 5px 0;'><strong>Date:</strong> {$displayDate}</p>
                                <p style='margin: 5px 0;'><strong>Time:</strong> {$displayTime}</p>
                                
                                <h3 style='margin-top: 20px; color: {$theme_color}; border-bottom: 2px solid {$theme_color}; padding-bottom: 5px; display: inline-block;'>Payment Summary</h3>
                                <p style='margin: 5px 0;'><strong>Payment Type:</strong> {$payment_type}</p>
                                <p style='margin: 5px 0;'><strong>Amount Paid:</strong> ₱{$displayPaid}</p>
                                <p style='margin: 5px 0;'><strong>Remaining Balance:</strong> ₱{$displayBalance}</p>
                            </div>
                            
                            <p style='font-size: 14px; color: #777777;'>Please arrive 10 minutes before your scheduled time. If you have any questions or need to cancel, please contact the clinic.</p>
                        </div>
                        <div style='background-color: #f4f5f7; padding: 15px; text-align: center; color: #999999; font-size: 12px; border-top: 1px solid #eeeeee;'>
                            &copy; " . date('Y') . " {$clinic_name}. Powered by MaternityHub.
                        </div>
                    </div>";

                    $mail->send();
                } catch (Exception $e) {
                    // We purposefully ignore email failures here so the app doesn't crash if the wifi stutters!
                }
            }

            echo json_encode(["status" => "success"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Failed to save to database."]);
        }
    } catch(PDOException $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}
?>