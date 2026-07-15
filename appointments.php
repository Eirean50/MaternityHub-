<?php
// 1. SETTINGS & SESSION
date_default_timezone_set('Asia/Manila');
ob_start();
ini_set('display_errors', 1); 
error_reporting(E_ALL);

session_start();

require_once 'db.php'; 
try { $pdo->exec("SET time_zone = '+08:00'"); } catch (Exception $e) {}

// ?? AUTO-FIX: ADD `remaining_balance` COLUMN SA APPOINTMENTS TABLE ??
try {
    $pdo->query("SELECT remaining_balance FROM appointments LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE appointments ADD remaining_balance DECIMAL(10,2) NULL AFTER status"); } catch (PDOException $ex) {}
}

// ?? AUTO-FIX: ENSURE BOTH `assigned_staff` AND `assigned_midwife` COLUMNS EXIST ??
try {
    $pdo->query("SELECT assigned_staff FROM appointments LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE appointments ADD assigned_staff VARCHAR(255) NULL AFTER service"); } catch (PDOException $ex) {}
}
try {
    $pdo->query("SELECT assigned_midwife FROM appointments LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE appointments ADD assigned_midwife VARCHAR(255) NULL AFTER service"); } catch (PDOException $ex) {}
}

// Function to convert 24-hour time to 12-hour format (HH:MM, no seconds)
function convertTo12HourFormat($time) {
    if (empty($time)) return '�';
    $time = trim($time);
    // If time includes seconds (HH:MM:SS), trim to HH:MM
    if (strlen($time) >= 5) {
        $time = substr($time, 0, 5);
    }
    try {
        $datetime = DateTime::createFromFormat('H:i', $time);
        return $datetime ? $datetime->format('h:i A') : '�';
    } catch (Exception $e) {
        return '�';
    }
}

// --- 2. BACKEND LOGOUT LOGIC ---
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    // --- AUDIT LOG LOGOUT ---
    if (isset($_SESSION['full_name']) && isset($pdo)) {
        try {
            $logoutName = $_SESSION['full_name'];
            $logoutRole = $_SESSION['role'] ?? 'User';
            $auditTenant = $_SESSION['TenantID'] ?? null;
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $currentTime = date('Y-m-d H:i:s');
            $auditDetails = 'User securely logged out of their clinic portal.';
            $stmtLogoutLog = $pdo->prepare("INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, 'Logout', ?, ?, ?)");
            $stmtLogoutLog->execute([$auditTenant, $logoutName, $logoutRole, $auditDetails, $ip, $currentTime]);
        } catch (Exception $e) {}
    }
    $_SESSION = array(); 
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    $clinicCode = $_GET['c'] ?? 'N/A';
    if (!empty($clinicCode) && $clinicCode !== 'N/A') {
        header("Location: tenant_login.php?c=" . urlencode($clinicCode)); 
    } else {
        header("Location: tenant_login.php");
    }
    exit();
}

// Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'Patient') {
    header("Location: index.php");
    exit();
}

$displayName = $_SESSION['full_name'] ?? 'User';
$displayRole = $_SESSION['role'] ?? 'Staff';
$normalizedRole = strtolower(trim((string)$displayRole));
$isStaffRole = ($normalizedRole === 'staff');
$current_staff_id = $_SESSION['user_id'];
$tenant_id = $_SESSION['TenantID'] ?? null; 
$message = "";
$current_date = date('Y-m-d');

