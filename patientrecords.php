<?php
// 1. SETTINGS & SESSION
date_default_timezone_set('Asia/Manila');
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    if (!isset($pdo)) {
        require_once 'db.php';
        if (!isset($pdo) && isset($conn)) { $pdo = $conn; }
    }
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

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

require_once 'db.php';

// ---  AUTO-FIX: ADD is_archived COLUMN TO PATIENTS TABLE  ---
try {
    $pdo->query("SELECT is_archived FROM patients LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE patients ADD is_archived TINYINT(1) DEFAULT 0 AFTER TenantID");
    } catch (PDOException $ex) {}
}

// ---  AUTO-FIX: ADD gender COLUMN TO PATIENTS TABLE ---
try {
    $pdo->query("SELECT gender FROM patients LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE patients ADD gender VARCHAR(20) NULL AFTER full_name");
    } catch (PDOException $ex) {}
}

// ---  AUTO-FIX: ADD created_at COLUMN TO PATIENTS TABLE (FOR SORTING/LISTING)  ---
try {
    $pdo->query("SELECT created_at FROM patients LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE patients ADD created_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER husband_name");
    } catch (PDOException $ex) {}
}

// ---  AUTO-FIX: ADD certificate_data COLUMN TO INFANTS TABLE ---
try {
    $pdo->query("SELECT certificate_data FROM infants LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE infants ADD certificate_data TEXT NULL AFTER certificate_of_live_birth"); } catch (PDOException $ex) {}
}

// ---  AUTO-FIX: EXPAND philhealth_id_pic_back TO VARCHAR(255) ---
try {
    $colCheck = $pdo->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'patients' AND COLUMN_NAME = 'philhealth_id_pic_back' AND TABLE_SCHEMA = DATABASE()");
    $colType = $colCheck->fetchColumn();
    if ($colType && stripos($colType, '255') === false) {
        $pdo->exec("ALTER TABLE patients MODIFY philhealth_id_pic_back VARCHAR(255) NULL");
    }
} catch (PDOException $e) {}

// ---  AUTO-FIX: ENSURE admission_id COLUMN EXISTS IN PAYMENTS ---
try {
    $pdo->query("SELECT admission_id FROM payments LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE payments ADD admission_id INT NULL"); } catch (PDOException $ex) {}
}