// --- FETCH CLINIC NAME & CODE (MULTI-TENANT) ---
$clinicName = "MaternityHub";
$clinicCode = "N/A";
$clinicLogo = null;
$themeColor = "#15803d";
if ($tenant_id) {
    try {
        $stmtClinic = $pdo->prepare("SELECT clinic_name, clinic_code, clinic_logo, theme_color FROM tenants WHERE TenantID = ?");
        $stmtClinic->execute([$tenant_id]);
        $clinicData = $stmtClinic->fetch(PDO::FETCH_ASSOC);

        if ($clinicData) {
            $clinicName = $clinicData['clinic_name'];
            if (!empty($clinicData['clinic_code'])) {
                $clinicCode = $clinicData['clinic_code'];
            }
            if (!empty($clinicData['clinic_logo']) && file_exists(__DIR__ . '/uploads/logos/' . $clinicData['clinic_logo'])) {
                $clinicLogo = 'uploads/logos/' . $clinicData['clinic_logo'];
            }
            if (!empty($clinicData['theme_color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$clinicData['theme_color'])) {
                $themeColor = $clinicData['theme_color'];
            }
        }
    } catch (PDOException $e) {}
}

// =========================================================
// ?? DYNAMIC CONTRAST CALCULATOR PARA SA HEADER & SIDEBAR ??
// =========================================================
$hex = ltrim($themeColor, '#');
if (strlen($hex) == 3) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; }
$r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
$luminance = ($r * 0.299) + ($g * 0.587) + ($b * 0.114);
$isLightTheme = ($luminance > 150);

$headerTextPrimary = $isLightTheme ? "text-slate-900" : "text-white";
$headerTextSecondary = $isLightTheme ? "text-slate-700" : "text-primary-light";
$headerTextMuted = $isLightTheme ? "text-slate-400" : "text-white/50";
$headerBadgeBg = $isLightTheme ? "bg-slate-200 text-slate-800" : "bg-black/20 text-white";
$headerIconBox = $isLightTheme ? "bg-white border border-slate-200" : "bg-white/15 border border-white/25";
$headerIconColor = $isLightTheme ? "text-slate-700" : "text-white/90";
$headerBtn = $isLightTheme ? "bg-white hover:bg-slate-50 text-slate-800 border-slate-200 shadow-sm" : "bg-white/15 hover:bg-white/25 text-white border-white/30";

// FETCH CURRENT PROFILE PICTURE & FULL NAME
try {
    $stmtPic = $pdo->prepare("SELECT u.first_name, u.middle_name, u.last_name, COALESCE(u.profile_image, cs.profile_image) AS profile_image FROM users u LEFT JOIN clinic_staff cs ON cs.TenantID = u.TenantID AND LOWER(TRIM(COALESCE(cs.email_address, ''))) = LOWER(TRIM(COALESCE(u.email, ''))) WHERE u.id = ? LIMIT 1");
    $stmtPic->execute([$current_staff_id]);
    $userRow = $stmtPic->fetch(PDO::FETCH_ASSOC);
    $fn = trim($userRow['first_name'] ?? ''); $mn = trim($userRow['middle_name'] ?? ''); $ln = trim($userRow['last_name'] ?? '');
    $builtName = trim($fn . ($mn ? ' ' . $mn : '') . ' ' . $ln);
    if ($builtName !== '') { $displayName = $builtName; }
    $dbPic = $userRow['profile_image'] ?? null;
    if (!empty($dbPic)) {
        if (str_starts_with((string)$dbPic, 'http') || str_starts_with((string)$dbPic, 'uploads/')) { $profilePic = (string)$dbPic; }
        elseif (file_exists(__DIR__ . '/uploads/profiles/' . $dbPic)) { $profilePic = 'uploads/profiles/' . $dbPic; }
        else { $profilePic = "https://ui-avatars.com/api/?name=" . urlencode($displayName) . "&background=" . ltrim($themeColor, '#') . "&color=fff"; }
    } else {
        $profilePic = "https://ui-avatars.com/api/?name=" . urlencode($displayName) . "&background=" . ltrim($themeColor, '#') . "&color=fff";
    }
} catch (PDOException $e) {
    $profilePic = "https://ui-avatars.com/api/?name=" . urlencode($displayName) . "&background=" . ltrim($themeColor, '#') . "&color=fff";
}

// --- FETCH CLINIC SERVICES PARA SA DROPDOWN ---
$clinicServicesList = [];
$servicePrices = [];
try {
    if ($tenant_id) {
        $stmtSrv = $pdo->prepare("SELECT service_name, price FROM clinic_services WHERE TenantID = ? ORDER BY service_name ASC");
        $stmtSrv->execute([$tenant_id]);
        $srvData = $stmtSrv->fetchAll(PDO::FETCH_ASSOC);
        foreach($srvData as $s) {
            $clinicServicesList[] = $s['service_name'];
            $servicePrices[$s['service_name']] = (float)$s['price'];
        }
    }
} catch (PDOException $e) {}

// ?? FETCH CLINIC STAFF (MIDWIFE/OB-GYN ONLY) PARA SA ADMISSION DROPDOWN ??
$clinicStaffList = [];
try {
    if ($tenant_id) {
        $stmtStaff = $pdo->prepare("
            SELECT first_name, middle_name, last_name, role 
            FROM clinic_staff 
            WHERE TenantID = ? AND status = 'Active' AND LOWER(TRIM(COALESCE(role, ''))) IN ('midwife', 'ob-gynecologist', 'pediatrician')
            ORDER BY first_name ASC
        ");
        $stmtStaff->execute([$tenant_id]);
        $clinicStaffList = $stmtStaff->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {}

// --- 3. NOTIFICATION COUNTERS (TENANT-ISOLATED) ---
try {
    $stmt1 = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE status != 'Cancelled' AND (is_admitted = 0 OR is_admitted IS NULL) AND TenantID = ?");
    $stmt1->execute([$tenant_id]);
    $n1 = $stmt1->fetchColumn();

    $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE() AND status != 'Cancelled' AND (is_admitted = 0 OR is_admitted IS NULL) AND TenantID = ?");
    $stmt2->execute([$tenant_id]);
    $n2 = $stmt2->fetchColumn();

    $stmt3 = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date > CURDATE() AND appointment_date <= DATE_ADD(CURDATE(), INTERVAL 5 DAY) AND status != 'Cancelled' AND (is_admitted = 0 OR is_admitted IS NULL) AND TenantID = ?");
    $stmt3->execute([$tenant_id]);
    $n3 = $stmt3->fetchColumn();

    $stmt4 = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE status = 'Cancelled' AND TenantID = ?");
    $stmt4->execute([$tenant_id]);
    $n4 = $stmt4->fetchColumn();
    
    $stmtNew = $pdo->prepare("SELECT full_name FROM appointments WHERE status != 'Cancelled' AND (is_admitted = 0 OR is_admitted IS NULL) AND TenantID = ? ORDER BY id DESC LIMIT 5");
    $stmtNew->execute([$tenant_id]);
    $new_appointments = $stmtNew->fetchAll(PDO::FETCH_COLUMN);

    $stmtCancel = $pdo->prepare("SELECT full_name FROM appointments WHERE status = 'Cancelled' AND TenantID = ? ORDER BY id DESC LIMIT 5");
    $stmtCancel->execute([$tenant_id]);
    $cancelled_appointments = $stmtCancel->fetchAll(PDO::FETCH_COLUMN);

    $totalNotifs = $n1 + $n2 + $n3 + $n4;
} catch (PDOException $e) { $totalNotifs = 0; }

// --- 4. BACKEND LOGIC (OPERATIONS) ---

// --- REMOVE LATE APPOINTMENT (MARK AS CANCELLED) ---
if (isset($_GET['remove_appt_id'])) {
    $removeId = intval($_GET['remove_appt_id']);
    try {
        $stmtCheck = $pdo->prepare("SELECT id, full_name, appointment_date, appointment_time, status FROM appointments WHERE id = ? AND TenantID = ? LIMIT 1");
        $stmtCheck->execute([$removeId, $tenant_id]);
        $toRemove = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        $isLateForRemove = false;
        if ($toRemove && $toRemove['status'] !== 'Cancelled' && $toRemove['status'] !== 'Admitted') {
            $apptTimeStr = !empty($toRemove['appointment_time']) ? $toRemove['appointment_time'] : '00:00:00';
            $apptDt = strtotime($toRemove['appointment_date'] . ' ' . $apptTimeStr);
            if ($apptDt && (time() - $apptDt) > 3600) {
                $isLateForRemove = true;
            } elseif ($toRemove['appointment_date'] < date('Y-m-d')) {
                $isLateForRemove = true;
            }
        }
        if ($isLateForRemove) {
            $stmtCancel = $pdo->prepare("UPDATE appointments SET status = 'Cancelled' WHERE id = ? AND TenantID = ?");
            $stmtCancel->execute([$removeId, $tenant_id]);
            header("Location: appointments.php?msg=Cancelled");
        } else {
            header("Location: appointments.php");
        }
    } catch (PDOException $e) {
        header("Location: appointments.php");
    }
    exit();
}

// --- ADMIT PATIENT LOGIC (TRANSFER TO ADMISSIONS & DELETE FROM APPOINTMENTS) ---
if (isset($_GET['approve_id'])) {
    $id = intval($_GET['approve_id']);
    $assigned_staff = trim($_GET['assigned_staff'] ?? 'Unassigned'); 
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT * FROM appointments WHERE id = ? AND TenantID = ?");
        $stmt->execute([$id, $tenant_id]);
        $appt = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($appt) {
            
            // ?? CHECK BY PATIENT_ID FIRST PARA IWAS 1062 DUPLICATE ERROR ??
            $check = $pdo->prepare("SELECT id, patient_id FROM patients WHERE patient_id = ? AND TenantID = ? LIMIT 1");
            $check->execute([$appt['patient_id'], $tenant_id]);
            $existing = $check->fetch();

            if (!$existing) {
                // Fallback check by name kung walang ID
                $checkName = $pdo->prepare("SELECT id, patient_id FROM patients WHERE full_name = ? AND TenantID = ? LIMIT 1");
                $checkName->execute([$appt['full_name'], $tenant_id]);
                $existing = $checkName->fetch();
            }

            $stmtPay = $pdo->prepare("SELECT service FROM payments WHERE TenantID = ? AND TRIM(full_name) = TRIM(?) ORDER BY payment_date DESC LIMIT 1");
            $stmtPay->execute([$tenant_id, $appt['full_name']]);
            $payService = $stmtPay->fetchColumn();
            
            $appService = !empty($appt['service']) ? trim($appt['service']) : null;
            $serviceToInsert = $appService ? $appService : $payService;
            
            // ?? KUKUNIN ANG BALANCE GALING SA APPOINTMENTS PARA IPASA SA ADMISSION ??
            $baseServicePrice = isset($servicePrices[$serviceToInsert]) ? (float)$servicePrices[$serviceToInsert] : 0.0;
            $hasApptBalance = isset($appt['remaining_balance']) && $appt['remaining_balance'] !== null && $appt['remaining_balance'] !== '';
            $rawBalanceCheck = $hasApptBalance ? (float)$appt['remaining_balance'] : $baseServicePrice;

            // Clamp para maiwasan negative/invalid balances.
            if ($rawBalanceCheck < 0) { $rawBalanceCheck = 0.0; }
            if ($baseServicePrice > 0 && $rawBalanceCheck > $baseServicePrice) { $rawBalanceCheck = $baseServicePrice; }

            // Logic to determine payment type
            if ($appt['status'] === 'Follow-up') {
                $paymentTypeToInsert = 'Follow-up';
                // Force base price as remaining balance so they have to pay full price
                $rawBalanceCheck = $baseServicePrice;
            } else {
                if ($rawBalanceCheck <= 0) {
                    $paymentTypeToInsert = 'Fully Paid';
                } elseif ($baseServicePrice > 0 && $rawBalanceCheck < $baseServicePrice) {
                    $paymentTypeToInsert = 'Downpayment';
                } elseif (strcasecmp((string)($appt['status'] ?? ''), 'Downpayment') === 0) {
                    $paymentTypeToInsert = 'Downpayment';
                } else {
                    $paymentTypeToInsert = 'Unpaid';
                }
            }

            $current_patient_id = ''; // Gagawa tayo ng variable para hawakan ang final patient ID

            if ($existing) {
                $final_db_id = $existing['id'];
                $final_pt_id = $existing['patient_id'];
                $current_patient_id = $final_pt_id; // Ipasa ang ID
                
                $stmtUpdatePat = $pdo->prepare("UPDATE patients SET service = ?, payment_type = ? WHERE id = ?");
                $stmtUpdatePat->execute([$serviceToInsert, $paymentTypeToInsert, $final_db_id]);
                
            } else {
                
                // Double check uniqueness of patient_id before insert to prevent error 1062
                $pt_id_to_insert = $appt['patient_id'];
                $chkDup = $pdo->prepare("SELECT id FROM patients WHERE patient_id = ?");
                $chkDup->execute([$pt_id_to_insert]);
                if($chkDup->fetch()) {
                    $pt_id_to_insert = "PT-" . date("Y") . "-" . rand(1000, 9999);
                }
                
                $current_patient_id = $pt_id_to_insert; // Ipasa ang ID

                $sqlInsert = "INSERT INTO patients (
                    TenantID, patient_id, full_name, email_address, age, birthday, 
                    menarche, civil_status, religion, occupation, contact_number, 
                    address, husband_name, father_name, mother_name, medical_history, 
                    philhealth_id, philhealth_id_pic, service, payment_type, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, 
                    ?, ?, ?, ?, ?, 
                    ?, ?, ?, ?, ?, 
                    ?, ?, ?, ?, ?,
                    NOW()
                )";
                
                $phRef = isset($appt['philhealth_id']) ? $appt['philhealth_id'] : (isset($appt['philhealth_id_pic']) ? $appt['philhealth_id_pic'] : null);

                $stmtInsert = $pdo->prepare($sqlInsert);
                $stmtInsert->execute([
                    $tenant_id, $pt_id_to_insert, $appt['full_name'], $appt['email_address'] ?? null, 
                    $appt['age'], $appt['birthday'], $appt['menarche'], 
                    $appt['civil_status'], $appt['religion'], $appt['occupation'], 
                    $appt['contact_number'], $appt['address'], $appt['husband_name'], 
                    $appt['father_name'], $appt['mother_name'], $appt['medical_history'],
                    $phRef, $appt['philhealth_id_pic'] ?? null, $serviceToInsert, $paymentTypeToInsert
                ]);
            }
            
            // ?? CREATE ADMISSION RECORD FIRST (BEFORE DELETE PARA DI MA-VIOLATE FK) ??
            $stmtCheckAdm = $pdo->prepare("SELECT id FROM admissions WHERE TenantID = ? AND full_name = ? AND status IN ('Pending', 'Admitted')");
            $stmtCheckAdm->execute([$tenant_id, $appt['full_name']]);
            if (!$stmtCheckAdm->fetch()) {
                $stmtAdmit = $pdo->prepare("INSERT INTO admissions (TenantID, patient_id, full_name, reason, admission_date, status, assigned_staff, stage, remaining_balance, payment_type) VALUES (?, ?, ?, ?, NOW(), 'Pending', ?, 'Waiting', ?, ?)");
                $stmtAdmit->execute([$tenant_id, $current_patient_id, $appt['full_name'], $serviceToInsert, $assigned_staff, $rawBalanceCheck, $paymentTypeToInsert]);
            }

            // ?? MARK AS ADMITTED INSTEAD OF DELETE — PARA MANATILI SA TABLE ??
            $stmtUpdate = $pdo->prepare("UPDATE appointments SET status = 'Admitted', is_admitted = 1 WHERE id = ? AND TenantID = ?");
            $stmtUpdate->execute([$id, $tenant_id]);
        }
        
        $pdo->commit();
        header("Location: admissions.php?msg=admitted");
        exit();
    } catch (PDOException $e) { 
        $pdo->rollBack(); 
        die("Fatal Error during Admission: " . $e->getMessage()); 
    }
}

// --- SAVE NEW APPOINTMENT (MANUAL STAFF ENTRY) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_appointment'])) {
    try {
        $appt_date = strtotime($_POST['checkup_date']);
        $today = strtotime(date('Y-m-d'));
        if ($appt_date < $today) {
            throw new Exception("Cannot book appointments for past dates. Please select today or a future date.");
        }
        
        $full_name = trim($_POST['first_name']." ".$_POST['middle_name']." ".$_POST['surname']);
        $check = $pdo->prepare("SELECT patient_id FROM patients WHERE full_name = ? AND TenantID = ? LIMIT 1");
        $check->execute([$full_name, $tenant_id]);
        $old_pt_id = $check->fetchColumn();

        if ($old_pt_id) { $patient_id = $old_pt_id; } 
        else {
            $isUnique = false;
            while (!$isUnique) {
                $temp_id = "PT-" . date("Y") . "-" . rand(1000, 9999);
                $stmt_check = $pdo->prepare("SELECT id FROM patients WHERE patient_id = ? AND TenantID = ? UNION SELECT id FROM appointments WHERE patient_id = ? AND TenantID = ?");
                $stmt_check->execute([$temp_id, $tenant_id, $temp_id, $tenant_id]);
                if (!$stmt_check->fetch()) { $patient_id = $temp_id; $isUnique = true; }
            }
        }
        $birthDate = new DateTime($_POST['birthday']);
        $age = $birthDate->diff(new DateTime('today'))->y;
        
        $service = !empty($_POST['service']) ? trim($_POST['service']) : null;
        $price = isset($servicePrices[$service]) ? (float)$servicePrices[$service] : 0;

        // Force Manual entries to be treated as basic "Pending" so it expects full payment
        $sql = "INSERT INTO appointments (TenantID, patient_id, full_name, age, birthday, menarche, civil_status, religion, occupation, contact_number, address, husband_name, father_name, mother_name, appointment_date, appointment_time, service, medical_history, status, remaining_balance) 
            VALUES (:tenant, :pid, :name, :age, :bday, :menarche, :status, :rel, :occ, :contact, :addr, :hname, :fname, :mname, :appt_date, :appt_time, :service, :history, 'Pending', :rem_bal)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':tenant' => $tenant_id, ':pid' => $patient_id, ':name' => $full_name, ':age' => $age,
            ':bday' => $_POST['birthday'], ':menarche' => $_POST['menarche'], ':status' => $_POST['civil_status'],
            ':rel' => $_POST['religion'], ':occ' => $_POST['occupation'], ':contact' => $_POST['contact_number'], 
            ':addr' => $_POST['address'],
            ':hname' => trim($_POST['h_first'] ?? "")." ".trim($_POST['h_middle'] ?? "")." ".trim($_POST['h_last'] ?? ""), 
            ':fname' => trim($_POST['d_first'] ?? "")." ".trim($_POST['d_middle'] ?? "")." ".trim($_POST['d_last'] ?? ""), 
            ':mname' => trim($_POST['m_first'] ?? "")." ".trim($_POST['m_middle'] ?? "")." ".trim($_POST['m_last'] ?? ""),
            ':appt_date' => $_POST['checkup_date'], ':appt_time' => $_POST['checkup_time'] ?? null, 
            ':service' => $service, ':history' => $_POST['medical_history'], ':rem_bal' => $price
        ]);

        header("Location: appointments.php?msg=Saved");
        exit();
    } catch (PDOException $e) { $message = "? Error: " . $e->getMessage(); }
    catch (Exception $e) { $message = "? " . $e->getMessage(); }
}

// FETCH ONLY APPOINTMENTS FOR CURRENT TENANT WITH LEFT JOIN ON PATIENTS
$stmt = $pdo->prepare("
    SELECT a.*, 
           COALESCE(p.full_name, a.full_name) as display_name,
           COALESCE(p.age, a.age) as display_age,
           COALESCE(p.birthday, a.birthday) as display_birthday,
           COALESCE(p.civil_status, a.civil_status) as display_civil_status,
           COALESCE(p.contact_number, a.contact_number) as display_contact_number,
           COALESCE(p.religion, a.religion) as display_religion,
           COALESCE(p.occupation, a.occupation) as display_occupation,
           COALESCE(p.menarche, a.menarche) as display_menarche,
           a.email_address as display_email,
           COALESCE(p.address, a.address) as display_address,
           COALESCE(p.husband_name, a.husband_name) as display_husband_name,
           COALESCE(p.father_name, a.father_name) as display_father_name,
           COALESCE(p.mother_name, a.mother_name) as display_mother_name,
           p.profile_image as patient_image,
           p.profile_pic_url as profile_pic_url,
           p.payment_type as payment_type,
           (SELECT service FROM payments pay WHERE pay.TenantID = a.TenantID AND TRIM(pay.full_name) = TRIM(COALESCE(p.full_name, a.full_name)) ORDER BY pay.id DESC LIMIT 1) as fetched_payment_service
    FROM appointments a
    LEFT JOIN patients p 
           ON a.patient_id = p.patient_id AND a.TenantID = p.TenantID
    WHERE a.TenantID = ? AND (a.is_admitted = 0 OR a.is_admitted IS NULL)
    ORDER BY a.appointment_date ASC
");
$stmt->execute([$tenant_id]);
$all_appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// MGA COUNTER PARA SA BREADCRUMBS
$active_count = 0;
$cancelled_count = 0;

foreach ($all_appointments as &$appt_check) {
    $appt_check['full_name'] = $appt_check['display_name'];
    $appt_check['age'] = $appt_check['display_age'];
    $appt_check['birthday'] = $appt_check['display_birthday'];
    $appt_check['civil_status'] = $appt_check['display_civil_status'];
    $appt_check['contact_number'] = $appt_check['display_contact_number'];
    $appt_check['religion'] = $appt_check['display_religion'];
    $appt_check['occupation'] = $appt_check['display_occupation'];
    $appt_check['menarche'] = $appt_check['display_menarche'];
    $appt_check['email_address'] = $appt_check['display_email'];
    $appt_check['address'] = $appt_check['display_address'];
    $appt_check['husband_name'] = $appt_check['display_husband_name'];
    $appt_check['father_name'] = $appt_check['display_father_name'];
    $appt_check['mother_name'] = $appt_check['display_mother_name'];

    // ?? LOGIC PARA SA BADGE / STATUS ??
    // Kung Admitted na, huwag na baguhin ang status
    if ($appt_check['status'] === 'Admitted') {
        // Keep as Admitted — do not override
    } elseif ($appt_check['status'] !== 'Cancelled') {
        // Kung may payment_type sa patients table, iyon ang gagamiting status
        if (!empty($appt_check['payment_type'])) {
            $appt_check['status'] = $appt_check['payment_type'];
        }

        // Kung may remaining balance pa, Downpayment; kung 0 na, Fully Paid
        $apptBalance = isset($appt_check['remaining_balance']) ? (float)$appt_check['remaining_balance'] : 0.0;
        if ($appt_check['status'] !== 'Follow-up') {
            if ($apptBalance <= 0.0001) {
                $appt_check['status'] = 'Fully Paid';
            } else {
                $appt_check['status'] = 'Downpayment';
            }
        }
    }

    if ($appt_check['status'] === 'Admitted') {
        $active_count++;
        $appt_check['tab_category'] = 'active-appt';
    } elseif ($appt_check['status'] === 'Cancelled') {
        $cancelled_count++;
        $appt_check['tab_category'] = 'cancelled-appt';
    } else {
        $active_count++;
        $appt_check['tab_category'] = 'active-appt';
    }

    $service_from_appt = !empty($appt_check['service']) ? trim($appt_check['service']) : '';
    $service_from_pay = !empty($appt_check['fetched_payment_service']) ? trim($appt_check['fetched_payment_service']) : '';

    if ($service_from_appt !== '') {
        $appt_check['display_service'] = $service_from_appt; 
    } elseif ($service_from_pay !== '') {
        $appt_check['display_service'] = $service_from_pay; 
    } else {
        $appt_check['display_service'] = '�'; 
    }
}
unset($appt_check);

$calendar_json = [];
foreach($all_appointments as $a) {
    if ($a['status'] !== 'Cancelled') {
        $d = date('Y-m-d', strtotime($a['appointment_date']));
        $calendar_json[$d][] = $a; 
    }
}

$appointmentsVersionPayload = [];
foreach ($all_appointments as $row) {
    $appointmentsVersionPayload[] = [
        'id' => (int)($row['id'] ?? 0),
        'status' => (string)($row['status'] ?? ''),
        'remaining_balance' => (float)($row['remaining_balance'] ?? 0),
        'appointment_date' => (string)($row['appointment_date'] ?? ''),
        'appointment_time' => (string)($row['appointment_time'] ?? ''),
    ];
}
$appointmentsVersion = md5(json_encode($appointmentsVersionPayload));

if (isset($_GET['ajax']) && $_GET['ajax'] === 'appointments_feed') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'version' => $appointmentsVersion,
        'active_count' => (int)$active_count,
        'cancelled_count' => (int)$cancelled_count,
        'appointments' => $all_appointments,
        'calendar' => $calendar_json,
    ]);
    exit();
}
// Sidebar active style based on theme brightness (match tenantsettings)
$sidebarActive = $isLightTheme ? "bg-slate-800 text-white shadow-md" : "bg-primary/10 text-primary";

// --- OWNER / STAFF ADMIN PERMISSION SYSTEM ---
$currentUserIsOwner = in_array($normalizedRole, ['admin', 'administrator', 'owner', 'owner/midwife'], true);
$currentUserIsStaffAdmin = false;
$currentUserGrantedFeatures = [];
if (!$currentUserIsOwner && $tenant_id) {
    try {
        $stmtCurAccess = $pdo->prepare("SELECT cs.is_admin, cs.granted_features FROM clinic_staff cs INNER JOIN users u ON LOWER(TRIM(cs.email_address)) = LOWER(TRIM(u.email)) WHERE cs.TenantID = ? AND u.id = ? LIMIT 1");
        $stmtCurAccess->execute([$tenant_id, $current_staff_id]);
        $curAccess = $stmtCurAccess->fetch(PDO::FETCH_ASSOC);
        if ($curAccess) {
            $currentUserIsStaffAdmin = (bool)($curAccess['is_admin'] ?? false);
            $currentUserGrantedFeatures = json_decode($curAccess['granted_features'] ?? '[]', true) ?: [];
        }
    } catch (PDOException $e) {}
}
$_ownerAlsoMidwife = false;
if ($currentUserIsOwner && $tenant_id) {
    try { $_stmtMw = $pdo->prepare("SELECT COALESCE(also_midwife, 0) FROM users WHERE id = ? AND TenantID = ? LIMIT 1"); $_stmtMw->execute([$current_staff_id, $tenant_id]); $_ownerAlsoMidwife = ((int)$_stmtMw->fetchColumn() === 1); } catch (PDOException $e) {}
}
if ($currentUserIsOwner) { $displayRole = $_ownerAlsoMidwife ? 'Owner / Midwife' : 'Owner'; }
elseif ($currentUserIsStaffAdmin) { $displayRole = $displayRole . ' | Admin'; }

?>