// ---  AJAX: SAVE CERTIFICATE IMAGE TO DB ---
if (isset($_GET['action']) && $_GET['action'] === 'save_certificate') {
    header('Content-Type: application/json');
    $tenant_id = $_SESSION['TenantID'] ?? null;
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $babyId = (int)($input['baby_id'] ?? 0);
        $imageData = $input['image'] ?? '';
        if(!$babyId || !$imageData) {
            echo json_encode(['success' => false, 'error' => 'Missing data']);
            exit();
        }
        // Remove data:image/png;base64, prefix
        $base64 = preg_replace('/^data:image\/\w+;base64,/', '', $imageData);
        $binaryData = base64_decode($base64);
        if($binaryData === false) {
            echo json_encode(['success' => false, 'error' => 'Invalid image data']);
            exit();
        }
        // Save file to uploads/certificates/
        $uploadDir = __DIR__ . '/uploads/certificates/';
        if(!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
        $fileName = 'cert_' . $babyId . '_' . time() . '.png';
        $filePath = $uploadDir . $fileName;
        file_put_contents($filePath, $binaryData);
        // Store relative path in varchar column
        $relativePath = 'uploads/certificates/' . $fileName;
        // Also save certificate form data JSON
        $certData = isset($input['cert_data']) ? json_encode($input['cert_data']) : null;
        $stmt = $pdo->prepare("UPDATE infants SET certificate_of_live_birth = :path, certificate_data = :cdata WHERE id = :id AND TenantID = :tenant");
        $stmt->execute([':path' => $relativePath, ':cdata' => $certData, ':id' => $babyId, ':tenant' => $tenant_id]);
        echo json_encode(['success' => true, 'path' => $relativePath]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// --- SIDEBAR & HEADER LOGIC ---
$current_staff_id = (int)($_SESSION['user_id'] ?? 0);
$tenant_id = $_SESSION['TenantID'] ?? null;
$displayName = $_SESSION['full_name'] ?? 'User';

$currentRole = $_SESSION['role'] ?? 'Staff';
$normalizedRole = strtolower(trim((string)$currentRole));
$currentIsAdmin = in_array($normalizedRole, ['admin', 'administrator', 'owner', 'owner/midwife'], true);
$isStaffRole = ($normalizedRole === 'staff');
// Receptionist now has the same view access as midwife (Menarche, APGAR, View checkup details).
// Keep the variable defined for backward compatibility but disable its gating effect.
$isReceptionist = false;
$displayRole = $currentRole;

$clinicName = "MaternityHub";
$clinicCode = "N/A";
$clinicLogo = null;
$themeColor = "#15803d";
$clinicAddress = "Address not set";
$clinicContact = "Contact not set";

if ($tenant_id) {
    try {
        $stmtClinic = $pdo->prepare("SELECT clinic_name, clinic_code, clinic_logo, theme_color, complete_address, clinic_contact FROM tenants WHERE TenantID = ?");
        $stmtClinic->execute([$tenant_id]);
        $clinicData = $stmtClinic->fetch(PDO::FETCH_ASSOC);

        if ($clinicData) {
            $clinicName = $clinicData['clinic_name'];
            $clinicAddress = $clinicData['complete_address'] ?: 'Address not provided';
            $clinicContact = $clinicData['clinic_contact'] ?: 'Contact not provided';

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
//  DYNAMIC CONTRAST CALCULATOR PARA SA HEADER & SIDEBAR
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

function calculateAge($birthday) {
    if (empty($birthday)) return 0;
    $birthDate = new DateTime($birthday);
    $today = new DateTime('today');
    return $birthDate->diff($today)->y;
}

// ---  FETCH HISTORY AT INFANTS DIREKTA SA DATABASE  ---
if (isset($_GET['fetch_checkups'])) {
    ob_clean();
    header('Content-Type: application/json');

    $pid = intval($_GET['fetch_checkups']);

    $stmtCheck = $pdo->prepare("SELECT full_name, patient_id, husband_name, age, religion, address, last_menstrual_period, estimated_delivery_date, pregnancy_status, gravida, para FROM patients WHERE id = ? AND TenantID = ?");
    $stmtCheck->execute([$pid, $tenant_id]);
    $patient = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if ($patient) {
        $pt_id = $patient['patient_id'];
        $fname = $patient['full_name'];
        $husband = $patient['husband_name'] ?: '';
        $m_age = $patient['age'] ?: '';
        $religion = $patient['religion'] ?: '';
        $address = $patient['address'] ?: '';

        $stmtAppts = $pdo->prepare("
            SELECT
                id,
                'Appointment' as source,
                appointment_date as visit_date,
                appointment_time as visit_time,
                service as service_type,
                medical_history as remarks,
                '—' as weight, '—' as bp, '—' as temp, '—' as pulse_rate, '—' as spo2,
                status as stage_status,
                'Unassigned' as assigned_staff,
                NULL as receipt
            FROM appointments
                        WHERE (patient_id = :pid OR full_name = :pname)
                            AND TenantID = :tenant
                            AND LOWER(TRIM(COALESCE(status, ''))) NOT IN ('cancelled', 'canceled')
                            AND (is_admitted = 0 OR is_admitted IS NULL)
            ORDER BY appointment_date DESC
        ");
        $stmtAppts->execute([':pid' => $pt_id, ':pname' => $fname, ':tenant' => $tenant_id]);
        $appts = $stmtAppts->fetchAll(PDO::FETCH_ASSOC);

        $stmtAdms = $pdo->prepare("
            SELECT
                a.id,
                'Admission' as source,
                DATE(a.admission_date) as visit_date,
                TIME(a.admission_date) as visit_time,
                a.reason as service_type,
                'Vitals recorded in Admission' as remarks,
                a.weight, a.bp, a.temp, a.pulse as pulse_rate, a.spo2,
                a.lab_cbc, a.lab_urinalysis, a.lab_blood_type, a.lab_blood_sugar, a.lab_hep_b, a.lab_syphilis, a.lab_transvaginal, a.lab_pelvic,
                a.fetal_aog, a.fetal_fundal_height, a.fetal_fht, a.fetal_presentation,
                a.stage as stage_status,
                a.assigned_staff,
                COALESCE(a.full_name, '') as adm_full_name,
                COALESCE(
                    NULLIF(TRIM(a.receipt), ''),
                    (
                        SELECT pay.receipt
                        FROM payments pay
                        WHERE pay.TenantID = a.TenantID
                          AND pay.admission_id = a.id
                          AND pay.status = 'Paid'
                          AND pay.receipt IS NOT NULL
                          AND pay.receipt != ''
                        ORDER BY pay.payment_date DESC, pay.id DESC
                        LIMIT 1
                    ),
                    (
                        SELECT pay.receipt
                        FROM payments pay
                        WHERE pay.TenantID = a.TenantID
                          AND TRIM(pay.full_name) = TRIM(COALESCE(
                              (SELECT p2.full_name FROM patients p2 WHERE p2.patient_id = a.patient_id AND p2.TenantID = a.TenantID LIMIT 1),
                              a.full_name
                          ))
                          AND TRIM(pay.service) = TRIM(a.reason)
                          AND pay.status = 'Paid'
                          AND pay.receipt IS NOT NULL
                          AND pay.receipt != ''
                        ORDER BY pay.payment_date DESC, pay.id DESC
                        LIMIT 1
                    )
                ) AS receipt,
                (SELECT pay.amount FROM payments pay WHERE pay.TenantID = a.TenantID AND pay.admission_id = a.id AND pay.status = 'Paid' ORDER BY pay.payment_date DESC, pay.id DESC LIMIT 1) AS pay_amount,
                (SELECT pay.is_philhealth FROM payments pay WHERE pay.TenantID = a.TenantID AND pay.admission_id = a.id AND pay.status = 'Paid' ORDER BY pay.payment_date DESC, pay.id DESC LIMIT 1) AS pay_is_philhealth,
                (SELECT pay.philhealth_amount FROM payments pay WHERE pay.TenantID = a.TenantID AND pay.admission_id = a.id AND pay.status = 'Paid' ORDER BY pay.payment_date DESC, pay.id DESC LIMIT 1) AS pay_philhealth_amount,
                (SELECT pay.payment_date FROM payments pay WHERE pay.TenantID = a.TenantID AND pay.admission_id = a.id AND pay.status = 'Paid' ORDER BY pay.payment_date DESC, pay.id DESC LIMIT 1) AS pay_date
            FROM admissions a
            WHERE (a.patient_id = :pid OR a.full_name = :pname) AND a.TenantID = :tenant
            ORDER BY a.admission_date DESC
        ");
        $stmtAdms->execute([':pid' => $pt_id, ':pname' => $fname, ':tenant' => $tenant_id]);
        $adms = $stmtAdms->fetchAll(PDO::FETCH_ASSOC);

        $combined = array_merge($appts, $adms);
        usort($combined, function($a, $b) {
            $dateA = strtotime($a['visit_date'] . ' ' . $a['visit_time']);
            $dateB = strtotime($b['visit_date'] . ' ' . $b['visit_time']);
            return $dateB - $dateA; // Descending
        });

        // Inject patient-level maternity info into each visit row
        $patLmp       = $patient['last_menstrual_period'] ?? null;
        $patEdd       = $patient['estimated_delivery_date'] ?? null;
        $patPregStat  = $patient['pregnancy_status'] ?? null;
        $patGravida   = isset($patient['gravida']) ? (int)$patient['gravida'] : 0;
        $patPara      = isset($patient['para']) ? (int)$patient['para'] : 0;
        if (!$patEdd && $patLmp) {
            try {
                $dt = new DateTime($patLmp);
                $dt->modify('+7 days');
                $y = (int)$dt->format('Y');
                $m = (int)$dt->format('n');
                $d = (int)$dt->format('j');
                $m -= 3;
                if ($m <= 0) { $m += 12; $y -= 1; }
                $y += 1;
                $lastDay = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
                if ($d > $lastDay) { $d = $lastDay; }
                $patEdd = sprintf('%04d-%02d-%02d', $y, $m, $d);
            } catch (Exception $e) {}
        }
        foreach ($combined as &$visitRow) {
            $visitRow['last_menstrual_period']    = $patLmp;
            $visitRow['estimated_delivery_date']  = $patEdd;
            $visitRow['pregnancy_status']         = $patPregStat;
            $visitRow['gravida']                  = $patGravida;
            $visitRow['para']                     = $patPara;
        }
        unset($visitRow);

        $stmtBabies = $pdo->prepare("
            SELECT id, infant_name, gender, birth_date, birth_time, weight_kg, length_cm, apgar_score, delivery_method, attending_staff, certificate_of_live_birth, certificate_data
            FROM infants
            WHERE mother_patient_id = :pid AND TenantID = :tenant
            ORDER BY birth_date DESC, birth_time DESC
        ");
        $stmtBabies->execute([':pid' => $pt_id, ':tenant' => $tenant_id]);
        $babies = $stmtBabies->fetchAll(PDO::FETCH_ASSOC);

        // Populate extra data for the form 102 printout
        foreach ($babies as &$baby) {
            $baby['mother_name'] = $fname;
            $baby['mother_age'] = $m_age;
            $baby['father_name'] = $husband;
            $baby['religion'] = $religion;
            $baby['address'] = $address;
            $baby['patient_id'] = $pt_id; //  ADDED FIX: Included patient_id
            $baby['patient_db_id'] = $pid; // numeric DB id for saving certificate
        }
        unset($baby);

        echo json_encode([
            'history' => $combined,
            'babies' => $babies
        ]);
    } else {
        echo json_encode(['history' => [], 'babies' => []]);
    }
    exit();
}

// --- ARCHIVE / UNARCHIVE LOGIC ---
if (isset($_GET['archive_id'])) {
    $archId = (int)$_GET['archive_id'];
    try {
        $stmtName = $pdo->prepare("SELECT full_name, patient_id FROM patients WHERE id = ? AND TenantID = ?");
        $stmtName->execute([$archId, $tenant_id]);
        $archPatient = $stmtName->fetch(PDO::FETCH_ASSOC);
        $archPatientName = $archPatient['full_name'] ?? 'Unknown';
        $archPatientId = $archPatient['patient_id'] ?? 'N/A';

        $stmt = $pdo->prepare("UPDATE patients SET is_archived = 1 WHERE id = ? AND TenantID = ?");
        $stmt->execute([$archId, $tenant_id]);
    } catch (PDOException $e) { die("Archive Error: " . $e->getMessage()); }

    // Audit log (separate try-catch para hindi ma-block ang archive)
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $auditUser = $displayName ?? ($_SESSION['full_name'] ?? 'Unknown');
        $auditRole = $currentRole ?? ($_SESSION['role'] ?? 'Staff');
        $currentTime = date('Y-m-d H:i:s');
        $pdo->prepare("INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$tenant_id, $auditUser, $auditRole, 'Patient Archived', "Archived patient record: $archPatientName (ID: $archPatientId)", $ip, $currentTime]);
    } catch (Exception $e) { /* silent */ }

    header("Location: patientrecords.php?msg=patient_archived");
    exit();
}

if (isset($_GET['unarchive_id'])) {
    $unarchId = (int)$_GET['unarchive_id'];
    try {
        $stmtName = $pdo->prepare("SELECT full_name, patient_id FROM patients WHERE id = ? AND TenantID = ?");
        $stmtName->execute([$unarchId, $tenant_id]);
        $unarchPatient = $stmtName->fetch(PDO::FETCH_ASSOC);
        $unarchPatientName = $unarchPatient['full_name'] ?? 'Unknown';
        $unarchPatientId = $unarchPatient['patient_id'] ?? 'N/A';

        $stmt = $pdo->prepare("UPDATE patients SET is_archived = 0 WHERE id = ? AND TenantID = ?");
        $stmt->execute([$unarchId, $tenant_id]);
    } catch (PDOException $e) { die("Restore Error: " . $e->getMessage()); }

    // Audit log (separate try-catch para hindi ma-block ang restore)
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $auditUser = $displayName ?? ($_SESSION['full_name'] ?? 'Unknown');
        $auditRole = $currentRole ?? ($_SESSION['role'] ?? 'Staff');
        $currentTime = date('Y-m-d H:i:s');
        $pdo->prepare("INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$tenant_id, $auditUser, $auditRole, 'Patient Restored', "Restored archived patient record: $unarchPatientName (ID: $unarchPatientId)", $ip, $currentTime]);
    } catch (Exception $e) { /* silent */ }

    header("Location: patientrecords.php?view=archived&msg=patient_restored");
    exit();
}

// --- SAVE/UPDATE PATIENT ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $age = calculateAge($_POST['birthday']);
        $full_name = trim($_POST['first_name'] . " " . $_POST['middle_name'] . " " . $_POST['surname']);
        $mother_full = trim($_POST['m_first'] . " " . $_POST['m_middle'] . " " . $_POST['m_last']);
        $father_full = trim($_POST['d_first'] . " " . $_POST['d_middle'] . " " . $_POST['d_last']);
        $husband_full = trim($_POST['h_first'] . " " . $_POST['h_middle'] . " " . $_POST['h_last']);

        $params = [
            ':tenant' => $tenant_id,
            ':name' => $full_name, ':age' => $age, ':status' => $_POST['status'], ':cdate' => $_POST['checkup_date'],
            ':addr' => $_POST['address'], ':contact' => $_POST['contact_number'], ':bday' => $_POST['birthday'],
            ':occ' => $_POST['occupation'], ':rel' => $_POST['religion'], ':mom' => $mother_full,
            ':dad' => $father_full, ':hubby' => $husband_full,
            ':menarche' => $_POST['menarche']
        ];

        // Optional Maternity fields (LMP -> auto EDD via Naegele's rule)
        $lmp_in = trim($_POST['last_menstrual_period'] ?? '');
        if ($lmp_in === '') { $lmp_in = null; }
        $edd_in = null;
        if ($lmp_in !== null) {
            try {
                $dt = new DateTime($lmp_in);
                $dt->modify('+7 days');
                $y = (int)$dt->format('Y');
                $m = (int)$dt->format('n');
                $d = (int)$dt->format('j');
                $m -= 3;
                if ($m <= 0) { $m += 12; $y -= 1; }
                $y += 1;
                $lastDay = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
                if ($d > $lastDay) { $d = $lastDay; }
                $edd_in = sprintf('%04d-%02d-%02d', $y, $m, $d);
            } catch (Exception $e) { $edd_in = null; }
        }
        $allowedPregStatuses = ['Pending Confirmation','Confirmed Pregnant','Not Pregnant','Miscarriage'];
        $preg_status_in = trim($_POST['pregnancy_status'] ?? '');
        if ($preg_status_in !== '' && !in_array($preg_status_in, $allowedPregStatuses, true)) {
            $preg_status_in = '';
        }
        $params[':lmp']       = $lmp_in;
        $params[':edd']       = $edd_in;
        $params[':pregstat']  = ($preg_status_in === '' ? null : $preg_status_in);

        if (isset($_POST['update_patient'])) {
            $sql = "UPDATE patients SET full_name = :name, age = :age, status = :status, checkup_date = :cdate, address = :addr, contact_number = :contact, birthday = :bday, occupation = :occ, religion = :rel, mother_name = :mom, father_name = :dad, husband_name = :hubby, menarche = :menarche, last_menstrual_period = COALESCE(:lmp, last_menstrual_period), estimated_delivery_date = COALESCE(:edd, estimated_delivery_date), pregnancy_status = COALESCE(:pregstat, pregnancy_status) WHERE id = :id AND TenantID = :tenant";
            $params[':id'] = $_POST['patient_id_db'];
        } else {
            $isUnique = false;
            while (!$isUnique) {
                $patient_id = "PT-" . date("Y") . "-" . rand(1000, 9999);
                $chk = $pdo->prepare("SELECT id FROM patients WHERE patient_id = ?");
                $chk->execute([$patient_id]);
                if(!$chk->fetch()) { $isUnique = true; }
            }

            $sql = "INSERT INTO patients (TenantID, patient_id, full_name, age, status, checkup_date, address, contact_number, birthday, occupation, religion, mother_name, father_name, husband_name, menarche, last_menstrual_period, estimated_delivery_date, pregnancy_status) VALUES (:tenant, :pid, :name, :age, :status, :cdate, :addr, :contact, :bday, :occ, :rel, :mom, :dad, :hubby, :menarche, :lmp, :edd, COALESCE(:pregstat, 'Pending Confirmation'))";
            $params[':pid'] = $patient_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        header("Location: patientrecords.php?msg=patient_saved");
        exit();
    } catch (PDOException $e) { die("Error: " . $e->getMessage()); }
}

$viewMode = $_GET['view'] ?? 'active';
$isArchivedView = ($viewMode === 'archived');
$archiveStatusFilter = $isArchivedView ? 1 : 0;

$stmtDisplay = $pdo->prepare("
    SELECT p.*,
           p.guardian_id_url as guardian_id_photo,
           p.philhealth_id_pic_front as ph_id_front,
           p.philhealth_id_pic_back as ph_id_back
    FROM patients p
    WHERE p.TenantID = ? AND p.is_archived = ?
      AND (p.account_status IS NULL OR p.account_status NOT IN ('Pending','Rejected'))
    ORDER BY p.created_at DESC
");
$stmtDisplay->execute([$tenant_id, $archiveStatusFilter]);
$patients = $stmtDisplay->fetchAll(PDO::FETCH_ASSOC);
// Sidebar active style based on theme brightness (match tenantsettings)
$sidebarActive = $isLightTheme ? "bg-slate-800 text-white shadow-md" : "bg-primary/10 text-primary";

// --- OWNER / STAFF ADMIN PERMISSION SYSTEM ---
$currentUserIsOwner = $currentIsAdmin;
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
elseif ($currentUserIsStaffAdmin) { $displayRole = $currentRole . ' | Admin'; }

?>

<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Patient Records - <?= htmlspecialchars($clinicName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

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
                    boxShadow: { 'soft': '0 10px 40px -10px rgba(0,0,0,0.08)' }
                }
            }
        }
    </script>
    <style>
        html, body { margin: 0; padding: 0; scroll-behavior: smooth; }
        .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        .icon-filled { font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; overflow: hidden; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .cert-text-input { border: 1px solid #e2e8f0; border-radius: 8px; outline: none; background: #f8fafc; width: 100%; padding: 7px 10px; font-size: 12px; transition: all 0.2s; }
        .cert-text-input:focus { background: #fff; border-color: var(--color-primary, #4f46e5); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.08); }
        @view-transition { navigation: auto; }
        header { view-transition-name: header; }
        aside { view-transition-name: sidebar; }
        ::view-transition-old(sidebar), ::view-transition-new(sidebar),
        ::view-transition-old(header), ::view-transition-new(header) { animation: none; }
    </style>
</head>
<body class="bg-background-light text-slate-800 h-screen overflow-hidden flex flex-col relative text-sm antialiased font-display">

<?php if(isset($_GET['msg'])): ?>
    <?php
        $msgText = ''; $msgColor = 'emerald'; $icon = 'check_circle';
        if($_GET['msg'] == 'patient_saved') { $msgText = 'Patient profile saved successfully!'; }
        elseif($_GET['msg'] == 'patient_archived') { $msgText = 'Patient record archived.'; $msgColor = 'amber'; $icon = 'inventory_2'; }
        elseif($_GET['msg'] == 'patient_restored') { $msgText = 'Patient record restored successfully!'; }
    ?>
    <?php if($msgText): ?>
    <div id="alertMsg" class="fixed top-24 left-1/2 -translate-x-1/2 z-[120] bg-white border-l-4 border-<?= $msgColor ?>-500 p-4 rounded-2xl shadow-2xl flex items-center gap-3 animate-bounce">
        <span class="material-symbols-outlined text-<?= $msgColor ?>-500"><?= $icon ?></span>
        <p class="text-xs font-black text-slate-800"><?= $msgText ?></p>
    </div>
    <script>setTimeout(() => { document.getElementById('alertMsg')?.remove(); }, 4000);</script>
    <?php endif; ?>
<?php endif; ?>

<div id="loggingOutScreen" class="fixed inset-0 z-[110] hidden bg-white flex-col items-center justify-center no-print">
    <div class="size-12 border-4 border-slate-200 border-t-primary rounded-full animate-spin mb-4"></div>
    <p class="font-bold text-slate-800 animate-pulse tracking-tight text-xs">Logging out safely...</p>
</div>

<div id="logoutModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm no-print">
    <div class="bg-white rounded-[2rem] p-6 max-w-xs w-full shadow-2xl border border-slate-100 text-center">
        <div class="size-12 rounded-2xl bg-red-50 text-red-500 flex items-center justify-center mx-auto mb-4"><span class="material-symbols-outlined text-2xl">logout</span></div>
        <h3 class="text-base font-black text-slate-900 mb-1">Logout Account?</h3>
        <p class="text-slate-500 text-[11px] mb-6">Sigurado ka bang gusto mong lumabas?</p>
        <div class="flex gap-2">
            <button onclick="closeLogoutModal()" class="flex-1 py-2.5 rounded-xl font-bold text-slate-400 hover:bg-slate-50 transition-all text-[11px]">Cancel</button>
            <button onclick="confirmLogout()" class="flex-1 py-2.5 rounded-xl font-bold bg-red-500 text-white hover:bg-red-600 transition-all text-[11px] shadow-lg shadow-red-100">Logout</button>
        </div>
    </div>
</div>

<div id="archiveModal" class="fixed inset-0 z-[150] hidden items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm no-print">
    <div class="bg-white rounded-[2rem] p-6 max-w-sm w-full shadow-2xl border border-slate-100 text-center">
        <div class="size-12 rounded-2xl bg-amber-50 text-amber-500 flex items-center justify-center mx-auto mb-4"><span class="material-symbols-outlined text-2xl">inventory_2</span></div>
        <h3 class="text-base font-black text-slate-900 mb-1">Archive Patient Record?</h3>
        <p class="text-slate-500 text-[11px] mb-2"><span id="archivePatientName" class="font-bold text-slate-700"></span><span id="archivePatientCode" class="block text-[10px] text-slate-400 mt-1"></span></p>
        <p class="text-slate-500 text-[11px] mb-6">The patient will be moved to <span class="font-bold">Archived Records</span> but their visit history will be kept.</p>
        <div class="flex gap-2">
            <button onclick="closeArchiveModal()" class="flex-1 py-2.5 rounded-xl font-bold text-slate-400 hover:bg-slate-50 transition-all text-[11px]">Cancel</button>
            <button onclick="confirmArchive()" class="flex-1 py-2.5 rounded-xl font-bold bg-amber-500 text-white hover:bg-amber-600 transition-all text-[11px] shadow-lg shadow-amber-100">Archive</button>
        </div>
    </div>
</div>

<div id="restoreModal" class="fixed inset-0 z-[150] hidden items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm no-print">
    <div class="bg-white rounded-[2rem] p-6 max-w-sm w-full shadow-2xl border border-slate-100 text-center">
        <div class="size-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center mx-auto mb-4"><span class="material-symbols-outlined text-2xl">settings_backup_restore</span></div>
        <h3 class="text-base font-black text-slate-900 mb-1">Restore Patient Record?</h3>
        <p class="text-slate-500 text-[11px] mb-2"><span id="restorePatientName" class="font-bold text-slate-700"></span><span id="restorePatientCode" class="block text-[10px] text-slate-400 mt-1"></span></p>
        <p class="text-slate-500 text-[11px] mb-6">The patient will be returned to <span class="font-bold">Active Records</span>.</p>
        <div class="flex gap-2">
            <button onclick="closeRestoreModal()" class="flex-1 py-2.5 rounded-xl font-bold text-slate-400 hover:bg-slate-50 transition-all text-[11px]">Cancel</button>
            <button onclick="confirmRestore()" class="flex-1 py-2.5 rounded-xl font-bold bg-emerald-500 text-white hover:bg-emerald-600 transition-all text-[11px] shadow-lg shadow-emerald-100">Restore</button>
        </div>
    </div>
</div>

<header class="h-20 bg-primary <?= $isLightTheme ? 'border-b border-slate-200' : 'border-b border-primary-dark' ?> flex items-center justify-between px-6 md:px-12 sticky top-0 z-50 shrink-0 shadow-soft relative no-print transition-colors duration-300">
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
            <span class="material-symbols-outlined text-sm">logout</span><span class="hidden md:inline">Logout</span>
        </button>
    </div>
</header>

    <div class="flex-1 flex overflow-hidden no-print">
    <aside class="w-80 bg-white border-r border-slate-200 hidden md:flex flex-col shrink-0 overflow-hidden" style="visibility:hidden">
        <nav id="sidebarNav" class="space-y-3 flex-1 p-6 overflow-y-auto">
            <p class="text-xs font-black text-slate-400 uppercase tracking-widest px-4 mb-2">Main Menu</p>
            <a href="dashboard.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all"><span class="material-symbols-outlined text-2xl">dashboard</span> <span>Dashboard</span></a>
            <a href="appointments.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all"><span class="material-symbols-outlined text-2xl">calendar_today</span> <span>Appointments</span></a>
            <a href="admissions.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all"><span class="material-symbols-outlined text-2xl icon-filled">how_to_reg</span> <span>Admissions</span></a>
            <a href="room.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all"><span class="material-symbols-outlined text-2xl">bed</span> <span>Rooms</span></a>
            <a href="patientrecords.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] <?= $sidebarActive ?> font-bold shadow-sm transition-all hover:scale-[1.02]"><span class="material-symbols-outlined text-2xl icon-filled">folder_shared</span> <span>Patients</span></a>
                <a href="staffmanagement.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all"><span class="material-symbols-outlined text-2xl">badge</span> <span>Accounts</span></a>
            <div class="space-y-3 mt-4 mb-4">
                <p class="text-xs font-black text-slate-400 uppercase tracking-widest px-4 mb-2 mt-6">Operations</p>
                <?php if ($currentUserIsOwner || $currentUserIsStaffAdmin || in_array('financials', $currentUserGrantedFeatures)): ?>
                <a href="financials.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800"><span class="material-symbols-outlined text-2xl">payments</span> <span>Financials</span></a>
                <?php endif; ?>
                <?php if ($currentUserIsOwner || $currentUserIsStaffAdmin || in_array('reports', $currentUserGrantedFeatures)): ?>
                <a href="report.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800"><span class="material-symbols-outlined text-2xl">bar_chart</span> <span>Reports</span></a>
                <?php endif; ?>
                <?php if ($currentUserIsOwner || $currentUserIsStaffAdmin || in_array('help_support', $currentUserGrantedFeatures)): ?>
                <a href="support.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800"><span class="material-symbols-outlined text-2xl">support_agent</span> <span>Help & Support</span></a>
                <?php endif; ?>
                <?php if ($currentUserIsOwner || $currentUserIsStaffAdmin || in_array('feedback', $currentUserGrantedFeatures)): ?>
                <a href="feedback.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800"><span class="material-symbols-outlined text-2xl">feedback</span> <span>Feedback</span></a>
                <?php endif; ?>
                <?php if ($currentUserIsOwner || $currentUserIsStaffAdmin): ?>
                <a href="tenantauditlogs.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800"><span class="material-symbols-outlined text-2xl">history</span> <span>Audit Logs</span></a>
                <?php endif; ?>
                <a href="<?= $currentUserIsOwner ? 'tenantsettings.php' : 'staffsettings.php' ?>" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800"><span class="material-symbols-outlined text-2xl">settings</span> <span>Settings</span></a>
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
        <div class="max-w-7xl mx-auto space-y-8">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div>
                    <h1 class="text-3xl font-black text-slate-800 tracking-tighter uppercase leading-tight">
                        <?= $isArchivedView ? 'Archived Records' : 'Patient Records' ?>
                    </h1>
                    <p class="text-slate-500 text-sm font-medium tracking-tight">
                        <?= $isArchivedView ? 'View and restore hidden patient records.' : 'Manage patient demographics and visit history.' ?>
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <?php if($isArchivedView): ?>
                        <a href="patientrecords.php" class="bg-slate-800 text-white p-2.5 rounded-xl hover:bg-slate-900 transition-all flex items-center gap-2 shadow-sm text-sm font-bold active:scale-95"><span class="material-symbols-outlined text-[18px]">arrow_back</span> Active Records</a>
                    <?php else: ?>
                        <a href="patientrecords.php?view=archived" class="bg-slate-200 text-slate-600 p-2.5 rounded-xl hover:bg-slate-300 transition-all flex items-center gap-2 shadow-sm text-sm font-bold active:scale-95"><span class="material-symbols-outlined text-[18px]">inventory_2</span> Archived Records</a>
                    <?php endif; ?>

                    <div class="flex items-center gap-2">
                        <select id="monthFilter" class="text-xs rounded-xl border-slate-200 py-2 focus:ring-primary shadow-sm">
                            <option value="">Month</option>
                            <?php for($m=1; $m<=12; $m++): ?>
                                <option value="<?php echo sprintf('%02d', $m); ?>"><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                            <?php endfor; ?>
                        </select>
                        <select id="yearFilter" class="text-xs rounded-xl border-slate-200 py-2 focus:ring-primary shadow-sm">
                            <?php for($y=date('Y'); $y>=2020; $y--): ?>
                                <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                        <button type="button" onclick="filterPatients()" class="bg-primary text-white p-2.5 rounded-xl hover:bg-primary-dark transition-all flex items-center shadow-lg active:scale-95"><span class="material-symbols-outlined text-sm">filter_alt</span></button>
                        <button onclick="resetDateFilter()" class="text-[10px] bg-slate-200 px-3 py-2 rounded-xl text-slate-500 font-bold uppercase tracking-widest hover:bg-slate-300 hover:text-slate-700 transition-all">All</button>
                        <select id="sortCreated" onchange="sortPatients()" class="ml-1 text-xs rounded-xl border-slate-200 py-2 focus:ring-primary shadow-sm">
                            <option value="desc">Newest to Oldest</option>
                            <option value="asc">Oldest to Newest</option>
                        </select>
                    </div>
                    <div class="h-8 w-px bg-slate-200 mx-1"></div>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-lg">search</span>
                        <input type="text" id="pSearch" onkeyup="filterPatients()" placeholder="Search patient..." class="pl-11 pr-4 py-2.5 rounded-xl border-slate-200 text-sm w-44 md:w-60 focus:ring-primary shadow-sm">
                    </div>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-3xl shadow-sm overflow-hidden text-sm">
                <table class="w-full text-left" id="pTable">
                    <thead class="bg-slate-50 border-b border-slate-100 text-[10px] font-black uppercase tracking-widest text-slate-400">
                        <tr>
                            <th class="px-6 py-5">Patient Name</th>
                            <th class="px-6 py-5">Contact</th>
                            <th class="px-6 py-5">Email / Account</th>
                            <th class="px-6 py-5">Address</th>
                            <th class="px-6 py-5">Registration Date</th>
                            <th class="px-6 py-5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium text-slate-600">
                        <?php if (count($patients) > 0): ?>
                            <?php foreach ($patients as $row): ?>
                            <tr class="p-row hover:bg-slate-50 transition-colors" data-visit="<?= date('Y-m', strtotime($row['created_at'])) ?>" data-created="<?= htmlspecialchars($row['created_at']) ?>" data-patientid="<?= htmlspecialchars($row['patient_id']) ?>" data-contact="<?= htmlspecialchars($row['contact_number']) ?>" data-address="<?= htmlspecialchars($row['address']) ?>">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <?php $patPic = $row['profile_pic_url'] ?? ''; ?>
                                        <?php if (!empty($patPic)): ?>
                                            <img src="<?= htmlspecialchars($patPic) ?>" class="w-10 h-10 rounded-full object-cover ring-2 ring-slate-200 shrink-0" alt="">
                                        <?php else: ?>
                                            <div class="w-10 h-10 rounded-full bg-slate-200 flex items-center justify-center shrink-0">
                                                <span class="material-symbols-outlined text-slate-400 text-lg">person</span>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="font-black text-slate-800 tracking-tight text-base mb-1 cell-name flex items-center gap-2">
                                                <?= htmlspecialchars($row['full_name']) ?>
                                                <?php $isPhilhealthMember = !empty($row['ph_id_front']) || !empty($row['ph_id_back']); ?>
                                                <?php if($isPhilhealthMember): ?>
                                                    <span class="inline-flex items-center justify-center h-7 px-2 rounded-md bg-green-50 border border-green-200" title="PhilHealth Member">
                                                        <img src="uploads/philhealth_logo.png" class="h-6 w-auto object-contain" alt="PhilHealth" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex'">
                                                        <span class="material-symbols-outlined text-[16px] text-green-700" style="display:none">health_and_safety</span>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if(isset($row['age']) && ($row['age'] === '0' || $row['age'] === 0)): ?>
                                                    <span class="text-[9px] bg-pink-100 text-pink-600 px-2 py-0.5 rounded-full border border-pink-200 flex items-center gap-1 w-max"><span class="material-symbols-outlined text-[12px]">child_care</span> NEWBORN</span>
                                                <?php elseif(isset($row['age']) && $row['age'] !== '' && (int)$row['age'] < 18): ?>
                                                    <span class="text-[9px] bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full border border-amber-200 flex items-center gap-1 w-max"><span class="material-symbols-outlined text-[12px]">shield_person</span> MINOR</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-[10px] text-slate-400 font-bold uppercase tracking-widest bg-slate-100 inline-block px-2 py-0.5 rounded border border-slate-200 mt-1"><?= $row['patient_id'] ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4"><?= htmlspecialchars($row['contact_number'] ?: '---') ?></td>
                                <td class="px-6 py-4">
                                    <?php if (!empty($row['email_address'])): ?>
                                        <span class="text-slate-700"><?= htmlspecialchars($row['email_address']) ?></span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 text-[10px] bg-slate-100 text-slate-400 px-2 py-1 rounded-lg border border-slate-200 font-bold uppercase tracking-widest"><span class="material-symbols-outlined text-[12px]">person_off</span> No Account</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 truncate max-w-[200px]"><?= htmlspecialchars($row['address'] ?: '---') ?></td>
                                <td class="px-6 py-4 font-bold text-primary"><?= !empty($row['created_at']) ? date('M d, Y', strtotime($row['created_at'])) : '<span class="text-slate-300 font-normal">No date</span>' ?></td>
                                <td class="px-6 py-4 text-right flex justify-end gap-2">
                                    <button onclick='viewFullPatient(<?= json_encode($row) ?>)' class="px-4 py-2 flex items-center justify-center rounded-xl bg-slate-50 text-slate-600 hover:bg-primary hover:text-white transition-all shadow-sm text-xs font-bold border border-slate-100">View</button>
                                    <?php if(!$isArchivedView): ?>
                                        <button type="button" onclick='openArchiveModal(<?= json_encode($row['id']) ?>, <?= json_encode($row['full_name']) ?>, <?= json_encode($row['patient_id']) ?>)' class="size-10 flex items-center justify-center rounded-xl bg-amber-50 text-amber-500 hover:bg-amber-500 hover:text-white transition-all shadow-sm border border-amber-100" title="Archive Patient"><span class="material-symbols-outlined text-[18px]">inventory_2</span></button>
                                    <?php else: ?>
                                        <button type="button" onclick='openRestoreModal(<?= json_encode($row['id']) ?>, <?= json_encode($row['full_name']) ?>, <?= json_encode($row['patient_id']) ?>)' class="size-10 flex items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 hover:bg-emerald-500 hover:text-white transition-all shadow-sm border border-emerald-100" title="Restore Patient"><span class="material-symbols-outlined text-[18px]">settings_backup_restore</span></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="px-6 py-20 text-center text-slate-400 italic">No patient records found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<div id="fullViewModal" class="fixed inset-0 z-[200] hidden overflow-y-auto no-print">
    <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeFullView()"></div>
    <div class="relative min-h-screen flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-6xl rounded-[2rem] shadow-2xl flex flex-col md:flex-row overflow-hidden max-h-[90vh] relative">
            <button onclick="closeFullView()" class="absolute top-4 right-4 z-[210] size-10 flex items-center justify-center bg-slate-100 hover:bg-red-500 hover:text-white rounded-full transition-all"><span class="material-symbols-outlined text-xl">close</span></button>
            <div class="w-full md:w-2/5 bg-slate-50 border-r border-slate-200 p-8 overflow-y-auto">
                <div class="mb-6 pb-6 border-b border-slate-200">
                    <div class="flex items-center gap-4 mb-3">
                        <div id="v_profile_pic" class="w-16 h-16 rounded-full bg-slate-200 flex items-center justify-center shrink-0 ring-2 ring-slate-200 overflow-hidden">
                            <span class="material-symbols-outlined text-slate-400 text-2xl">person</span>
                        </div>
                        <div>
                            <h2 id="v_name" class="text-3xl font-black text-slate-800 leading-tight tracking-tighter mb-2"></h2>
                            <p id="v_id" class="text-[10px] text-slate-500 font-bold uppercase tracking-widest bg-white border border-slate-200 inline-block px-3 py-1 rounded-lg"></p>
                        </div>
                    </div>
                </div>
                <div class="space-y-6 text-sm text-slate-600">
                    <div>
                        <h3 class="text-[10px] font-black uppercase tracking-widest text-primary mb-3">Personal Details</h3>
                        <div class="grid grid-cols-1 gap-y-3 bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
                            <p><b>Birthday:</b> <span id="v_bday"></span></p>
                            <p><b>Age:</b> <span id="v_age"></span></p>
                            <?php if (!$isReceptionist): ?>
                            <p><b>Menarche:</b> <span id="v_menarche"></span></p>
                            <?php endif; ?>
                            <p class="flex items-center gap-2"><b>Gravida / Para:</b> <span id="v_gp_main" class="inline-block text-[10px] font-black tracking-widest px-2 py-0.5 rounded border bg-pink-100 text-pink-700 border-pink-200">G0P0</span></p>
                            <p><b>Status:</b> <span id="v_status"></span></p>
                            <p><b>Religion:</b> <span id="v_religion"></span></p>
                            <p><b>Contact:</b> <span id="v_contact"></span></p>
                            <p class="flex items-center gap-2"><b>Email:</b> <span id="v_email"></span> <span id="v_account_badge"></span></p>
                            <p><b>Address:</b> <span id="v_address"></span></p>
                            <div id="v_pregnancy_block" class="hidden mt-2 pt-3 border-t border-dashed border-pink-200 space-y-2">
                                <p class="flex items-center gap-2"><b>Pregnancy Status:</b> <span id="v_pregnancy_status" class="inline-block text-[10px] font-black uppercase tracking-widest px-2 py-0.5 rounded border bg-pink-100 text-pink-700 border-pink-200">---</span></p>
                                <p class="flex items-center gap-2"><b>Gravida / Para:</b> <span id="v_gp_preg" class="inline-block text-[10px] font-black tracking-widest px-2 py-0.5 rounded border bg-pink-100 text-pink-700 border-pink-200">G0P0</span></p>
                                <p><b>Last Menstrual Period:</b> <span id="v_lmp">---</span></p>
                                <p><b>Estimated Delivery Date:</b> <span id="v_edd">---</span></p>
                            </div>
                        </div>
                    </div>
                    <div>
                        <h3 class="text-[10px] font-black uppercase tracking-widest text-primary mb-3">Family Info</h3>
                        <div class="grid grid-cols-1 gap-y-3 bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
                            <p><b>Husband:</b> <span id="v_husband"></span></p>
                            <p><b>Mother:</b> <span id="v_mother"></span></p>
                            <p><b>Father:</b> <span id="v_father"></span></p>
                        </div>
                    </div>

                    <div id="v_guardian_section" class="hidden">
                        <h3 class="text-[10px] font-black uppercase tracking-widest text-amber-600 mb-3 flex items-center gap-1">
                            <span class="material-symbols-outlined text-[14px]">shield_person</span> Guardian ID — Minor Patient
                        </h3>
                        <div class="bg-amber-50 p-4 rounded-2xl border border-amber-200 shadow-sm">
                            <span class="inline-block text-[9px] bg-amber-100 text-amber-700 px-2 py-0.5 rounded border border-amber-200 font-black uppercase tracking-widest mb-3">Minor Patient</span>
                            <img id="v_guardian_img" src="" alt="Guardian ID" class="w-full rounded-xl border border-amber-200 shadow-sm cursor-pointer" onclick="window.open(this.src, '_blank')">
                        </div>
                    </div>

                    <div id="v_philhealth_section" class="hidden">
                        <h3 class="text-[10px] font-black uppercase tracking-widest text-green-700 mb-3 flex items-center gap-1">
                            <img src="uploads/philhealth_logo.png" class="w-4 h-4 object-contain" onerror="this.style.display='none';this.nextElementSibling.style.display='inline'">
                            <span class="material-symbols-outlined text-[14px]" style="display:none">health_and_safety</span> PhilHealth ID
                        </h3>
                        <div class="bg-green-50 p-4 rounded-2xl border border-green-200 shadow-sm space-y-3">
                            <span class="inline-block text-[9px] bg-green-100 text-green-700 px-2 py-0.5 rounded border border-green-200 font-black uppercase tracking-widest mb-1">PhilHealth Member</span>
                            <div id="v_ph_front_wrap">
                                <p class="text-[10px] font-bold text-green-600 uppercase tracking-widest mb-1">Front</p>
                                <img id="v_ph_front_img" src="" alt="PhilHealth ID Front" class="w-full rounded-xl border border-green-200 shadow-sm cursor-pointer" onclick="window.open(this.src, '_blank')">
                            </div>
                            <div id="v_ph_back_wrap">
                                <p class="text-[10px] font-bold text-green-600 uppercase tracking-widest mb-1">Back</p>
                                <img id="v_ph_back_img" src="" alt="PhilHealth ID Back" class="w-full rounded-xl border border-green-200 shadow-sm cursor-pointer" onclick="window.open(this.src, '_blank')">
                            </div>
                        </div>
                    </div>

                    <div id="v_babies_section" class="hidden mt-6">
                        <button onclick="openBabiesModal()" class="w-full bg-pink-50 hover:bg-pink-500 hover:text-white border border-pink-200 text-pink-600 rounded-xl p-3 flex items-center justify-between transition-all shadow-sm group">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-xl">child_care</span>
                                <span class="font-black text-sm uppercase tracking-widest">Registered Infants(<span id="v_babies_count">0</span>)</span>
                            </div>
                            <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform">open_in_new</span>
                        </button>
                    </div>


                </div>
            </div>
            <div class="w-full md:w-3/5 p-8 flex flex-col overflow-hidden text-sm bg-white">
                <div class="flex justify-between items-center mb-6 border-b border-slate-100 pb-4">
                    <h3 class="font-black text-2xl tracking-tighter text-slate-800 flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">timeline</span> Visit Timeline
                    </h3>
                    <div class="flex items-center gap-2">
                        <select id="timelineSortFilter" onchange="renderTimeline()" class="py-1.5 px-2 rounded-xl border border-slate-200 text-xs bg-white shadow-sm outline-none focus:border-primary cursor-pointer font-semibold text-slate-600">
                            <option value="newest">Newest</option>
                            <option value="oldest">Oldest</option>
                        </select>
                        <select id="timelineServiceFilter" onchange="renderTimeline()" class="py-1.5 px-2 rounded-xl border border-slate-200 text-xs bg-white shadow-sm outline-none focus:border-primary cursor-pointer font-semibold text-slate-600 max-w-[140px]">
                            <option value="all">All Services</option>
                        </select>
                    </div>
                </div>
                <div id="v_history_list" class="flex-1 overflow-y-auto space-y-4 pr-2 pb-4"></div>
            </div>
        </div>
    </div>
</div>

<div id="babiesModal" class="fixed inset-0 z-[250] hidden flex items-center justify-center p-4 no-print">
    <div class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm" onclick="closeBabiesModal()"></div>
    <div class="relative bg-white w-full max-w-3xl rounded-[2rem] shadow-2xl flex flex-col overflow-hidden max-h-[90vh]">
        <div class="bg-pink-50 p-6 border-b border-pink-100 flex justify-between items-center shrink-0">
            <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-pink-500 text-3xl icon-filled">child_care</span>
                <div>
                    <h3 class="font-black uppercase text-sm tracking-widest text-pink-800 leading-none">Registered Infants</h3>
                    <p class="text-[10px] font-bold text-pink-500 mt-1">Babies linked to this mother</p>
                </div>
            </div>
            <button onclick="closeBabiesModal()" class="hover:bg-pink-200 text-pink-500 rounded-full size-8 flex items-center justify-center transition-colors"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>
        <div class="p-6 space-y-4 overflow-y-auto bg-slate-50/50" id="modal_babies_list"></div>
    </div>
</div>

<div id="receiptModal" class="fixed inset-0 z-[260] hidden flex items-center justify-center p-4 no-print">
    <div class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm" onclick="closeReceiptModal()"></div>
    <div class="relative bg-white w-full max-w-3xl rounded-[2rem] shadow-2xl flex flex-col overflow-hidden max-h-[90vh]">
        <div class="bg-emerald-50 p-4 border-b border-emerald-100 flex justify-between items-center shrink-0">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-emerald-500 icon-filled">receipt_long</span>
                <h3 class="font-black uppercase text-xs tracking-widest text-emerald-800">Payment Receipt</h3>
            </div>
            <button onclick="closeReceiptModal()" class="hover:bg-emerald-100 text-emerald-600 rounded-full size-8 flex items-center justify-center transition-colors"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>
        <div class="flex-1 bg-slate-50 p-4 overflow-auto flex items-center justify-center">
            <img id="receiptImage" src="" alt="Receipt Image" class="max-h-[80vh] max-w-full rounded-xl border border-slate-200 shadow hidden" />
            <iframe id="receiptFrame" src="" class="w-full h-[80vh] rounded-xl border border-slate-200 shadow hidden" frameborder="0"></iframe>
            <!-- On-the-fly generated receipt -->
            <div id="receiptGenerated" class="hidden w-full max-w-md mx-auto">
                <div class="px-8 py-10 bg-white rounded-xl border border-slate-200 shadow">
                    <div class="text-center mb-8 border-b border-dashed border-slate-300 pb-6">
                        <span class="material-symbols-outlined text-[48px] text-emerald-500 mb-2 drop-shadow-sm">verified</span>
                        <h2 class="text-2xl font-black text-slate-800 uppercase tracking-tight"><?= htmlspecialchars($clinicName) ?></h2>
                        <div class="flex items-center justify-center gap-2 mt-1">
                            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest bg-slate-100 px-2 py-0.5 rounded">CODE: <?= htmlspecialchars($clinicCode) ?></p>
                        </div>
                    </div>
                    <div class="text-center mb-6">
                        <p class="text-lg font-black text-slate-800 uppercase tracking-widest">Official Receipt</p>
                    </div>
                    <div class="space-y-4 text-sm font-medium">
                        <div class="flex justify-between items-end border-b border-slate-100 pb-2">
                            <span class="text-slate-500 text-xs uppercase tracking-widest font-bold">Date & Time</span>
                            <span class="font-bold text-slate-800 text-right" id="gen_rec_date"></span>
                        </div>
                        <div class="flex justify-between items-end border-b border-slate-100 pb-2">
                            <span class="text-slate-500 text-xs uppercase tracking-widest font-bold">Patient Name</span>
                            <span class="font-black text-slate-800 text-right" id="gen_rec_patient"></span>
                        </div>
                        <div class="flex justify-between items-end border-b border-slate-100 pb-2">
                            <span class="text-slate-500 text-xs uppercase tracking-widest font-bold">Service Rendered</span>
                            <span class="font-bold text-primary text-right max-w-[200px] truncate" id="gen_rec_service"></span>
                        </div>
                        <div class="flex justify-between items-end border-b border-slate-100 pb-2">
                            <span class="text-slate-500 text-xs uppercase tracking-widest font-bold">Payment Method</span>
                            <span class="font-bold text-slate-800 text-right" id="gen_rec_method"></span>
                        </div>
                        <div id="gen_rec_philhealth_section" class="hidden space-y-2 pt-2 pb-2 border-b border-green-200 bg-green-50 p-3 rounded-xl">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="material-symbols-outlined text-green-600 text-[16px]">health_and_safety</span>
                                <span class="text-[10px] font-black text-green-700 uppercase tracking-widest">PhilHealth Coverage</span>
                            </div>
                            <div class="flex justify-between items-end">
                                <span class="text-green-600 text-xs font-bold">Original Amount</span>
                                <span class="font-black text-green-800" id="gen_rec_ph_original">₱ 0.00</span>
                            </div>
                            <div class="flex justify-between items-end">
                                <span class="text-green-600 text-xs font-bold">PhilHealth Covered</span>
                                <span class="font-black text-green-600" id="gen_rec_ph_covered">- ₱ 0.00</span>
                            </div>
                        </div>
                        <div class="flex justify-between items-end pt-4 bg-slate-50 p-4 rounded-xl border border-slate-100">
                            <span class="font-black text-slate-800 uppercase tracking-widest text-xs">Total Amount</span>
                            <span class="font-black text-emerald-600 text-2xl" id="gen_rec_amount"></span>
                        </div>
                    </div>
                    <div class="mt-10 text-center">
                        <p class="text-[10px] text-slate-400 italic">This is an electronically generated receipt.<br>Thank you for trusting <?= htmlspecialchars($clinicName) ?>!</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="certFormModal" class="fixed inset-0 z-[300] hidden flex items-center justify-center p-4 no-print">
    <div class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm" onclick="closeCertForm()"></div>
    <div class="relative bg-white w-full max-w-3xl rounded-2xl shadow-2xl flex flex-col overflow-hidden max-h-[92vh]">
        <div class="bg-primary px-6 py-4 text-white flex justify-between items-center shrink-0">
            <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-xl opacity-80">description</span>
                <div>
                    <h3 class="font-bold text-sm tracking-wide">Certificate of Live Birth</h3>
                    <p class="text-[10px] opacity-70 mt-0.5">Municipal Form No. 102</p>
                </div>
            </div>
            <button onclick="closeCertForm()" class="hover:bg-white/20 rounded-full size-8 flex items-center justify-center transition-colors"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>
        <div class="p-5 space-y-4 overflow-y-auto bg-slate-50/80">
            <input type="hidden" id="cf_baby_json">

            <!-- Registry Info -->
            <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-sm">
                <h4 class="text-[10px] font-black text-primary uppercase tracking-[0.15em] mb-3 flex items-center gap-1.5"><span class="material-symbols-outlined text-xs">pin_drop</span>Registry Information</h4>
                <div class="grid grid-cols-3 gap-3">
                    <div><label class="text-[10px] font-semibold text-slate-500 block mb-1">Province</label><input type="text" id="cf_province" class="cert-text-input focus:border-primary"></div>
                    <div><label class="text-[10px] font-semibold text-slate-500 block mb-1">City / Municipality</label><input type="text" id="cf_city" class="cert-text-input focus:border-primary"></div>
                    <div><label class="text-[10px] font-semibold text-slate-500 block mb-1">Registry No.</label><input type="text" id="cf_registry_no" class="cert-text-input focus:border-primary" placeholder="N/A"></div>
                </div>
            </div>

            <!-- Birth Details -->
            <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-sm">
                <h4 class="text-[10px] font-black text-primary uppercase tracking-[0.15em] mb-3 flex items-center gap-1.5"><span class="material-symbols-outlined text-xs">child_care</span>Child / Birth Details</h4>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="text-[10px] font-semibold text-slate-500 block mb-1">5a. Type of Birth</label>
                        <select id="cf_birth_type" class="cert-text-input focus:border-primary cursor-pointer">
                            <option value="Single">Single</option><option value="Twin">Twin</option><option value="Triplet">Triplet</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] font-semibold text-slate-500 block mb-1">5b. If Multiple</label>
                        <select id="cf_multiple_type" class="cert-text-input focus:border-primary cursor-pointer">
                            <option value="">Not Applicable</option><option value="First">First</option><option value="Second">Second</option><option value="Third">Third</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] font-semibold text-slate-500 block mb-1">5c. Birth Order</label>
                        <select id="cf_birth_order" class="cert-text-input focus:border-primary cursor-pointer">
                            <option value="First">First</option><option value="Second">Second</option><option value="Third">Third</option><option value="Fourth">Fourth</option><option value="Fifth">Fifth</option><option value="Sixth">Sixth</option><option value="Seventh">Seventh</option><option value="Eighth">Eighth</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Mother & Father side by side -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Mother -->
                <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-sm">
                    <h4 class="text-[10px] font-black text-pink-500 uppercase tracking-[0.15em] mb-3 flex items-center gap-1.5"><span class="material-symbols-outlined text-xs">female</span>Mother's Details</h4>
                    <div class="space-y-2.5">
                        <div class="grid grid-cols-2 gap-2">
                            <div><label class="text-[10px] font-semibold text-slate-500 block mb-1">8. Citizenship</label><input type="text" id="cf_m_citizen" class="cert-text-input focus:border-primary" value="Filipino"></div>
                            <div><label class="text-[10px] font-semibold text-slate-500 block mb-1">9. Religion</label><input type="text" id="cf_m_religion" class="cert-text-input focus:border-primary" placeholder="Roman Catholic"></div>
                        </div>
                        <div><label class="text-[10px] font-semibold text-slate-500 block mb-1">11. Occupation</label><input type="text" id="cf_m_job" class="cert-text-input focus:border-primary" placeholder="Housewife"></div>
                        <div class="grid grid-cols-3 gap-2">
                            <div><label class="text-[10px] font-semibold text-slate-500 block mb-1">10a. Born Alive</label><input type="number" id="cf_m_alive" class="cert-text-input focus:border-primary text-center" value="1"></div>
                            <div><label class="text-[10px] font-semibold text-slate-500 block mb-1">10b. Still Living</label><input type="number" id="cf_m_living" class="cert-text-input focus:border-primary text-center" value="1"></div>
                            <div><label class="text-[10px] font-semibold text-slate-500 block mb-1">10c. Dead</label><input type="number" id="cf_m_dead" class="cert-text-input focus:border-primary text-center" value="0"></div>
                        </div>
                        <div>
                            <label class="text-[10px] font-semibold text-slate-500 block mb-1">13. Residence</label>
                            <div class="grid grid-cols-4 gap-2">
                                <input type="text" id="cf_m_res_house" class="cert-text-input focus:border-primary" placeholder="House/St/Brgy">
                                <input type="text" id="cf_m_res_city" class="cert-text-input focus:border-primary" placeholder="City/Municipality">
                                <input type="text" id="cf_m_res_province" class="cert-text-input focus:border-primary" placeholder="Province">
                                <input type="text" id="cf_m_res_country" class="cert-text-input focus:border-primary" placeholder="Country" value="Philippines">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Father -->
                <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-sm">
                    <h4 class="text-[10px] font-black text-blue-500 uppercase tracking-[0.15em] mb-3 flex items-center gap-1.5"><span class="material-symbols-outlined text-xs">male</span>Father's Details</h4>
                    <div class="space-y-2.5">
                        <div class="grid grid-cols-3 gap-2">
                            <div><label class="text-[10px] font-semibold text-slate-500 block mb-1">14. First</label><input type="text" id="cf_f_first" class="cert-text-input focus:border-primary"></div>
                            <div><label class="text-[10px] font-semibold text-slate-500 block mb-1">Middle</label><input type="text" id="cf_f_middle" class="cert-text-input focus:border-primary"></div>
                            <div><label class="text-[10px] font-semibold text-slate-500 block mb-1">Last</label><input type="text" id="cf_f_last" class="cert-text-input focus:border-primary"></div>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div><label class="text-[10px] font-semibold text-slate-500 block mb-1">15. Citizenship</label><input type="text" id="cf_f_citizen" class="cert-text-input focus:border-primary" value="Filipino"></div>
                            <div><label class="text-[10px] font-semibold text-slate-500 block mb-1">16. Religion</label><input type="text" id="cf_f_religion" class="cert-text-input focus:border-primary" placeholder="Roman Catholic"></div>
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <div><label class="text-[10px] font-semibold text-slate-500 block mb-1">17. Occupation</label><input type="text" id="cf_f_job" class="cert-text-input focus:border-primary"></div>
                            <div><label class="text-[10px] font-semibold text-slate-500 block mb-1">18. Age</label><input type="number" id="cf_f_age" class="cert-text-input focus:border-primary text-center"></div>
                            <div><label class="text-[10px] font-semibold text-slate-500 block mb-1">Date of Birth</label><input type="date" id="cf_f_dob" class="cert-text-input focus:border-primary"></div>
                        </div>
                        <label class="text-[10px] font-semibold text-slate-500 block mb-1">10. Residence</label>
                        <div class="grid grid-cols-2 gap-2">
                            <div><input type="text" id="cf_f_res_house" class="cert-text-input focus:border-primary" placeholder="House No., St., Brgy."></div>
                            <div><input type="text" id="cf_f_res_city" class="cert-text-input focus:border-primary" placeholder="City/Municipality"></div>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div><input type="text" id="cf_f_res_province" class="cert-text-input focus:border-primary" placeholder="Province"></div>
                            <div><input type="text" id="cf_f_res_country" class="cert-text-input focus:border-primary" placeholder="Country" value="Philippines"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Marriage & Attendant -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-sm">
                    <h4 class="text-[10px] font-black text-primary uppercase tracking-[0.15em] mb-3 flex items-center gap-1.5"><span class="material-symbols-outlined text-xs">favorite</span>Marriage of Parents</h4>
                    <div class="space-y-2.5">
                        <div><label class="text-[10px] font-semibold text-slate-500 block mb-1">20a. Date of Marriage</label><input type="date" id="cf_mar_date" class="cert-text-input focus:border-primary"></div>
                        <label class="text-[10px] font-semibold text-slate-500 block mb-1">20b. Place of Marriage</label>
                        <div class="grid grid-cols-3 gap-2">
                            <div><input type="text" id="cf_mar_city" class="cert-text-input focus:border-primary" placeholder="City/Municipality"></div>
                            <div><input type="text" id="cf_mar_province" class="cert-text-input focus:border-primary" placeholder="Province"></div>
                            <div><input type="text" id="cf_mar_country" class="cert-text-input focus:border-primary" placeholder="Country" value="Philippines"></div>
                        </div>
                    </div>
                </div>
                <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-sm">
                    <h4 class="text-[10px] font-black text-primary uppercase tracking-[0.15em] mb-3 flex items-center gap-1.5"><span class="material-symbols-outlined text-xs">medical_services</span>Attendant at Birth</h4>
                    <div class="space-y-2.5">
                        <div>
                            <label class="text-[10px] font-semibold text-slate-500 block mb-1">21a. Type</label>
                            <select id="cf_att_type" class="cert-text-input focus:border-primary cursor-pointer">
                                <option value="Physician">1 Physician</option><option value="Nurse">2 Nurse</option><option value="Midwife" selected>3 Midwife</option><option value="Hilot">4 Hilot</option><option value="Others">5 Others</option>
                            </select>
                        </div>
                        <div><label class="text-[10px] font-semibold text-slate-500 block mb-1">Name of Attendant</label><input type="text" id="cf_attendant" class="cert-text-input focus:border-primary"></div>
                    </div>
                </div>
            </div>

            <button type="button" onclick="executePrintCertificate()" class="w-full bg-primary hover:bg-primary-dark text-white font-bold py-3 rounded-xl shadow-lg active:scale-[0.98] flex items-center justify-center gap-2 transition-all text-sm">
                <span class="material-symbols-outlined text-[18px]">image</span> Generate Certificate
            </button>
        </div>
    </div>
</div>

<!-- Certificate Options Modal -->
<div id="certOptionsModal" class="fixed inset-0 z-[360] hidden flex items-center justify-center p-4 no-print">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="closeCertOptions()"></div>
    <div class="relative bg-white w-full max-w-[280px] rounded-2xl shadow-2xl">
        <button onclick="closeCertOptions()" class="absolute -top-2 -right-2 z-10 size-7 flex items-center justify-center bg-red-500 hover:bg-red-600 text-white rounded-full transition-all shadow-lg"><span class="material-symbols-outlined text-[16px]">close</span></button>
        <div class="px-6 py-8 flex items-start justify-center gap-10">
            <button onclick="editCertFromOptions()" class="flex flex-col items-center gap-3 hover:opacity-70 transition-opacity">
                <span class="material-symbols-outlined text-[56px] text-slate-800">edit_square</span>
                <span class="text-xs font-bold text-slate-800">Edit Certificate</span>
            </button>
            <button onclick="viewCertFromOptions()" class="flex flex-col items-center gap-3 hover:opacity-70 transition-opacity">
                <span class="material-symbols-outlined text-[56px] text-slate-800">pageview</span>
                <span class="text-xs font-bold text-slate-800">View Certificate</span>
            </button>
        </div>
    </div>
</div>

<!-- View Saved Certificate Modal -->
<div id="viewCertModal" class="fixed inset-0 z-[350] hidden flex items-center justify-center p-4 no-print">
    <div class="fixed inset-0 bg-slate-900/85 backdrop-blur-sm" onclick="closeViewCert()"></div>
    <div class="relative bg-white w-full max-w-3xl rounded-2xl shadow-2xl flex flex-col overflow-hidden max-h-[95vh]">
        <div class="bg-emerald-600 px-6 py-4 text-white flex justify-between items-center shrink-0">
            <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-xl opacity-80">verified</span>
                <div>
                    <h3 class="font-bold text-sm tracking-wide">Certificate of Live Birth</h3>
                    <p class="text-[10px] opacity-70 mt-0.5">Saved Record</p>
                </div>
            </div>
            <button onclick="closeViewCert()" class="hover:bg-white/20 rounded-full size-8 flex items-center justify-center transition-colors"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>
        <div class="flex-1 overflow-auto p-4 bg-slate-100">
            <img id="viewCertImg" src="" alt="Certificate" class="w-full rounded-lg shadow-lg border border-slate-200">
        </div>
        <div class="px-5 py-4 bg-white border-t border-slate-200 flex items-center gap-3 shrink-0">
            <button onclick="closeViewCert()" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-2.5 rounded-xl flex items-center justify-center gap-2 text-xs transition-all">
                <span class="material-symbols-outlined text-[16px]">close</span> Close
            </button>
            <a id="viewCertDownloadLink" href="" download="Certificate_LiveBirth.png" class="flex-1 bg-slate-700 hover:bg-slate-800 text-white font-bold py-2.5 rounded-xl flex items-center justify-center gap-2 text-xs transition-all no-underline">
                <span class="material-symbols-outlined text-[16px]">download</span> Download
            </a>
        </div>
    </div>
</div>

<!-- Certificate Preview Modal -->
<div id="certPreviewModal" class="fixed inset-0 z-[350] hidden flex items-center justify-center p-4 no-print">
    <div class="fixed inset-0 bg-slate-900/85 backdrop-blur-sm" onclick="closeCertPreview()"></div>
    <div class="relative bg-white w-full max-w-3xl rounded-2xl shadow-2xl flex flex-col overflow-hidden max-h-[95vh]">
        <div class="bg-primary px-6 py-4 text-white flex justify-between items-center shrink-0">
            <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-xl opacity-80">verified</span>
                <div>
                    <h3 class="font-bold text-sm tracking-wide">Certificate of Live Birth</h3>
                    <p class="text-[10px] opacity-70 mt-0.5">Preview & Save</p>
                </div>
            </div>
            <button onclick="closeCertPreview()" class="hover:bg-white/20 rounded-full size-8 flex items-center justify-center transition-colors"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>
        <div class="flex-1 overflow-auto p-4 bg-slate-100">
            <img id="certPreviewImg" src="" alt="Certificate Preview" class="w-full rounded-lg shadow-lg border border-slate-200">
        </div>
        <div class="px-5 py-4 bg-white border-t border-slate-200 flex items-center gap-3 shrink-0">
            <button onclick="editCertificateAgain()" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-2.5 rounded-xl flex items-center justify-center gap-2 text-xs transition-all">
                <span class="material-symbols-outlined text-[16px]">edit</span> Edit Details
            </button>
            <button onclick="downloadCertificate()" class="flex-1 bg-slate-700 hover:bg-slate-800 text-white font-bold py-2.5 rounded-xl flex items-center justify-center gap-2 text-xs transition-all">
                <span class="material-symbols-outlined text-[16px]">download</span> Download
            </button>
            <button id="certSaveBtn" onclick="saveCertAndShowOptions()" class="flex-1 bg-primary hover:bg-primary-dark text-white font-bold py-2.5 rounded-xl flex items-center justify-center gap-2 text-xs transition-all shadow-md">
                <span class="material-symbols-outlined text-[16px]">save</span> Save to Record
            </button>
        </div>
    </div>
</div>

<div id="viewCheckupDetailModal" class="fixed inset-0 z-[300] hidden flex items-center justify-center p-4 no-print">
    <div class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm" onclick="closeCheckupView()"></div>
    <div class="relative bg-white w-full max-w-4xl rounded-[2rem] shadow-2xl flex flex-col overflow-hidden max-h-[90vh]">
        <div class="bg-primary p-6 text-white flex justify-between items-center shrink-0">
            <h3 class="font-black uppercase text-sm tracking-widest">Medical Visit Details</h3>
            <button onclick="closeCheckupView()" class="hover:bg-white/20 rounded-full size-8 flex items-center justify-center transition-colors"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>
        <div class="p-8 space-y-8 overflow-y-auto text-slate-800 text-sm bg-slate-50">
            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between mb-6">
                <div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Check-up Date</p>
                    <p id="vd_date" class="font-black text-2xl tracking-tighter text-primary leading-none"></p>
                </div>
                <div class="text-right">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Attending Staff</p>
                    <p id="vd_staff" class="font-bold text-slate-700"></p>
                </div>
            </div>

            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm relative overflow-hidden">
                <div class="absolute -right-4 -top-4 text-red-50 opacity-50"><span class="material-symbols-outlined text-[100px]">favorite</span></div>
                <p class="font-black text-red-500 uppercase text-[10px] mb-4 tracking-widest relative z-10">Physical Vitals</p>
                <div class="grid grid-cols-3 md:grid-cols-5 gap-y-4 relative z-10 text-slate-600">
                    <p><b class="text-slate-800">Weight:</b> <span id="vd_wt"></span> kg</p>
                    <p><b class="text-slate-800">BP:</b> <span id="vd_bp"></span></p>
                    <p><b class="text-slate-800">Temp:</b> <span id="vd_temp"></span> °C</p>
                    <p><b class="text-slate-800">Pulse:</b> <span id="vd_pulse"></span> bpm</p>
                    <p><b class="text-slate-800">SpO2:</b> <span id="vd_resp"></span></p>
                </div>
            </div>

            <div id="vd_maternity_section" class="hidden">
                <div class="bg-white p-6 rounded-2xl border border-pink-200 shadow-sm relative overflow-hidden">
                    <div class="absolute -right-4 -top-4 text-pink-50 opacity-60"><span class="material-symbols-outlined text-[100px]">pregnant_woman</span></div>
                    <div class="flex items-center justify-between mb-4 relative z-10">
                        <p class="font-black text-pink-500 uppercase text-[10px] tracking-widest">Maternity / Pregnancy</p>
                        <span id="vd_gp_badge" class="text-[11px] font-black tracking-widest px-3 py-1 rounded-lg bg-pink-100 text-pink-700 border border-pink-200">G0P0</span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-y-4 relative z-10 text-slate-600">
                        <p><b class="text-slate-800">Last Menstrual Period:</b><br><span id="vd_lmp" class="font-bold text-pink-700">---</span></p>
                        <p><b class="text-slate-800">Estimated Date of Delivery:</b><br><span id="vd_edd" class="font-black text-pink-700">---</span></p>
                        <p><b class="text-slate-800">Pregnancy Status:</b><br><span id="vd_pregnancy_status" class="inline-block mt-1 text-[10px] font-black uppercase tracking-widest px-2 py-0.5 rounded border">---</span></p>
                    </div>
                </div>
            </div>

            <div id="vd_fetal_section" class="hidden">
                <div class="bg-white p-6 rounded-2xl border border-pink-200 shadow-sm relative overflow-hidden">
                    <div class="absolute -right-4 -top-4 text-pink-50 opacity-60"><span class="material-symbols-outlined text-[100px]">child_care</span></div>
                    <p class="font-black text-pink-500 uppercase text-[10px] mb-4 tracking-widest relative z-10">Fetal Status</p>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-y-4 relative z-10 text-slate-600">
                        <p><b class="text-slate-800">AOG:</b><br><span id="vd_fetal_aog" class="font-bold text-pink-700">---</span></p>
                        <p><b class="text-slate-800">Fundal Height:</b><br><span id="vd_fetal_fundal_height" class="font-bold text-pink-700">---</span></p>
                        <p><b class="text-slate-800">FHT:</b><br><span id="vd_fetal_fht" class="font-bold text-pink-700">---</span></p>
                        <p><b class="text-slate-800">Presentation:</b><br><span id="vd_fetal_presentation" class="font-bold text-pink-700">---</span></p>
                    </div>
                </div>
            </div>

            <div id="vd_lab_section" class="hidden">
                <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm relative overflow-hidden">
                    <div class="absolute -right-4 -top-4 text-purple-50 opacity-50"><span class="material-symbols-outlined text-[100px]">science</span></div>
                    <p class="font-black text-purple-500 uppercase text-[10px] mb-4 tracking-widest relative z-10">Laboratory Results</p>
                    <div class="grid grid-cols-2 gap-y-4 relative z-10 text-slate-600">
                        <p><b class="text-slate-800">CBC:</b> <span id="vd_lab_cbc"></span></p>
                        <p><b class="text-slate-800">Urinalysis:</b> <span id="vd_lab_urinalysis"></span></p>
                        <p><b class="text-slate-800">Blood Type:</b> <span id="vd_lab_blood_type"></span></p>
                        <p><b class="text-slate-800">Blood Sugar:</b> <span id="vd_lab_blood_sugar"></span></p>
                        <p><b class="text-slate-800">Hepatitis B:</b> <span id="vd_lab_hep_b"></span></p>
                        <p><b class="text-slate-800">Syphilis:</b> <span id="vd_lab_syphilis"></span></p>
                    </div>
                    <div id="vd_lab_ultrasound" class="mt-4 grid grid-cols-2 gap-4 relative z-10 hidden">
                        <div id="vd_lab_tv_wrap" class="hidden">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Transvaginal Ultrasound</p>
                            <img id="vd_lab_tv_img" src="" class="rounded-xl border border-slate-200 w-full object-contain max-h-48 cursor-pointer" onclick="window.open(this.src)" />
                        </div>
                        <div id="vd_lab_pv_wrap" class="hidden">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Pelvic Ultrasound</p>
                            <img id="vd_lab_pv_img" src="" class="rounded-xl border border-slate-200 w-full object-contain max-h-48 cursor-pointer" onclick="window.open(this.src)" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Hidden canvas for certificate generation -->
<canvas id="certCanvas" width="2550" height="3300" style="display:none;"></canvas>

<script>
    function filterPatients() {
        const rawSearch = document.getElementById('pSearch').value.trim().toLowerCase();
        const firstChar = rawSearch.charAt(0);
        const selectedMonth = document.getElementById('monthFilter').value;
        const selectedYear = document.getElementById('yearFilter').value;

        let searchYm = "";
        if (selectedYear !== "") {
            searchYm = selectedYear;
            if (selectedMonth !== "") {
                searchYm += "-" + selectedMonth;
            }
        }

        document.querySelectorAll('.p-row').forEach(row => {
            let textMatch = true;

            if (firstChar) {
                const nameCell = row.querySelector('.cell-name');
                const nameText = nameCell ? nameCell.innerText.trim().toLowerCase() : '';
                const pid = (row.getAttribute('data-patientid') || '').trim().toLowerCase();
                const contact = (row.getAttribute('data-contact') || '').trim().toLowerCase();
                const addr = (row.getAttribute('data-address') || '').trim().toLowerCase();
                const created = (row.getAttribute('data-created') || '').trim().toLowerCase();

                const candidates = [nameText, pid, contact, addr, created];
                textMatch = candidates.some(val => val.charAt(0) === firstChar);
            }

            let dateMatch = true;
            if (searchYm !== "") {
                const visitDate = row.getAttribute('data-visit');
                dateMatch = visitDate && visitDate.startsWith(searchYm);
            }

            row.style.display = (textMatch && dateMatch) ? '' : 'none';
        });
    }

    function parseCreatedAt(str) {
        if (!str) return 0;
        const parts = str.split(/[- :]/);
        if (parts.length < 3) return 0;
        const y = parseInt(parts[0], 10);
        const m = parseInt(parts[1], 10) - 1;
        const d = parseInt(parts[2], 10);
        const hh = parseInt(parts[3] || '0', 10);
        const mm = parseInt(parts[4] || '0', 10);
        const ss = parseInt(parts[5] || '0', 10);
        return new Date(y, m, d, hh, mm, ss).getTime();
    }

    function sortPatients() {
        const sortVal = document.getElementById('sortCreated').value;
        const tbody = document.querySelector('#pTable tbody');
        if (!tbody) return;

        const rows = Array.from(tbody.querySelectorAll('.p-row'));
        rows.sort((a, b) => {
            const ta = parseCreatedAt(a.getAttribute('data-created'));
            const tb = parseCreatedAt(b.getAttribute('data-created'));
            return sortVal === 'asc' ? ta - tb : tb - ta;
        });

        rows.forEach(row => tbody.appendChild(row));
    }

    function resetDateFilter() {
        document.getElementById('monthFilter').value = '';
        document.getElementById('yearFilter').value = '<?php echo date('Y'); ?>';
        document.getElementById('pSearch').value = '';
        filterPatients();
    }

    function viewFullPatient(data) {
        document.getElementById('v_name').innerText = data.full_name;
        document.getElementById('v_id').innerText = "Patient ID: " + data.patient_id;

        const picContainer = document.getElementById('v_profile_pic');
        if (data.profile_pic_url) {
            picContainer.innerHTML = `<img src="${data.profile_pic_url}" class="w-full h-full object-cover" alt="">`;
        } else {
            picContainer.innerHTML = `<span class="material-symbols-outlined text-slate-400 text-2xl">person</span>`;
        }
        document.getElementById('v_bday').innerText = data.birthday || '---';

        let displayAge = '---';
        if (data.age !== null && data.age !== undefined && data.age !== '') {
            if (data.age === '0' || data.age === 0) {
                displayAge = 'NEWBORN (< 1 YR OLD)';
            } else {
                displayAge = data.age + ' YRS OLD';
            }
        }
        document.getElementById('v_age').innerText = displayAge;

        var menarcheEl = document.getElementById('v_menarche');
        if (menarcheEl) menarcheEl.innerText = data.menarche ? data.menarche + " YRS OLD" : '---';
        document.getElementById('v_status').innerText = data.civil_status || '---';
        document.getElementById('v_religion').innerText = data.religion || '---';
        document.getElementById('v_contact').innerText = data.contact_number || '---';

        const gMain = Math.max(0, parseInt(data.gravida, 10) || 0);
        const pMain = Math.max(0, parseInt(data.para, 10) || 0);
        const gpMainEl = document.getElementById('v_gp_main');
        if (gpMainEl) gpMainEl.innerText = 'G' + gMain + 'P' + pMain;
        const gpPregEl = document.getElementById('v_gp_preg');
        if (gpPregEl) gpPregEl.innerText = 'G' + gMain + 'P' + pMain;

        // Email & account status
        const emailEl = document.getElementById('v_email');
        const badgeEl = document.getElementById('v_account_badge');
        if (data.email_address) {
            emailEl.innerText = data.email_address;
            badgeEl.innerHTML = '<span class="inline-block text-[9px] bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded border border-emerald-200 font-black uppercase tracking-widest">Has Account</span>';
        } else {
            emailEl.innerText = '---';
            badgeEl.innerHTML = '<span class="inline-block text-[9px] bg-slate-100 text-slate-500 px-2 py-0.5 rounded border border-slate-200 font-black uppercase tracking-widest">No Account</span>';
        }

        document.getElementById('v_address').innerText = data.address || '---';
        document.getElementById('v_husband').innerText = data.husband_name || 'NOT RECORDED';
        document.getElementById('v_mother').innerText = data.mother_name || 'NOT RECORDED';
        document.getElementById('v_father').innerText = data.father_name || 'NOT RECORDED';

        // Pregnancy block: only show when patient is Confirmed Pregnant
        try {
            const pregBlock = document.getElementById('v_pregnancy_block');
            const pregStatusEl = document.getElementById('v_pregnancy_status');
            const lmpEl = document.getElementById('v_lmp');
            const eddEl = document.getElementById('v_edd');
            const pregStatus = (data.pregnancy_status || '').toString().trim();
            const hasLmp = !!(data.last_menstrual_period && String(data.last_menstrual_period).trim() !== '' && String(data.last_menstrual_period) !== '0000-00-00');
            const hasEdd = !!(data.estimated_delivery_date && String(data.estimated_delivery_date).trim() !== '' && String(data.estimated_delivery_date) !== '0000-00-00');
            if (pregBlock) {
                if (pregStatus.toLowerCase() === 'confirmed pregnant' && (hasLmp || hasEdd)) {
                    pregBlock.classList.remove('hidden');
                    if (pregStatusEl) pregStatusEl.innerText = pregStatus;
                    if (lmpEl) lmpEl.innerText = hasLmp ? data.last_menstrual_period : '---';
                    if (eddEl) eddEl.innerText = hasEdd ? data.estimated_delivery_date : '---';
                } else {
                    pregBlock.classList.add('hidden');
                }
            }
        } catch (e) {}


        // Guardian ID for minor patients
        const guardianSection = document.getElementById('v_guardian_section');
        const guardianImg = document.getElementById('v_guardian_img');
        const gidVal = data.guardian_id_photo || data.guardian_id_url || '';
        if (gidVal) {
            // Handle both full path and filename-only storage
            if (gidVal.startsWith('http') || gidVal.startsWith('uploads/')) {
                guardianImg.src = gidVal;
            } else {
                guardianImg.src = 'uploads/guardian_ids/' + gidVal;
            }
            guardianSection.classList.remove('hidden');
        } else {
            guardianSection.classList.add('hidden');
            guardianImg.src = '';
        }

        // PhilHealth ID photos
        const phSection = document.getElementById('v_philhealth_section');
        const phFrontImg = document.getElementById('v_ph_front_img');
        const phBackImg = document.getElementById('v_ph_back_img');
        const phFrontWrap = document.getElementById('v_ph_front_wrap');
        const phBackWrap = document.getElementById('v_ph_back_wrap');
        const phFrontVal = data.ph_id_front || '';
        const phBackVal = data.ph_id_back || '';
        if (phFrontVal || phBackVal) {
            phSection.classList.remove('hidden');
            if (phFrontVal) {
                phFrontImg.src = (phFrontVal.startsWith('http') || phFrontVal.startsWith('uploads/')) ? phFrontVal : 'uploads/philhealth_ids/' + phFrontVal;
                phFrontWrap.classList.remove('hidden');
            } else { phFrontWrap.classList.add('hidden'); phFrontImg.src = ''; }
            if (phBackVal) {
                phBackImg.src = (phBackVal.startsWith('http') || phBackVal.startsWith('uploads/')) ? phBackVal : 'uploads/philhealth_ids/' + phBackVal;
                phBackWrap.classList.remove('hidden');
            } else { phBackWrap.classList.add('hidden'); phBackImg.src = ''; }
        } else {
            phSection.classList.add('hidden');
            phFrontImg.src = '';
            phBackImg.src = '';
        }

        document.getElementById('fullViewModal').classList.remove('hidden');
        fetchHistory(data.id);
    }

    function openBabiesModal() {
        document.getElementById('babiesModal').classList.remove('hidden');
    }

    function closeBabiesModal() {
        document.getElementById('babiesModal').classList.add('hidden');
    }


    let _allTimelineRows = [];

    function renderTimeline() {
        const sortOrder = document.getElementById('timelineSortFilter').value;
        const serviceFilter = document.getElementById('timelineServiceFilter').value;

        let rows = _allTimelineRows.slice();

        if (serviceFilter !== 'all') {
            rows = rows.filter(function(row) {
                return (row.service_type || 'General Checkup') === serviceFilter;
            });
        }

        rows.sort(function(a, b) {
            var da = new Date((a.visit_date || '') + 'T' + (a.visit_time || '00:00:00'));
            var db = new Date((b.visit_date || '') + 'T' + (b.visit_time || '00:00:00'));
            return sortOrder === 'oldest' ? da - db : db - da;
        });

        let container = document.getElementById('v_history_list');
        container.innerHTML = rows.length ? '' : '<p class="text-center py-20 text-slate-400 text-xs italic bg-white rounded-2xl border border-slate-200">No visits match the selected filter.</p>';

        rows.forEach(function(row) {
            const rj = JSON.stringify(row).replace(/'/g, "&apos;");

            let timeLabel = '';
            if (row.visit_time && row.visit_time !== '00:00:00') {
                const timeParts = row.visit_time.split(':');
                let h = parseInt(timeParts[0], 10);
                const m = timeParts[1] || '00';
                if (!isNaN(h)) {
                    const ampm = h >= 12 ? 'PM' : 'AM';
                    h = h % 12;
                    if (h === 0) h = 12;
                    timeLabel = h + ':' + m + ' ' + ampm;
                }
            }

            let tagType = row.source === 'Admission'
                ? '<span class="px-2 py-0.5 bg-indigo-50 text-indigo-600 rounded text-[8px] font-black uppercase tracking-widest border border-indigo-100"><span class="material-symbols-outlined text-[10px] align-middle mr-0.5">how_to_reg</span></span>'
                : '<span class="px-2 py-0.5 bg-orange-50 text-orange-600 rounded text-[8px] font-black uppercase tracking-widest border border-orange-100"><span class="material-symbols-outlined text-[10px] align-middle mr-0.5">calendar_today</span> Upcoming Appointment</span>';

            let receiptButtonHtml = '';
            if (row.source === 'Admission') {
                receiptButtonHtml = '<button onclick=\'openReceiptForVisit(' + rj + ')\' class="px-4 py-2 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center hover:bg-emerald-500 hover:text-white transition-all border border-emerald-100 hover:border-emerald-500 text-xs font-bold shadow-sm">Receipt</button>';
            }

            var dateObj = new Date(row.visit_date);
            var dayNum = dateObj.getDate();
            var monShort = dateObj.toLocaleString('default', { month: 'short' });
            var fullDate = dateObj.toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' });
            var timeSpan = timeLabel ? '<span class="text-xs text-slate-400 ml-1">@ ' + timeLabel + '</span>' : '';

            container.innerHTML += '<div class="bg-white border border-slate-200 p-5 rounded-2xl shadow-sm flex flex-col md:flex-row justify-between md:items-center gap-4 group hover:border-primary/50 transition-all">' +
                '<div class="flex items-start gap-4">' +
                '<div class="size-14 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center font-black text-xs leading-none flex-col border border-emerald-100 shrink-0">' +
                '<span class="text-[18px]">' + dayNum + '</span>' +
                '<span class="uppercase text-[9px] tracking-widest mt-0.5">' + monShort + '</span>' +
                '</div>' +
                '<div>' +
                '<div class="flex items-center gap-2 mb-1">' +
                '<p class="font-black text-slate-800 text-base tracking-tight">' + fullDate + ' ' + timeSpan + '</p>' +
                tagType +
                '</div>' +
                '<p class="text-[11px] font-bold text-primary uppercase tracking-widest truncate max-w-[250px]">' + (row.service_type || 'General Checkup') + '</p>' +
                '</div>' +
                '</div>' +
                '<div class="flex gap-2 items-center self-end md:self-center">' +
                receiptButtonHtml +
                <?php if (!$isReceptionist): ?>
                '<button onclick=\'showCheckupDetail(' + rj + ')\' class="px-4 py-2 bg-slate-50 text-slate-600 rounded-xl flex items-center justify-center hover:bg-emerald-500 hover:text-white transition-all border border-slate-100 hover:border-emerald-500 text-xs font-bold shadow-sm">View</button>' +
                <?php else: ?>
                '' +
                <?php endif; ?>
                '</div>' +
                '</div>';
        });
    }
    function fetchHistory(id) {
        fetch('?fetch_checkups=' + id).then(r => r.json()).then(data => {
            _allTimelineRows = data.history || [];
            const babies = data.babies || [];

            // Populate service filter with unique services
            const svcSelect = document.getElementById('timelineServiceFilter');
            const uniqueServices = [...new Set(_allTimelineRows.map(function(r) { return r.service_type || 'General Checkup'; }))];
            svcSelect.innerHTML = '<option value="all">All Services</option>';
            uniqueServices.forEach(function(svc) {
                var opt = document.createElement('option');
                opt.value = svc; opt.textContent = svc;
                svcSelect.appendChild(opt);
            });

            // Reset filters then render
            document.getElementById('timelineSortFilter').value = 'newest';
            svcSelect.value = 'all';
            renderTimeline();

            // 2.  RENDER BABIES SECTION & PRINT BUTTON
            let babyContainer = document.getElementById('modal_babies_list');
            let babySection = document.getElementById('v_babies_section');
            let babyCount = document.getElementById('v_babies_count');

            if (babies.length > 0) {
                babySection.classList.remove('hidden');
                babyCount.innerText = babies.length;
                babyContainer.innerHTML = '';

                babies.forEach(baby => {
                    let isGirl = baby.gender.toLowerCase() === 'female' || baby.gender.toLowerCase() === 'girl';
                    let icon = isGirl ? 'female' : 'male';
                    let color = isGirl ? 'pink' : 'blue';
                    let genderLabel = (baby.gender || '---').toUpperCase();
                    let babyJson = JSON.stringify(baby).replace(/'/g, "&apos;");

                    babyContainer.innerHTML += `
                        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex flex-col md:flex-row items-start md:items-center justify-between gap-4 relative overflow-hidden">
                            <div class="absolute -right-4 -top-4 text-${color}-50 opacity-50"><span class="material-symbols-outlined text-[100px]">child_care</span></div>
                            <div class="flex items-center gap-4 relative z-10 w-full md:w-auto">
                                <div class="size-12 bg-${color}-50 text-${color}-500 rounded-full flex items-center justify-center border border-${color}-100 shrink-0 shadow-sm"><span class="material-symbols-outlined text-[24px]">${icon}</span></div>
                                <div class="flex-1">
                                    <p class="font-black text-slate-800 text-lg tracking-tight">${baby.infant_name || 'Unnamed Infant'}</p>
                                    <p class="text-[10px] font-black uppercase tracking-widest text-${color}-600 mt-0.5">${genderLabel}</p>
                                    <p class="text-[10px] text-slate-500 font-bold uppercase tracking-widest flex items-center gap-1 mt-0.5"><span class="material-symbols-outlined text-[12px]">calendar_today</span> ${new Date(baby.birth_date).toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' })} @ ${baby.birth_time.substring(0, 5)}</p>
                                </div>
                            </div>

                            <div class="flex gap-2 items-center relative z-10 w-full md:w-auto justify-between md:justify-end">
                                <div class="flex gap-4 items-center bg-slate-50 p-3 rounded-xl border border-slate-100">
                                    <div class="text-center">
                                        <p class="text-[9px] font-black uppercase tracking-widest text-slate-400">Weight</p>
                                        <p class="font-bold text-slate-700 text-sm">${baby.weight_kg}kg</p>
                                    </div>
                                    <?php if (!$isReceptionist): ?>
                                    <div class="w-px h-8 bg-slate-200"></div>
                                    <div class="text-center">
                                        <p class="text-[9px] font-black uppercase tracking-widest text-slate-400">APGAR</p>
                                        <p class="font-black text-blue-600 text-sm">${baby.apgar_score || '-'}</p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                ${baby.certificate_of_live_birth ? `
                                <button id="certBtnWrap_${baby.id}" data-cert-path="${baby.certificate_of_live_birth}" data-baby-json='${babyJson}' onclick="openCertOptionsFromBtn(this)" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-3 rounded-xl font-bold shadow-md flex items-center gap-2 text-xs transition-all active:scale-95">
                                    <span class="material-symbols-outlined text-[16px]">verified</span> View Certificate
                                </button>` : `
                                <button onclick='openCertForm(${babyJson})' class="bg-primary hover:bg-primary-dark text-white px-4 py-3 rounded-xl font-bold shadow-md flex items-center gap-2 text-xs transition-all active:scale-95">
                                    <span class="material-symbols-outlined text-[16px]">description</span> Certificate
                                </button>`}
                            </div>
                        </div>
                    `;
                });
            } else {
                babySection.classList.add('hidden');
                babyContainer.innerHTML = '';
                babyCount.innerText = '0';
            }

        });
    }

    //  FORM TO GENERATE CERTIFICATE OF LIVE BIRTH
    function openCertOptionsFromBtn(btn) {
        window._optionsCertPath = btn.dataset.certPath;
        window._optionsBabyData = JSON.parse((btn.dataset.babyJson || '{}').replace(/&apos;/g, "'"));
        document.getElementById('certOptionsModal').classList.remove('hidden');
    }
    function openCertOptions(certPath, babyData) {
        window._optionsCertPath = certPath;
        window._optionsBabyData = babyData;
        document.getElementById('certOptionsModal').classList.remove('hidden');
    }
    function closeCertOptions() {
        document.getElementById('certOptionsModal').classList.add('hidden');
    }
    function viewCertFromOptions() {
        closeCertOptions();
        document.getElementById('viewCertImg').src = window._optionsCertPath;
        document.getElementById('viewCertDownloadLink').href = window._optionsCertPath;
        document.getElementById('viewCertModal').classList.remove('hidden');
    }
    function editCertFromOptions() {
        closeCertOptions();
        if(window._optionsBabyData) openCertForm(window._optionsBabyData);
    }
    function viewSavedCertificate(path) {
        document.getElementById('viewCertImg').src = path;
        document.getElementById('viewCertDownloadLink').href = path;
        document.getElementById('viewCertModal').classList.remove('hidden');
    }
    function closeViewCert() {
        document.getElementById('viewCertModal').classList.add('hidden');
    }

    function openCertForm(baby) {
        document.getElementById('cf_baby_json').value = JSON.stringify(baby);
        // Check if saved certificate data exists
        let cd = null;
        if (baby.certificate_data) {
            cd = (typeof baby.certificate_data === 'string') ? JSON.parse(baby.certificate_data) : baby.certificate_data;
        }
        document.getElementById('cf_attendant').value = (cd && cd.attendant) ? cd.attendant : (baby.attending_staff || '<?= htmlspecialchars($displayName) ?>');
        // Father name fields
        if (cd && (cd.f_first || cd.f_middle || cd.f_last)) {
            document.getElementById('cf_f_first').value = cd.f_first || '';
            document.getElementById('cf_f_middle').value = cd.f_middle || '';
            document.getElementById('cf_f_last').value = cd.f_last || '';
        } else {
            const fParts = (baby.father_name || '').split(/\s+/);
            document.getElementById('cf_f_first').value = fParts[0] || '';
            document.getElementById('cf_f_middle').value = fParts.length > 2 ? fParts.slice(1, -1).join(' ') : '';
            document.getElementById('cf_f_last').value = fParts.length > 1 ? fParts[fParts.length-1] : '';
        }
        document.getElementById('cf_f_job').value = cd ? (cd.f_job || '') : '';
        document.getElementById('cf_f_age').value = cd ? (cd.f_age || '') : '';
        document.getElementById('cf_f_dob').value = cd ? (cd.f_dob || '') : '';
        document.getElementById('cf_f_religion').value = cd ? (cd.f_religion || '') : '';
        // Father residence
        if (cd && (cd.f_res_house || cd.f_res_city || cd.f_res_province)) {
            document.getElementById('cf_f_res_house').value = cd.f_res_house || '';
            document.getElementById('cf_f_res_city').value = cd.f_res_city || '';
            document.getElementById('cf_f_res_province').value = cd.f_res_province || '';
            document.getElementById('cf_f_res_country').value = cd.f_res_country || 'Philippines';
        } else {
            const addrParts = (baby.address || '').split(',').map(s => s.trim());
            document.getElementById('cf_f_res_house').value = addrParts[0] || '';
            document.getElementById('cf_f_res_city').value = addrParts[1] || '';
            document.getElementById('cf_f_res_province').value = addrParts[2] || '';
            document.getElementById('cf_f_res_country').value = addrParts[3] || 'Philippines';
        }
        // Mother fields
        document.getElementById('cf_m_citizen').value = cd ? (cd.m_citizen || 'Filipino') : 'Filipino';
        document.getElementById('cf_m_religion').value = cd ? (cd.m_religion || baby.religion || '') : (baby.religion || '');
        document.getElementById('cf_m_job').value = cd ? (cd.m_job || '') : '';
        document.getElementById('cf_m_alive').value = cd ? (cd.m_alive || '1') : '1';
        document.getElementById('cf_m_living').value = cd ? (cd.m_living || '1') : '1';
        document.getElementById('cf_m_dead').value = cd ? (cd.m_dead || '0') : '0';
        // Mother residence split fields
        if (cd && (cd.m_res_house || cd.m_res_city || cd.m_res_province)) {
            document.getElementById('cf_m_res_house').value = cd.m_res_house || '';
            document.getElementById('cf_m_res_city').value = cd.m_res_city || '';
            document.getElementById('cf_m_res_province').value = cd.m_res_province || '';
            document.getElementById('cf_m_res_country').value = cd.m_res_country || 'Philippines';
        } else {
            const mAddrParts = (baby.address || '').split(',').map(s => s.trim());
            document.getElementById('cf_m_res_house').value = mAddrParts[0] || '';
            document.getElementById('cf_m_res_city').value = mAddrParts[1] || '';
            document.getElementById('cf_m_res_province').value = mAddrParts[2] || '';
            document.getElementById('cf_m_res_country').value = mAddrParts[3] || 'Philippines';
        }
        // Header fields
        document.getElementById('cf_province').value = cd ? (cd.province || '') : '';
        document.getElementById('cf_city').value = cd ? (cd.city || '') : '';
        document.getElementById('cf_registry_no').value = cd ? (cd.registry_no || '') : '';
        // Birth details
        document.getElementById('cf_birth_type').value = cd ? (cd.birth_type || 'Single') : 'Single';
        document.getElementById('cf_multiple_type').value = cd ? (cd.multiple_type || '') : '';
        document.getElementById('cf_birth_order').value = cd ? (cd.birth_order || '') : '';
        // Father citizenship
        document.getElementById('cf_f_citizen').value = cd ? (cd.f_citizen || 'Filipino') : 'Filipino';
        // Marriage fields
        document.getElementById('cf_mar_date').value = cd ? (cd.mar_date || '') : '';
        document.getElementById('cf_mar_city').value = cd ? (cd.mar_city || '') : '';
        document.getElementById('cf_mar_province').value = cd ? (cd.mar_province || '') : '';
        document.getElementById('cf_mar_country').value = cd ? (cd.mar_country || 'Philippines') : 'Philippines';
        // Attendant type
        document.getElementById('cf_att_type').value = cd ? (cd.att_type || 'Midwife') : 'Midwife';
        document.getElementById('certFormModal').classList.remove('hidden');
    }

    function closeCertForm() {
        document.getElementById('certFormModal').classList.add('hidden');
    }

    //  CANVAS-BASED CERTIFICATE OF LIVE BIRTH IMAGE GENERATOR
    function executePrintCertificate() {
        const babyJsonStr = document.getElementById('cf_baby_json').value;
        if(!babyJsonStr) return;
        const baby = JSON.parse(babyJsonStr);

        const printBtn = document.querySelector('button[onclick="executePrintCertificate()"]');
        const origBtnText = printBtn.innerHTML;
        printBtn.innerHTML = '<span class="material-symbols-outlined text-[18px] animate-spin">refresh</span> Generating...';
        printBtn.disabled = true;

        // Collect form values
        const province = (document.getElementById('cf_province').value || '').toUpperCase();
        const city = (document.getElementById('cf_city').value || '').toUpperCase();
        const registryNo = document.getElementById('cf_registry_no').value || '';
        const birthType = document.getElementById('cf_birth_type').value || '';
        const multipleType = document.getElementById('cf_multiple_type').value || '';
        const birthOrder = document.getElementById('cf_birth_order').value || '';
        const mCitizen = document.getElementById('cf_m_citizen').value || '';
        const mReligion = document.getElementById('cf_m_religion').value || '';
        const mJob = document.getElementById('cf_m_job').value || '';
        const mAlive = document.getElementById('cf_m_alive').value || '0';
        const mLiving = document.getElementById('cf_m_living').value || '0';
        const mDead = document.getElementById('cf_m_dead').value || '0';
        const mResHouse = document.getElementById('cf_m_res_house').value || '';
        const mResCity = document.getElementById('cf_m_res_city').value || '';
        const mResProvince = document.getElementById('cf_m_res_province').value || '';
        const mResCountry = document.getElementById('cf_m_res_country').value || 'Philippines';
        const mResidence = [mResHouse, mResCity, mResProvince, mResCountry].filter(s=>s).join(', ');
        const fFirst = (document.getElementById('cf_f_first').value || '').toUpperCase();
        const fMiddle = (document.getElementById('cf_f_middle').value || '').toUpperCase();
        const fLast = (document.getElementById('cf_f_last').value || '').toUpperCase();
        const fCitizen = document.getElementById('cf_f_citizen').value || '';
        const fReligion = document.getElementById('cf_f_religion').value || '';
        const fJob = document.getElementById('cf_f_job').value || '';
        const fAge = document.getElementById('cf_f_age').value || '';
        const fResHouse = document.getElementById('cf_f_res_house').value || '';
        const fResCity = document.getElementById('cf_f_res_city').value || '';
        const fResProvince = document.getElementById('cf_f_res_province').value || '';
        const fResCountry = document.getElementById('cf_f_res_country').value || '';
        const marDateRaw = document.getElementById('cf_mar_date').value || '';
        let marMonth = '', marDay = '', marYear = '';
        if(marDateRaw) {
            const md = new Date(marDateRaw);
            marMonth = md.toLocaleString('en',{month:'long'}).toUpperCase();
            marDay = String(md.getDate());
            marYear = String(md.getFullYear());
        }
        const marCity = (document.getElementById('cf_mar_city').value || '').toUpperCase();
        const marProvince = (document.getElementById('cf_mar_province').value || '').toUpperCase();
        const marCountry = (document.getElementById('cf_mar_country').value || '').toUpperCase();
        const attType = document.getElementById('cf_att_type').value || 'Midwife';
        const attendantName = (document.getElementById('cf_attendant').value || '').toUpperCase();

        const infantName = (baby.infant_name || 'UNNAMED').toUpperCase();
        const gender = (baby.gender || '---').toUpperCase();
        const bDate = new Date(baby.birth_date);
        const dobDay = String(bDate.getDate()).padStart(2, '0');
        const dobMonth = String(bDate.getMonth() + 1).padStart(2, '0');
        const dobYear = bDate.getFullYear();
        const motherName = (baby.mother_name || 'UNKNOWN').toUpperCase();
        const motherAge = baby.mother_age || '---';
        const wtGrams = Math.round((parseFloat(baby.weight_kg) || 0) * 1000);
        const placeOfBirth = "<?= addslashes(htmlspecialchars($clinicName)) ?>";

        let btime = '';
        if(baby.birth_time) {
            const tp = baby.birth_time.split(':');
            let h = parseInt(tp[0], 10);
            const mn = tp[1];
            const ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12; h = h ? h : 12;
            btime = h + ':' + mn + ' ' + ampm;
        }

        const today = new Date();
        const issueDate = today.toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' });

        // Parse name parts
        const nameParts = infantName.split(' ');
        const nFirst = nameParts[0] || '';
        const nLast = nameParts.length > 1 ? nameParts[nameParts.length - 1] : '';
        const nMiddle = nameParts.length > 2 ? nameParts.slice(1, -1).join(' ') : '';

        // --- CANVAS SETUP ---
        const canvas = document.getElementById('certCanvas');
        const ctx = canvas.getContext('2d');
        const W = 2550, H = 3300;
        canvas.width = W; canvas.height = H;

        // Layout constants - fill the ENTIRE page
        const ML = 60, MR = W - 60, FW = MR - ML; // 2430px wide
        const BW = 40; // green band width
        const CL = ML + BW; // content left after green band

        // ===== SECTION Y BOUNDARIES (fills full page 50→3220) =====
        const YT = 50;   // top
        const YB = 3220; // bottom
        // Header
        const Y_PROV = 240;
        const Y_CITY = 310;
        const Y_CHILD = 380;
        // CHILD rows
        const Y_C1 = 490;   // after NAME
        const Y_C2 = 600;   // after SEX/DOB
        const Y_C3 = 730;   // after PLACE
        const Y_C4 = 880;   // after TYPE/WEIGHT (child end)
        // MOTHER rows
        const Y_M1 = 990;   // after MAIDEN NAME
        const Y_M2 = 1080;  // after CITIZENSHIP/RELIG
        const Y_M3 = 1190;  // after 10abc/OCC/AGE
        const Y_M4 = 1290;  // after RESIDENCE (mother end)
        // FATHER rows
        const Y_F1 = 1385;  // after NAME
        const Y_F2 = 1475;  // after 15-18
        const Y_F3 = 1570;  // after RESIDENCE (father end)
        // MARRIAGE
        const Y_MARHDR = 1620; // after header bar
        const Y_MAREND = 1740; // after date/place
        // ATTENDANT
        const Y_A1 = 1800;  // after 21a checkboxes
        const Y_A2 = 1870;  // after 21b cert text
        const Y_AEND = 2060; // after sig/name/title (attendant end)
        // 22/23
        const Y_22END = 2470;
        // 24/25
        const Y_24END = 2750;
        // REMARKS
        const Y_REND = 3010;
        // FOOTER
        // Y_REND to YB = 210px for footer

        // Background
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, W, H);

        // Outer border (double line like real form)
        ctx.strokeStyle = '#000'; ctx.lineWidth = 4;
        ctx.strokeRect(ML - 8, YT - 8, FW + 16, YB - YT + 16);
        ctx.strokeStyle = '#000'; ctx.lineWidth = 1.5;
        ctx.strokeRect(ML, YT, FW, YB - YT);

        // ===== HELPERS =====
        const F = '"Times New Roman", Times, serif';
        const mc = (c) => c==='#444'?'#000':c==='#888'?'#666':c==='#555'?'#000':c==='#777'?'#555':(c||'#000');
        const ln = (x1,y1,x2,y2,lw,col) => {
            ctx.beginPath(); ctx.lineWidth = lw||1; ctx.strokeStyle = col||'#000';
            ctx.moveTo(x1,y1); ctx.lineTo(x2,y2); ctx.stroke();
        };
        const hl = (y,lw) => ln(ML,y,MR,y,lw||1);
        const shl = (y,lw) => ln(CL,y,MR,y,lw||1);
        const vl = (x,y1,y2,lw) => ln(x,y1,x,y2,lw||1);
        const t = (s,x,y,sz,b,c) => {
            ctx.fillStyle = mc(c);
            ctx.font = (b?'bold ':'')+(sz||22)+'px '+F;
            ctx.fillText(s||'',x,y);
        };
        const tc = (s,y,sz,b,c) => {
            ctx.fillStyle = mc(c); ctx.font = (b?'bold ':'')+(sz||22)+'px '+F;
            ctx.fillText(s||'',(W - ctx.measureText(s||'').width)/2, y);
        };
        const tr = (s,x,y,sz,b,c) => {
            ctx.fillStyle = mc(c); ctx.font = (b?'bold ':'')+(sz||22)+'px '+F;
            ctx.fillText(s||'',x - ctx.measureText(s||'').width, y);
        };
        const uv = (v,x,y,w) => {
            t(v, x+4, y, 24, true, '#000');
            ln(x, y+6, x+w, y+6, 0.8, '#999');
        };
        const cb = (x,y,chk) => {
            ctx.strokeStyle = '#000'; ctx.lineWidth = 1;
            ctx.strokeRect(x, y-18, 22, 22);
            if(chk) t('', x+3, y, 20, true, '#000');
        };
        const vertLabel = (letters, x, y1, y2) => {
            const sp = (y2-y1)/(letters.length+1);
            ctx.fillStyle = '#000'; ctx.font = 'bold 28px '+F;
            for(let i=0; i<letters.length; i++) {
                const cw = ctx.measureText(letters[i]).width;
                ctx.fillText(letters[i], x - cw/2, y1 + sp*(i+1));
            }
        };
        const gBand = (y1,y2) => {
            ctx.fillStyle = '#ddd'; ctx.fillRect(ML, y1, BW, y2-y1);
            vl(ML+BW, y1, y2, 1);
        };
        const rm = (y1,y2) => y1 + (y2-y1)*0.55;
        const r2t = (y1,y2) => y1 + (y2-y1)*0.35;
        const r2b = (y1,y2) => y1 + (y2-y1)*0.58;

        // ==================== HEADER ====================
        t('Municipal Form No. 102', ML+10, YT+28, 20, false, '#555');
        t('(Revised January 2007)', ML+10, YT+50, 17, false, '#777');
        tr('(To be accomplished in quadruplicate using black ink)', MR-10, YT+28, 17, false, '#555');
        tc('Republic of the Philippines', YT+35, 24, false, '#000');
        tc('OFFICE OF THE CIVIL REGISTRAR GENERAL', YT+65, 24, true, '#000');
        tc('CERTIFICATE OF LIVE BIRTH', YT+115, 48, true, '#000');

        // ==================== PROVINCE / CITY ====================
        hl(Y_PROV);
        const ym_prov = rm(Y_PROV, Y_CITY);
        t('Province', ML+10, ym_prov, 22, false, '#444');
        uv(province, ML+160, ym_prov, 700);
        vl(MR-550, Y_PROV, Y_CITY);
        t('Registry No.', MR-540, ym_prov, 22, false, '#444');
        uv(registryNo, MR-370, ym_prov, 360);
        hl(Y_CITY);
        const ym_city = rm(Y_CITY, Y_CHILD);
        t('City/Municipality', ML+10, ym_city, 22, false, '#444');
        uv(city, ML+280, ym_city, 600);
        hl(Y_CHILD);

        // ==================== CHILD SECTION ====================
        gBand(Y_CHILD, Y_C4);
        vertLabel('CHILD'.split(''), ML+BW/2, Y_CHILD+20, Y_C4-20);

        // Row 1: NAME (Y_CHILD → Y_C1)
        const ym1 = rm(Y_CHILD, Y_C1);
        t('1. NAME', CL+10, ym1, 22, true, '#444');
        vl(CL+160, Y_CHILD, Y_C1);
        t('(First)', CL+175, Y_CHILD+30, 16, false, '#888');
        uv(nFirst, CL+175, ym1, 450);
        vl(CL+680, Y_CHILD, Y_C1);
        t('(Middle)', CL+695, Y_CHILD+30, 16, false, '#888');
        uv(nMiddle, CL+695, ym1, 450);
        vl(CL+1200, Y_CHILD, Y_C1);
        t('(Last)', CL+1215, Y_CHILD+30, 16, false, '#888');
        uv(nLast, CL+1215, ym1, MR-CL-1225);
        shl(Y_C1);

        // Row 2: SEX + DATE OF BIRTH (Y_C1 → Y_C2)
        const ym2t = r2t(Y_C1, Y_C2);
        const ym2b = r2b(Y_C1, Y_C2);
        t('2. SEX', CL+10, ym2t, 22, true, '#444');
        t('(Male / Female)', CL+10, ym2b, 15, false, '#888');
        uv(gender, CL+200, ym2b+20, 280);
        vl(CL+530, Y_C1, Y_C2);
        t('3. DATE OF', CL+550, ym2t, 22, true, '#444');
        t('BIRTH', CL+600, ym2b, 22, true, '#444');
        vl(CL+720, Y_C1, Y_C2);
        t('(Day)', CL+740, ym2t, 16, false, '#888');
        uv(dobDay, CL+740, ym2b+5, 160);
        vl(CL+940, Y_C1, Y_C2);
        t('(Month)', CL+960, ym2t, 16, false, '#888');
        uv(dobMonth, CL+960, ym2b+5, 180);
        vl(CL+1200, Y_C1, Y_C2);
        t('(Year)', CL+1220, ym2t, 16, false, '#888');
        uv(String(dobYear), CL+1220, ym2b+5, MR-CL-1230);
        shl(Y_C2);

        // Row 3: PLACE OF BIRTH (Y_C2 → Y_C3)
        const ym3t = r2t(Y_C2, Y_C3);
        const ym3b = r2b(Y_C2, Y_C3);
        t('4. PLACE OF', CL+10, ym3t, 22, true, '#444');
        t('BIRTH', CL+42, ym3b, 22, true, '#444');
        vl(CL+180, Y_C2, Y_C3);
        t('(Name of Hospital/Clinic/Institution/', CL+195, ym3t-5, 15, false, '#888');
        t('House No., St., Barangay)', CL+195, ym3t+15, 15, false, '#888');
        uv(placeOfBirth, CL+195, ym3b+15, 560);
        vl(CL+800, Y_C2, Y_C3);
        t('(City/Municipality)', CL+815, ym3t, 15, false, '#888');
        uv(city, CL+815, ym3b+15, 350);
        vl(CL+1210, Y_C2, Y_C3);
        t('(Province)', CL+1225, ym3t, 15, false, '#888');
        uv(province, CL+1225, ym3b+15, MR-CL-1235);
        shl(Y_C3);

        // Row 4: TYPE / MULTIPLE / ORDER / WEIGHT (Y_C3 → Y_C4)
        const rh4 = Y_C4 - Y_C3;
        const y4t = Y_C3 + rh4*0.15;
        const y4m = Y_C3 + rh4*0.42;
        const y4v = Y_C3 + rh4*0.72;
        t('5a. TYPE OF BIRTH', CL+10, y4t, 18, true, '#444');
        t('(Single, Twin, Triplets, etc.)', CL+10, y4m-8, 14, false, '#888');
        uv(birthType, CL+10, y4v, 280);
        vl(CL+340, Y_C3, Y_C4);
        t('5b. IF MULTIPLE CHILD, CHILD WAS', CL+355, y4t, 17, true, '#444');
        t('(First, Second, Third, etc.)', CL+355, y4m-8, 14, false, '#888');
        uv(multipleType, CL+355, y4v, 280);
        vl(CL+690, Y_C3, Y_C4);
        t('5c. BIRTH ORDER', CL+705, y4t, 17, true, '#444');
        t('(Order of this birth in', CL+705, y4m-15, 13, false, '#888');
        t('total children born alive)', CL+705, y4m+2, 13, false, '#888');
        t('(First, Second, Third, etc.)', CL+705, y4m+19, 13, false, '#888');
        uv(birthOrder, CL+705, y4v, 260);
        vl(CL+1010, Y_C3, Y_C4);
        t('6. WEIGHT AT BIRTH', CL+1030, y4t, 18, true, '#444');
        uv(wtGrams.toString(), CL+1100, y4v-10, 240);
        t('grams', CL+1400, y4v+10, 20, false, '#444');
        hl(Y_C4);

        // ==================== MOTHER SECTION ====================
        gBand(Y_C4, Y_M4);
        vertLabel('MOTHER'.split(''), ML+BW/2, Y_C4+15, Y_M4-15);

        // Row 7: MAIDEN NAME (Y_C4 → Y_M1)
        const ymn = rm(Y_C4, Y_M1);
        t('7. MAIDEN', CL+10, r2t(Y_C4,Y_M1), 22, true, '#444');
        t('NAME', CL+30, r2b(Y_C4,Y_M1), 22, true, '#444');
        vl(CL+160, Y_C4, Y_M1);
        t('(First)', CL+175, Y_C4+28, 16, false, '#888');
        uv(motherName, CL+175, ymn+5, 450);
        vl(CL+680, Y_C4, Y_M1);
        t('(Middle)', CL+695, Y_C4+28, 16, false, '#888');
        uv('', CL+695, ymn+5, 450);
        vl(CL+1200, Y_C4, Y_M1);
        t('(Last)', CL+1215, Y_C4+28, 16, false, '#888');
        uv('', CL+1215, ymn+5, MR-CL-1225);
        shl(Y_M1);

        // Row 8-9: CITIZENSHIP + RELIGION (Y_M1 → Y_M2)
        const ym89 = rm(Y_M1, Y_M2);
        t('8. CITIZENSHIP', CL+10, ym89, 22, true, '#444');
        uv(mCitizen, CL+250, ym89, 440);
        vl(CL+730, Y_M1, Y_M2);
        t('9. RELIGION/RELIGIOUS SECT', CL+750, ym89, 22, true, '#444');
        uv(mReligion, CL+1120, ym89, MR-CL-1130);
        shl(Y_M2);

        // Row 10abc + 11 + 12 (Y_M2 → Y_M3)
        const y10t = r2t(Y_M2, Y_M3);
        const y10b = r2b(Y_M2, Y_M3);
        t('10a. Total number of', CL+10, y10t, 16, false, '#444');
        t('children born alive', CL+10, y10b, 16, false, '#444');
        uv(mAlive, CL+250, y10b+20, 90);
        vl(CL+370, Y_M2, Y_M3);
        t('10b. No. of children still', CL+385, y10t, 16, false, '#444');
        t('living including one birth', CL+385, y10b, 16, false, '#444');
        uv(mLiving, CL+630, y10b+20, 90);
        vl(CL+750, Y_M2, Y_M3);
        t('10c. No. of children have', CL+765, y10t, 16, false, '#444');
        t('alive but are now dead', CL+765, y10b, 16, false, '#444');
        uv(mDead, CL+1010, y10b+20, 90);
        vl(CL+1130, Y_M2, Y_M3);
        t('11. OCCUPATION', CL+1145, y10t+10, 18, true, '#444');
        uv(mJob, CL+1340, y10b+10, 200);
        vl(CL+1570, Y_M2, Y_M3);
        t('12. AGE at the time of this', CL+1585, y10t, 15, false, '#444');
        t('birth (completed years)', CL+1585, y10b, 15, false, '#444');
        uv(motherAge, CL+1830, y10b+20, 100);
        shl(Y_M3);

        // Row 13: RESIDENCE (Y_M3 → Y_M4)
        const ym13 = rm(Y_M3, Y_M4);
        t('13. RESIDENCE', CL+10, ym13, 22, true, '#444');
        vl(CL+210, Y_M3, Y_M4);
        t('(House No., St., Barangay)', CL+225, Y_M3+22, 14, false, '#888');
        uv(mResHouse, CL+225, ym13+8, 520);
        vl(CL+790, Y_M3, Y_M4);
        t('(City/Municipality)', CL+805, Y_M3+22, 14, false, '#888');
        uv(mResCity, CL+805, ym13+8, 260);
        vl(CL+1110, Y_M3, Y_M4);
        t('(Province)', CL+1125, Y_M3+22, 14, false, '#888');
        uv(mResProvince, CL+1125, ym13+8, 230);
        vl(CL+1400, Y_M3, Y_M4);
        t('(Country)', CL+1415, Y_M3+22, 14, false, '#888');
        uv(mResCountry, CL+1415, ym13+8, 200);
        hl(Y_M4);

        // ==================== FATHER SECTION ====================
        gBand(Y_M4, Y_F3);
        vertLabel('FATHER'.split(''), ML+BW/2, Y_M4+15, Y_F3-15);

        // Row 14: NAME (Y_M4 → Y_F1)
        const ymf = rm(Y_M4, Y_F1);
        t('14. NAME', CL+10, ymf, 22, true, '#444');
        vl(CL+160, Y_M4, Y_F1);
        t('(First)', CL+175, Y_M4+28, 16, false, '#888');
        uv(fFirst, CL+175, ymf+5, 450);
        vl(CL+680, Y_M4, Y_F1);
        t('(Middle)', CL+695, Y_M4+28, 16, false, '#888');
        uv(fMiddle, CL+695, ymf+5, 450);
        vl(CL+1200, Y_M4, Y_F1);
        t('(Last)', CL+1215, Y_M4+28, 16, false, '#888');
        uv(fLast, CL+1215, ymf+5, MR-CL-1225);
        shl(Y_F1);

        // Row 15-18 (Y_F1 → Y_F2)
        const ymf2t = r2t(Y_F1, Y_F2);
        const ymf2b = r2b(Y_F1, Y_F2);
        t('15. CITIZENSHIP', CL+10, rm(Y_F1,Y_F2), 18, true, '#444');
        uv(fCitizen, CL+240, rm(Y_F1,Y_F2)+5, 230);
        vl(CL+510, Y_F1, Y_F2);
        t('16. RELIGION/RELIGIOUS SECT', CL+525, rm(Y_F1,Y_F2), 18, true, '#444');
        uv(fReligion, CL+880, rm(Y_F1,Y_F2)+5, 200);
        vl(CL+1110, Y_F1, Y_F2);
        t('17. OCCUPATION', CL+1125, rm(Y_F1,Y_F2), 18, true, '#444');
        uv(fJob, CL+1350, rm(Y_F1,Y_F2)+5, 200);
        vl(CL+1580, Y_F1, Y_F2);
        t('18. AGE at the time of this', CL+1595, ymf2t+5, 15, false, '#444');
        t('birth (completed years)', CL+1595, ymf2b+5, 15, false, '#444');
        uv(fAge, CL+1850, rm(Y_F1,Y_F2)+5, 80);
        shl(Y_F2);

        // Row Father Residence (Y_F2 → Y_F3)
        const ymfr = rm(Y_F2, Y_F3);
        t('10. RESIDENCE', CL+10, ymfr, 22, true, '#444');
        vl(CL+210, Y_F2, Y_F3);
        t('(House No., St., Barangay)', CL+225, Y_F2+22, 14, false, '#888');
        uv(fResHouse, CL+225, ymfr+8, 520);
        vl(CL+790, Y_F2, Y_F3);
        t('(City/Municipality)', CL+805, Y_F2+22, 14, false, '#888');
        uv(fResCity, CL+805, ymfr+8, 260);
        vl(CL+1110, Y_F2, Y_F3);
        t('(Province)', CL+1125, Y_F2+22, 14, false, '#888');
        uv(fResProvince, CL+1125, ymfr+8, 230);
        vl(CL+1400, Y_F2, Y_F3);
        t('(Country)', CL+1415, Y_F2+22, 14, false, '#888');
        uv(fResCountry, CL+1415, ymfr+8, MR-CL-1425);
        hl(Y_F3);

        // ==================== MARRIAGE OF PARENTS ====================
        // Header bar
        ctx.fillStyle = '#ddd';
        ctx.fillRect(ML, Y_F3, FW, Y_MARHDR - Y_F3);
        hl(Y_F3); hl(Y_MARHDR);
        const ymhdr = rm(Y_F3, Y_MARHDR);
        t('MARRIAGE OF PARENTS', ML+15, ymhdr, 22, true, '#000');
        t('(If not married, accomplish Affidavit of Acknowledgement/Admission of Paternity at the back)', ML+380, ymhdr, 15, false, '#555');

        // Row 20a/20b (Y_MARHDR → Y_MAREND)
        const ymm = rm(Y_MARHDR, Y_MAREND);
        t('20a. DATE', ML+15, ymm-18, 22, true, '#444');
        vl(ML+200, Y_MARHDR, Y_MAREND);
        t('(Month)', ML+215, ymm-20, 15, false, '#888');
        uv(marMonth, ML+215, ymm+10, 200);
        vl(ML+430, Y_MARHDR, Y_MAREND);
        t('(Day)', ML+445, ymm-20, 15, false, '#888');
        uv(marDay, ML+445, ymm+10, 100);
        vl(ML+560, Y_MARHDR, Y_MAREND);
        t('(Year)', ML+575, ymm-20, 15, false, '#888');
        uv(marYear, ML+575, ymm+10, 100);
        vl(ML+700, Y_MARHDR, Y_MAREND);
        t('20b. PLACE', ML+720, ymm-18, 22, true, '#444');
        vl(ML+920, Y_MARHDR, Y_MAREND);
        t('(City/ Municipality)', ML+935, ymm-20, 15, false, '#888');
        uv(marCity, ML+935, ymm+10, 300);
        vl(ML+1260, Y_MARHDR, Y_MAREND);
        t('(Province)', ML+1275, ymm-20, 15, false, '#888');
        uv(marProvince, ML+1275, ymm+10, 250);
        vl(ML+1550, Y_MARHDR, Y_MAREND);
        t('(Country)', ML+1565, ymm-20, 15, false, '#888');
        uv(marCountry, ML+1565, ymm+10, MR-ML-1575);
        hl(Y_MAREND);

        // ==================== 21a. ATTENDANT ====================
        const yma = rm(Y_MAREND, Y_A1);
        t('21a. ATTENDANT', ML+15, yma, 22, true, '#444');
        cb(ML+280, yma, attType==='Physician'); t('1 Physician', ML+310, yma, 22);
        cb(ML+510, yma, attType==='Nurse'); t('2 Nurse', ML+540, yma, 22);
        cb(ML+710, yma, attType==='Midwife'); t('3 Midwife', ML+740, yma, 22);
        cb(ML+940, yma, attType==='Hilot'); t('4 Hilot (Traditional Birth Attendant)', ML+970, yma, 22);
        cb(ML+1480, yma, attType==='Others'); t('5 Others (Specify)', ML+1510, yma, 22);
        hl(Y_A1);

        // 21b. CERTIFICATION (Y_A1 → Y_A2)
        const ya2m = Y_A1 + (Y_A2-Y_A1)*0.35;
        t('21b. CERTIFICATION OF ATTENDANT AT BIRTH', ML+15, ya2m, 20, true, '#444');
        t('(Physician, Nurse, Midwife, Traditional Birth Attendant/Hilot, etc.)', ML+650, ya2m, 15, false, '#888');
        const ya2v = Y_A1 + (Y_A2-Y_A1)*0.75;
        t('I hereby certify that I attended the birth of the child who was born alive at', ML+40, ya2v, 22, false, '#000');
        uv(btime, ML+1120, ya2v, 220);
        t('am/pm   on the date of birth specified above.', ML+1370, ya2v, 22, false, '#000');
        hl(Y_A2);

        // Attendant signature area (Y_A2 → Y_AEND)
        const sigH = Y_AEND - Y_A2;
        const sigR1 = Y_A2 + sigH*0.18;
        const sigR2 = Y_A2 + sigH*0.42;
        const sigR3 = Y_A2 + sigH*0.65;
        const sigR4 = Y_A2 + sigH*0.88;
        t('Signature', ML+20, sigR1, 22, false, '#444');
        ln(ML+180, sigR1+6, ML+700, sigR1+6, 0.8, '#999');
        t('Address', ML+860, sigR1, 22, false, '#444');
        uv(city+', '+province, ML+990, sigR1, 600);
        t('Name in Print', ML+20, sigR2, 22, false, '#444');
        uv(attendantName, ML+220, sigR2, 600);
        t('Title or Position', ML+20, sigR3, 22, false, '#444');
        uv(attType.toUpperCase(), ML+280, sigR3, 520);
        vl(ML+870, sigR3-30, sigR4+10);
        t('Date', ML+890, sigR3, 22, false, '#444');
        uv(issueDate, ML+970, sigR3, 450);
        hl(Y_AEND);

        // ==================== 22/23 INFORMANT + PREPARED BY ====================
        const midX = ML + FW/2;
        vl(midX, Y_AEND, Y_22END);

        // 22. LEFT SIDE
        const s22h = Y_22END - Y_AEND;
        let y22 = Y_AEND + 30;
        t('22. CERTIFICATION OF INFORMANT', ML+20, y22, 20, true, '#444');
        y22 += 35;
        t('I hereby certify that all information supplied are true and', ML+40, y22, 19, false, '#000');
        y22 += 24;
        t('correct to my own knowledge and belief.', ML+40, y22, 19, false, '#000');
        y22 += 45;
        t('Signature', ML+40, y22, 20, false, '#444');
        ln(ML+180, y22+6, midX-30, y22+6, 0.8, '#999');
        y22 += 42;
        t('Name in Print', ML+40, y22, 20, false, '#444');
        uv(motherName, ML+240, y22, midX-ML-280);
        y22 += 42;
        t('Relationship in the Child', ML+40, y22, 20, false, '#444');
        uv('MOTHER', ML+380, y22, midX-ML-420);
        y22 += 42;
        t('Address', ML+40, y22, 20, false, '#444');
        uv(mResidence, ML+170, y22, midX-ML-210);
        y22 += 42;
        t('Date', ML+40, y22, 20, false, '#444');
        uv(issueDate, ML+120, y22, midX-ML-160);

        // 23. RIGHT SIDE
        let y23 = Y_AEND + 30;
        t('23. PREPARED BY', midX+30, y23, 20, true, '#444');
        y23 += 60;
        t('Signature', midX+50, y23, 20, false, '#444');
        ln(midX+190, y23+6, MR-20, y23+6, 0.8, '#999');
        y23 += 50;
        t('Name in Print', midX+50, y23, 20, false, '#444');
        uv(attendantName, midX+260, y23, MR-midX-300);
        y23 += 50;
        t('Title or Position', midX+50, y23, 20, false, '#444');
        uv(attType.toUpperCase(), midX+290, y23, MR-midX-330);
        y23 += 50;
        t('Date', midX+50, y23, 20, false, '#444');
        uv(issueDate, midX+140, y23, MR-midX-180);
        hl(Y_22END);

        // ==================== 24/25 RECEIVED + REGISTERED ====================
        vl(midX, Y_22END, Y_24END);

        // 24. LEFT
        let y24 = Y_22END + 28;
        t('24. RECEIVED BY', ML+20, y24, 20, true, '#444');
        y24 += 48;
        t('Signature', ML+40, y24, 20, false, '#444');
        ln(ML+180, y24+6, midX-30, y24+6, 0.8, '#999');
        y24 += 48;
        t('Name in Print', ML+40, y24, 20, false, '#444');
        ln(ML+240, y24+6, midX-30, y24+6, 0.8, '#999');
        y24 += 48;
        t('Title or Position', ML+40, y24, 20, false, '#444');
        ln(ML+280, y24+6, midX-30, y24+6, 0.8, '#999');
        y24 += 48;
        t('Date', ML+40, y24, 20, false, '#444');
        ln(ML+120, y24+6, midX-30, y24+6, 0.8, '#999');

        // 25. RIGHT
        let y25 = Y_22END + 28;
        t('25. REGISTERED BY THE CIVIL REGISTRAR', midX+30, y25, 20, true, '#444');
        y25 += 48;
        t('Signature', midX+50, y25, 20, false, '#444');
        ln(midX+190, y25+6, MR-20, y25+6, 0.8, '#999');
        y25 += 48;
        t('Name in Print', midX+50, y25, 20, false, '#444');
        ln(midX+250, y25+6, MR-20, y25+6, 0.8, '#999');
        y25 += 48;
        t('Title or Position', midX+50, y25, 20, false, '#444');
        ln(midX+290, y25+6, MR-20, y25+6, 0.8, '#999');
        y25 += 48;
        t('Date', midX+50, y25, 20, false, '#444');
        ln(midX+140, y25+6, MR-20, y25+6, 0.8, '#999');
        hl(Y_24END);

        // ==================== REMARKS/ANNOTATIONS ====================
        t('REMARKS/ANNOTATIONS (For LCRO/OCRG Use Only)', ML+15, Y_24END+28, 20, true);
        ctx.strokeStyle = '#000'; ctx.lineWidth = 1;
        ctx.strokeRect(ML, Y_24END, FW, Y_REND-Y_24END);

        // ==================== TO BE FILLED-UP AT THE OFFICE ====================
        hl(Y_REND, 1.5);
        t('TO BE FILLED-UP AT THE OFFICE OF THE CIVIL REGISTRAR', ML+10, Y_REND+24, 17, true);

        // Bottom boxes matching Municipal Form 102 exactly
        // Numbers at TOP-LEFT of each box, horizontal lines inside
        const boxY = Y_REND + 32;
        const boxH = YB - boxY;
        const boxCols = [
            {n:'8',  w:1},
            {n:'9',  w:1},
            {n:'11', w:1},
            {n:'13', w:1.5},
            {n:'',   w:0.25},
            {n:'15', w:1},
            {n:'16', w:1},
            {n:'17', w:1},
            {n:'19', w:1}
        ];
        const totU = boxCols.reduce((s,b)=>s+b.w,0);
        const uW = FW/totU;
        ctx.strokeStyle = '#000'; ctx.lineWidth = 1;
        let bxX = ML;
        for(const col of boxCols) {
            const cw = col.w * uW;
            // Draw box
            ctx.strokeRect(bxX, boxY, cw, boxH);
            if(col.n) {
                // Number at TOP-LEFT inside the box
                t(col.n, bxX+4, boxY+boxH-8, 14, false);
                // Horizontal lines inside the box (3 rows)
                const rowH = boxH / 4;
                for(let r=1; r<4; r++) {
                    ln(bxX, boxY + r*rowH, bxX+cw, boxY + r*rowH, 0.5, '#aaa');
                }
            }
            bxX += cw;
        }

        // ===== SHOW PREVIEW INSTEAD OF DOWNLOAD =====
        setTimeout(() => {
            try {
                const dataUrl = canvas.toDataURL('image/png');

                // Store for saving
                window._certDataUrl = dataUrl;
                window._certBabyId = baby.id;

                // Show preview modal
                document.getElementById('certPreviewImg').src = dataUrl;
                document.getElementById('certPreviewModal').classList.remove('hidden');

                printBtn.innerHTML = origBtnText;
                printBtn.disabled = false;
                closeCertForm();
            } catch(err) {
                console.error('Certificate generation error:', err);
                alert('Error generating certificate. Please try again.');
                printBtn.innerHTML = origBtnText;
                printBtn.disabled = false;
            }
        }, 300);
    }

    // Update baby card button from "Certificate" to "View Certificate" after saving
    function updateBabyCardBtn(babyId, certPath) {
        // Also capture current form data for persistence
        let currentCertData = null;
        try { currentCertData = collectCertFormData(); } catch(e) {}

        const existingBtn = document.getElementById('certBtnWrap_' + babyId);
        if(existingBtn) {
            existingBtn.dataset.certPath = certPath;
            // Update baby JSON with new cert path and form data
            try {
                let bd = JSON.parse((existingBtn.dataset.babyJson || '{}').replace(/&apos;/g, "'"));
                bd.certificate_of_live_birth = certPath;
                if(currentCertData) bd.certificate_data = currentCertData;
                existingBtn.dataset.babyJson = JSON.stringify(bd);
            } catch(e) {}
            return;
        }
        const allBtns = document.querySelectorAll('#modal_babies_list button');
        allBtns.forEach(btn => {
            if(btn.textContent.trim().includes('Certificate') && btn.getAttribute('onclick') && btn.getAttribute('onclick').includes('openCertForm')) {
                try {
                    const onclickStr = btn.getAttribute('onclick');
                    const jsonMatch = onclickStr.match(/openCertForm\((.+)\)/);
                    if(jsonMatch) {
                        const babyData = JSON.parse(jsonMatch[1].replace(/&apos;/g, "'"));
                        if(babyData.id == babyId) {
                            babyData.certificate_of_live_birth = certPath;
                            if(currentCertData) babyData.certificate_data = currentCertData;
                            const newBtn = document.createElement('button');
                            newBtn.id = 'certBtnWrap_' + babyId;
                            newBtn.dataset.certPath = certPath;
                            newBtn.dataset.babyJson = JSON.stringify(babyData);
                            newBtn.className = 'bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-3 rounded-xl font-bold shadow-md flex items-center gap-2 text-xs transition-all active:scale-95';
                            newBtn.innerHTML = '<span class="material-symbols-outlined text-[16px]">verified</span> View Certificate';
                            newBtn.addEventListener('click', function() { openCertOptionsFromBtn(this); });
                            btn.replaceWith(newBtn);
                        }
                    }
                } catch(e) {}
            }
        });
    }

    // Save certificate and then show the options modal (View / Edit)
    function collectCertFormData() {
        return {
            province: document.getElementById('cf_province').value || '',
            city: document.getElementById('cf_city').value || '',
            registry_no: document.getElementById('cf_registry_no').value || '',
            birth_type: document.getElementById('cf_birth_type').value || '',
            multiple_type: document.getElementById('cf_multiple_type').value || '',
            birth_order: document.getElementById('cf_birth_order').value || '',
            m_citizen: document.getElementById('cf_m_citizen').value || '',
            m_religion: document.getElementById('cf_m_religion').value || '',
            m_job: document.getElementById('cf_m_job').value || '',
            m_alive: document.getElementById('cf_m_alive').value || '',
            m_living: document.getElementById('cf_m_living').value || '',
            m_dead: document.getElementById('cf_m_dead').value || '',
            m_res_house: document.getElementById('cf_m_res_house').value || '',
            m_res_city: document.getElementById('cf_m_res_city').value || '',
            m_res_province: document.getElementById('cf_m_res_province').value || '',
            m_res_country: document.getElementById('cf_m_res_country').value || '',
            f_first: document.getElementById('cf_f_first').value || '',
            f_middle: document.getElementById('cf_f_middle').value || '',
            f_last: document.getElementById('cf_f_last').value || '',
            f_citizen: document.getElementById('cf_f_citizen').value || '',
            f_religion: document.getElementById('cf_f_religion').value || '',
            f_job: document.getElementById('cf_f_job').value || '',
            f_age: document.getElementById('cf_f_age').value || '',
            f_dob: document.getElementById('cf_f_dob').value || '',
            f_res_house: document.getElementById('cf_f_res_house').value || '',
            f_res_city: document.getElementById('cf_f_res_city').value || '',
            f_res_province: document.getElementById('cf_f_res_province').value || '',
            f_res_country: document.getElementById('cf_f_res_country').value || '',
            mar_date: document.getElementById('cf_mar_date').value || '',
            mar_city: document.getElementById('cf_mar_city').value || '',
            mar_province: document.getElementById('cf_mar_province').value || '',
            mar_country: document.getElementById('cf_mar_country').value || '',
            att_type: document.getElementById('cf_att_type').value || '',
            attendant: document.getElementById('cf_attendant').value || ''
        };
    }

    function saveCertAndShowOptions() {
        if(!window._certDataUrl || !window._certBabyId) { alert('No certificate to save.'); return; }
        const saveBtn = document.getElementById('certSaveBtn');
        const origText = saveBtn.innerHTML;
        saveBtn.innerHTML = '<span class="material-symbols-outlined text-[16px] animate-spin">refresh</span> Saving...';
        saveBtn.disabled = true;

        fetch('patientrecords.php?action=save_certificate', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ baby_id: window._certBabyId, image: window._certDataUrl, cert_data: collectCertFormData() })
        })
        .then(r => r.json())
        .then(data => {
            if(data.success) {
                updateBabyCardBtn(window._certBabyId, data.path);
                // Close preview, show options modal
                document.getElementById('certPreviewModal').classList.add('hidden');
                window._optionsCertPath = data.path;
                // Get baby data from hidden input
                try { window._optionsBabyData = JSON.parse(document.getElementById('cf_baby_json').value); } catch(e) { window._optionsBabyData = {}; }
                window._optionsBabyData.certificate_of_live_birth = data.path;
                window._optionsBabyData.certificate_data = collectCertFormData();
                document.getElementById('certOptionsModal').classList.remove('hidden');
                saveBtn.innerHTML = origText;
                saveBtn.disabled = false;
            } else {
                alert('Error: ' + (data.error || 'Unknown error'));
                saveBtn.innerHTML = origText;
                saveBtn.disabled = false;
            }
        })
        .catch(err => {
            alert('Network error. Please try again.');
            saveBtn.innerHTML = origText;
            saveBtn.disabled = false;
        });
    }

    function downloadCertificate() {
        if(!window._certDataUrl || !window._certBabyId) return;
        // Save to DB first, then download
        const dlBtn = event.target.closest('button');
        const origText = dlBtn.innerHTML;
        dlBtn.innerHTML = '<span class="material-symbols-outlined text-[16px] animate-spin">refresh</span> Saving...';
        dlBtn.disabled = true;

        fetch('patientrecords.php?action=save_certificate', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ baby_id: window._certBabyId, image: window._certDataUrl, cert_data: collectCertFormData() })
        })
        .then(r => r.json())
        .then(data => {
            // Download regardless
            const link = document.createElement('a');
            link.download = 'Certificate_LiveBirth.png';
            link.href = window._certDataUrl;
            link.click();

            if(data.success) {
                updateBabyCardBtn(window._certBabyId, data.path);
                dlBtn.innerHTML = '<span class="material-symbols-outlined text-[16px]">check_circle</span> Saved & Downloaded!';
                dlBtn.classList.remove('bg-slate-700');
                dlBtn.classList.add('bg-green-600');
            } else {
                dlBtn.innerHTML = origText;
            }
            setTimeout(() => {
                dlBtn.innerHTML = origText;
                dlBtn.disabled = false;
                dlBtn.classList.remove('bg-green-600');
                dlBtn.classList.add('bg-slate-700');
            }, 2000);
        })
        .catch(() => {
            // Still download even if save fails
            const link = document.createElement('a');
            link.download = 'Certificate_LiveBirth.png';
            link.href = window._certDataUrl;
            link.click();
            dlBtn.innerHTML = origText;
            dlBtn.disabled = false;
        });
    }

    function editCertificateAgain() {
        document.getElementById('certPreviewModal').classList.add('hidden');
        document.getElementById('certFormModal').classList.remove('hidden');
    }

    function closeCertPreview() {
        document.getElementById('certPreviewModal').classList.add('hidden');
    }

    function openReceiptForVisit(row) {
        const rec = row && row.receipt ? String(row.receipt).trim() : '';
        const modal = document.getElementById('receiptModal');
        const img = document.getElementById('receiptImage');
        const frame = document.getElementById('receiptFrame');
        const genDiv = document.getElementById('receiptGenerated');

        if (!modal) return;

        // Hide all receipt views first
        if (img) { img.classList.add('hidden'); img.src = ''; }
        if (frame) { frame.classList.add('hidden'); frame.src = ''; }
        if (genDiv) genDiv.classList.add('hidden');

        if (rec) {
            // Has saved receipt file — show image or PDF
            let path = rec;
            if (rec.includes('|')) {
                const parts = rec.split('|');
                path = (parts[1] || parts[0] || '').trim();
            }
            if (path) {
                const lowerPath = path.toLowerCase();
                if (lowerPath.endsWith('.pdf')) {
                    frame.src = path;
                    frame.classList.remove('hidden');
                } else {
                    img.src = path;
                    img.classList.remove('hidden');
                }
                modal.classList.remove('hidden');
                return;
            }
        }

        // No saved receipt file — generate on-the-fly from payment data
        if (!genDiv) {
            alert('Walang na-save na resibo para sa visit na ito.');
            return;
        }

        const patientName = row.adm_full_name || '—';
        const service = row.service_type || '—';
        const amount = parseFloat(row.pay_amount || 0);
        const isPh = parseInt(row.pay_is_philhealth || 0) === 1;
        const phAmount = parseFloat(row.pay_philhealth_amount || 0);
        const payDate = row.pay_date || row.visit_date || '';

        document.getElementById('gen_rec_patient').innerText = patientName;
        document.getElementById('gen_rec_service').innerText = service;
        document.getElementById('gen_rec_amount').innerText = '₱ ' + amount.toLocaleString('en-US', {minimumFractionDigits: 2});

        // Payment method
        if (isPh && phAmount > 0) {
            document.getElementById('gen_rec_method').innerText = 'PhilHealth';
        } else {
            document.getElementById('gen_rec_method').innerText = 'Online Payment';
        }

        // Date
        if (payDate) {
            const d = new Date(payDate);
            document.getElementById('gen_rec_date').innerText = d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
        } else {
            document.getElementById('gen_rec_date').innerText = '—';
        }

        // PhilHealth section — only show if actually PhilHealth
        const phSection = document.getElementById('gen_rec_philhealth_section');
        if (isPh && phAmount > 0) {
            phSection.classList.remove('hidden');
            const originalAmt = amount + phAmount;
            document.getElementById('gen_rec_ph_original').innerText = '₱ ' + originalAmt.toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById('gen_rec_ph_covered').innerText = '- ₱ ' + phAmount.toLocaleString('en-US', {minimumFractionDigits: 2});
        } else {
            phSection.classList.add('hidden');
        }

        genDiv.classList.remove('hidden');
        modal.classList.remove('hidden');
    }

    function showCheckupDetail(row) {
        document.getElementById('vd_date').innerText = new Date(row.visit_date).toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' });
        document.getElementById('vd_staff').innerText = row.assigned_staff || 'Unassigned';
        document.getElementById('vd_wt').innerText = row.weight || '---';
        document.getElementById('vd_bp').innerText = row.bp || '---';
        document.getElementById('vd_temp').innerText = row.temperature || row.temp || '---';
        document.getElementById('vd_pulse').innerText = row.pulse_rate || '---';
        document.getElementById('vd_resp').innerText = row.spo2 || '---';
        // Service & Remarks section removed

        // Maternity / Pregnancy block
        const matSection = document.getElementById('vd_maternity_section');
        const serviceTxt = (row.service_type || '').toLowerCase();
        const isMaternityService = serviceTxt.includes('prenatal') || serviceTxt.includes('postnatal') || serviceTxt.includes('delivery') || serviceTxt.includes('labor');
        if (row.last_menstrual_period || row.pregnancy_status || isMaternityService) {
            matSection.classList.remove('hidden');
            const fmtDate = function(s){
                if (!s) return '---';
                try {
                    const d = new Date(s + 'T00:00:00');
                    if (isNaN(d.getTime())) return s;
                    return d.toLocaleDateString('en-US', {month:'long', day:'numeric', year:'numeric'});
                } catch(e) { return s; }
            };
            document.getElementById('vd_lmp').innerText = fmtDate(row.last_menstrual_period);
            let eddTxt = row.estimated_delivery_date;
            if (!eddTxt && row.last_menstrual_period) {
                const parts = String(row.last_menstrual_period).split('-');
                if (parts.length === 3) {
                    let y = parseInt(parts[0], 10);
                    let m = parseInt(parts[1], 10);
                    let d = parseInt(parts[2], 10);
                    if (y && m && d) {
                        const tmp = new Date(y, m - 1, d);
                        tmp.setDate(tmp.getDate() + 7);
                        y = tmp.getFullYear(); m = tmp.getMonth() + 1; d = tmp.getDate();
                        m -= 3;
                        if (m <= 0) { m += 12; y -= 1; }
                        y += 1;
                        const lastDay = new Date(y, m, 0).getDate();
                        if (d > lastDay) d = lastDay;
                        eddTxt = `${String(y).padStart(4,'0')}-${String(m).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
                    }
                }
            }
            document.getElementById('vd_edd').innerText = fmtDate(eddTxt);
            const psEl = document.getElementById('vd_pregnancy_status');
            const psVal = row.pregnancy_status || 'Pending Confirmation';
            psEl.innerText = psVal;
            const psStyles = {
                'Pending Confirmation': 'bg-slate-100 text-slate-600 border-slate-200',
                'Confirmed Pregnant':   'bg-pink-100 text-pink-700 border-pink-200',
                'Not Pregnant':         'bg-amber-100 text-amber-700 border-amber-200',
                'Miscarriage':          'bg-red-100 text-red-700 border-red-200'
            };
            psEl.className = 'inline-block mt-1 text-[10px] font-black uppercase tracking-widest px-2 py-0.5 rounded border ' + (psStyles[psVal] || psStyles['Pending Confirmation']);

            // Gravida / Para badge
            const gpBadge = document.getElementById('vd_gp_badge');
            if (gpBadge) {
                const g = parseInt(row.gravida, 10) || 0;
                const p = parseInt(row.para, 10) || 0;
                gpBadge.textContent = 'G' + Math.max(0, g) + 'P' + Math.max(0, p);
                gpBadge.classList.remove('hidden');
            }
        } else {
            matSection.classList.add('hidden');
        }

        // Fetal Status block (admission rows with fetal data only)
        const fetalSection = document.getElementById('vd_fetal_section');
        if (fetalSection) {
            const hasFetal = row.fetal_aog || row.fetal_fundal_height || row.fetal_fht || row.fetal_presentation;
            if (hasFetal) {
                fetalSection.classList.remove('hidden');
                document.getElementById('vd_fetal_aog').innerText = row.fetal_aog || '---';
                const fh = row.fetal_fundal_height;
                document.getElementById('vd_fetal_fundal_height').innerText = (fh !== null && fh !== '' && fh !== undefined) ? (fh + ' cm') : '---';
                document.getElementById('vd_fetal_fht').innerText = row.fetal_fht || '---';
                document.getElementById('vd_fetal_presentation').innerText = row.fetal_presentation || '---';
            } else {
                fetalSection.classList.add('hidden');
            }
        }

        // Laboratory Results (Admission only)
        let labSection = document.getElementById('vd_lab_section');
        if (row.source === 'Admission') {
            let hasLab = row.lab_cbc || row.lab_urinalysis || row.lab_blood_type || row.lab_blood_sugar || row.lab_hep_b || row.lab_syphilis || row.lab_transvaginal || row.lab_pelvic;
            if (hasLab) {
                labSection.classList.remove('hidden');
                document.getElementById('vd_lab_cbc').innerText = row.lab_cbc || '---';
                document.getElementById('vd_lab_urinalysis').innerText = row.lab_urinalysis || '---';
                document.getElementById('vd_lab_blood_type').innerText = row.lab_blood_type || '---';
                document.getElementById('vd_lab_blood_sugar').innerText = row.lab_blood_sugar || '---';
                document.getElementById('vd_lab_hep_b').innerText = row.lab_hep_b || '---';
                document.getElementById('vd_lab_syphilis').innerText = row.lab_syphilis || '---';

                let tvWrap = document.getElementById('vd_lab_tv_wrap');
                let pvWrap = document.getElementById('vd_lab_pv_wrap');
                let ultrasoundSection = document.getElementById('vd_lab_ultrasound');
                tvWrap.classList.add('hidden');
                pvWrap.classList.add('hidden');
                ultrasoundSection.classList.add('hidden');

                if (row.lab_transvaginal) {
                    document.getElementById('vd_lab_tv_img').src = row.lab_transvaginal;
                    tvWrap.classList.remove('hidden');
                    ultrasoundSection.classList.remove('hidden');
                }
                if (row.lab_pelvic) {
                    document.getElementById('vd_lab_pv_img').src = row.lab_pelvic;
                    pvWrap.classList.remove('hidden');
                    ultrasoundSection.classList.remove('hidden');
                }
            } else {
                labSection.classList.add('hidden');
            }
        } else {
            labSection.classList.add('hidden');
        }

        document.getElementById('viewCheckupDetailModal').classList.remove('hidden');
    }

    function closeCheckupView() { document.getElementById('viewCheckupDetailModal').classList.add('hidden'); }
    function closeFullView() { document.getElementById('fullViewModal').classList.add('hidden'); }

    function closeReceiptModal() {
        const modal = document.getElementById('receiptModal');
        const img = document.getElementById('receiptImage');
        const frame = document.getElementById('receiptFrame');
        if (img) { img.src = ''; img.classList.add('hidden'); }
        if (frame) { frame.src = ''; frame.classList.add('hidden'); }
        if (modal) { modal.classList.add('hidden'); }
    }

    // LOGOUT MODAL SCRIPTS
    function openLogoutModal() {
        const modal = document.getElementById('logoutModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeLogoutModal() {
        const modal = document.getElementById('logoutModal');
        modal.classList.remove('flex');
        modal.classList.add('hidden');
    }

    function confirmLogout() {
        closeLogoutModal();
        const loading = document.getElementById('loggingOutScreen');
        loading.classList.remove('hidden');
        loading.classList.add('flex');
        setTimeout(() => {
            window.location.href = '?action=logout&c=<?= urlencode($clinicCode) ?>';
        }, 1500);
    }

    let archivePatientId = null;
    let restorePatientId = null;

    function openArchiveModal(id, name, code) {
        archivePatientId = id;
        const modal = document.getElementById('archiveModal');
        document.getElementById('archivePatientName').innerText = name || '';
        document.getElementById('archivePatientCode').innerText = code ? 'Patient ID: ' + code : '';
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeArchiveModal() {
        const modal = document.getElementById('archiveModal');
        modal.classList.remove('flex');
        modal.classList.add('hidden');
    }

    function confirmArchive() {
        if (!archivePatientId) {
            closeArchiveModal();
            return;
        }
        window.location.href = '?archive_id=' + encodeURIComponent(archivePatientId);
    }

    function openRestoreModal(id, name, code) {
        restorePatientId = id;
        const modal = document.getElementById('restoreModal');
        document.getElementById('restorePatientName').innerText = name || '';
        document.getElementById('restorePatientCode').innerText = code ? 'Patient ID: ' + code : '';
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeRestoreModal() {
        const modal = document.getElementById('restoreModal');
        modal.classList.remove('flex');
        modal.classList.add('hidden');
    }

    function confirmRestore() {
        if (!restorePatientId) {
            closeRestoreModal();
            return;
        }
        window.location.href = '?unarchive_id=' + encodeURIComponent(restorePatientId);
    }

    document.addEventListener('DOMContentLoaded', () => {
        sortPatients();
    });
</script>

</body>
</html>