<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Appointments - <?= htmlspecialchars($clinicName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = { 
            theme: { 
                extend: { 
                    colors: {
                        "primary": "<?= htmlspecialchars($themeColor) ?>",
                        "primary-dark": "color-mix(in srgb, <?= htmlspecialchars($themeColor) ?> 70%, black)",
                        "primary-light": "color-mix(in srgb, <?= htmlspecialchars($themeColor) ?> 20%, white)",
                        "background-light": "color-mix(in srgb, <?= htmlspecialchars($themeColor) ?> 4%, white)"
                    }, 
                    fontFamily: { "display": ["Plus Jakarta Sans", "sans-serif"] },
                    boxShadow: {
                        'soft': '0 10px 40px -10px rgba(0,0,0,0.08)'
                    }
                } 
            } 
        }
    </script>
    <style>
        html, body { margin: 0; padding: 0; scroll-behavior: smooth; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; overflow: hidden; }
        .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        .icon-filled { font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        .modal-bg { display: none; position: fixed; inset: 0; z-index: 100; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-bg.active { display: flex; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; background-color: transparent; border: none; overflow: visible; }
        .calendar-day { background: white; min-height: 120px; padding: 12px; position: relative; border-radius: 12px; border: 1px solid #e2e8f0; display: flex; flex-direction: column; transition: all 0.2s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .calendar-day:hover:not(.disabled) { box-shadow: 0 4px 12px color-mix(in srgb, var(--theme-primary) 20%, transparent); transform: translateY(-2px); border-color: var(--theme-primary); }
        .calendar-day.disabled { background: #f8f8f8; color: #cbd5e1; opacity: 0.5; }
        .calendar-day.disabled:hover { box-shadow: none; transform: none; }
        .calendar-day.bg-slate-50 { background: color-mix(in srgb, var(--theme-primary) 6%, white); }
        .calendar-header { background: linear-gradient(135deg, var(--theme-primary) 0%, color-mix(in srgb, var(--theme-primary) 70%, black) 100%); text-align: center; padding: 14px 8px; font-weight: 900; font-size: 11px; text-transform: uppercase; color: white; border-radius: 8px 8px 0 0; letter-spacing: 0.5px; }
        .today-bg { background: linear-gradient(135deg, color-mix(in srgb, var(--theme-primary) 18%, white) 0%, color-mix(in srgb, var(--theme-primary) 6%, white) 100%) !important; border: 2px solid var(--theme-primary) !important; box-shadow: 0 0 0 3px color-mix(in srgb, var(--theme-primary) 16%, transparent), 0 4px 12px color-mix(in srgb, var(--theme-primary) 24%, transparent) !important; }
        .today-badge { color: var(--theme-primary); font-weight: 900; font-size: 14px; }
        @view-transition { navigation: auto; }
        header { view-transition-name: header; }
        aside { view-transition-name: sidebar; }
        ::view-transition-old(sidebar), ::view-transition-new(sidebar),
        ::view-transition-old(header), ::view-transition-new(header) { animation: none; }
    </style>
</head>
<body class="bg-background-light text-slate-800 h-screen overflow-hidden flex flex-col relative text-sm antialiased font-display">

<style>
    :root { --theme-primary: <?= htmlspecialchars($themeColor) ?>; }
</style>

<?php if(isset($_GET['msg'])): ?>
<div id="alertMsg" class="fixed top-24 left-1/2 -translate-x-1/2 z-[120] bg-white border-l-4 border-primary p-4 rounded-2xl shadow-2xl flex items-center gap-3 animate-bounce">
    <span class="material-symbols-outlined text-primary">check_circle</span>
    <p class="text-xs font-black text-slate-800 tracking-tight">Record Successfully <?= htmlspecialchars($_GET['msg']) ?>!</p>
    <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-slate-600"><span class="material-symbols-outlined text-sm">close</span></button>
</div>
<script>setTimeout(() => { document.getElementById('alertMsg')?.remove(); }, 4000);</script>
<?php endif; ?>

<?php if(!empty($message)): ?>
<div id="alertMsg" class="fixed top-24 left-1/2 -translate-x-1/2 z-[120] bg-white border-l-4 border-red-500 p-4 rounded-2xl shadow-2xl flex items-center gap-3 animate-bounce">
    <span class="material-symbols-outlined text-red-500">error</span>
    <p class="text-xs font-black text-slate-800 tracking-tight"><?= htmlspecialchars($message) ?></p>
    <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-slate-600"><span class="material-symbols-outlined text-sm">close</span></button>
</div>
<script>setTimeout(() => { document.getElementById('alertMsg')?.remove(); }, 5000);</script>
<?php endif; ?>

<div id="loggingOutScreen" class="fixed inset-0 z-[110] hidden bg-white flex-col items-center justify-center">
    <div class="size-12 border-4 border-slate-200 border-t-primary rounded-full animate-spin mb-4"></div>
    <p class="font-bold text-slate-800 animate-pulse tracking-tight text-xs">Logging out safely...</p>
</div>

<div id="logoutModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm">
    <div class="bg-white rounded-[2rem] p-6 max-w-xs w-full shadow-2xl border border-slate-100 text-center">
        <div class="size-12 rounded-2xl bg-red-50 text-red-500 flex items-center justify-center mx-auto mb-4">
            <span class="material-symbols-outlined text-2xl">logout</span>
        </div>
        <h3 class="text-base font-black text-slate-900 mb-1">Logout Account?</h3>
        <p class="text-slate-500 text-[11px] mb-6">Sigurado ka bang gusto mong lumabas?</p>
        <div class="flex gap-2">
            <button onclick="closeModal('logoutModal')" class="flex-1 py-2.5 rounded-xl font-bold text-slate-400 hover:bg-slate-100 transition-all text-[11px]">Cancel</button>
            <button onclick="confirmLogout()" class="flex-1 py-2.5 rounded-xl font-bold bg-red-500 text-white hover:bg-red-600 transition-all text-[11px] shadow-lg shadow-red-100">Logout</button>
        </div>
    </div>
</div>

<div id="admitConfirmModal" class="modal-bg">
    <div class="bg-white rounded-[2rem] p-8 max-w-sm w-full shadow-2xl border border-slate-100 text-center transform transition-all duration-300">
        <div id="admitIconContainer" class="size-16 rounded-3xl flex items-center justify-center mx-auto mb-4 transition-colors">
            <span id="admitIcon" class="material-symbols-outlined text-3xl">info</span>
        </div>
        <h3 id="admitTitle" class="text-lg font-black text-slate-900 mb-2">Transfer to Admission</h3>
        <p id="admitMessage" class="text-slate-500 text-xs mb-6 px-2 leading-relaxed">Are you sure you want to admit this patient?</p>
        
        <form action="" method="GET" class="text-left flex flex-col gap-4">
            <input type="hidden" name="approve_id" id="admitApproveId" value="">
            <input type="hidden" name="assigned_staff" id="admitAutoStaff" value="" disabled>

            <div id="admitStaffSelectBox" class="bg-slate-50 p-4 rounded-xl border border-slate-100 mb-2">
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Assign to Staff/Doctor <span class="text-red-500">*</span></label>
                <select name="assigned_staff" id="admitStaffSelect" required class="w-full rounded-lg border border-slate-300 bg-white p-2.5 text-sm font-bold text-slate-700 outline-none focus:border-primary shadow-sm cursor-pointer">
                    <option value="">-- Select Staff --</option>
                    <option value="Unassigned">Assign Later (Unassigned)</option>
                    <?php foreach($clinicStaffList as $staff): 
                        $staffFullName = trim($staff['first_name'] . ' ' . $staff['middle_name'] . ' ' . $staff['last_name']);
                    ?>
                        <option value="<?= htmlspecialchars($staffFullName) ?>"><?= htmlspecialchars($staffFullName) ?> (<?= htmlspecialchars($staff['role']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="admitAutoStaffInfo" class="hidden bg-green-50 p-4 rounded-xl border border-green-200 mb-2">
                <div class="flex items-center gap-3">
                    <div class="size-8 rounded-lg bg-green-100 text-green-600 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-lg">person_check</span>
                    </div>
                    <div class="text-left">
                        <p class="text-[10px] font-black text-green-500 uppercase tracking-widest">Auto-Assigned Staff</p>
                        <p id="admitAutoStaffName" class="text-sm font-bold text-green-800"></p>
                    </div>
                </div>
            </div>

            <div class="flex gap-3">
                <button type="button" onclick="closeModal('admitConfirmModal')" class="flex-1 py-3 rounded-xl font-bold text-slate-500 bg-slate-100 hover:bg-slate-200 transition-all text-xs">Cancel</button>
                <button type="submit" id="confirmAdmitBtn" class="flex-1 py-3 rounded-xl font-bold text-white transition-all text-xs shadow-lg bg-primary hover:bg-primary-dark">Proceed</button>
            </div>
        </form>
    </div>
</div>

<!-- REMOVE LATE APPOINTMENT MODAL -->
<div id="removeApptModal" class="modal-bg">
    <div class="bg-white rounded-[2rem] p-8 max-w-sm w-full shadow-2xl border border-slate-100 text-center">
        <div class="size-16 rounded-3xl bg-red-50 text-red-500 flex items-center justify-center mx-auto mb-4 border border-red-100">
            <span class="material-symbols-outlined text-3xl">event_busy</span>
        </div>
        <h3 class="text-lg font-black text-slate-900 mb-2">Remove Appointment?</h3>
        <p class="text-slate-500 text-xs mb-1 px-2 leading-relaxed">This appointment is past its scheduled date.</p>
        <p id="removeApptName" class="text-sm font-black text-slate-800 mb-6"></p>
        <div class="flex gap-3">
            <button type="button" onclick="closeModal('removeApptModal')" class="flex-1 py-3 rounded-xl font-bold text-slate-500 bg-slate-100 hover:bg-slate-200 transition-all text-xs">Cancel</button>
            <a id="removeApptLink" href="#" class="flex-1 py-3 rounded-xl font-bold text-white bg-red-500 hover:bg-red-600 transition-all text-xs shadow-lg flex items-center justify-center">Remove</a>
        </div>
    </div>
</div>

<header class="h-20 bg-primary <?= $isLightTheme ? 'border-b border-slate-200' : 'border-b border-primary-dark' ?> flex items-center justify-between px-6 md:px-12 sticky top-0 z-50 shrink-0 shadow-soft relative transition-colors duration-300">
    <div class="flex items-center gap-4">
        <div class="size-12 rounded-full <?= $headerIconBox ?> overflow-hidden flex items-center justify-center shrink-0 border">
            <?php if ($clinicLogo): ?>
                <img src="<?= htmlspecialchars($clinicLogo) ?>" alt="Clinic Logo" class="size-full object-cover">
            <?php else: ?>
                <span class="material-symbols-outlined <?= $headerIconColor ?> text-2xl">domain</span>
            <?php endif; ?>
        </div>
        <div class="flex flex-col justify-center <?= $headerTextPrimary ?>">
            <h1 class="text-lg font-bold leading-none tracking-tight"><?= htmlspecialchars($clinicName) ?></h1>
            <div class="flex items-center gap-2 mt-1">
                <p class="<?= $headerTextSecondary ?> text-[10px] font-bold uppercase tracking-widest opacity-90">POWERED BY MATERNITYHUB</p>
                <span class="<?= $headerTextMuted ?> text-[10px]">|</span>
                <p class="<?= $headerBadgeBg ?> px-2 py-0.5 rounded text-[10px] font-black tracking-widest flex items-center gap-1">CODE: <?= htmlspecialchars($clinicCode) ?></p>
            </div>
        </div>
    </div>
    
    <div class="flex items-center gap-4 ml-auto">
        <div class="hidden sm:flex flex-col text-right justify-center <?= $headerTextPrimary ?>">
            <p class="text-sm font-bold leading-none"><?= htmlspecialchars($displayName) ?></p>
            <p class="<?= $headerTextSecondary ?> text-[9px] italic opacity-80 mt-1 uppercase tracking-tighter"><?= htmlspecialchars($displayRole) ?></p>
        </div>
        <button onclick="openLogoutModal()" class="flex items-center gap-2 <?= $headerBtn ?> border px-4 py-2 rounded-xl text-xs font-bold transition-all active:scale-95">
            <span class="material-symbols-outlined text-sm">logout</span>
            <span class="hidden md:inline">Logout</span>
        </button>
    </div>
</header>

<div class="flex-1 flex overflow-hidden">
    <aside class="w-80 bg-white border-r border-slate-200 hidden md:flex flex-col shrink-0 overflow-hidden" style="visibility:hidden">
        <nav id="sidebarNav" class="space-y-3 flex-1 p-6 overflow-y-auto">
            <p class="text-xs font-black text-slate-400 uppercase tracking-widest px-4 mb-2">Main Menu</p>

            <a href="dashboard.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all">
                <span class="material-symbols-outlined text-2xl">dashboard</span> <span>Dashboard</span>
            </a>
            <a href="appointments.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] <?= $sidebarActive ?> font-bold shadow-sm transition-all hover:scale-[1.02]">
                <span class="material-symbols-outlined text-2xl icon-filled">calendar_today</span> <span>Appointments</span>
            </a>
            <a href="admissions.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all">
                <span class="material-symbols-outlined text-2xl">how_to_reg</span> <span>Admissions</span>
            </a>
            <a href="room.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all">
                <span class="material-symbols-outlined text-2xl">bed</span> <span>Rooms</span>
            </a>
            <a href="patientrecords.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all">
                <span class="material-symbols-outlined text-2xl">folder_shared</span> <span>Patients</span>
            </a>
                <a href="staffmanagement.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all">
                    <span class="material-symbols-outlined text-2xl">badge</span> <span>Accounts</span>
                </a>

            <div class="space-y-3 mt-4 mb-4">
                <p class="text-xs font-black text-slate-400 uppercase tracking-widest px-4 mb-2 mt-6">Operations</p>
                <?php if ($currentUserIsOwner || $currentUserIsStaffAdmin || in_array('financials', $currentUserGrantedFeatures)): ?>
                <a href="financials.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">payments</span> <span>Financials</span>
                </a>
                <?php endif; ?>
                <?php if ($currentUserIsOwner || $currentUserIsStaffAdmin || in_array('reports', $currentUserGrantedFeatures)): ?>
                <a href="report.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">bar_chart</span> <span>Reports</span>
                </a>
                <?php endif; ?>
                <?php if ($currentUserIsOwner || $currentUserIsStaffAdmin || in_array('help_support', $currentUserGrantedFeatures)): ?>
                <a href="support.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">support_agent</span> <span>Help & Support</span>
                </a>
                <?php endif; ?>
                <?php if ($currentUserIsOwner || $currentUserIsStaffAdmin || in_array('feedback', $currentUserGrantedFeatures)): ?>
                <a href="feedback.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">feedback</span> <span>Feedback</span>
                </a>
                <?php endif; ?>
                <?php if ($currentUserIsOwner || $currentUserIsStaffAdmin): ?>
                <a href="tenantauditlogs.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">history</span> <span>Audit Logs</span>
                </a>
                <?php endif; ?>
                <a href="<?= $currentUserIsOwner ? 'tenantsettings.php' : 'staffsettings.php' ?>" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">settings</span> <span>Settings</span>
                </a>
            </div>
        </nav>
        <script>!function(){var s=document.getElementById('sidebarNav');if(!s)return;var k='sidebarScroll';s.scrollTop=parseInt(sessionStorage.getItem(k)||'0',10);s.closest('aside').style.visibility='visible';window.addEventListener('beforeunload',function(){sessionStorage.setItem(k,s.scrollTop)})}();</script>
        
        <div class="mt-auto px-6 pt-6 pb-4 border-t border-slate-100">
            <div class="bg-slate-50 rounded-3xl p-4 flex items-center gap-4">
                <div class="size-12 rounded-full bg-cover bg-center border-2 border-white shadow-sm" style="background-image: url('<?= htmlspecialchars($profilePic) ?>');"></div>
                <div class="overflow-hidden">
                    <p class="text-sm font-bold text-slate-900 truncate"><?= htmlspecialchars($displayName) ?></p>
                    <p class="text-[10px] text-slate-500 italic">Online</p>
                </div>
            </div>
        </div>
    </aside>

    <main class="flex-1 overflow-y-auto p-4 md:p-8 bg-background-light">
        <div class="max-w-7xl mx-auto space-y-6">

            <div class="space-y-6">
                <div class="bg-white border border-slate-200 rounded-[2rem] shadow-sm overflow-hidden">
                    <div class="p-5 border-b flex justify-between items-center bg-slate-50/80 backdrop-blur">
                        <h2 class="text-[11px] font-black text-slate-800 uppercase tracking-widest flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary text-base font-bold">calendar_today</span> Calendar
                        </h2>
                        <div class="flex items-center gap-4 mr-4">
                            <div class="flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-blue-500 inline-block"></span><span class="text-[9px] font-bold text-slate-500">Appointment for the day</span></div>
                            <div class="flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-green-500 inline-block"></span><span class="text-[9px] font-bold text-green-600">Your appointment for the day</span></div>
                        </div>
                        <div class="flex gap-1">
                            <select id="monthFilter" onchange="updateCalendar()" class="bg-transparent border-none text-[10px] font-black uppercase py-0 focus:ring-0 cursor-pointer">
                                <?php 
                                    $months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
                                    foreach($months as $idx => $m) echo "<option value='$idx' ".($idx==date('n')-1?'selected':'').">$m</option>"; 
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="calendar-grid" id="calendarGrid"></div>
                </div>

                <div class="bg-white border border-slate-200 rounded-[2.5rem] shadow-sm overflow-hidden">
                    
                    <div class="px-8 pt-6 border-b bg-white flex gap-6 overflow-x-auto sticky top-0 z-20 backdrop-blur supports-[backdrop-filter]:bg-white/95">
                        <button onclick="switchTab('active')" id="tab-active" class="w-52 shrink-0 pb-4 border-b-[3px] border-emerald-500 text-emerald-500 font-bold text-sm flex items-center justify-center gap-2 transition-all whitespace-nowrap">
                            Appointments 
                            <span id="activeCountBadge" class="bg-emerald-100 text-emerald-600 py-0.5 px-2 rounded-full text-[10px] font-black"><?= $active_count ?></span>
                        </button>
                        <button onclick="switchTab('cancelled')" id="tab-cancelled" class="w-52 shrink-0 pb-4 border-b-[3px] border-transparent text-slate-500 hover:text-slate-700 font-bold text-sm flex items-center justify-center gap-2 transition-all whitespace-nowrap">
                            Cancelled 
                            <span id="cancelledCountBadge" class="bg-rose-100 text-rose-600 py-0.5 px-2 rounded-full text-[10px] font-black"><?= $cancelled_count ?></span>
                        </button>
                      
                    </div>
                    
                    <div class="overflow-x-auto overflow-y-auto max-h-[360px] scrollable-box relative">
                        <table class="w-full text-left">
                            <thead class="sticky top-0 z-10 bg-slate-50/95 backdrop-blur border-b text-[10px] font-bold uppercase text-slate-400 tracking-widest">
                                <tr>
                                    <th class="px-8 py-4">Patient Name</th>
                                    <th class="px-8 py-4 text-center">Visit Date</th>
                                    <th class="px-8 py-4 text-center">Visit Time</th>
                                    <th class="px-8 py-4 text-center">Service</th>
                                    <th class="px-8 py-4 text-center">Preferred Midwife</th>
                                    <th class="px-8 py-4 text-center">Status</th>
                                    <th class="px-8 py-4 text-right">Remaining Balance</th>
                                    <th class="px-8 py-4 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100" id="apptTableBody">
                                <?php foreach($all_appointments as $appt): 
                                    $displayStatus = $appt['status'];
                                    
                                    $isPaid = ($displayStatus == 'Fully Paid' || $displayStatus == 'Downpayment' || $displayStatus == 'Follow-up');
                                    
                                    $displayService = $appt['display_service'];
                                    
                                    $sColor = match($displayStatus) {
                                        'Fully Paid' => "bg-green-100 text-green-700",
                                        'Downpayment' => "bg-blue-100 text-blue-700",
                                        'Follow-up' => "bg-purple-100 text-purple-700",
                                        'Cancelled' => "bg-red-100 text-red-700",
                                        default => "bg-slate-100 text-slate-700"
                                        };
                                        
                                    $balance = number_format((float)($appt['remaining_balance'] ?? 0), 2);
                                    
                                    $jsDate = $appt['appointment_date'];
                                    $jsTime = !empty($appt['appointment_time']) ? $appt['appointment_time'] : '';
                                    // Late = scheduled datetime is more than 1 hour in the past (matches LATE badge in JS)
                                    $isLate = false;
                                    if ($appt['status'] !== 'Cancelled' && $appt['status'] !== 'Admitted') {
                                        $apptTimeForCheck = !empty($appt['appointment_time']) ? $appt['appointment_time'] : '00:00:00';
                                        $apptDtCheck = strtotime($appt['appointment_date'] . ' ' . $apptTimeForCheck);
                                        if ($apptDtCheck && (time() - $apptDtCheck) > 3600) {
                                            $isLate = true;
                                        }
                                    }
                                    
                                    // Set visibility default: hide non-active items initially
                                    $isHidden = ($appt['tab_category'] !== 'active-appt') ? 'hidden' : '';
                                    $staffDisplay = !empty($appt['assigned_staff']) ? $appt['assigned_staff'] : ($appt['assigned_midwife'] ?? '');
                                    ?>
                                    <?php $isMyAppt = strtolower(trim($staffDisplay)) === strtolower(trim($displayName)); ?>
                                    <tr class="appt-row <?= $appt['tab_category'] ?> <?= $isHidden ?> <?= $isMyAppt ? 'bg-green-100 hover:bg-green-200/70' : 'hover:bg-slate-50/50' ?> transition-all">
                                        <td class="px-8 py-5">
                                            <div class="flex items-center gap-3">
                                                <?php 
                                                    $profPic = $appt['profile_pic_url'] ?? '';
                                                    if (!empty($profPic)): 
                                                ?>
                                                    <img src="<?= htmlspecialchars($profPic) ?>" class="w-8 h-8 rounded-full object-cover ring-2 ring-slate-200" alt="">
                                                <?php else: ?>
                                                    <div class="w-8 h-8 rounded-full bg-slate-200 flex items-center justify-center">
                                                        <span class="material-symbols-outlined text-slate-400 text-sm">person</span>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="font-bold text-slate-800 text-sm"><?= htmlspecialchars($appt['full_name']) ?></div>
                                            </div>
                                        </td>
                                        <td class="px-8 py-5 text-center">
                                            <div class="text-[11px] font-medium text-slate-400 uppercase tracking-wider"><?= date('M d, Y', strtotime($appt['appointment_date'])) ?></div>
                                        </td>
                                        <td class="px-8 py-5 text-center">
                                            <div class="text-[11px] font-bold text-slate-700 tracking-wider"><?= isset($appt['appointment_time']) ? convertTo12HourFormat($appt['appointment_time']) : '�' ?></div>
                                        </td>
                                        <td class="px-8 py-5 text-center">
                                            <div class="text-[10px] font-black text-slate-500 tracking-wider uppercase"><?= htmlspecialchars($displayService) ?></div>
                                        </td>
                                        <td class="px-8 py-5 text-center">
                                            <div class="text-[10px] font-bold text-slate-500 tracking-wider"><?= htmlspecialchars(!empty($staffDisplay) ? $staffDisplay : '—') ?></div>
                                        </td>
                                        <td class="px-8 py-5 text-center">
                                            <span class="inline-block whitespace-nowrap px-4 py-1.5 rounded-full text-[9px] font-black uppercase tracking-widest <?= $sColor ?>">
                                                <?= $displayStatus ?>
                                            </span>
                                        </td>
                                        <td class="px-8 py-5 text-right">
                                            <div class="font-mono font-bold text-slate-700">&#8369;<?= $balance ?></div>
                                        </td>
                                        <td class="px-8 py-5">
                                            <div class="flex justify-end gap-2">

                                                <button onclick="confirmAdmission(<?= $appt['id'] ?>, '<?= $jsDate ?>', '<?= $jsTime ?>', '<?= htmlspecialchars($staffDisplay, ENT_QUOTES) ?>')" class="size-9 bg-primary/10 text-primary rounded-xl hover:bg-primary hover:text-white flex items-center justify-center transition-all" title="Transfer to Admission">
                                                    <span class="material-symbols-outlined text-lg">drive_file_move</span>
                                                </button>

                                                <button onclick='viewDetails(<?= json_encode($appt) ?>)' class="h-9 px-3 bg-slate-100 text-slate-600 rounded-xl hover:bg-slate-900 hover:text-white flex items-center justify-center transition-all text-[10px] font-black uppercase tracking-wider" title="View Details">
                                                    View
                                                </button>

                                                <?php if ($isLate): ?>
                                                <button onclick="confirmRemoveAppointment(<?= (int)$appt['id'] ?>, '<?= htmlspecialchars($appt['full_name'], ENT_QUOTES) ?>')" class="h-9 px-3 bg-red-50 text-red-500 rounded-xl hover:bg-red-500 hover:text-white flex items-center justify-center transition-all text-[10px] font-black uppercase tracking-wider" title="Remove Late Appointment">
                                                    Remove
                                                </button>
                                                <?php endif; ?>
                                                
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<div id="notifModal" class="modal-bg">
    <div class="bg-white w-full max-w-md rounded-[32px] shadow-2xl overflow-hidden m-4 text-sm">
        <div class="p-6 border-b flex justify-between items-center bg-primary text-white">
            <h2 class="text-sm font-black uppercase tracking-widest">Notifications</h2>
            <button onclick="closeModal('notifModal')"><span class="material-symbols-outlined">close</span></button>
        </div>
        <div class="p-6 overflow-y-auto max-h-[70vh]" id="notifContent">
            <?php if($totalNotifs > 0): ?>
                <div class="space-y-4">
                    <?php if($n1 > 0): ?>
                    <div class="space-y-2">
                        <div class="flex items-center gap-2 text-yellow-600 mb-2"><span class="material-symbols-outlined">pending_actions</span><span class="font-black text-[10px] uppercase">New Requests</span></div>
                        <?php foreach($new_appointments as $name): ?>
                            <div class="p-3 bg-yellow-50 border border-yellow-100 rounded-xl text-[11px] font-bold text-slate-700">Patient <span class="text-yellow-700 underline"><?= htmlspecialchars($name) ?></span> sent a request.</div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <button onclick="clearNotifs()" class="w-full mt-4 py-3 bg-slate-100 hover:bg-red-50 hover:text-red-600 text-slate-500 rounded-2xl font-black text-[10px] uppercase tracking-widest transition-all">Clear Notification View</button>
                </div>
            <?php else: ?>
                <div class="py-10 text-center"><span class="material-symbols-outlined text-slate-200 text-6xl mb-4">notifications_off</span><p class="text-slate-400 font-black">No notification</p></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="dayListModal" class="modal-bg">
    <div class="bg-white w-full max-w-2xl rounded-[32px] flex flex-col max-h-[80vh] overflow-hidden m-4">
        <div class="p-6 border-b flex justify-between items-center bg-slate-50 shrink-0">
            <h2 class="text-sm font-black text-slate-800 uppercase" id="dayModalTitle">Appointments</h2>
            <button onclick="closeModal('dayListModal')" class="text-slate-400 hover:text-red-500">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div id="dayModalBody" class="p-6 overflow-y-auto space-y-3"></div>
    </div>
</div>

<div id="viewModal" class="modal-bg">
    <div class="bg-white w-full max-w-5xl rounded-[32px] shadow-2xl flex flex-col max-h-[90vh] overflow-hidden m-4">
        <div class="p-8 border-b flex justify-between items-center bg-primary text-white shrink-0">
            <div>
                <h2 class="text-sm font-black uppercase tracking-widest">Complete Electronic Record</h2>
                <p class="text-[10px] text-white/70" id="viewPatientId"></p>
            </div>
            <button onclick="closeModal('viewModal')" class="text-white hover:rotate-90 transition-all">
                <span class="material-symbols-outlined text-2xl">close</span>
            </button>
        </div>
        <div class="overflow-y-auto p-10 space-y-10 bg-white flex-1" id="viewContent"></div>
        <div class="p-6 bg-slate-50 border-t flex justify-end gap-3" id="viewModalFooter"></div>
    </div>
</div>

<script>
    let allAppointments = <?= json_encode($all_appointments) ?>;
    function convertTo12Hour(time24) {
        if (!time24) return '';
        const [hours, minutes] = time24.split(':');
        const hour = parseInt(hours);
        const ampm = hour >= 12 ? 'PM' : 'AM';
        const hour12 = hour % 12 || 12;
        return `${String(hour12).padStart(2, '0')}:${minutes} ${ampm}`;
    }

    let appointmentsByDate = <?= json_encode($calendar_json) ?>;
    let appointmentsVersion = <?= json_encode($appointmentsVersion) ?>;
    let previousAppointmentIds = new Set(allAppointments.map(a => a.id));
    let currentTab = 'active';
    const todayStr = '<?= $current_date ?>';
    const currentStaffName = <?= json_encode($displayName) ?>;

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getStatusClass(status) {
        switch (status) {
            case 'Fully Paid': return 'bg-green-100 text-green-700';
            case 'Downpayment': return 'bg-blue-100 text-blue-700';
            case 'Follow-up': return 'bg-purple-100 text-purple-700';
            case 'Admitted': return 'bg-violet-100 text-violet-700';
            case 'Cancelled': return 'bg-red-100 text-red-700';
            default: return 'bg-slate-100 text-slate-700';
        }
    }

    function renderAppointmentsTable() {
        const tbody = document.getElementById('apptTableBody');
        if (!tbody) return;

        if (!Array.isArray(allAppointments) || allAppointments.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="px-8 py-10 text-center text-slate-400 font-bold text-sm">No appointments found.</td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = '';
        allAppointments.forEach((appt) => {
            const displayStatus = appt.status || 'Pending';
            const isPaid = displayStatus === 'Fully Paid' || displayStatus === 'Downpayment' || displayStatus === 'Follow-up';
            const isAdmitted = displayStatus === 'Admitted';
            const displayService = appt.display_service || '-';
            const sColor = getStatusClass(displayStatus);
            const balance = Number(appt.remaining_balance || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            const jsDate = appt.appointment_date || '';
            const jsTime = appt.appointment_time || '';
            const isHidden = (currentTab === 'active' && appt.tab_category !== 'active-appt') || (currentTab === 'cancelled' && appt.tab_category !== 'cancelled-appt');

            // Check if appointment is more than 1 hour late
            let isLateAdmission = false;
            if (jsDate && jsTime && displayStatus !== 'Cancelled') {
                let tp = jsTime;
                if (tp.split(':').length === 2) tp += ':00';
                const apptDt = new Date(`${jsDate}T${tp}`);
                const nowDt = new Date();
                if (nowDt > apptDt && (nowDt - apptDt) > (1000 * 60 * 60)) {
                    isLateAdmission = true;
                }
            }
            const lateBadge = isLateAdmission ? ' <span class=\"px-2.5 py-1 rounded-full text-[8px] font-black uppercase tracking-widest bg-red-100 text-red-600 ml-1\">Late</span>' : '';

            const row = document.createElement('tr');
            const isMyRow = (appt.assigned_staff || appt.assigned_midwife || '').trim().toLowerCase() === currentStaffName.trim().toLowerCase();
            row.className = `appt-row ${appt.tab_category || ''} ${isHidden ? 'hidden' : ''} ${isMyRow ? 'bg-green-100 hover:bg-green-200/70' : 'hover:bg-slate-50/50'} transition-all`;

            row.innerHTML = `
                <td class="px-8 py-5">
                    <div class="flex items-center gap-3">
                        ${appt.profile_pic_url ? `<img src="${escapeHtml(appt.profile_pic_url)}" class="w-8 h-8 rounded-full object-cover ring-2 ring-slate-200" alt="">` : `<div class="w-8 h-8 rounded-full bg-slate-200 flex items-center justify-center"><span class="material-symbols-outlined text-slate-400 text-sm">person</span></div>`}
                        <div class="font-bold text-slate-800 text-sm">${escapeHtml(appt.full_name || '')}${lateBadge}</div>
                    </div>
                </td>
                <td class="px-8 py-5 text-center"><div class="text-[11px] font-medium text-slate-400 uppercase tracking-wider">${jsDate ? new Date(jsDate + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' }) : '-'}</div></td>
                <td class="px-8 py-5 text-center"><div class="text-[11px] font-bold text-slate-700 tracking-wider">${escapeHtml(convertTo12Hour(jsTime) || '—')}</div></td>
                <td class="px-8 py-5 text-center"><div class="text-[10px] font-black text-slate-500 tracking-wider uppercase">${escapeHtml(displayService)}</div></td>
                <td class="px-8 py-5 text-center"><div class="text-[10px] font-bold text-slate-500 tracking-wider">${escapeHtml(appt.assigned_staff || appt.assigned_midwife || '—')}</div></td>
                <td class="px-8 py-5 text-center"><span class="inline-block whitespace-nowrap px-4 py-1.5 rounded-full text-[9px] font-black uppercase tracking-widest ${sColor}">${escapeHtml(displayStatus)}</span></td>
                <td class="px-8 py-5 text-right"><div class="font-mono font-bold text-slate-700">&#8369;${balance}</div></td>
                <td class="px-8 py-5 text-right"><div class="flex justify-end gap-2"></div></td>
            `;

            const actionsCell = row.querySelector('td:last-child > div');

            if (!isAdmitted) {
                const transferBtn = document.createElement('button');
                transferBtn.className = 'size-9 bg-primary/10 text-primary rounded-xl hover:bg-primary hover:text-white flex items-center justify-center transition-all';
                transferBtn.title = 'Transfer to Admission';
                transferBtn.innerHTML = '<span class="material-symbols-outlined text-lg">drive_file_move</span>';
                transferBtn.onclick = () => confirmAdmission(appt.id, jsDate, jsTime, appt.assigned_staff || appt.assigned_midwife || '');
                actionsCell.appendChild(transferBtn);
            }

            const viewBtn = document.createElement('button');
            viewBtn.className = 'h-9 px-3 bg-slate-100 text-slate-600 rounded-xl hover:bg-slate-900 hover:text-white flex items-center justify-center transition-all text-[10px] font-black uppercase tracking-wider';
            viewBtn.title = 'View Details';
            viewBtn.textContent = 'View';
            viewBtn.onclick = () => viewDetails(appt);
            actionsCell.appendChild(viewBtn);

            // Show Remove button whenever the LATE badge is visible (time-aware, > 1hr past)
            if (isLateAdmission) {
                const removeBtn = document.createElement('button');
                removeBtn.className = 'h-9 px-3 bg-red-50 text-red-500 rounded-xl hover:bg-red-500 hover:text-white flex items-center justify-center transition-all text-[10px] font-black uppercase tracking-wider';
                removeBtn.title = 'Remove Late Appointment';
                removeBtn.textContent = 'Remove';
                removeBtn.onclick = () => confirmRemoveAppointment(appt.id, appt.full_name);
                actionsCell.appendChild(removeBtn);
            }
            tbody.appendChild(row);
        });
    }

    function showAjaxToast(message, icon = 'check_circle', color = 'primary') {
        const colorMap = {
            'primary': { border: 'border-primary', text: 'text-primary' },
            'green': { border: 'border-green-500', text: 'text-green-500' },
            'blue': { border: 'border-blue-500', text: 'text-blue-500' },
        };
        const c = colorMap[color] || colorMap['primary'];
        const toast = document.createElement('div');
        toast.className = `fixed top-24 left-1/2 -translate-x-1/2 z-[120] bg-white border-l-4 ${c.border} p-4 rounded-2xl shadow-2xl flex items-center gap-3 transition-all duration-500 opacity-0 translate-y-[-20px]`;
        toast.innerHTML = `<span class="material-symbols-outlined ${c.text}">${icon}</span><p class="text-xs font-black text-slate-800 tracking-tight">${message}</p><button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-slate-600"><span class="material-symbols-outlined text-sm">close</span></button>`;
        document.body.appendChild(toast);
        requestAnimationFrame(() => { toast.style.opacity = '1'; toast.style.transform = 'translateX(-50%) translateY(0)'; });
        setTimeout(() => { toast.style.opacity = '0'; toast.style.transform = 'translateX(-50%) translateY(-20px)'; setTimeout(() => toast.remove(), 500); }, 4000);
    }

    async function pollAppointments() {
        try {
            const response = await fetch('appointments.php?ajax=appointments_feed&_t=' + Date.now(), { credentials: 'same-origin' });
            if (!response.ok) return;

            const data = await response.json();
            if (!data || !data.success || !data.version) return;
            if (data.version === appointmentsVersion) return;

            appointmentsVersion = data.version;
            const newAppointments = Array.isArray(data.appointments) ? data.appointments : [];

            // Detect newly added appointments
            const newIds = newAppointments.filter(a => !previousAppointmentIds.has(a.id)).map(a => a.id);
            const newNames = newAppointments.filter(a => newIds.includes(a.id)).map(a => a.full_name || 'Unknown');

            allAppointments = newAppointments;
            appointmentsByDate = data.calendar || {};
            previousAppointmentIds = new Set(allAppointments.map(a => a.id));

            const activeBadge = document.getElementById('activeCountBadge');
            const cancelledBadge = document.getElementById('cancelledCountBadge');
            if (activeBadge) activeBadge.textContent = data.active_count ?? 0;
            if (cancelledBadge) cancelledBadge.textContent = data.cancelled_count ?? 0;

            renderAppointmentsTable();
            renderCalendar();
            switchTab(currentTab);

            // Show toast for new appointments
            if (newNames.length > 0) {
                const label = newNames.length === 1
                    ? `New appointment: ${newNames[0]}`
                    : `${newNames.length} new appointments added`;
                showAjaxToast(label, 'calendar_add_on', 'green');

                // Highlight new rows briefly
                setTimeout(() => {
                    newIds.forEach(id => {
                        const rows = document.querySelectorAll('#apptTableBody tr');
                        const idx = allAppointments.findIndex(a => a.id === id);
                        if (idx >= 0 && rows[idx]) {
                            rows[idx].classList.add('ring-2', 'ring-green-400', 'bg-green-50');
                            setTimeout(() => rows[idx].classList.remove('ring-2', 'ring-green-400', 'bg-green-50'), 3000);
                        }
                    });
                }, 100);
            }
        } catch (e) {
            // Silent fail: keep current UI stable even if polling fails temporarily.
        }
    }

    function openModal(id) { 
        const modal = document.getElementById(id);
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
    
    function closeModal(id) { 
        const modal = document.getElementById(id);
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    function openLogoutModal() { openModal('logoutModal'); }
    function closeLogoutModal() { closeModal('logoutModal'); }

    function confirmLogout() {
        closeLogoutModal();
        document.getElementById('loggingOutScreen').classList.remove('hidden');
        document.getElementById('loggingOutScreen').classList.add('flex');
        setTimeout(() => { window.location.href = '?action=logout&c=<?= urlencode($clinicCode) ?>'; }, 1500);
    }

    function clearNotifs() {
        document.getElementById('notifContent').innerHTML = `<div class="py-10 text-center"><span class="material-symbols-outlined text-slate-200 text-6xl mb-4">notifications_off</span><p class="text-slate-400 font-black">No notification</p></div>`;
        document.getElementById('notifDot').style.display = 'none';
    }

    // FUNCTION PARA SA TABS/BREADCRUMBS (Appointments vs Cancelled lang)
    function switchTab(tabName) {
        currentTab = tabName;
        // Reset lahat ng buttons sa default grey color
        document.getElementById('tab-active').className = "w-52 shrink-0 pb-4 border-b-[3px] border-transparent text-slate-500 hover:text-slate-700 font-bold text-sm flex items-center justify-center gap-2 transition-all whitespace-nowrap";
        document.getElementById('tab-cancelled').className = "w-52 shrink-0 pb-4 border-b-[3px] border-transparent text-slate-500 hover:text-slate-700 font-bold text-sm flex items-center justify-center gap-2 transition-all whitespace-nowrap";

        // Lagyan ng kulay yung active button
        if(tabName === 'active') {
            document.getElementById('tab-active').className = "w-52 shrink-0 pb-4 border-b-[3px] border-emerald-500 text-emerald-500 font-bold text-sm flex items-center justify-center gap-2 transition-all whitespace-nowrap";
        } else if(tabName === 'cancelled') {
            document.getElementById('tab-cancelled').className = "w-52 shrink-0 pb-4 border-b-[3px] border-rose-500 text-rose-500 font-bold text-sm flex items-center justify-center gap-2 transition-all whitespace-nowrap";
        }

        // Ipakita o itago ang mga rows
        const rows = document.querySelectorAll('.appt-row');
        rows.forEach(row => {
            if (tabName === 'active' && row.classList.contains('active-appt')) {
                row.classList.remove('hidden');
            } else if (tabName === 'cancelled' && row.classList.contains('cancelled-appt')) {
                row.classList.remove('hidden');
            } else {
                row.classList.add('hidden');
            }
        });
    }

    function renderCalendar() {
        const grid = document.getElementById('calendarGrid');
        const month = parseInt(document.getElementById('monthFilter').value);
        const year = new Date().getFullYear();
        const dayNames = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
        grid.innerHTML = '';
        dayNames.forEach(day => { const h = document.createElement('div'); h.className = 'calendar-header'; h.innerText = day; grid.appendChild(h); });
        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        for (let i = 0; i < firstDay; i++) { const empty = document.createElement('div'); empty.className = 'calendar-day bg-slate-50 disabled'; grid.appendChild(empty); }
        for (let day = 1; day <= daysInMonth; day++) {
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const isToday = todayStr === dateStr;
            const isPastDate = dateStr < todayStr;
            const appts = appointmentsByDate[dateStr] || [];
            
            const cell = document.createElement('div');
            if(appts.length === 0 && !isPastDate) {
                cell.className = `calendar-day cursor-default ${isToday ? 'today-bg' : ''}`;
                cell.style.boxShadow = 'none';
                cell.style.transform = 'none';
            } else {
                cell.className = `calendar-day ${isToday ? 'today-bg' : ''} ${isPastDate ? 'disabled' : ''}`;
            }

            let content = `<div class="flex justify-between items-start"><span class="${isToday ? 'today-badge' : 'text-slate-600'} font-bold text-sm">${day}</span></div>`;
            
            if (appts.length > 0) {
                const hasMyAppt = appts.some(a => (a.assigned_staff || a.assigned_midwife || '').trim().toLowerCase() === currentStaffName.trim().toLowerCase());
                let badgeColor = hasMyAppt ? 'bg-green-500' : 'bg-blue-500';
                
                content += `<div class="mt-auto pt-3"><button onclick="showDayList('${dateStr}')" class="w-full py-2 ${badgeColor} text-white text-[10px] font-black rounded-lg uppercase hover:opacity-90 shadow-md transition-all flex items-center justify-center gap-1"><span class="material-symbols-outlined text-sm">event</span> ${appts.length}</button></div>`;
            }
            
            cell.innerHTML = content;
            grid.appendChild(cell);
        }
    }

    function showDayList(dateStr) {
        const appts = appointmentsByDate[dateStr];
        document.getElementById('dayModalTitle').innerText = dateStr;
        const body = document.getElementById('dayModalBody');
        body.innerHTML = '';
        appts.forEach(a => {
            let status = a.status;
            let service = a.display_service;
            
            const isMyAppt = (a.assigned_staff || a.assigned_midwife || '').trim().toLowerCase() === currentStaffName.trim().toLowerCase();
            const rowBg = isMyAppt ? 'bg-green-100 border-green-300' : 'bg-slate-50 border';
            const row = document.createElement('div'); row.className = `flex items-center justify-between p-3 rounded-xl ${rowBg}`;
            row.innerHTML = `<div class="flex flex-col"><span class="font-bold text-xs">${a.full_name}</span><span class="text-[9px] uppercase font-bold text-slate-500">${service}${(a.assigned_staff || a.assigned_midwife) ? ' &bull; ' + (a.assigned_staff || a.assigned_midwife) : ''} - <span class="text-primary">${status}</span></span></div><button onclick='closeModal("dayListModal"); viewDetails(${JSON.stringify(a)})' class="bg-white px-2.5 py-1 rounded-lg border shadow-sm text-[10px] font-black uppercase tracking-wider text-slate-700 hover:bg-slate-900 hover:text-white transition-all">View</button>`;
            body.appendChild(row);
        });
        openModal('dayListModal');
    }

    function confirmRemoveAppointment(id, name) {
        document.getElementById('removeApptName').textContent = name || '';
        document.getElementById('removeApptLink').href = 'appointments.php?remove_appt_id=' + id;
        openModal('removeApptModal');
    }

    function confirmAdmission(id, dateStr, timeStr, assignedMidwife) {
        let timePart = timeStr ? timeStr : '00:00:00';

        if (timePart.split(':').length === 2) {
            timePart += ':00';
        }
        
        let apptDateTime = new Date(`${dateStr}T${timePart}`);
        let now = new Date();
        let diffMs = now - apptDateTime;
        let diffHours = diffMs / (1000 * 60 * 60);
        let isLate = diffHours > 1;
        let isEarly = now < apptDateTime;
        let midwifeName = (assignedMidwife || '').trim();
        
        let titleEl = document.getElementById('admitTitle');
        let msgEl = document.getElementById('admitMessage');
        let iconContainer = document.getElementById('admitIconContainer');
        let iconEl = document.getElementById('admitIcon');
        let confirmBtn = document.getElementById('confirmAdmitBtn');
        let staffSelectBox = document.getElementById('admitStaffSelectBox');
        let staffSelect = document.getElementById('admitStaffSelect');
        let autoStaffInfo = document.getElementById('admitAutoStaffInfo');
        let autoStaffHidden = document.getElementById('admitAutoStaff');
        
        document.getElementById('admitApproveId').value = id;
        
        if (isEarly) {
            // EARLY: auto-assign from appointment midwife (no staff picker)
            titleEl.innerText = "Early Admission Warning";
            titleEl.className = "text-lg font-black text-amber-600 mb-2";
            msgEl.innerHTML = "The appointment is scheduled for <b>" + dateStr + "</b> at <b>" + convertTo12Hour(timePart) + "</b>.<br><br>Are you sure you want to transfer this patient early?";
            
            iconContainer.className = "size-16 rounded-3xl bg-amber-50 text-amber-500 flex items-center justify-center mx-auto mb-4";
            iconEl.innerText = "warning";
            
            confirmBtn.className = "flex-1 py-3 rounded-xl font-bold bg-amber-500 text-white hover:bg-amber-600 transition-all text-xs shadow-lg shadow-amber-500/30";
            confirmBtn.innerText = "Transfer Early";

            // Auto-assign the appointment's midwife
            if (midwifeName && midwifeName !== '—') {
                staffSelectBox.classList.add('hidden');
                staffSelect.removeAttribute('required');
                staffSelect.disabled = true;
                autoStaffInfo.classList.remove('hidden');
                document.getElementById('admitAutoStaffName').innerText = midwifeName;
                autoStaffHidden.value = midwifeName;
                autoStaffHidden.disabled = false;
            } else {
                staffSelectBox.classList.remove('hidden');
                staffSelect.setAttribute('required', 'required');
                staffSelect.disabled = false;
                autoStaffInfo.classList.add('hidden');
                autoStaffHidden.disabled = true;
            }

        } else if (isLate) {
            // LATE (>1 hour): show staff picker with Assign Later option
            titleEl.innerText = "Late Admission";
            titleEl.className = "text-lg font-black text-red-600 mb-2";
            msgEl.innerHTML = "This appointment was scheduled for <b>" + convertTo12Hour(timePart) + "</b> and is now <b>over 1 hour late</b>.<br><br>Please select an available staff or assign later.";
            
            iconContainer.className = "size-16 rounded-3xl bg-red-50 text-red-500 flex items-center justify-center mx-auto mb-4";
            iconEl.innerText = "schedule";
            
            confirmBtn.className = "flex-1 py-3 rounded-xl font-bold bg-red-500 text-white hover:bg-red-600 transition-all text-xs shadow-lg shadow-red-500/30";
            confirmBtn.innerText = "Transfer Late Admission";

            // Show staff selection (required)
            staffSelectBox.classList.remove('hidden');
            staffSelect.setAttribute('required', 'required');
            staffSelect.disabled = false;
            staffSelect.value = '';
            autoStaffInfo.classList.add('hidden');
            autoStaffHidden.disabled = true;

        } else {
            // ON-TIME (within 1 hour): auto-assign from appointment midwife
            titleEl.innerText = "Transfer to Admission";
            titleEl.className = "text-lg font-black text-slate-900 mb-2";
            msgEl.innerText = "Are you sure you want to process this patient's transfer to the clinic admissions queue?";
            
            iconContainer.className = "size-16 rounded-3xl bg-primary/10 text-primary flex items-center justify-center mx-auto mb-4";
            iconEl.innerText = "local_hospital";
            
            confirmBtn.className = "flex-1 py-3 rounded-xl font-bold bg-primary text-white hover:bg-primary-dark transition-all text-xs shadow-lg shadow-primary/30";
            confirmBtn.innerText = "Proceed to Transfer";

            // Auto-assign the appointment's midwife
            if (midwifeName && midwifeName !== '—') {
                staffSelectBox.classList.add('hidden');
                staffSelect.removeAttribute('required');
                staffSelect.disabled = true;
                autoStaffInfo.classList.remove('hidden');
                document.getElementById('admitAutoStaffName').innerText = midwifeName;
                autoStaffHidden.value = midwifeName;
                autoStaffHidden.disabled = false;
            } else {
                staffSelectBox.classList.remove('hidden');
                staffSelect.setAttribute('required', 'required');
                staffSelect.disabled = false;
                autoStaffInfo.classList.add('hidden');
                autoStaffHidden.disabled = true;
            }
        }
        
        openModal('admitConfirmModal');
        closeModal('viewModal');
        closeModal('dayListModal');
    }

    function viewDetails(data) {
        const displayID = data.patient_db_id ? data.patient_id : 'PENDING APPROVAL';
        document.getElementById('viewPatientId').innerText = 'System ID: ' + displayID;
        
        let displayStatus = data.status;
        let displayService = data.display_service; 
        let balance = parseFloat(data.remaining_balance || 0).toLocaleString('en-US', {minimumFractionDigits: 2});

        // Pic Logic
        const picUrl = data.profile_pic_url || (data.patient_image ? `uploads/${data.patient_image}` : null);
        const picHtml = picUrl 
            ? `<img src="${picUrl}" class="size-full object-cover">`
            : `<span class="text-white text-3xl font-black">${data.full_name.charAt(0)}</span>`;

        // Benefit/ID Pics Logic (PhilHealth only)
        let phIdHtml = data.philhealth_id_pic 
            ? `<div class="p-4 bg-blue-50 rounded-2xl border border-blue-100">
                <p class="text-[9px] font-bold text-blue-600 uppercase mb-2 tracking-widest">PhilHealth ID Document</p>
                <img src="uploads/${data.philhealth_id_pic}" class="w-full h-32 object-cover rounded-xl border border-blue-200 cursor-pointer hover:opacity-80 transition-opacity" onclick="window.open('uploads/${data.philhealth_id_pic}', '_blank')">
                <p class="text-[8px] text-blue-400 mt-2 text-center italic">Click image to enlarge</p>
               </div>` 
            : '';

        document.getElementById('viewContent').innerHTML = `
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
                
                <div class="lg:col-span-12 flex flex-col md:flex-row items-center gap-8 p-8 bg-slate-50 rounded-[2.5rem] border shadow-sm">
                    <div class="size-32 rounded-[2rem] bg-primary flex items-center justify-center overflow-hidden border-4 border-white shadow-xl shrink-0">
                        ${picHtml}
                    </div>
                    <div class="text-center md:text-left flex-1">
                        <h3 class="text-3xl font-black text-slate-800 tracking-tight mb-2">${data.full_name}</h3>
                        <div class="flex flex-wrap justify-center md:justify-start gap-2">
                            <span class="px-4 py-1.5 bg-primary text-white rounded-full text-[10px] font-black uppercase tracking-widest shadow-md shadow-primary/20">${displayStatus}</span>
                            <span class="px-4 py-1.5 bg-white border border-slate-200 text-slate-500 rounded-full text-[10px] font-black uppercase tracking-widest">Visit Date: ${data.appointment_date}${data.appointment_time ? ' @ ' + convertTo12Hour(data.appointment_time) : ''}</span>
                            <span class="px-4 py-1.5 bg-white border border-slate-200 text-slate-500 rounded-full text-[10px] font-black uppercase tracking-widest">Patient ID: ${data.patient_id}</span>
                            <span class="px-4 py-1.5 bg-white border border-slate-200 text-slate-500 rounded-full text-[10px] font-black uppercase tracking-widest">Service: ${displayService}</span>
                            ${(data.assigned_staff || data.assigned_midwife) ? `<span class="px-4 py-1.5 bg-green-50 border border-green-200 text-green-700 rounded-full text-[10px] font-black uppercase tracking-widest">Staff: ${data.assigned_staff || data.assigned_midwife}</span>` : ''}
                            <span class="px-4 py-1.5 bg-slate-800 text-white rounded-full text-[10px] font-black uppercase tracking-widest shadow-md">Balance: &#8369;${balance}</span>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-7 space-y-6">
                    <div class="p-8 bg-white rounded-[2rem] border border-slate-100 shadow-sm h-full">
                        <h4 class="text-[11px] font-black text-primary uppercase tracking-[0.2em] mb-6 flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">person</span> Basic Bio-Data
                        </h4>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-y-6 gap-x-4">
                            <div><p class="text-[9px] font-bold text-slate-400 uppercase mb-1">Age</p><p class="font-bold text-slate-800 text-sm">${data.age} yrs old</p></div>
                            <div><p class="text-[9px] font-bold text-slate-400 uppercase mb-1">Birthday</p><p class="font-bold text-slate-800 text-sm">${data.birthday}</p></div>
                            <div><p class="text-[9px] font-bold text-slate-400 uppercase mb-1">Civil Status</p><p class="font-bold text-slate-800 text-sm">${data.civil_status}</p></div>
                            <div><p class="text-[9px] font-bold text-slate-400 uppercase mb-1">Contact No.</p><p class="font-bold text-slate-800 text-sm">${data.contact_number}</p></div>
                            <div><p class="text-[9px] font-bold text-slate-400 uppercase mb-1">Religion</p><p class="font-bold text-slate-800 text-sm">${data.religion || 'N/A'}</p></div>
                            <div><p class="text-[9px] font-bold text-slate-400 uppercase mb-1">Occupation</p><p class="font-bold text-slate-800 text-sm">${data.occupation || 'N/A'}</p></div>
                            <div><p class="text-[9px] font-bold text-slate-400 uppercase mb-1">Menarche</p><p class="font-bold text-slate-800 text-sm">${data.menarche || '0'} yrs old</p></div>
                            <div class="col-span-2"><p class="text-[9px] font-bold text-slate-400 uppercase mb-1">Email Address</p><p class="font-bold text-slate-800 text-sm">${data.email_address || 'None provided'}</p></div>
                        </div>
                        <div class="mt-8 pt-6 border-t border-slate-50">
                            <p class="text-[9px] font-bold text-slate-400 uppercase mb-2">Residential Address</p>
                            <div class="p-4 bg-slate-50 rounded-2xl border border-slate-100 text-slate-700 font-medium leading-relaxed">
                                <span class="material-symbols-outlined text-xs align-middle mr-1 text-slate-400">location_on</span> ${data.address}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-5 space-y-6">
                    <div class="p-8 bg-white rounded-[2rem] border border-slate-100 shadow-sm">
                        <h4 class="text-[11px] font-black text-primary uppercase tracking-[0.2em] mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">favorite</span> Husband / Partner
                        </h4>
                        <div class="p-4 bg-slate-50 rounded-2xl border border-slate-100">
                             <p class="text-sm font-bold text-slate-700">${data.husband_name || 'N/A'}</p>
                        </div>
                    </div>

                    <div class="p-8 bg-white rounded-[2rem] border border-slate-100 shadow-sm">
                        <h4 class="text-[11px] font-black text-primary uppercase tracking-[0.2em] mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">family_restroom</span> Parental Background
                        </h4>
                        <div class="space-y-4">
                            <div class="p-3 hover:bg-slate-50 rounded-xl transition-all border border-transparent hover:border-slate-100">
                                <p class="text-[9px] font-bold text-slate-400 uppercase">Father's Full Name</p>
                                <p class="text-sm font-bold text-slate-700">${data.father_name || 'N/A'}</p>
                            </div>
                            <div class="p-3 hover:bg-slate-50 rounded-xl transition-all border border-transparent hover:border-slate-100">
                                <p class="text-[9px] font-bold text-slate-400 uppercase">Mother's Maiden Name</p>
                                <p class="text-sm font-bold text-slate-700">${data.mother_name || 'N/A'}</p>
                            </div>
                        </div>
                    </div>

                    ${phIdHtml ? `
                    <div class="p-8 bg-white rounded-[2rem] border border-slate-100 shadow-sm space-y-4">
                        <h4 class="text-[11px] font-black text-blue-600 uppercase tracking-[0.2em] mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">file_present</span> Benefit Documents
                        </h4>
                        ${phIdHtml}
                    </div>` : ''}


                </div>

            </div>`;
        
        const footer = document.getElementById('viewModalFooter');
        
        let jsDate = data.appointment_date;
        let jsTime = data.appointment_time || '';
        
        let admitBtn = `<button onclick="confirmAdmission(${data.id}, '${jsDate}', '${jsTime}', '${escapeHtml(data.assigned_staff || data.assigned_midwife || '')}')" class="px-10 py-3 bg-primary text-white font-black uppercase text-[10px] tracking-widest rounded-2xl hover:bg-primary-dark transition-all shadow-lg flex items-center gap-2"><span class="material-symbols-outlined text-sm">drive_file_move</span> Transfer to Admission</button>`;

        footer.innerHTML = `
            <div class="flex w-full justify-between items-center px-4">
                <p class="text-[10px] font-bold text-slate-400 italic">Official Electronic Health Record &copy; 2026</p>
                <div class="flex gap-3">
                    ${admitBtn}
                    <button onclick="closeModal('viewModal')" class="px-10 py-3 bg-slate-900 text-white font-black uppercase text-[10px] tracking-widest rounded-2xl hover:bg-black transition-all shadow-lg">Close Details</button>
                </div>
            </div>`;
        
        openModal('viewModal');
    }

    function updateCalendar() { renderCalendar(); }
    window.addEventListener('DOMContentLoaded', () => { 
        renderAppointmentsTable();
        renderCalendar(); 
        switchTab('active'); 
        setInterval(pollAppointments, 3000);
    });
    window.onclick = (e) => { ung
        if (e.target.classList.contains('modal-bg')) {
            closeModal(e.target.id);
        } 
    }
</script>
</body>
</html>