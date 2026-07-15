<?php
// 1. SETTINGS & SESSION
date_default_timezone_set('Asia/Manila');
ob_start();
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

session_start();
require_once 'db.php';
try { $pdo->exec("SET time_zone = '+08:00'"); } catch (Exception $e) {}

//  AUTO-FIX: ADD COLUMNS KUNG WALA PA SA DATABASE
try {
    $pdo->query("SELECT payment_type FROM admissions LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE admissions ADD payment_type VARCHAR(50) NULL AFTER stage"); } catch (PDOException $ex) {}
}

try {
    $pdo->query("SELECT payment_method FROM admissions LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE admissions ADD payment_method VARCHAR(50) NULL AFTER payment_type"); } catch (PDOException $ex) {}
}

try {
    $pdo->query("SELECT remaining_balance FROM admissions LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE admissions ADD remaining_balance DECIMAL(10,2) NULL AFTER payment_method"); } catch (PDOException $ex) {}
}

//  ADD PATIENT ID COLUMN TO ADMISSIONS & AUTO-SYNC EXISTING DATA
try {
    $pdo->query("SELECT patient_id FROM admissions LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE admissions ADD patient_id VARCHAR(50) NULL AFTER TenantID");
        $pdo->exec("UPDATE admissions a JOIN patients p ON a.full_name = p.full_name AND a.TenantID = p.TenantID SET a.patient_id = p.patient_id WHERE a.patient_id IS NULL");
    } catch (PDOException $ex) {}
}

// EDD calculation helper: Naegele's rule (LMP + 7 days - 3 months + 1 year)
// Uses explicit year/month/day arithmetic with day-clamping to avoid month overflow.
if (!function_exists('mh_calculate_edd')) {
    function mh_calculate_edd($lmp) {
        if (empty($lmp)) return null;
        try {
            $dt = new DateTime($lmp);
            $dt->modify('+7 days');
            $y = (int)$dt->format('Y');
            $m = (int)$dt->format('n');
            $d = (int)$dt->format('j');
            $m -= 3;
            if ($m <= 0) { $m += 12; $y -= 1; }
            $y += 1;
            $lastDay = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
            if ($d > $lastDay) { $d = $lastDay; }
            return sprintf('%04d-%02d-%02d', $y, $m, $d);
        } catch (Exception $e) { return null; }
    }
}

if (!function_exists('releaseAdmissionBedToCleaning')) {
    function releaseAdmissionBedToCleaning($pdo, $tenantId, $admissionId) {
        $stmtCurrentBed = $pdo->prepare("SELECT assigned_bed_id FROM admissions WHERE id = ? AND TenantID = ? LIMIT 1");
        $stmtCurrentBed->execute([(int)$admissionId, $tenantId]);
        $oldBedId = (int)$stmtCurrentBed->fetchColumn();
        if ($oldBedId > 0) {
            $stmtRelease = $pdo->prepare("UPDATE clinic_room_beds SET bed_status = 'cleaning', admission_id = NULL, patient_id = NULL, patient_name = NULL WHERE id = ? AND TenantID = ?");
            $stmtRelease->execute([$oldBedId, $tenantId]);
        }
    }
}

if (!function_exists('applyRoomChargeOnce')) {
    /**
     * Idempotently add a room-type fee to an admission's bill. Tracks applied
     * tags inside admissions.room_charges_applied (CSV). Returns true if the
     * charge was newly applied, false if it was already applied previously.
     */
    function applyRoomChargeOnce($pdo, $tenantId, $admissionId, $chargeTag, $amount) {
        $admissionId = (int)$admissionId;
        $amount = (float)$amount;
        if ($admissionId <= 0 || $amount <= 0 || $chargeTag === '') return false;

        $stmt = $pdo->prepare("SELECT COALESCE(room_charges_applied, '') FROM admissions WHERE id = ? AND TenantID = ? LIMIT 1");
        $stmt->execute([$admissionId, $tenantId]);
        $existing = (string)$stmt->fetchColumn();
        $tags = array_filter(array_map('trim', explode(',', $existing)));
        if (in_array($chargeTag, $tags, true)) {
            return false;
        }
        $tags[] = $chargeTag;
        $newCsv = implode(',', $tags);

        $upd = $pdo->prepare("UPDATE admissions SET total_price = COALESCE(total_price, 0) + ?, remaining_balance = COALESCE(remaining_balance, 0) + ?, room_charges_applied = ? WHERE id = ? AND TenantID = ?");
        $upd->execute([$amount, $amount, $newCsv, $admissionId, $tenantId]);
        return true;
    }
}

if (!function_exists('buildAdmissionRoomChargesParam')) {
    /**
     * Returns a URL query fragment "&r_rooms=...json..." listing the
     * room-type charges already applied to this admission, for use on the
     * Official Receipt modal. Returns '' when there is nothing to show.
     */
    function buildAdmissionRoomChargesParam($pdo, $tenantId, $admissionId) {
        try {
            $stmtRC = $pdo->prepare("SELECT COALESCE(room_charges_applied, '') FROM admissions WHERE id = ? AND TenantID = ? LIMIT 1");
            $stmtRC->execute([(int)$admissionId, $tenantId]);
            $rcCsv = (string)$stmtRC->fetchColumn();
            if ($rcCsv === '') return '';

            $stmtPrices = $pdo->prepare("SELECT room_type, room_subtype, price FROM clinic_room_subtype_prices WHERE TenantID = ?");
            $stmtPrices->execute([$tenantId]);
            $priceLookup = [];
            foreach ($stmtPrices->fetchAll(PDO::FETCH_ASSOC) as $rp) {
                $rt = strtolower((string)$rp['room_type']);
                $rs = strtolower((string)$rp['room_subtype']);
                $key = ($rs === 'default') ? $rt : ($rt . ':' . $rs);
                $priceLookup[$key] = (float)$rp['price'];
            }
            $labelMap = [
                'labor_room'                  => 'Labor Room',
                'labor_room:regular'          => 'Labor Room (Basic)',
                'labor_room:private'          => 'Labor Room (Semi-Private)',
                'labor_room:large_private'    => 'Labor Room (Private)',
                'delivery_room'               => 'Delivery Room',
                'infant_ward'                 => 'Infant Ward',
                'recovery_room:regular'       => 'Recovery Room (Basic)',
                'recovery_room:private'       => 'Recovery Room (Semi-Private)',
                'recovery_room:large_private' => 'Recovery Room (Private)',
            ];
            $rooms = [];
            foreach (array_filter(array_map('trim', explode(',', $rcCsv))) as $tag) {
                $price = $priceLookup[$tag] ?? 0.0;
                if ($price > 0) {
                    $rooms[] = ['label' => $labelMap[$tag] ?? $tag, 'price' => round($price, 2)];
                }
            }
            if (empty($rooms)) return '';
            return '&r_rooms=' . urlencode(json_encode($rooms));
        } catch (PDOException $e) {
            return '';
        }
    }
}

if (!function_exists('releaseAdmissionInfantBeds')) {
    function releaseAdmissionInfantBeds($pdo, $tenantId, $admissionId) {
        $stmtInfants = $pdo->prepare("SELECT DISTINCT linked_bed_id FROM infants WHERE TenantID = ? AND admission_id = ? AND linked_bed_id IS NOT NULL AND linked_bed_id > 0");
        $stmtInfants->execute([$tenantId, (int)$admissionId]);
        $bedIds = array_filter(array_map('intval', $stmtInfants->fetchAll(PDO::FETCH_COLUMN)));

        if (!empty($bedIds)) {
            $placeholders = implode(',', array_fill(0, count($bedIds), '?'));
            $params = array_merge($bedIds, [$tenantId]);
            $stmtRelease = $pdo->prepare("UPDATE clinic_room_beds SET bed_status = 'cleaning', admission_id = NULL, patient_id = NULL, patient_name = NULL WHERE id IN ($placeholders) AND TenantID = ?");
            $stmtRelease->execute($params);
        }

        $stmtClear = $pdo->prepare("UPDATE infants SET location_option = 'released', linked_room_id = NULL, linked_bed_id = NULL WHERE TenantID = ? AND admission_id = ?");
        $stmtClear->execute([$tenantId, (int)$admissionId]);
    }
}

//  ADD PATIENT ID COLUMN TO PAYMENTS TABLE KUNG WALA PA
try {
    $pdo->query("SELECT patient_id FROM payments LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE payments ADD patient_id VARCHAR(50) NULL AFTER full_name"); } catch (PDOException $ex) {}
}

//  AUTO-FIX: RENAME appointment_id TO admission_id SA PAYMENTS TABLE
try {
    $pdo->query("SELECT admission_id FROM payments LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE payments CHANGE appointment_id admission_id INT NULL"); } catch (PDOException $ex) {}
}

//  AUTO-FIX: ADD PHILHEALTH COLUMNS TO PAYMENTS TABLE
try {
    $pdo->query("SELECT is_philhealth FROM payments LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE payments ADD is_philhealth TINYINT(1) NOT NULL DEFAULT 0 AFTER status"); } catch (PDOException $ex) {}
}
try {
    $pdo->query("SELECT philhealth_amount FROM payments LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE payments ADD philhealth_amount DECIMAL(10,2) NULL DEFAULT 0 AFTER is_philhealth"); } catch (PDOException $ex) {}
}
try {
    $pdo->query("SELECT philhealth_id_front FROM payments LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE payments ADD philhealth_id_front VARCHAR(255) NULL AFTER philhealth_amount"); } catch (PDOException $ex) {}
}
try {
    $pdo->query("SELECT philhealth_id_back FROM payments LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE payments ADD philhealth_id_back VARCHAR(255) NULL AFTER philhealth_id_front"); } catch (PDOException $ex) {}
}

//  AUTO-FIX: ADD FETAL STATUS COLUMNS TO ADMISSIONS
try {
    $pdo->query("SELECT fetal_aog FROM admissions LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE admissions ADD fetal_aog VARCHAR(50) NULL, ADD fetal_fundal_height VARCHAR(20) NULL, ADD fetal_fht VARCHAR(20) NULL, ADD fetal_presentation VARCHAR(40) NULL"); } catch (PDOException $ex) {}
}

//  AUTO-FIX: ADD PREGNANCY STATUS COLUMN TO ADMISSIONS
try {
    $pdo->query("SELECT pregnancy_status FROM admissions LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE admissions ADD pregnancy_status VARCHAR(40) NULL AFTER have_feedback"); }
    catch (PDOException $ex) {
        try { $pdo->exec("ALTER TABLE admissions ADD pregnancy_status VARCHAR(40) NULL"); } catch (PDOException $ex2) {}
    }
}

//  AUTO-FIX: ADD GRAVIDA / PARA COLUMNS TO PATIENTS
try {
    $pdo->query("SELECT gravida FROM patients LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE patients ADD gravida INT NOT NULL DEFAULT 0, ADD para INT NOT NULL DEFAULT 0"); } catch (PDOException $ex) {}
}

//  AUTO-FIX: Track if delivery already counted for GP update (prevents double increment for twins/retries)
try {
    $pdo->query("SELECT gp_delivery_counted FROM admissions LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE admissions ADD gp_delivery_counted TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $ex) {}
}

//  AUTO-FIX: ADD LABORATORY COLUMNS TO ADMISSIONS
try {
    $pdo->query("SELECT lab_cbc FROM admissions LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE admissions
            ADD lab_cbc VARCHAR(150) NULL,
            ADD lab_urinalysis VARCHAR(150) NULL,
            ADD lab_blood_type VARCHAR(50) NULL,
            ADD lab_blood_sugar VARCHAR(150) NULL,
            ADD lab_hep_b VARCHAR(150) NULL,
            ADD lab_syphilis VARCHAR(150) NULL
        ");
    } catch (PDOException $ex) {}
}

//  AUTO-FIX: ADD ULTRASOUND IMAGE COLUMNS TO ADMISSIONS
try {
    $pdo->query("SELECT lab_transvaginal FROM admissions LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE admissions
            ADD lab_transvaginal VARCHAR(255) NULL,
            ADD lab_pelvic VARCHAR(255) NULL
        ");
    } catch (PDOException $ex) {}
}

//  AUTO-FIX: ADD receipt COLUMN TO ADMISSIONS
try {
    $pdo->query("SELECT receipt FROM admissions LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE admissions ADD receipt VARCHAR(255) NULL"); } catch (PDOException $ex) {}
}

//  AUTO-FIX: ADD total_price COLUMN TO ADMISSIONS
try {
    $pdo->query("SELECT total_price FROM admissions LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE admissions ADD total_price DECIMAL(10,2) NULL"); } catch (PDOException $ex) {}
}

//  AUTO-FIX: ADD assigned_room_id AND assigned_bed_id FOR LABOR BED ASSIGNMENT
try {
    $pdo->query("SELECT assigned_room_id FROM admissions LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE admissions ADD assigned_room_id INT NULL AFTER total_price"); } catch (PDOException $ex) {}
}

try {
    $pdo->query("SELECT assigned_bed_id FROM admissions LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE admissions ADD assigned_bed_id INT NULL AFTER assigned_room_id"); } catch (PDOException $ex) {}
}

try {
    $pdo->query("SELECT assigned_room_type FROM admissions LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE admissions ADD assigned_room_type VARCHAR(50) NULL AFTER assigned_bed_id"); } catch (PDOException $ex) {}
}

//  AUTO-FIX: ADD assigned_room_subtype COLUMN FOR RECOVERY ROOM TYPE (Basic/Semi-Private/Private)
try {
    $pdo->query("SELECT assigned_room_subtype FROM admissions LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE admissions ADD assigned_room_subtype VARCHAR(30) NULL AFTER assigned_room_type"); } catch (PDOException $ex) {}
}

//  AUTO-FIX: ADD room_charges_applied COLUMN (tracks which room-type fees already added to bill, to prevent double charging)
try {
    $pdo->query("SELECT room_charges_applied FROM admissions LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE admissions ADD room_charges_applied VARCHAR(150) NULL AFTER assigned_room_subtype"); } catch (PDOException $ex) {}
}

//  AUTO-FIX: ADD guardian_id_url COLUMN TO patients
try {
    $pdo->query("SELECT guardian_id_url FROM patients LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE patients ADD guardian_id_url VARCHAR(255) NULL"); } catch (PDOException $ex) {}
}

//  AUTO-FIX: ADD LMP / EDD / PREGNANCY STATUS COLUMNS TO patients
try { $pdo->query("SELECT estimated_delivery_date FROM patients LIMIT 1"); }
catch (PDOException $e) { try { $pdo->exec("ALTER TABLE patients ADD estimated_delivery_date DATE NULL AFTER last_menstrual_period"); } catch (PDOException $ex) {} }
try { $pdo->query("SELECT pregnancy_status FROM patients LIMIT 1"); }
catch (PDOException $e) { try { $pdo->exec("ALTER TABLE patients ADD pregnancy_status VARCHAR(40) NOT NULL DEFAULT 'Pending Confirmation' AFTER estimated_delivery_date"); } catch (PDOException $ex) {} }

//  AUTO-FIX: EXPAND philhealth_id_pic_back TO VARCHAR(255)
try {
    $colCheck = $pdo->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'patients' AND COLUMN_NAME = 'philhealth_id_pic_back' AND TABLE_SCHEMA = DATABASE()");
    $colType = $colCheck->fetchColumn();
    if ($colType && stripos($colType, '255') === false) {
        $pdo->exec("ALTER TABLE patients MODIFY philhealth_id_pic_back VARCHAR(255) NULL");
    }
} catch (PDOException $e) {}

//  AUTO-FIX: ADD BED OCCUPANCY LINK COLUMNS TO clinic_room_beds
try {
    $pdo->query("SELECT admission_id FROM clinic_room_beds LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE clinic_room_beds ADD admission_id VARCHAR(50) NULL AFTER bed_status"); } catch (PDOException $ex) {}
}

try {
    $pdo->query("SELECT patient_id FROM clinic_room_beds LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE clinic_room_beds ADD patient_id VARCHAR(50) NULL AFTER admission_id"); } catch (PDOException $ex) {}
}

try {
    $pdo->query("SELECT patient_name FROM clinic_room_beds LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE clinic_room_beds ADD patient_name VARCHAR(150) NULL AFTER patient_id"); } catch (PDOException $ex) {}
}

//  AUTO-FIX: ADD GENDER COLUMN TO PATIENTS TABLE PARA SA NEWBORNS
try {
    $pdo->query("SELECT gender FROM patients LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE patients ADD gender VARCHAR(20) NULL AFTER full_name");
    } catch (PDOException $ex) {}
}

//  AUTO-FIX: ADD is_active COLUMN TO clinic_services KUNG WALA PA
try {
    $pdo->query("SELECT is_active FROM clinic_services LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE clinic_services ADD is_active TINYINT(1) NOT NULL DEFAULT 1");
    } catch (PDOException $ex) {}
}

//  AUTO-FIX: ADD philhealth_rate COLUMN TO clinic_services KUNG WALA PA
try {
    $pdo->query("SELECT philhealth_rate FROM clinic_services LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE clinic_services ADD philhealth_rate DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER downpayment_percent"); } catch (PDOException $ex) {}
}

//  AUTO-FIX: CREATE INFANTS TABLE KUNG WALA PA
try {
    $pdo->query("SELECT 1 FROM infants LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS infants (
            id INT AUTO_INCREMENT PRIMARY KEY,
            TenantID VARCHAR(50) NOT NULL,
            mother_patient_id VARCHAR(50) NOT NULL,
            infant_name VARCHAR(150) NULL,
            gender VARCHAR(20) NOT NULL,
            birth_date DATE NOT NULL,
            birth_time TIME NOT NULL,
            weight_kg DECIMAL(5,2) NOT NULL,
            length_cm DECIMAL(5,2) NULL,
            apgar_score VARCHAR(10) NULL,
            delivery_method VARCHAR(50) NULL,
            attending_staff VARCHAR(150) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (PDOException $ex) {}
}

//  AUTO-FIX: ADD assigned_staff COLUMN TO APPOINTMENTS TABLE
try {
    $pdo->query("SELECT assigned_staff FROM appointments LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE appointments ADD assigned_staff VARCHAR(150) NULL AFTER service"); } catch (PDOException $ex) {}
}

//  AUTO-FIX: ADD admission_id COLUMN TO INFANTS TABLE KUNG WALA PA (LINK TO SPECIFIC ADMISSION)
try {
    $pdo->query("SELECT admission_id FROM infants LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE infants ADD admission_id INT NULL AFTER mother_patient_id"); } catch (PDOException $ex) {}
}

//  AUTO-FIX: ADD NEWBORN LOCATION COLUMNS TO INFANTS TABLE
try {
    $pdo->query("SELECT location_option FROM infants LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE infants ADD location_option VARCHAR(30) NULL AFTER attending_staff"); } catch (PDOException $ex) {}
}

try {
    $pdo->query("SELECT linked_room_id FROM infants LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE infants ADD linked_room_id INT NULL AFTER location_option"); } catch (PDOException $ex) {}
}

try {
    $pdo->query("SELECT linked_bed_id FROM infants LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE infants ADD linked_bed_id INT NULL AFTER linked_room_id"); } catch (PDOException $ex) {}
}

// ==============================================================
// 100% RELIABLE EMAIL SENDER FUNCTION (ALWAYSDATA SMTP)
// ==============================================================
if (!function_exists('send_email_via_smtp_gmail')) {
    function send_email_via_smtp_gmail($to, $subject, $body) {
        $username = 'maternityhub@alwaysdata.net';
        $password = 'Eirean252004!';
        $host = 'ssl://smtp-maternityhub.alwaysdata.net';
        $port = 465;

        $socket = fsockopen($host, $port, $errno, $errstr, 15);
        if (!$socket) return "Socket Error: $errstr ($errno)";

        $read_res = function($socket) {
            $res = '';
            while ($line = fgets($socket, 515)) {
                $res .= $line;
                if (substr($line, 3, 1) == ' ') { break; }
            }
            return $res;
        };

        $read_res($socket);
        fputs($socket, "EHLO " . $_SERVER['HTTP_HOST'] . "\r\n"); $read_res($socket);
        fputs($socket, "AUTH LOGIN\r\n"); $read_res($socket);
        fputs($socket, base64_encode($username) . "\r\n"); $read_res($socket);
        fputs($socket, base64_encode($password) . "\r\n");
        $auth_response = $read_res($socket);

        if (strpos($auth_response, '235') === false) {
            fclose($socket);
            return false;
        }

        fputs($socket, "MAIL FROM: <$username>\r\n"); $read_res($socket);
        fputs($socket, "RCPT TO: <$to>\r\n"); $read_res($socket);
        fputs($socket, "DATA\r\n"); $read_res($socket);

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: MaternityHub System <$username>\r\n";
        $headers .= "To: $to\r\n";
        $headers .= "Subject: $subject\r\n";

        fputs($socket, "$headers\r\n\r\n$body\r\n.\r\n");
        $send_response = $read_res($socket);
        fputs($socket, "QUIT\r\n"); fclose($socket);

        return (strpos($send_response, '250') !== false) ? true : false;
    }
}
// ==============================================================

// --- SYSTEM SETTINGS (JSON BASED) ---
$settingsFile = __DIR__ . '/maternityhub_settings.json';
if (!file_exists($settingsFile)) {
    file_put_contents($settingsFile, json_encode([
        'maintenance_mode' => false,
        'super_theme_color' => '#10b981',
        'super_logo' => null,
        'super_hero_image' => null,
        'allow_new_registrations' => true,
        'system_email' => 'support@maternityhub.com',
        'session_timeout' => 30
    ]));
}

$settings = json_decode(file_get_contents($settingsFile), true);
$maintenanceMode = $settings['maintenance_mode'] ?? false;
$superThemeColor = $settings['super_theme_color'] ?? '#10b981';
$superLogo = $settings['super_logo'] ?? null;
$superLogoPath = ($superLogo && file_exists(__DIR__ . '/uploads/logos/' . $superLogo)) ? 'uploads/logos/' . $superLogo : null;

// Contrast Calculator
$hex = ltrim($superThemeColor, '#');
if (strlen($hex) == 3) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; }
$r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
$luminance = ($r * 0.299) + ($g * 0.587) + ($b * 0.114);
$isLightTheme = ($luminance > 150);

$headerTextPrimary = $isLightTheme ? "text-slate-900" : "text-white";
$headerTextSecondary = $isLightTheme ? "text-slate-700" : "text-primary-light";
$headerTextMuted = $isLightTheme ? "text-slate-400" : "text-white/50";
$headerBadgeBg = $isLightTheme ? "bg-slate-200 text-slate-800" : "bg-black/20 text-white";
$headerIconBox = $isLightTheme ? "bg-white border-slate-200" : "bg-white/15 border-white/25";
$headerIconColor = $isLightTheme ? "text-slate-700" : "text-white/90";
$headerBtn = $isLightTheme ? "bg-white hover:bg-slate-50 text-slate-800 border-slate-200 shadow-sm" : "bg-white/15 hover:bg-white/25 text-white border-white/30";
$sidebarActive = $isLightTheme ? "bg-slate-800 text-white shadow-md" : "bg-primary/10 text-primary";

// --- SESSION VARIABLES FIRST ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'Patient') {
    header("Location: index.php");
    exit();
}

$displayName = $_SESSION['full_name'] ?? 'User';
$userRole    = $_SESSION['role'] ?? 'Clinic Administrator';
$isStaffRole = (strtolower(trim((string)$userRole)) === 'staff');
$userId      = $_SESSION['user_id'];
$tenant_id   = $_SESSION['TenantID'] ?? null;
$dbError     = null;
$currentPage = basename($_SERVER['PHP_SELF']);

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$baseUrl = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);

// --- OWNER / STAFF ADMIN PERMISSION SYSTEM ---
$normalizedRole = strtolower(trim((string)$userRole));
$currentUserIsOwner = in_array($normalizedRole, ['admin', 'administrator', 'owner', 'owner/midwife'], true);
$currentUserIsStaffAdmin = false;
$currentUserGrantedFeatures = [];
if (!$currentUserIsOwner && $tenant_id) {
    try {
        $stmtCurAccess = $pdo->prepare("SELECT cs.is_admin, cs.granted_features FROM clinic_staff cs INNER JOIN users u ON LOWER(TRIM(cs.email_address)) = LOWER(TRIM(u.email)) WHERE cs.TenantID = ? AND u.id = ? LIMIT 1");
        $stmtCurAccess->execute([$tenant_id, $userId]);
        $curAccess = $stmtCurAccess->fetch(PDO::FETCH_ASSOC);
        if ($curAccess) {
            $currentUserIsStaffAdmin = (bool)($curAccess['is_admin'] ?? false);
            $currentUserGrantedFeatures = json_decode($curAccess['granted_features'] ?? '[]', true) ?: [];
        }
    } catch (PDOException $e) {}
}

// --- CHECK IF CURRENT LOGGED-IN USER IS A MIDWIFE ---
$isCurrentUserMidwife = false;
$_mwRoleCheck = strtolower(trim((string)$userRole));
if ($_mwRoleCheck === 'midwife' || strpos($_mwRoleCheck, 'midwife') !== false) {
    $isCurrentUserMidwife = true;
}
// Always also check the also_midwife flag on the users row for the current logged-in user.
// This makes the owner -> midwife toggle (staffmanagement.php) work regardless of what the
// session role string currently happens to be (e.g. 'Owner', 'Owner/Midwife',
// 'Clinic Administrator', etc.).
if (!$isCurrentUserMidwife && $tenant_id && !empty($userId)) {
    try {
        $_stmtAnyMw = $pdo->prepare("SELECT COALESCE(also_midwife, 0) FROM users WHERE id = ? AND TenantID = ? LIMIT 1");
        $_stmtAnyMw->execute([$userId, $tenant_id]);
        if ((int)$_stmtAnyMw->fetchColumn() === 1) {
            $isCurrentUserMidwife = true;
        }
    } catch (PDOException $e) {}
}
// Final fallback: match by display name against active midwife staff.
if (!$isCurrentUserMidwife && !empty($displayName) && $tenant_id) {
    try {
        $_stmtMwCheck = $pdo->prepare("SELECT COUNT(*) FROM clinic_staff WHERE TenantID = ? AND status = 'Active' AND LOWER(TRIM(COALESCE(role, ''))) = 'midwife' AND (LOWER(TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')))) = LOWER(?) OR LOWER(TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(middle_name,''),' ',COALESCE(last_name,'')))) = LOWER(?))");
        $_stmtMwCheck->execute([$tenant_id, $displayName, $displayName]);
        $isCurrentUserMidwife = ((int)$_stmtMwCheck->fetchColumn() > 0);
    } catch (PDOException $e) {}
}

// --- CHECK IF CURRENT LOGGED-IN USER IS A RECEPTIONIST ---
// Receptionists can record vitals / start checkup BUT NOT for labor services.
$isCurrentUserReceptionist = false;
$_recRoleCheck = strtolower(trim((string)$userRole));
if (strpos($_recRoleCheck, 'receptionist') !== false) {
    $isCurrentUserReceptionist = true;
}
if (!$isCurrentUserReceptionist && !empty($displayName) && $tenant_id) {
    try {
        $_stmtRecCheck = $pdo->prepare("SELECT COUNT(*) FROM clinic_staff WHERE TenantID = ? AND status = 'Active' AND LOWER(TRIM(COALESCE(role, ''))) = 'receptionist' AND (LOWER(TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')))) = LOWER(?) OR LOWER(TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(middle_name,''),' ',COALESCE(last_name,'')))) = LOWER(?))");
        $_stmtRecCheck->execute([$tenant_id, $displayName, $displayName]);
        $isCurrentUserReceptionist = ((int)$_stmtRecCheck->fetchColumn() > 0);
    } catch (PDOException $e) {}
}

// Helper: is a given service reason a "labor" service (which receptionists are blocked from)?
function mh_is_labor_service($reason) {
    $r = strtolower((string)$reason);
    return (strpos($r, 'labor') !== false);
}

// Helper: can the current user record vitals / start checkup for this admission's service?
// - Midwife: always yes
// - Receptionist: yes UNLESS the service is a labor service
$canRecordVitalsFor = function($reason) use ($isCurrentUserMidwife, $isCurrentUserReceptionist) {
    if ($isCurrentUserMidwife) return true;
    if ($isCurrentUserReceptionist && !mh_is_labor_service($reason)) return true;
    return false;
};

// --- SET DISPLAY ROLE BASED ON PERMISSION ---
if ($currentUserIsOwner) { $displayRole = $isCurrentUserMidwife ? 'Owner / Midwife' : 'Owner'; }
elseif ($currentUserIsStaffAdmin) { $displayRole = $userRole . ' | Admin'; }
else { $displayRole = $userRole; }

// =========================================================================
// AJAX HANDLERS (MUST BE BEFORE HTML OUTPUT)
// =========================================================================

//  NEW: AJAX HANDLER PARA SA "REGISTER ANOTHER BABY"
if (isset($_POST['ajax_register_infant']) && isset($_POST['nb_adm_id'])) {
    header('Content-Type: application/json');
    if (!$isCurrentUserMidwife) {
        echo json_encode(['success' => false, 'message' => 'Only Midwife can register a baby.']);
        exit;
    }
    $adm_id = intval($_POST['nb_adm_id']);
    $mom_patient_id = trim($_POST['nb_mom_patient_id']);
    $infant_name = trim($_POST['infant_name'] ?? '');
    $gender = trim($_POST['gender'] ?? 'Unknown');
    $birth_date = $_POST['birth_date'] ?? date('Y-m-d');
    $birth_time = $_POST['birth_time'] ?? date('H:i');
    $weight_kg = floatval($_POST['weight_kg'] ?? 0);
    $length_cm = floatval($_POST['length_cm'] ?? 0);
    $apgar_score = trim($_POST['apgar_score'] ?? '');
    $delivery_method = trim($_POST['delivery_method'] ?? 'Normal Delivery');
    $attending_staff = trim($_POST['attending_staff'] ?? 'Unassigned');
    $location_option = trim($_POST['infant_location_option'] ?? 'rooming_in');
    $nursery_bed_id = intval($_POST['nursery_bed_id'] ?? 0);

    try {
        $pdo->beginTransaction();

        $isCesarean = (stripos($delivery_method, 'cesarean') !== false || stripos($delivery_method, 'c-section') !== false);
        if ($isCesarean) {
            $location_option = 'nursery';
        }

        $linked_room_id = null;
        $linked_bed_id = null;

        if ($location_option === 'nursery') {
            if ($nursery_bed_id <= 0) {
                throw new RuntimeException('Please select a nursery bed.');
            }

            $stmtNurseryBed = $pdo->prepare("SELECT b.id, b.room_id, b.bed_status FROM clinic_room_beds b INNER JOIN clinic_rooms r ON r.id = b.room_id AND r.TenantID = b.TenantID WHERE b.id = ? AND b.TenantID = ? AND r.room_type = 'infant_ward' LIMIT 1");
            $stmtNurseryBed->execute([$nursery_bed_id, $tenant_id]);
            $nurseryBed = $stmtNurseryBed->fetch(PDO::FETCH_ASSOC);
            if (!$nurseryBed || strtolower((string)$nurseryBed['bed_status']) !== 'available') {
                throw new RuntimeException('Selected nursery bed is no longer available.');
            }

            $linked_room_id = (int)$nurseryBed['room_id'];
            $linked_bed_id = (int)$nurseryBed['id'];
        } else {
            $stmtMotherBed = $pdo->prepare("SELECT assigned_room_id, assigned_bed_id FROM admissions WHERE id = ? AND TenantID = ? LIMIT 1");
            $stmtMotherBed->execute([$adm_id, $tenant_id]);
            $motherBed = $stmtMotherBed->fetch(PDO::FETCH_ASSOC);
            $linked_room_id = (int)($motherBed['assigned_room_id'] ?? 0);
            $linked_bed_id = (int)($motherBed['assigned_bed_id'] ?? 0);

            if ($linked_bed_id <= 0) {
                throw new RuntimeException('Mother bed location not found.');
            }
        }

        $stmtMom = $pdo->prepare("SELECT full_name, address, contact_number, husband_name FROM patients WHERE patient_id = ? AND TenantID = ? LIMIT 1");
        $stmtMom->execute([$mom_patient_id, $tenant_id]);
        $mom = $stmtMom->fetch(PDO::FETCH_ASSOC);

        $mom_name = $mom ? $mom['full_name'] : '';
        $father_name = $mom ? $mom['husband_name'] : '';
        $address = $mom ? $mom['address'] : '';
        $contact = $mom ? $mom['contact_number'] : '';

        // Dito pumapasok sa infants table (Gaya ng gusto mo: "just in the infants" for the immediate save, tapos gagawa rin ng sarili niyang record sa patients)
        $stmtInfant = $pdo->prepare("
            INSERT INTO infants (TenantID, mother_patient_id, admission_id, infant_name, gender, birth_date, birth_time, weight_kg, length_cm, apgar_score, delivery_method, attending_staff, location_option, linked_room_id, linked_bed_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtInfant->execute([$tenant_id, $mom_patient_id, $adm_id, $infant_name, $gender, $birth_date, $birth_time, $weight_kg, $length_cm, $apgar_score, $delivery_method, $attending_staff, $location_option, $linked_room_id, $linked_bed_id]);

        // Auto-update maternal GP only once per admission after successful delivery registration.
        // Rule: increment PARA by 1, then keep GRAVIDA at least equal to PARA.
        try {
            $stmtGpFlag = $pdo->prepare("SELECT gp_delivery_counted FROM admissions WHERE id = ? AND TenantID = ? LIMIT 1 FOR UPDATE");
            $stmtGpFlag->execute([$adm_id, $tenant_id]);
            $gpAlreadyCounted = (int)$stmtGpFlag->fetchColumn();

            if ($gpAlreadyCounted === 0) {
                $stmtMomGp = $pdo->prepare("SELECT id, gravida, para FROM patients WHERE patient_id = ? AND TenantID = ? LIMIT 1 FOR UPDATE");
                $stmtMomGp->execute([$mom_patient_id, $tenant_id]);
                $momGp = $stmtMomGp->fetch(PDO::FETCH_ASSOC);

                if ($momGp) {
                    $curG = isset($momGp['gravida']) ? (int)$momGp['gravida'] : 0;
                    $curP = isset($momGp['para']) ? (int)$momGp['para'] : 0;
                    $newP = max(0, $curP + 1);
                    $newG = max(0, max($curG, $newP));

                    $stmtSetGp = $pdo->prepare("UPDATE patients SET gravida = ?, para = ? WHERE id = ? AND TenantID = ?");
                    $stmtSetGp->execute([$newG, $newP, (int)$momGp['id'], $tenant_id]);
                }

                $pdo->prepare("UPDATE admissions SET gp_delivery_counted = 1 WHERE id = ? AND TenantID = ?")
                    ->execute([$adm_id, $tenant_id]);
            }
        } catch (Throwable $e) {}

        // After delivery: clear mother's maternity tracking fields (LMP / EDD / pregnancy_status)
        try {
            $stmtClearPreg = $pdo->prepare("UPDATE patients SET last_menstrual_period = NULL, estimated_delivery_date = NULL, pregnancy_status = NULL WHERE patient_id = ? AND TenantID = ?");
            $stmtClearPreg->execute([$mom_patient_id, $tenant_id]);
        } catch (Throwable $e) {}

        if ($location_option === 'nursery' && $linked_bed_id > 0) {
            $stmtBedUpdate = $pdo->prepare("UPDATE clinic_room_beds SET bed_status = 'occupied', patient_name = ? WHERE id = ? AND TenantID = ?");
            $stmtBedUpdate->execute([$infant_name, $linked_bed_id, $tenant_id]);

            // Add Infant Ward fee to mother's admission bill (idempotent — only first nursery assignment per admission)
            $stmtNurseryFee = $pdo->prepare("SELECT price FROM clinic_room_subtype_prices WHERE TenantID = ? AND room_type = 'infant_ward' AND room_subtype = 'default' LIMIT 1");
            $stmtNurseryFee->execute([$tenant_id]);
            $nurseryFeeAmount = (float)($stmtNurseryFee->fetchColumn() ?: 0);
            applyRoomChargeOnce($pdo, $tenant_id, $adm_id, 'infant_ward', $nurseryFeeAmount);
        }

        // Note: no separate baby patient record is created here;
        // newborn details stay only in the infants table linked to the mother.

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => "Baby $infant_name registered successfully."]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage() ?: 'Failed to register baby.']);
    }
    exit;
}

if (isset($_POST['ajax_check_stage']) && isset($_POST['adm_id'])) {
    $a_id = intval($_POST['adm_id']);
    try {
        $stmt = $pdo->prepare("SELECT stage FROM admissions WHERE id = ? AND TenantID = ?");
        $stmt->execute([$a_id, $tenant_id]);
        echo json_encode(['stage' => $stmt->fetchColumn()]);
    } catch (Exception $e) {
        echo json_encode(['stage' => 'Error']);
    }
    exit;
}

if (isset($_POST['ajax_update_method']) && isset($_POST['adm_id']) && isset($_POST['method'])) {
    $a_id = intval($_POST['adm_id']);
    $meth = trim($_POST['method']);
    try {
        $stmt = $pdo->prepare("UPDATE admissions SET payment_method = ? WHERE id = ? AND TenantID = ?");
        $stmt->execute([$meth, $a_id, $tenant_id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false]);
    }
    exit;
}

if (isset($_POST['ajax_update_balance']) && isset($_POST['adm_id']) && isset($_POST['new_balance'])) {
    $a_id = intval($_POST['adm_id']);
    $n_bal = floatval($_POST['new_balance']);
    try {
        $stmt = $pdo->prepare("UPDATE admissions SET remaining_balance = ? WHERE id = ? AND TenantID = ?");
        $stmt->execute([$n_bal, $a_id, $tenant_id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false]);
    }
    exit;
}

if (isset($_POST['ajax_save_receipt_image']) && isset($_POST['receipt_id'])) {
    header('Content-Type: application/json');

    $paymentId = intval($_POST['payment_id'] ?? 0);
    $receiptId = trim($_POST['receipt_id'] ?? '');
    $admId = isset($_POST['adm_id']) ? intval($_POST['adm_id']) : 0;

    // receipt_id at image lang ang required; payment_id optional
    if ($receiptId === '' || !isset($_FILES['receipt_image'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid input']);
        exit;
    }

    if (!isset($_FILES['receipt_image']) || $_FILES['receipt_image']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Upload failed']);
        exit;
    }

    $safeReceiptId = preg_replace('/[^A-Za-z0-9_\-]/', '_', $receiptId);
    $uploadDir = __DIR__ . '/uploads/receipts/';
    if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0777, true); }

    $targetPath = $uploadDir . $safeReceiptId . '.jpg';
    if (!move_uploaded_file($_FILES['receipt_image']['tmp_name'], $targetPath)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save file']);
        exit;
    }

    $relativePath = 'uploads/receipts/' . $safeReceiptId . '.jpg';

    try {
        // Store direct JPG path in payments (if may payment record) and admissions
        if ($paymentId > 0) {
            $stmt = $pdo->prepare("UPDATE payments SET receipt = ? WHERE id = ? AND TenantID = ?");
            $stmt->execute([$relativePath, $paymentId, $tenant_id]);
        }

        if ($admId > 0) {
            $stmtAdm = $pdo->prepare("UPDATE admissions SET receipt = ? WHERE id = ? AND TenantID = ?");
            $stmtAdm->execute([$relativePath, $admId, $tenant_id]);
        }

        echo json_encode(['success' => true, 'path' => $relativePath]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit;
}

if (isset($_POST['ajax_save_receipt_pdf']) && isset($_POST['receipt_id'])) {
    header('Content-Type: application/json');

    $paymentId = intval($_POST['payment_id'] ?? 0);
    $receiptId = trim($_POST['receipt_id'] ?? '');
    $admId = isset($_POST['adm_id']) ? intval($_POST['adm_id']) : 0;

    if ($receiptId === '' || !isset($_FILES['receipt_pdf'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid input']);
        exit;
    }

    if (!isset($_FILES['receipt_pdf']) || $_FILES['receipt_pdf']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Upload failed']);
        exit;
    }

    $safeReceiptId = preg_replace('/[^A-Za-z0-9_\-]/', '_', $receiptId);
    $uploadDir = __DIR__ . '/uploads/receipts/';
    if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0777, true); }

    $targetPath = $uploadDir . $safeReceiptId . '.pdf';
    if (!move_uploaded_file($_FILES['receipt_pdf']['tmp_name'], $targetPath)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save file']);
        exit;
    }

    $relativePath = 'uploads/receipts/' . $safeReceiptId . '.pdf';

    try {
        $dbValue = $receiptId . '|' . $relativePath;
        if ($paymentId > 0) {
            $stmt = $pdo->prepare("UPDATE payments SET receipt = ? WHERE id = ? AND TenantID = ?");
            $stmt->execute([$dbValue, $paymentId, $tenant_id]);
        }

        //  UPDATE ADMISSIONS TABLE TO SAVE RECEIPT CODE + URL
        if ($admId > 0) {
            $stmtAdm = $pdo->prepare("UPDATE admissions SET receipt = ? WHERE id = ? AND TenantID = ?");
            $stmtAdm->execute([$dbValue, $admId, $tenant_id]);
        }

        echo json_encode(['success' => true, 'path' => $relativePath]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit;
}

if (isset($_POST['ajax_finalize_discharge']) && isset($_POST['adm_id'])) {
    header('Content-Type: application/json');

    $admId = intval($_POST['adm_id']);
    $paymentMethod = trim($_POST['payment_method'] ?? '');

    if ($admId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid admission id']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("UPDATE admissions SET status = 'Discharged', stage = 'Discharged', payment_type = 'Fully Paid', remaining_balance = 0, payment_method = COALESCE(NULLIF(payment_method, ''), ?), discharge_date = NOW() WHERE id = ? AND TenantID = ?");
        $stmt->execute([$paymentMethod, $admId, $tenant_id]);

        // Release any retained recovery-room bed now that payment is fully completed.
        if (function_exists('releaseAdmissionBedToCleaning')) {
            releaseAdmissionBedToCleaning($pdo, $tenant_id, $admId);
            $pdo->prepare("UPDATE admissions SET assigned_room_id = NULL, assigned_bed_id = NULL, assigned_room_type = NULL WHERE id = ? AND TenantID = ?")
                ->execute([$admId, $tenant_id]);
        }

        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to finalize discharge']);
    }
    exit;
}

// =========================================================================
// 2. AUTHENTICATION & SESSION MANAGEMENT
// =========================================================================
session_start();

// --- AJAX: CHECK STAFF AVAILABILITY FOR FOLLOW-UP ---
if (isset($_GET['action']) && $_GET['action'] === 'check_staff_availability') {
    header('Content-Type: application/json');
    $tenant_id = $_SESSION['TenantID'] ?? null;
    $fu_date = $_GET['date'] ?? '';
    $fu_time = $_GET['time'] ?? '';
    $result = [];
    try {
        // Get all clinic staff (midwife only)
        $stmtS = $pdo->prepare("
            SELECT first_name, middle_name, last_name, role FROM clinic_staff WHERE TenantID = ? AND status = 'Active' AND LOWER(TRIM(COALESCE(role, ''))) = 'midwife' ORDER BY first_name ASC
        ");
        $stmtS->execute([$tenant_id]);
        $allStaff = $stmtS->fetchAll(PDO::FETCH_ASSOC);

        // Find busy staff: those with appointments at the same date & time
        $busyStaffNames = [];
        if (!empty($fu_date) && !empty($fu_time)) {
            $stmtBusy = $pdo->prepare("SELECT assigned_staff, full_name FROM appointments WHERE TenantID = ? AND appointment_date = ? AND appointment_time = ? AND status != 'Cancelled' AND COALESCE(TRIM(assigned_staff), '') <> '' AND LOWER(TRIM(assigned_staff)) <> 'unassigned'");
            $stmtBusy->execute([$tenant_id, $fu_date, $fu_time]);
            foreach ($stmtBusy->fetchAll(PDO::FETCH_ASSOC) as $b) {
                $busyStaffNames[strtolower(trim($b['assigned_staff']))] = $b['full_name'];
            }
            // Also check admissions on same date
            $stmtBusyAdm = $pdo->prepare("SELECT assigned_staff, full_name FROM admissions WHERE TenantID = ? AND DATE(admission_date) = ? AND COALESCE(TRIM(assigned_staff), '') <> '' AND LOWER(TRIM(assigned_staff)) <> 'unassigned' AND status <> 'Discharged' AND stage <> 'Discharged' AND (is_archived = 0 OR is_archived IS NULL)");
            $stmtBusyAdm->execute([$tenant_id, $fu_date]);
            foreach ($stmtBusyAdm->fetchAll(PDO::FETCH_ASSOC) as $b) {
                $busyStaffNames[strtolower(trim($b['assigned_staff']))] = $b['full_name'];
            }
        }

        foreach ($allStaff as $s) {
            $parts = array_filter([$s['first_name'] ?? '', $s['middle_name'] ?? '', $s['last_name'] ?? '']);
            $name = trim(implode(' ', $parts));
            $key = strtolower($name);
            $isBusy = isset($busyStaffNames[$key]);
            $result[] = [
                'name' => $name,
                'role' => $s['role'] ?? '',
                'busy' => $isBusy,
                'busy_patient' => $isBusy ? $busyStaffNames[$key] : ''
            ];
        }
    } catch (PDOException $e) {}
    echo json_encode($result);
    exit();
}

// --- BACKEND LOGOUT LOGIC ---
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    if (isset($_SESSION['full_name'])) {
        try {
            $logoutName = $_SESSION['full_name'];
            $logoutRole = $_SESSION['role'] ?? 'User';
            $isSuperAdmin = (strtolower(trim((string)$logoutRole)) === 'superadmin' || strpos(strtolower((string)$logoutName), 'eirean') !== false);
            $auditRole = $isSuperAdmin ? 'SuperAdmin' : $logoutRole;
            $auditTenant = $isSuperAdmin ? null : ($_SESSION['TenantID'] ?? null);
            $auditDetails = $isSuperAdmin ? 'Super Admin safely logged out of the platform.' : 'User securely logged out of their clinic portal.';
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $currentTime = date('Y-m-d H:i:s');

            $stmtLogoutLog = $pdo->prepare("INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, 'Logout', ?, ?, ?)");
            $stmtLogoutLog->execute([$auditTenant, $logoutName, $auditRole, $auditDetails, $ip, $currentTime]);
        } catch (Exception $e) {
            // Silent fail
        }
    }

    // Unset all of the session variables
    $_SESSION = array();

    // If it's desired to kill the session, also delete the session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    // Finally, destroy the session
    session_destroy();

    // Redirect to login page, preserving clinic code if available
    $clinicCode = $_GET['c'] ?? 'N/A';
    if (!empty($clinicCode) && $clinicCode !== 'N/A') {
        header("Location: tenant_login.php?c=" . urlencode($clinicCode));
    } else {
        header("Location: tenant_login.php");
    }
    exit();
}


// Security check: ensure user is logged in and is not a patient
if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'Patient') {
    header("Location: index.php");
    exit();
}

$displayName = $_SESSION['full_name'] ?? 'User';
$userRole    = $_SESSION['role'] ?? 'Clinic Administrator';
$displayRole = $userRole;
if ($currentUserIsOwner) { $displayRole = $isCurrentUserMidwife ? 'Owner / Midwife' : 'Owner'; }
elseif ($currentUserIsStaffAdmin) { $displayRole = $userRole . ' | Admin'; }
$isStaffRole = (strtolower(trim((string)$userRole)) === 'staff');
$userId      = $_SESSION['user_id'];
$tenant_id   = $_SESSION['TenantID'] ?? null;
$dbError     = null;
$currentPage = basename($_SERVER['PHP_SELF']);

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$baseUrl = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);

// =========================================================================
// 1. FETCH CLINIC DATA
// =========================================================================
$clinicName = "MaternityHub";
$clinicCode = "N/A";
$clinicLogo = null;
$themeColor = $superThemeColor;

if ($tenant_id) {
    try {
        $stmtClinic = $pdo->prepare("SELECT clinic_name, clinic_code, clinic_logo, theme_color, opening_time, closing_time FROM tenants WHERE TenantID = ?");
        $stmtClinic->execute([$tenant_id]);
        $clinicData = $stmtClinic->fetch(PDO::FETCH_ASSOC);

        if ($clinicData) {
            $clinicName = $clinicData['clinic_name'];
            $clinicCode = $clinicData['clinic_code'] ?? "N/A";
            if (!empty($clinicData['clinic_logo']) && file_exists(__DIR__ . '/uploads/logos/' . $clinicData['clinic_logo'])) {
                $clinicLogo = 'uploads/logos/' . $clinicData['clinic_logo'];
            }
            if (!empty($clinicData['theme_color'])) {
                $themeColor = $clinicData['theme_color'];
            }
        }
    } catch (PDOException $e) {}
}

// --- CLINIC OPERATING HOURS FOR TIME SLOT PICKER ---
$clinicOpeningTime = !empty($clinicData['opening_time']) ? date('H:i', strtotime($clinicData['opening_time'])) : '08:00';
$clinicClosingTime = !empty($clinicData['closing_time']) ? date('H:i', strtotime($clinicData['closing_time'])) : '17:00';

// Generate 1-hour time slots
$timeSlots = [];
$slotStart = new DateTime($clinicOpeningTime);
$slotEnd = new DateTime($clinicClosingTime);
while ($slotStart < $slotEnd) {
    $nextHour = clone $slotStart;
    $nextHour->modify('+1 hour');
    if ($nextHour > $slotEnd) break;
    $timeSlots[] = [
        'value' => $slotStart->format('H:i'),
        'label' => $slotStart->format('g:i A') . ' - ' . $nextHour->format('g:i A')
    ];
    $slotStart = $nextHour;
}

try {
    $stmtPic = $pdo->prepare("SELECT u.first_name, u.middle_name, u.last_name, COALESCE(u.profile_image, cs.profile_image) AS profile_image FROM users u LEFT JOIN clinic_staff cs ON cs.TenantID = u.TenantID AND LOWER(TRIM(COALESCE(cs.email_address, ''))) = LOWER(TRIM(COALESCE(u.email, ''))) WHERE u.id = ? LIMIT 1");
    $stmtPic->execute([$userId]);
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

// =========================================================================
// FETCH CLINIC SERVICES & STAFF (WITH TOGGLE LOGIC)
// =========================================================================
$clinicServices = [];
$servicePrices = [];
$servicePhilhealthRates = [];
$isTransvaginalActive = false;
$isPelvicActive = false;
$hasLaborService = false;

try {
    $stmtSrv = $pdo->prepare("SELECT * FROM clinic_services WHERE TenantID = ? ORDER BY service_name ASC");
    $stmtSrv->execute([$tenant_id]);
    $allSrv = $stmtSrv->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allSrv as $srv) {
        $isActive = isset($srv['is_active']) ? (int)$srv['is_active'] : 1;
        $serviceName = trim($srv['service_name']);

        // I-save lahat ng prices para hindi mag-error ang mga lumang records
        $servicePrices[$serviceName] = (float)$srv['price'];
        $servicePhilhealthRates[$serviceName] = (float)($srv['philhealth_rate'] ?? 0);

        // Kung Active/Naka-ON, idagdag sa dropdown
        // Delivery flow now starts with Labor only.
        if ($isActive === 1) {
            if ($serviceName === 'Labor') {
                $hasLaborService = true;
                $clinicServices[] = $srv;
            } elseif ($serviceName === 'Normal Delivery' || $serviceName === 'Cesarean Delivery') {
                // Keep prices loaded but hide direct selection from Admission form.
            } else {
                $clinicServices[] = $srv;
            }
        }

        // I-check ang status ng Ultrasound para sa checkup modal
        if ($serviceName === 'Transvaginal Ultrasound' && $isActive === 1) {
            $isTransvaginalActive = true;
        }
        if ($serviceName === 'Pelvic Ultrasound' && $isActive === 1) {
            $isPelvicActive = true;
        }
    }

    // If delivery prices exist but Labor service is not configured as active,
    // still show Labor as the admission entry point.
    if (!$hasLaborService && (array_key_exists('Normal Delivery', $servicePrices) || array_key_exists('Cesarean Delivery', $servicePrices))) {
        $clinicServices[] = [
            'id' => 0,
            'service_name' => 'Labor',
            'price' => 0,
            'is_active' => 1
        ];
    }

    if (!array_key_exists('Labor', $servicePrices)) {
        $servicePrices['Labor'] = 0;
    }
    if (!array_key_exists('Labor', $servicePhilhealthRates)) {
        $servicePhilhealthRates['Labor'] = 0;
    }
} catch (PDOException $e) {}

$existingPatients = [];
try {
    $stmtPat = $pdo->prepare("
        SELECT p.patient_id, p.full_name, p.husband_name, p.birthday, p.age, p.religion, p.mother_name, p.father_name, p.contact_number, p.address,
               p.guardian_id_url, p.email_address, p.last_menstrual_period, p.estimated_delivery_date, p.pregnancy_status,
               CASE WHEN adm_active.id IS NOT NULL THEN 1 ELSE 0 END AS is_admitted
        FROM patients p
        LEFT JOIN admissions adm_active ON adm_active.TenantID = p.TenantID AND adm_active.patient_id = p.patient_id
            AND adm_active.status <> 'Discharged' AND adm_active.stage <> 'Discharged'
            AND (adm_active.is_archived = 0 OR adm_active.is_archived IS NULL)
        WHERE p.TenantID = ?
          AND (p.account_status IS NULL OR p.account_status != 'Pending')
        GROUP BY p.patient_id
        ORDER BY p.full_name ASC
    ");
    $stmtPat->execute([$tenant_id]);
    $existingPatients = $stmtPat->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$clinicStaffList = [];
$totalClinicStaff = 0;
try {
    if ($tenant_id) {
        $stmtStaff = $pdo->prepare("
            SELECT first_name, middle_name, last_name, role FROM clinic_staff WHERE TenantID = ? AND status = 'Active' AND LOWER(TRIM(COALESCE(role, ''))) = 'midwife' ORDER BY first_name ASC
        ");
        $stmtStaff->execute([$tenant_id]);
        $clinicStaffList = $stmtStaff->fetchAll(PDO::FETCH_ASSOC);
        // Include any user (owner/admin) flagged with also_midwife = 1 via staff management.
        // The role filter is intentionally loose here — if the user has the flag, they can act as a midwife.
        $stmtOwnerMw = $pdo->prepare("SELECT first_name, middle_name, last_name, role FROM users WHERE TenantID = ? AND COALESCE(also_midwife, 0) = 1");
        $stmtOwnerMw->execute([$tenant_id]);
        $ownerMwRows = $stmtOwnerMw->fetchAll(PDO::FETCH_ASSOC);
        foreach ($ownerMwRows as $ownerMw) {
            // Skip if a duplicate name is already in the midwife list
            $ownerFullLower = strtolower(trim($ownerMw['first_name'] . ' ' . ($ownerMw['middle_name'] ? $ownerMw['middle_name'] . ' ' : '') . $ownerMw['last_name']));
            $alreadyIn = false;
            foreach ($clinicStaffList as $existSt) {
                $existFullLower = strtolower(trim($existSt['first_name'] . ' ' . (!empty($existSt['middle_name']) ? $existSt['middle_name'] . ' ' : '') . $existSt['last_name']));
                if ($ownerFullLower !== '' && $ownerFullLower === $existFullLower) { $alreadyIn = true; break; }
            }
            if (!$alreadyIn) {
                $clinicStaffList[] = ['first_name' => $ownerMw['first_name'], 'middle_name' => $ownerMw['middle_name'], 'last_name' => $ownerMw['last_name'], 'role' => 'Owner / Midwife'];
            }
        }
        $totalClinicStaff = count($clinicStaffList);
    }
} catch (PDOException $e) {}

// Load labor and delivery rooms with beds
$laborRoomsWithBeds = [];
$deliveryRoomsWithBeds = [];
$recoveryRoomsWithBeds = [];
$infantWardAvailableBeds = [];
try {
    if ($tenant_id) {
        $stmtLaborRooms = $pdo->prepare("SELECT id, room_name, COALESCE(LOWER(room_subtype),'') AS room_subtype FROM clinic_rooms WHERE TenantID = ? AND room_type = 'labor_room' ORDER BY room_name ASC");
        $stmtLaborRooms->execute([$tenant_id]);
        $laborRoomsWithBeds = $stmtLaborRooms->fetchAll(PDO::FETCH_ASSOC);

        // Load labor subtype prices for display + billing
        $laborSubtypePrices = ['regular' => 0.00, 'private' => 0.00, 'large_private' => 0.00];
        try {
            $stmtLaborPrices = $pdo->prepare("SELECT room_subtype, price FROM clinic_room_subtype_prices WHERE TenantID = ? AND room_type = 'labor_room'");
            $stmtLaborPrices->execute([$tenant_id]);
            foreach ($stmtLaborPrices->fetchAll(PDO::FETCH_ASSOC) as $lp) {
                $lk = strtolower((string)($lp['room_subtype'] ?? ''));
                if (isset($laborSubtypePrices[$lk])) {
                    $laborSubtypePrices[$lk] = (float)($lp['price'] ?? 0);
                }
            }
        } catch (PDOException $e) {}

        $laborSubtypeLabelsMap = ['regular' => 'Basic', 'private' => 'Semi-Private', 'large_private' => 'Private'];

        foreach ($laborRoomsWithBeds as &$room) {
            $stmtBeds = $pdo->prepare("SELECT id, bed_label, bed_status FROM clinic_room_beds WHERE TenantID = ? AND room_id = ? ORDER BY id ASC");
            $stmtBeds->execute([$tenant_id, $room['id']]);
            $room['beds'] = $stmtBeds->fetchAll(PDO::FETCH_ASSOC);
            $stKey = strtolower((string)($room['room_subtype'] ?? ''));
            $room['subtype_label'] = $laborSubtypeLabelsMap[$stKey] ?? '';
            $room['subtype_price'] = isset($laborSubtypePrices[$stKey]) ? (float)$laborSubtypePrices[$stKey] : 0.00;
        }
        unset($room);

        $stmtDeliveryRooms = $pdo->prepare("SELECT id, room_name FROM clinic_rooms WHERE TenantID = ? AND room_type = 'delivery_room' ORDER BY room_name ASC");
        $stmtDeliveryRooms->execute([$tenant_id]);
        $deliveryRoomsWithBeds = $stmtDeliveryRooms->fetchAll(PDO::FETCH_ASSOC);

        foreach ($deliveryRoomsWithBeds as &$room) {
            $stmtBeds = $pdo->prepare("SELECT id, bed_label, bed_status FROM clinic_room_beds WHERE TenantID = ? AND room_id = ? ORDER BY id ASC");
            $stmtBeds->execute([$tenant_id, $room['id']]);
            $room['beds'] = $stmtBeds->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($room);

        $stmtRecoveryRooms = $pdo->prepare("SELECT id, room_name, COALESCE(LOWER(room_subtype),'') AS room_subtype FROM clinic_rooms WHERE TenantID = ? AND room_type = 'recovery_room' ORDER BY room_name ASC");
        $stmtRecoveryRooms->execute([$tenant_id]);
        $recoveryRoomsWithBeds = $stmtRecoveryRooms->fetchAll(PDO::FETCH_ASSOC);

        // Load recovery subtype prices for display + billing
        $recoverySubtypePrices = ['regular' => 0.00, 'private' => 0.00, 'large_private' => 0.00];
        try {
            $stmtRecPrices = $pdo->prepare("SELECT room_subtype, price FROM clinic_room_subtype_prices WHERE TenantID = ? AND room_type = 'recovery_room'");
            $stmtRecPrices->execute([$tenant_id]);
            foreach ($stmtRecPrices->fetchAll(PDO::FETCH_ASSOC) as $rp) {
                $rk = strtolower((string)($rp['room_subtype'] ?? ''));
                if (isset($recoverySubtypePrices[$rk])) {
                    $recoverySubtypePrices[$rk] = (float)($rp['price'] ?? 0);
                }
            }
        } catch (PDOException $e) {}

        // Load default per-room-type prices (labor / delivery / infant ward) — single price per type, stored under room_subtype = 'default'
        $defaultRoomPrices = ['labor_room' => 0.00, 'delivery_room' => 0.00, 'infant_ward' => 0.00];
        try {
            $stmtDefRoomPrices = $pdo->prepare("SELECT room_type, price FROM clinic_room_subtype_prices WHERE TenantID = ? AND room_subtype = 'default'");
            $stmtDefRoomPrices->execute([$tenant_id]);
            foreach ($stmtDefRoomPrices->fetchAll(PDO::FETCH_ASSOC) as $dp) {
                $dk = strtolower((string)($dp['room_type'] ?? ''));
                if (isset($defaultRoomPrices[$dk])) {
                    $defaultRoomPrices[$dk] = (float)($dp['price'] ?? 0);
                }
            }
        } catch (PDOException $e) {}

        $recoverySubtypeLabelsMap = ['regular' => 'Basic', 'private' => 'Semi-Private', 'large_private' => 'Private'];

        foreach ($recoveryRoomsWithBeds as &$room) {
            $stmtBeds = $pdo->prepare("SELECT id, bed_label, bed_status FROM clinic_room_beds WHERE TenantID = ? AND room_id = ? ORDER BY id ASC");
            $stmtBeds->execute([$tenant_id, $room['id']]);
            $room['beds'] = $stmtBeds->fetchAll(PDO::FETCH_ASSOC);
            $stKey = strtolower((string)($room['room_subtype'] ?? ''));
            $room['subtype_label'] = $recoverySubtypeLabelsMap[$stKey] ?? '';
            $room['subtype_price'] = isset($recoverySubtypePrices[$stKey]) ? (float)$recoverySubtypePrices[$stKey] : 0.00;
        }
        unset($room);

        $stmtInfantWardBeds = $pdo->prepare("SELECT b.id AS bed_id, b.room_id, b.bed_label, b.bed_status, r.room_name FROM clinic_room_beds b INNER JOIN clinic_rooms r ON r.id = b.room_id AND r.TenantID = b.TenantID WHERE b.TenantID = ? AND r.room_type = 'infant_ward' ORDER BY r.room_name ASC, b.id ASC");
        $stmtInfantWardBeds->execute([$tenant_id]);
        foreach ($stmtInfantWardBeds->fetchAll(PDO::FETCH_ASSOC) as $ib) {
            if (strtolower((string)($ib['bed_status'] ?? '')) !== 'available') {
                continue;
            }
            $infantWardAvailableBeds[] = [
                'bed_id' => (int)$ib['bed_id'],
                'room_id' => (int)$ib['room_id'],
                'room_name' => (string)($ib['room_name'] ?? ''),
                'bed_label' => (string)($ib['bed_label'] ?? '')
            ];
        }
    }
} catch (PDOException $e) {}

$busyAssignedStaff = [];
try {
    if ($tenant_id) {
        $stmtBusyStaff = $pdo->prepare("SELECT assigned_staff, full_name FROM admissions WHERE TenantID = ? AND COALESCE(TRIM(assigned_staff), '') <> '' AND LOWER(TRIM(assigned_staff)) <> 'unassigned' AND (status <> 'Discharged' AND stage <> 'Discharged') AND (is_archived = 0 OR is_archived IS NULL)");
        $stmtBusyStaff->execute([$tenant_id]);
        foreach ($stmtBusyStaff->fetchAll(PDO::FETCH_ASSOC) as $busyRow) {
            $staffName = trim((string)($busyRow['assigned_staff'] ?? ''));
            if ($staffName === '') { continue; }
            $staffKey = strtolower($staffName);
            if (!isset($busyAssignedStaff[$staffKey])) {
                $busyAssignedStaff[$staffKey] = [
                    'staff_name' => $staffName,
                    'patient_name' => trim((string)($busyRow['full_name'] ?? ''))
                ];
            }
        }
    }
} catch (PDOException $e) {}

// =========================================================================
// 2. HANDLE POST REQUESTS
// =========================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_update_method']) && !isset($_POST['ajax_update_balance']) && !isset($_POST['ajax_check_stage']) && !isset($_POST['ajax_register_infant'])) {

    // TINANGGAL ANG UPDATE STAGE ACTION PARA HINDI MABAGO MANUALLY (Tulad ng instruction mo)
    // if (isset($_POST['update_stage'])) { ... }

    if (isset($_POST['assign_bed_room'])) {
        $adm_id = intval($_POST['admission_id']);
        $assignment_mode = trim((string)($_POST['assignment_mode'] ?? 'labor'));
        $room_id = intval($_POST['room_id'] ?? 0);
        $bed_id = intval($_POST['bed_id'] ?? 0);

        if ($room_id <= 0 || $bed_id <= 0) {
            $dbError = 'Please select both room and bed.';
        } else {
            try {
                $expectedRoomType = 'labor_room';
                $nextStage = 'Delivery Type';
                if ($assignment_mode === 'delivery') {
                    $expectedRoomType = 'delivery_room';
                    $nextStage = 'Recovery Room Assignment';
                } elseif ($assignment_mode === 'recovery') {
                    $expectedRoomType = 'recovery_room';
                    $nextStage = 'Newborn';
                }

                $stmtRoomCheck = $pdo->prepare("SELECT id FROM clinic_rooms WHERE id = ? AND TenantID = ? AND room_type = ? LIMIT 1");
                $stmtRoomCheck->execute([$room_id, $tenant_id, $expectedRoomType]);
                if (!$stmtRoomCheck->fetchColumn()) {
                    throw new RuntimeException('Invalid room selected for this stage.');
                }

                $stmtBedCheck = $pdo->prepare("SELECT id, bed_status FROM clinic_room_beds WHERE id = ? AND TenantID = ? AND room_id = ? LIMIT 1");
                $stmtBedCheck->execute([$bed_id, $tenant_id, $room_id]);
                $bedRow = $stmtBedCheck->fetch(PDO::FETCH_ASSOC);
                if (!$bedRow) {
                    throw new RuntimeException('Invalid bed selected.');
                }

                if (strtolower((string)$bedRow['bed_status']) !== 'available') {
                    throw new RuntimeException('Selected bed is not available.');
                }

                if ($assignment_mode === 'delivery' || $assignment_mode === 'recovery') {
                    $stmtCurrentBed = $pdo->prepare("SELECT assigned_bed_id FROM admissions WHERE id = ? AND TenantID = ? LIMIT 1");
                    $stmtCurrentBed->execute([$adm_id, $tenant_id]);
                    $oldBedId = (int)$stmtCurrentBed->fetchColumn();
                    if ($oldBedId > 0 && $oldBedId !== $bed_id) {
                        $oldBedShouldClean = ($assignment_mode === 'recovery');
                        if ($assignment_mode === 'delivery') {
                            $stmtOldBedRoomType = $pdo->prepare("SELECT r.room_type FROM clinic_room_beds b INNER JOIN clinic_rooms r ON r.id = b.room_id AND r.TenantID = b.TenantID WHERE b.id = ? AND b.TenantID = ? LIMIT 1");
                            $stmtOldBedRoomType->execute([$oldBedId, $tenant_id]);
                            $oldRoomType = strtolower(trim((string)$stmtOldBedRoomType->fetchColumn()));
                            if ($oldRoomType === 'labor_room') {
                                $oldBedShouldClean = true;
                            }
                        }

                        if ($oldBedShouldClean) {
                            $stmtRelease = $pdo->prepare("UPDATE clinic_room_beds SET bed_status = 'cleaning', admission_id = NULL, patient_id = NULL, patient_name = NULL WHERE id = ? AND TenantID = ?");
                        } else {
                            $stmtRelease = $pdo->prepare("UPDATE clinic_room_beds SET bed_status = 'available', admission_id = NULL, patient_id = NULL, patient_name = NULL WHERE id = ? AND TenantID = ?");
                        }
                        $stmtRelease->execute([$oldBedId, $tenant_id]);
                    }
                }

                $stmtAssign = $pdo->prepare("UPDATE admissions SET assigned_room_id = ?, assigned_bed_id = ?, assigned_room_type = ?, stage = ? WHERE id = ? AND TenantID = ?");
                $stmtAssign->execute([$room_id, $bed_id, $expectedRoomType, $nextStage, $adm_id, $tenant_id]);

                // For Recovery Room: capture subtype, look up its price, save subtype + add price to bill (once)
                if ($assignment_mode === 'recovery') {
                    $stmtRoomSub = $pdo->prepare("SELECT COALESCE(LOWER(room_subtype), '') AS room_subtype FROM clinic_rooms WHERE id = ? AND TenantID = ? LIMIT 1");
                    $stmtRoomSub->execute([$room_id, $tenant_id]);
                    $chosenSubtype = (string)$stmtRoomSub->fetchColumn();
                    if (in_array($chosenSubtype, ['regular', 'private', 'large_private'], true)) {
                        $stmtPriceLookup = $pdo->prepare("SELECT price FROM clinic_room_subtype_prices WHERE TenantID = ? AND room_type = 'recovery_room' AND room_subtype = ? LIMIT 1");
                        $stmtPriceLookup->execute([$tenant_id, $chosenSubtype]);
                        $recoveryRoomPrice = (float)($stmtPriceLookup->fetchColumn() ?: 0);

                        $pdo->prepare("UPDATE admissions SET assigned_room_subtype = ? WHERE id = ? AND TenantID = ?")
                            ->execute([$chosenSubtype, $adm_id, $tenant_id]);

                        applyRoomChargeOnce($pdo, $tenant_id, $adm_id, 'recovery_room:' . $chosenSubtype, $recoveryRoomPrice);
                    }
                }

                // For Labor Room: capture subtype, look up its price, save subtype + add subtype-tagged charge to bill (once)
                if ($assignment_mode === 'labor') {
                    $stmtRoomSub = $pdo->prepare("SELECT COALESCE(LOWER(room_subtype), '') AS room_subtype FROM clinic_rooms WHERE id = ? AND TenantID = ? LIMIT 1");
                    $stmtRoomSub->execute([$room_id, $tenant_id]);
                    $chosenLaborSubtype = (string)$stmtRoomSub->fetchColumn();
                    if (in_array($chosenLaborSubtype, ['regular', 'private', 'large_private'], true)) {
                        $stmtPriceLookup = $pdo->prepare("SELECT price FROM clinic_room_subtype_prices WHERE TenantID = ? AND room_type = 'labor_room' AND room_subtype = ? LIMIT 1");
                        $stmtPriceLookup->execute([$tenant_id, $chosenLaborSubtype]);
                        $laborRoomPrice = (float)($stmtPriceLookup->fetchColumn() ?: 0);

                        $pdo->prepare("UPDATE admissions SET assigned_room_subtype = ? WHERE id = ? AND TenantID = ?")
                            ->execute([$chosenLaborSubtype, $adm_id, $tenant_id]);

                        applyRoomChargeOnce($pdo, $tenant_id, $adm_id, 'labor_room:' . $chosenLaborSubtype, $laborRoomPrice);
                    } else {
                        // Legacy fallback: no subtype on this labor room, use the old per-type 'default' price
                        $stmtDefPrice = $pdo->prepare("SELECT price FROM clinic_room_subtype_prices WHERE TenantID = ? AND room_type = 'labor_room' AND room_subtype = 'default' LIMIT 1");
                        $stmtDefPrice->execute([$tenant_id]);
                        $defPriceAmount = (float)($stmtDefPrice->fetchColumn() ?: 0);
                        applyRoomChargeOnce($pdo, $tenant_id, $adm_id, 'labor_room', $defPriceAmount);
                    }
                }

                // For Delivery Room: add the configured default price to bill (once per type)
                if ($assignment_mode === 'delivery') {
                    $chargeType = 'delivery_room';
                    $stmtDefPrice = $pdo->prepare("SELECT price FROM clinic_room_subtype_prices WHERE TenantID = ? AND room_type = ? AND room_subtype = 'default' LIMIT 1");
                    $stmtDefPrice->execute([$tenant_id, $chargeType]);
                    $defPriceAmount = (float)($stmtDefPrice->fetchColumn() ?: 0);
                    applyRoomChargeOnce($pdo, $tenant_id, $adm_id, $chargeType, $defPriceAmount);
                }

                $stmtAdmInfo = $pdo->prepare("SELECT patient_id, full_name FROM admissions WHERE id = ? AND TenantID = ? LIMIT 1");
                $stmtAdmInfo->execute([$adm_id, $tenant_id]);
                $admInfo = $stmtAdmInfo->fetch(PDO::FETCH_ASSOC) ?: [];

                $assignedPatientId = trim((string)($admInfo['patient_id'] ?? ''));
                $assignedPatientName = trim((string)($admInfo['full_name'] ?? ''));

                $stmtBedUpdate = $pdo->prepare("UPDATE clinic_room_beds SET bed_status = 'occupied', admission_id = ?, patient_id = ?, patient_name = ? WHERE id = ? AND TenantID = ?");
                $stmtBedUpdate->execute([$adm_id, $assignedPatientId, $assignedPatientName, $bed_id, $tenant_id]);

                if ($assignment_mode === 'delivery') {
                    header("Location: {$currentPage}?msg=delivery_room_assigned"); exit();
                }

                if ($assignment_mode === 'recovery') {
                    header("Location: {$currentPage}?msg=recovery_room_assigned"); exit();
                }

                header("Location: {$currentPage}?msg=labor_room_assigned"); exit();
            } catch (PDOException $e) {
                $dbError = "Failed to assign bed: " . $e->getMessage();
            } catch (RuntimeException $e) {
                $dbError = $e->getMessage();
            }
        }
    }

    // --- ASSIGN STAFF TO WAITING ADMISSION ---
    if (isset($_POST['assign_staff_to_admission'])) {
        $adm_id = intval($_POST['admission_id'] ?? 0);
        $new_staff = trim($_POST['assign_staff_name'] ?? '');
        if ($adm_id > 0 && !empty($new_staff) && strtolower($new_staff) !== 'unassigned') {
            try {
                // Validate: only midwife can be assigned
                $stmtMidwifeCheck = $pdo->prepare("SELECT COUNT(*) FROM clinic_staff WHERE TenantID = ? AND status = 'Active' AND LOWER(TRIM(COALESCE(role, ''))) = 'midwife' AND (LOWER(TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')))) = LOWER(?) OR LOWER(TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(middle_name,''),' ',COALESCE(last_name,'')))) = LOWER(?))");
                $stmtMidwifeCheck->execute([$tenant_id, $new_staff, $new_staff]);
                $_isMidwife = ((int)$stmtMidwifeCheck->fetchColumn() > 0);
                // Also allow owner / admin with also_midwife flag (regardless of role string)
                if (!$_isMidwife) {
                    $stmtOwnerMwChk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE TenantID = ? AND COALESCE(also_midwife, 0) = 1 AND (LOWER(TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')))) = LOWER(?) OR LOWER(TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(middle_name,''),' ',COALESCE(last_name,'')))) = LOWER(?))");
                    $stmtOwnerMwChk->execute([$tenant_id, $new_staff, $new_staff]);
                    $_isMidwife = ((int)$stmtOwnerMwChk->fetchColumn() > 0);
                }
                if (!$_isMidwife) {
                    $dbError = "Only Midwife staff can be assigned. $new_staff is not a Midwife.";
                    goto skip_assign_staff;
                }

                // Check if staff is busy
                $stmtBusy = $pdo->prepare("SELECT full_name FROM admissions WHERE TenantID = ? AND assigned_staff = ? AND (status <> 'Discharged' AND stage <> 'Discharged') AND (is_archived = 0 OR is_archived IS NULL) LIMIT 1");
                $stmtBusy->execute([$tenant_id, $new_staff]);
                $busyPatient = trim((string)$stmtBusy->fetchColumn());
                if ($busyPatient !== '') {
                    $dbError = "Cannot assign $new_staff. Staff is still handling patient $busyPatient.";
                } else {
                    // Get reason to determine next step for labor
                    $stmtReason = $pdo->prepare("SELECT reason, stage FROM admissions WHERE id = ? AND TenantID = ? LIMIT 1");
                    $stmtReason->execute([$adm_id, $tenant_id]);
                    $admRow = $stmtReason->fetch(PDO::FETCH_ASSOC);
                    $reasonText = strtolower(trim((string)($admRow['reason'] ?? '')));
                    $currentStage = trim((string)($admRow['stage'] ?? ''));

                    $isLaborAdmission = (strpos($reasonText, 'labor') !== false || strpos($reasonText, 'delivery') !== false || strpos($reasonText, 'cesarean') !== false || strpos($reasonText, 'c-section') !== false);

                    $stmt = $pdo->prepare("UPDATE admissions SET assigned_staff = ? WHERE id = ? AND TenantID = ?");
                    $stmt->execute([$new_staff, $adm_id, $tenant_id]);

                    header("Location: {$currentPage}?msg=staff_assigned"); exit();
                }
            } catch (PDOException $e) { $dbError = "Failed to assign staff: " . $e->getMessage(); }
            skip_assign_staff:;
        } else {
            $dbError = "Please select a staff member to assign.";
        }
    }

    if (isset($_POST['start_checkup'])) {
        $adm_id = intval($_POST['admission_id']);
        try {
            // Look up the admission's service so we can enforce per-service permissions.
            $_stmtSC = $pdo->prepare("SELECT reason FROM admissions WHERE id = ? AND TenantID = ?");
            $_stmtSC->execute([$adm_id, $tenant_id]);
            $_admReason = (string)($_stmtSC->fetchColumn() ?: '');
            $_isLaborSvc = mh_is_labor_service($_admReason);

            if (!$isCurrentUserMidwife && !($isCurrentUserReceptionist && !$_isLaborSvc)) {
                if ($isCurrentUserReceptionist && $_isLaborSvc) {
                    $dbError = "Receptionists cannot start a checkup for Labor services. Only a Midwife can perform Labor checkups.";
                } else {
                    $dbError = "Cannot start checkup. Only a Midwife (or Receptionist for non-labor services) can perform checkups. " . htmlspecialchars($displayName) . " is not authorized.";
                }
            } else {

            // Always go to Checkup stage first (record vitals before anything else)
            $stmt = $pdo->prepare("UPDATE admissions SET stage = 'Checkup' WHERE id = ? AND TenantID = ?");
            $stmt->execute([$adm_id, $tenant_id]);
            header("Location: {$currentPage}?msg=stage_updated"); exit();
            } // end role check
        } catch (PDOException $e) { $dbError = "Failed to start checkup: " . $e->getMessage(); }
    }

    elseif (isset($_POST['admit_patient'])) {
        $selected_patient_id = trim($_POST['selected_patient_id'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $spouse_name = trim($_POST['spouse_name'] ?? '');
        $dob = $_POST['dob'] ?? null;
        $religion = trim($_POST['religion'] ?? '');
        $father_name = trim($_POST['father_name'] ?? '');
        $mother_maiden_name = trim($_POST['mother_maiden_name'] ?? '');
        $contact_number = trim($_POST['contact_number'] ?? '');
        if (!preg_match('/^09\d{9}$/', $contact_number)) {
            header("Location: admissions.php?error=contact");
            exit();
        }
        $address = trim($_POST['address'] ?? '');
        $reason = trim($_POST['service_type'] ?? 'General Admission');
        $assigned_staff = trim($_POST['assigned_staff'] ?? 'Unassigned');
        $admission_date = $_POST['admission_date'] ?? date('Y-m-d H:i:s');
        $guardian_id_url = '';

        // LMP / EDD (Naegele's rule)
        $lmp = trim($_POST['last_menstrual_period'] ?? '');
        if ($lmp === '') { $lmp = null; }
        $edd = mh_calculate_edd($lmp);

        // Staff assignment is optional - allow "Assign Later" so admission can proceed and be assigned afterwards
        if (empty($assigned_staff) || strpos(strtolower($assigned_staff), 'no staff') !== false) {
            $assigned_staff = 'Assign Later';
        }

        $price = isset($servicePrices[$reason]) ? (float)$servicePrices[$reason] : 0;
        $age = 0;
        if (!empty($dob)) { $age = (new DateTime())->diff(new DateTime($dob))->y; }

        // Handle guardian ID photo upload for minors
        if ($age < 18) {
            if (!empty($_FILES['guardian_id_photo']['name']) && $_FILES['guardian_id_photo']['error'] === UPLOAD_ERR_OK) {
                $guardianDir = __DIR__ . '/uploads/guardian_ids/';
                if (!is_dir($guardianDir)) { mkdir($guardianDir, 0777, true); }
                $ext = strtolower(pathinfo($_FILES['guardian_id_photo']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                if (!in_array($ext, $allowed)) {
                    $dbError = "Invalid file type. Please upload a JPG, PNG, or WEBP image.";
                } else {
                    $guardianFileName = 'guardian_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (move_uploaded_file($_FILES['guardian_id_photo']['tmp_name'], $guardianDir . $guardianFileName)) {
                        $guardian_id_url = 'uploads/guardian_ids/' . $guardianFileName;
                    } else {
                        $dbError = "Failed to upload guardian ID photo.";
                    }
                }
            } elseif (!empty($_POST['existing_guardian_id_url'])) {
                // Use existing guardian ID from patient record
                $guardian_id_url = trim($_POST['existing_guardian_id_url']);
            } else {
                $dbError = "Guardian ID photo is required for patients under 18 years old.";
            }
        }

        if (empty($dbError) && !empty($full_name)) {
            try {
                $pdo->beginTransaction();

                // Staff assignment is required at admission

                $existingPatient = false;
                if(!empty($selected_patient_id)) {
                    $stmtCheck = $pdo->prepare("SELECT patient_id, full_name FROM patients WHERE patient_id = ? AND TenantID = ? LIMIT 1");
                    $stmtCheck->execute([$selected_patient_id, $tenant_id]);
                    $existingPatient = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                    if ($existingPatient) {
                        $stmtAlready = $pdo->prepare("SELECT id FROM admissions WHERE patient_id = ? AND TenantID = ? AND status <> 'Discharged' AND stage <> 'Discharged' AND (is_archived = 0 OR is_archived IS NULL) LIMIT 1");
                        $stmtAlready->execute([$selected_patient_id, $tenant_id]);
                        if ($stmtAlready->fetch()) {
                            throw new RuntimeException("This patient is currently admitted. Please discharge them first before creating a new admission.");
                        }
                    }
                }

                if(!$existingPatient) {
                     $stmtCheckName = $pdo->prepare("SELECT patient_id, full_name FROM patients WHERE full_name = ? AND TenantID = ? LIMIT 1");
                     $stmtCheckName->execute([$full_name, $tenant_id]);
                     $existingPatient = $stmtCheckName->fetch(PDO::FETCH_ASSOC);
                }

                $pat_id = "";
                if (!$existingPatient) {
                    $isUnique = false;
                    while (!$isUnique) {
                        $temp_id = "PT-" . date("Y") . "-" . rand(1000, 9999);
                        $chkDup = $pdo->prepare("SELECT id FROM patients WHERE patient_id = ? AND TenantID = ?");
                        $chkDup->execute([$temp_id, $tenant_id]);
                        if(!$chkDup->fetch()) { $pat_id = $temp_id; $isUnique = true; }
                    }

                    $stmtPatient = $pdo->prepare("INSERT INTO patients (TenantID, patient_id, full_name, husband_name, birthday, age, religion, mother_name, father_name, contact_number, address, guardian_id_url, last_menstrual_period, estimated_delivery_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmtPatient->execute([$tenant_id, $pat_id, $full_name, $spouse_name, $dob, $age, $religion, $mother_maiden_name, $father_name, $contact_number, $address, $age < 18 ? $guardian_id_url : null, $lmp, $edd]);
                } else {
                    $pat_id = $existingPatient['patient_id'];
                    $old_name = $existingPatient['full_name'];

                    $extraSet = '';
                    if ($age < 18 && !empty($guardian_id_url)) { $extraSet .= ", guardian_id_url=?"; }
                    if ($lmp !== null) { $extraSet .= ", last_menstrual_period=?, estimated_delivery_date=?"; }
                    $stmtUpdatePatient = $pdo->prepare("UPDATE patients SET full_name=?, husband_name=?, birthday=?, age=?, religion=?, mother_name=?, father_name=?, contact_number=?, address=?" . $extraSet . " WHERE patient_id=? AND TenantID=?");
                    $updateParams = [$full_name, $spouse_name, $dob, $age, $religion, $mother_maiden_name, $father_name, $contact_number, $address];
                    if ($age < 18 && !empty($guardian_id_url)) { $updateParams[] = $guardian_id_url; }
                    if ($lmp !== null) { $updateParams[] = $lmp; $updateParams[] = $edd; }
                    $updateParams[] = $pat_id;
                    $updateParams[] = $tenant_id;
                    $stmtUpdatePatient->execute($updateParams);

                    if ($old_name !== $full_name) {
                        $pdo->prepare("UPDATE admissions SET full_name = ? WHERE (patient_id = ? OR full_name = ?) AND TenantID = ?")->execute([$full_name, $pat_id, $old_name, $tenant_id]);
                        $pdo->prepare("UPDATE payments SET full_name = ? WHERE full_name = ? AND TenantID = ?")->execute([$full_name, $old_name, $tenant_id]);
                        $pdo->prepare("UPDATE appointments SET full_name = ? WHERE (patient_id = ? OR full_name = ?) AND TenantID = ?")->execute([$full_name, $pat_id, $old_name, $tenant_id]);
                    }
                }

                //  STORE total_price ON ADMIT
                $stmtAdmit = $pdo->prepare("INSERT INTO admissions (TenantID, patient_id, full_name, reason, admission_date, status, assigned_staff, stage, remaining_balance, total_price) VALUES (?, ?, ?, ?, ?, 'Admitted', ?, 'Waiting', ?, ?)");
                $stmtAdmit->execute([$tenant_id, $pat_id, $full_name, $reason, $admission_date, $assigned_staff, $price, $price]);

                // Audit log for patient admission
                $admitLogUser = $_SESSION['full_name'] ?? 'Staff';
                $admitLogRole = $_SESSION['role'] ?? 'Staff';
                $admitLogIp = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                $admitLogTime = date('Y-m-d H:i:s');
                try {
                    $stmtAdmitLog = $pdo->prepare("INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, 'Patient Admitted', ?, ?, ?)");
                    $stmtAdmitLog->execute([$tenant_id, $admitLogUser, $admitLogRole, "Patient admitted: $full_name | Reason: $reason | Assigned to: $assigned_staff", $admitLogIp, $admitLogTime]);
                } catch (Exception $logErr) {}

                $pdo->commit();
                header("Location: {$currentPage}?msg=admitted"); exit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $dbError = "Failed to admit patient: " . $e->getMessage();
            }
        }
    }

    // -- SELECT FINAL DELIVERY TYPE AFTER LABOR VITALS --
    elseif (isset($_POST['choose_delivery_type'])) {
        // Only Midwives can choose delivery type. Receptionists are blocked.
        if (!$isCurrentUserMidwife) {
            header("Location: {$currentPage}?error=midwife_only_delivery_type");
            exit();
        }
        $adm_id = intval($_POST['admission_id'] ?? 0);
        $delivery_type = trim($_POST['selected_delivery_type'] ?? '');

        if ($adm_id > 0 && in_array($delivery_type, ['Normal Delivery', 'Cesarean Delivery'], true)) {
            try {
                $selectedPrice = (float)($servicePrices[$delivery_type] ?? 0);

                // Preserve any previous payment credit (e.g., mobile downpayment from appointment flow).
                $stmtPrev = $pdo->prepare("SELECT reason, total_price, remaining_balance, payment_type FROM admissions WHERE id = ? AND TenantID = ? LIMIT 1");
                $stmtPrev->execute([$adm_id, $tenant_id]);
                $prev = $stmtPrev->fetch(PDO::FETCH_ASSOC);

                $prevReason = trim((string)($prev['reason'] ?? ''));
                $prevTotal = ($prev && $prev['total_price'] !== null)
                    ? (float)$prev['total_price']
                    : (float)($servicePrices[$prevReason] ?? 0);
                $prevRemaining = ($prev && $prev['remaining_balance'] !== null)
                    ? (float)$prev['remaining_balance']
                    : $prevTotal;

                $paidSoFar = max(0, $prevTotal - $prevRemaining);
                $newBalance = max(0, $selectedPrice - $paidSoFar);

                $nextPaymentType = 'Unpaid';
                if ($newBalance <= 0) {
                    $nextPaymentType = 'Fully Paid';
                } elseif ($paidSoFar > 0 || strcasecmp((string)($prev['payment_type'] ?? ''), 'Downpayment') === 0) {
                    $nextPaymentType = 'Downpayment';
                }

                $stmt = $pdo->prepare("UPDATE admissions SET reason = ?, total_price = ?, remaining_balance = ?, payment_type = ?, stage = 'Delivery Room Assignment', status = 'Checked-up' WHERE id = ? AND TenantID = ?");
                $stmt->execute([$delivery_type, $selectedPrice, $newBalance, $nextPaymentType, $adm_id, $tenant_id]);

                header("Location: {$currentPage}?msg=delivery_type_selected");
                exit();
            } catch (PDOException $e) {
                $dbError = "Failed to select delivery type: " . $e->getMessage();
            }
        }
    }

    // --  REGISTER NEWBORN (BABY) & SYNC TO PATIENT RECORDS  --
    elseif (isset($_POST['register_newborn'])) {
        if (!$isCurrentUserMidwife) {
            header("Location: admissions.php?msg=not_allowed&type=error");
            exit();
        }
        // DITO ANG FINAL SAVE (HINDI NA SA AJAX). Pwede din na isasara nalang yung modal kung nai-AJAX na lahat.
        // Pero ilalagay pa rin natin in case isubmit normally.
        $adm_id = intval($_POST['nb_adm_id'] ?? 0);
        $mom_patient_id = trim($_POST['nb_mom_patient_id']);
        $infant_name = trim($_POST['infant_name'] ?? '');
        $gender = trim($_POST['gender'] ?? 'Unknown');
        $birth_date = $_POST['birth_date'] ?? date('Y-m-d');
        $birth_time = $_POST['birth_time'] ?? date('H:i');
        $weight_kg = floatval($_POST['weight_kg'] ?? 0);
        $length_cm = floatval($_POST['length_cm'] ?? 0);
        $apgar_score = trim($_POST['apgar_score'] ?? '');
        $delivery_method = trim($_POST['delivery_method'] ?? 'Normal Delivery');
        $attending_staff = trim($_POST['attending_staff'] ?? 'Unassigned');
        $location_option = trim($_POST['infant_location_option'] ?? 'rooming_in');
        $nursery_bed_id = intval($_POST['nursery_bed_id'] ?? 0);

        if (!empty($infant_name)) { // Para kung blangko kasi nag-ajax na, hindi magsave doble
            try {
                $pdo->beginTransaction();

                $isCesarean = (stripos($delivery_method, 'cesarean') !== false || stripos($delivery_method, 'c-section') !== false);
                // Parse APGAR score (format: "X/10")
                $apgarNum = 10;
                if (preg_match('/^(\d+)/', $apgar_score, $apgarMatch)) {
                    $apgarNum = (int)$apgarMatch[1];
                }
                $isLowApgar = ($apgarNum <= 6);
                if ($isCesarean || $isLowApgar) {
                    $location_option = 'nursery';
                }

                $linked_room_id = null;
                $linked_bed_id = null;

                if ($location_option === 'nursery') {
                    if ($nursery_bed_id <= 0) {
                        throw new RuntimeException('Please select a nursery bed.');
                    }

                    $stmtNurseryBed = $pdo->prepare("SELECT b.id, b.room_id, b.bed_status FROM clinic_room_beds b INNER JOIN clinic_rooms r ON r.id = b.room_id AND r.TenantID = b.TenantID WHERE b.id = ? AND b.TenantID = ? AND r.room_type = 'infant_ward' LIMIT 1");
                    $stmtNurseryBed->execute([$nursery_bed_id, $tenant_id]);
                    $nurseryBed = $stmtNurseryBed->fetch(PDO::FETCH_ASSOC);
                    if (!$nurseryBed || strtolower((string)$nurseryBed['bed_status']) !== 'available') {
                        throw new RuntimeException('Selected nursery bed is no longer available.');
                    }

                    $linked_room_id = (int)$nurseryBed['room_id'];
                    $linked_bed_id = (int)$nurseryBed['id'];
                } else {
                    $stmtMotherBed = $pdo->prepare("SELECT assigned_room_id, assigned_bed_id FROM admissions WHERE id = ? AND TenantID = ? LIMIT 1");
                    $stmtMotherBed->execute([$adm_id, $tenant_id]);
                    $motherBed = $stmtMotherBed->fetch(PDO::FETCH_ASSOC);
                    $linked_room_id = (int)($motherBed['assigned_room_id'] ?? 0);
                    $linked_bed_id = (int)($motherBed['assigned_bed_id'] ?? 0);

                    if ($linked_bed_id <= 0) {
                        throw new RuntimeException('Mother bed location not found.');
                    }
                }

                $stmtMom = $pdo->prepare("SELECT full_name, address, contact_number, husband_name FROM patients WHERE patient_id = ? AND TenantID = ? LIMIT 1");
                $stmtMom->execute([$mom_patient_id, $tenant_id]);
                $mom = $stmtMom->fetch(PDO::FETCH_ASSOC);

                $mom_name = $mom ? $mom['full_name'] : '';
                $father_name = $mom ? $mom['husband_name'] : '';
                $address = $mom ? $mom['address'] : '';
                $contact = $mom ? $mom['contact_number'] : '';

                $stmtInfant = $pdo->prepare("
                    INSERT INTO infants (TenantID, mother_patient_id, admission_id, infant_name, gender, birth_date, birth_time, weight_kg, length_cm, apgar_score, delivery_method, attending_staff, location_option, linked_room_id, linked_bed_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtInfant->execute([$tenant_id, $mom_patient_id, $adm_id, $infant_name, $gender, $birth_date, $birth_time, $weight_kg, $length_cm, $apgar_score, $delivery_method, $attending_staff, $location_option, $linked_room_id, $linked_bed_id]);

                // Auto-update maternal GP only once per admission after successful delivery registration.
                // Rule: increment PARA by 1, then keep GRAVIDA at least equal to PARA.
                try {
                    $stmtGpFlag = $pdo->prepare("SELECT gp_delivery_counted FROM admissions WHERE id = ? AND TenantID = ? LIMIT 1 FOR UPDATE");
                    $stmtGpFlag->execute([$adm_id, $tenant_id]);
                    $gpAlreadyCounted = (int)$stmtGpFlag->fetchColumn();

                    if ($gpAlreadyCounted === 0) {
                        $stmtMomGp = $pdo->prepare("SELECT id, gravida, para FROM patients WHERE patient_id = ? AND TenantID = ? LIMIT 1 FOR UPDATE");
                        $stmtMomGp->execute([$mom_patient_id, $tenant_id]);
                        $momGp = $stmtMomGp->fetch(PDO::FETCH_ASSOC);

                        if ($momGp) {
                            $curG = isset($momGp['gravida']) ? (int)$momGp['gravida'] : 0;
                            $curP = isset($momGp['para']) ? (int)$momGp['para'] : 0;
                            $newP = max(0, $curP + 1);
                            $newG = max(0, max($curG, $newP));

                            $stmtSetGp = $pdo->prepare("UPDATE patients SET gravida = ?, para = ? WHERE id = ? AND TenantID = ?");
                            $stmtSetGp->execute([$newG, $newP, (int)$momGp['id'], $tenant_id]);
                        }

                        $pdo->prepare("UPDATE admissions SET gp_delivery_counted = 1 WHERE id = ? AND TenantID = ?")
                            ->execute([$adm_id, $tenant_id]);
                    }
                } catch (Throwable $e) {}

                // After delivery: clear mother's maternity tracking fields (LMP / EDD / pregnancy_status)
                try {
                    $stmtClearPreg = $pdo->prepare("UPDATE patients SET last_menstrual_period = NULL, estimated_delivery_date = NULL, pregnancy_status = NULL WHERE patient_id = ? AND TenantID = ?");
                    $stmtClearPreg->execute([$mom_patient_id, $tenant_id]);
                } catch (Throwable $e) {}

                if ($location_option === 'nursery' && $linked_bed_id > 0) {
                    $stmtBedUpdate = $pdo->prepare("UPDATE clinic_room_beds SET bed_status = 'occupied', patient_name = ? WHERE id = ? AND TenantID = ?");
                    $stmtBedUpdate->execute([$infant_name, $linked_bed_id, $tenant_id]);
                }

                // No separate baby patient record is created for newborns here;
                // details stay only in the infants table linked to the mother.

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                $dbError = "Failed to register newborn: " . $e->getMessage();
            }
        }

        // Pagkatapos ng save ng isa (o pagkatapos ng AJAX multiple), move to payment stage.
        if ($adm_id > 0) {
            try {
                // Check if patient is currently in a Recovery Room — if so, KEEP the bed
                // occupied until payment is fully processed (di sila aalisin sa recovery
                // room hangga't di nagbabayad).
                $stmtRoomTypeCheck = $pdo->prepare("SELECT COALESCE(LOWER(assigned_room_type), '') FROM admissions WHERE id = ? AND TenantID = ? LIMIT 1");
                $stmtRoomTypeCheck->execute([$adm_id, $tenant_id]);
                $currentAssignedRoomType = (string)$stmtRoomTypeCheck->fetchColumn();

                if ($currentAssignedRoomType === 'recovery_room') {
                    // Move to Payment stage but retain the recovery room/bed assignment.
                    $pdo->prepare("UPDATE admissions SET stage = 'Payment' WHERE id = ? AND TenantID = ?")->execute([$adm_id, $tenant_id]);
                } else {
                    releaseAdmissionBedToCleaning($pdo, $tenant_id, $adm_id);
                    $pdo->prepare("UPDATE admissions SET stage = 'Payment', assigned_room_id = NULL, assigned_bed_id = NULL, assigned_room_type = NULL WHERE id = ? AND TenantID = ?")->execute([$adm_id, $tenant_id]);
                }
            } catch (PDOException $e) {}
        }

        header("Location: {$currentPage}?msg=newborn_registered");
        exit();
    }

    // -- RECORD CHECK-UP & OPTIONAL FOLLOW-UP --
    elseif (isset($_POST['save_checkup_admission'])) {
        $adm_id = intval($_POST['adm_id'] ?? 0);
        // Look up the admission's service so we can enforce per-service permissions.
        $_stmtSV = $pdo->prepare("SELECT reason FROM admissions WHERE id = ? AND TenantID = ?");
        $_stmtSV->execute([$adm_id, $tenant_id]);
        $_admReasonSV = (string)($_stmtSV->fetchColumn() ?: '');
        $_isLaborSvcSV = mh_is_labor_service($_admReasonSV);

        // Permission: Midwife always allowed. Receptionist allowed UNLESS service is Labor.
        if (!$isCurrentUserMidwife && !($isCurrentUserReceptionist && !$_isLaborSvcSV)) {
            if ($isCurrentUserReceptionist && $_isLaborSvcSV) {
                $dbError = "Receptionists cannot record vitals for Labor services. Only a Midwife can record vitals for Labor.";
            } else {
                $dbError = "Only a Midwife (or Receptionist for non-labor services) can record vitals. " . htmlspecialchars($displayName) . " is not authorized.";
            }
            goto skip_save_checkup;
        }
        $bp = trim($_POST['bp'] ?? ''); $temp = trim($_POST['temp'] ?? ''); $weight = trim($_POST['weight'] ?? '');
        $pulse = trim($_POST['pulse'] ?? ''); $spo2 = trim($_POST['spo2'] ?? '');

        $lab_cbc = trim($_POST['lab_cbc'] ?? '');
        $lab_urinalysis = trim($_POST['lab_urinalysis'] ?? '');
        $lab_blood_type = trim($_POST['lab_blood_type'] ?? '');
        $lab_blood_sugar = trim($_POST['lab_blood_sugar'] ?? '');
        $lab_hep_b = trim($_POST['lab_hep_b'] ?? '');
        $lab_syphilis = trim($_POST['lab_syphilis'] ?? '');

        // Fetal status (only relevant when pregnancy_status === 'Confirmed Pregnant')
        $fetal_enabled = isset($_POST['enable_fetal']) && $_POST['enable_fetal'] == '1';
        $fetal_aog = $fetal_enabled ? trim($_POST['fetal_aog'] ?? '') : '';
        $fetal_fundal_height = $fetal_enabled ? trim($_POST['fetal_fundal_height'] ?? '') : '';
        $fetal_fht = $fetal_enabled ? trim($_POST['fetal_fht'] ?? '') : '';
        $fetal_presentation = $fetal_enabled ? trim($_POST['fetal_presentation'] ?? '') : '';
        $allowedFetalPresentations = ['', 'Cephalic', 'Breech', 'Transverse', 'Oblique', 'Unknown'];
        if (!in_array($fetal_presentation, $allowedFetalPresentations, true)) { $fetal_presentation = ''; }

        // Optional pregnancy status update (only present when service is maternity-related).
        $pregnancy_status_in = trim($_POST['pregnancy_status'] ?? '');
        $allowedPregnancyStatuses = ['Pending Confirmation', 'Confirmed Pregnant', 'Not Pregnant', 'Miscarriage'];
        if ($pregnancy_status_in !== '' && !in_array($pregnancy_status_in, $allowedPregnancyStatuses, true)) {
            $pregnancy_status_in = '';
        }

        // Gravida / Para (patient-level cumulative counts)
        $gravida_in = (isset($_POST['gravida']) && trim((string)$_POST['gravida']) !== '') ? max(0, min(20, (int)$_POST['gravida'])) : null;
        $para_in    = (isset($_POST['para']) && trim((string)$_POST['para']) !== '') ? max(0, min(20, (int)$_POST['para'])) : null;

        // Editable Last Menstrual Period (LMP) from the Checkup modal.
        // When provided, recompute EDD using Naegele's rule and persist both to the patient record.
        $lmp_in = trim($_POST['last_menstrual_period'] ?? '');
        if ($lmp_in !== '') {
            // Basic YYYY-MM-DD validation
            $dt = DateTime::createFromFormat('Y-m-d', $lmp_in);
            if (!$dt || $dt->format('Y-m-d') !== $lmp_in) { $lmp_in = ''; }
        }
        $edd_computed = ($lmp_in !== '') ? mh_calculate_edd($lmp_in) : null;

        //  GET EXISTING DATA FIRST PARA MALAMAN KUNG BAGONG UPLOAD
        $stmtEx = $pdo->prepare("SELECT lab_transvaginal, lab_pelvic, remaining_balance, total_price, reason FROM admissions WHERE id = ? AND TenantID = ?");
        $stmtEx->execute([$adm_id, $tenant_id]);
        $exData = $stmtEx->fetch(PDO::FETCH_ASSOC);

        $added_cost = 0; // Cost na idadagdag kung may inupload na UTZ ngayon

        $lab_transvaginal_path = null;
        if ($isTransvaginalActive && isset($_FILES['lab_transvaginal']) && $_FILES['lab_transvaginal']['error'] == 0) {
            $filename = time() . '_tv_' . preg_replace("/[^a-zA-Z0-9.]/", "", $_FILES['lab_transvaginal']['name']);
            if (!is_dir('uploads/ultrasounds/')) { mkdir('uploads/ultrasounds/', 0777, true); }
            move_uploaded_file($_FILES['lab_transvaginal']['tmp_name'], 'uploads/ultrasounds/' . $filename);
            $lab_transvaginal_path = 'uploads/ultrasounds/' . $filename;

            // Kung dati walang laman AT HINDI ITO ANG MAIN SERVICE, idadagdag yung presyo ngayon
            if (empty($exData['lab_transvaginal']) && stripos($exData['reason'], 'Transvaginal') === false) {
                $added_cost += (float)($servicePrices['Transvaginal Ultrasound'] ?? 0);
            }
        } else {
            $lab_transvaginal_path = $exData['lab_transvaginal'] ?? null;
        }

        $lab_pelvic_path = null;
        if ($isPelvicActive && isset($_FILES['lab_pelvic']) && $_FILES['lab_pelvic']['error'] == 0) {
            $filename = time() . '_plv_' . preg_replace("/[^a-zA-Z0-9.]/", "", $_FILES['lab_pelvic']['name']);
            if (!is_dir('uploads/ultrasounds/')) { mkdir('uploads/ultrasounds/', 0777, true); }
            move_uploaded_file($_FILES['lab_pelvic']['tmp_name'], 'uploads/ultrasounds/' . $filename);
            $lab_pelvic_path = 'uploads/ultrasounds/' . $filename;

            // Kung dati walang laman AT HINDI ITO ANG MAIN SERVICE, idadagdag yung presyo ngayon
            if (empty($exData['lab_pelvic']) && stripos($exData['reason'], 'Pelvic') === false) {
                $added_cost += (float)($servicePrices['Pelvic Ultrasound'] ?? 0);
            }
        } else {
            $lab_pelvic_path = $exData['lab_pelvic'] ?? null;
        }

        // KUNG ANG REASON AY PURE ULTRASOUND, HINDI NATIN SESAVE ANG VITALS AT FOLLOW UP (Kahit may laman, papatungan natin ng blank)
        $reasonText = strtolower((string)($exData['reason'] ?? ''));
        $hasUltrasoundWord = (strpos($reasonText, 'ultrasound') !== false || strpos($reasonText, 'utz') !== false || strpos($reasonText, 'transvaginal') !== false || strpos($reasonText, 'pelvic') !== false);
        $isMixedCheckup = (strpos($reasonText, 'prenatal') !== false || strpos($reasonText, 'check') !== false || strpos($reasonText, 'delivery') !== false || strpos($reasonText, 'labor') !== false);
        $isLaborOnlyService = (strpos($reasonText, 'labor') !== false && strpos($reasonText, 'normal delivery') === false && strpos($reasonText, 'cesarean') === false && strpos($reasonText, 'c-section') === false);
        $isDeliveryService = (strpos($reasonText, 'normal delivery') !== false || strpos($reasonText, 'cesarean') !== false || strpos($reasonText, 'c-section') !== false);
        $isPureUltrasound = $hasUltrasoundWord && !$isMixedCheckup;

        $has_followup = isset($_POST['enable_followup']) && $_POST['enable_followup'] == '1';
        $fu_date = $_POST['followup_date'] ?? null;
        $fu_time = $_POST['followup_time'] ?? null;
        $fu_service = trim($_POST['followup_service'] ?? '');
        $fu_staff = trim($_POST['followup_staff'] ?? 'Unassigned');

        if ($isPureUltrasound) {
            $bp = ''; $temp = ''; $weight = ''; $pulse = ''; $spo2 = '';
            $has_followup = false;
        }

        try {
            $stmtInfo = $pdo->prepare("SELECT patient_id, full_name, reason, payment_type, remaining_balance FROM admissions WHERE id = ? AND TenantID = ?");
            $stmtInfo->execute([$adm_id, $tenant_id]);
            $admInfo = $stmtInfo->fetch(PDO::FETCH_ASSOC);

            $balance = 0; $patient_db_id = 0; $patData = null; $price = 0;
            $newTotalAmount = (float)($exData['total_price'] ?? 0);

            if ($admInfo) {
                $pname = $admInfo['full_name']; $reason = $admInfo['reason']; $p_id = $admInfo['patient_id'];
                $price = isset($servicePrices[$reason]) ? (float)$servicePrices[$reason] : 0;

                $balance = $admInfo['remaining_balance'] !== null ? (float)$admInfo['remaining_balance'] : $price;

                //  IDAGDAG ANG BAGONG COST NG ULTRASOUND SA BILL NG PASYENTE
                $balance += $added_cost;
                $newTotalAmount += $added_cost; // I-update ang total din

                if (!empty($p_id)) {
                    $stmtPat = $pdo->prepare("SELECT * FROM patients WHERE TenantID = ? AND patient_id = ? LIMIT 1");
                    $stmtPat->execute([$tenant_id, $p_id]);
                } else {
                    $stmtPat = $pdo->prepare("SELECT * FROM patients WHERE TenantID = ? AND full_name = ? LIMIT 1");
                    $stmtPat->execute([$tenant_id, $pname]);
                }

                $patData = $stmtPat->fetch(PDO::FETCH_ASSOC);

                if ($patData) {
                    $patient_db_id = $patData['id'];
                    if ($admInfo['payment_type'] === 'Fully Paid' && $admInfo['payment_type'] !== 'Follow-up') { $balance = 0; }

                    // Persist pregnancy_status update (when supplied) onto the patient record.
                    if ($pregnancy_status_in !== '') {
                        try {
                            $stmtPS = $pdo->prepare("UPDATE patients SET pregnancy_status = ? WHERE id = ? AND TenantID = ?");
                            $stmtPS->execute([$pregnancy_status_in, $patient_db_id, $tenant_id]);
                        } catch (PDOException $e) {}
                    }

                    // Persist Gravida / Para counts (only when explicitly posted).
                    if ($gravida_in !== null || $para_in !== null) {
                        try {
                            $stmtGP = $pdo->prepare("UPDATE patients SET gravida = COALESCE(?, gravida), para = COALESCE(?, para) WHERE id = ? AND TenantID = ?");
                            $stmtGP->execute([$gravida_in, $para_in, $patient_db_id, $tenant_id]);
                        } catch (PDOException $e) {}
                    }

                    // Persist edited LMP (and recomputed EDD) onto the patient record.
                    if ($lmp_in !== '') {
                        try {
                            $stmtLMP = $pdo->prepare("UPDATE patients SET last_menstrual_period = ?, estimated_delivery_date = ? WHERE id = ? AND TenantID = ?");
                            $stmtLMP->execute([$lmp_in, $edd_computed, $patient_db_id, $tenant_id]);
                        } catch (PDOException $e) {}
                    }
                }
            }

            if ($has_followup && !empty($fu_date) && !empty($fu_time) && $patData && !$isPureUltrasound) {
                $stmtLimit = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND appointment_time = ? AND status != 'Cancelled' AND TenantID = ?");
                $stmtLimit->execute([$fu_date, $fu_time, $tenant_id]);
                $current_bookings = (int)$stmtLimit->fetchColumn();

                if ($totalClinicStaff > 0 && $current_bookings >= $totalClinicStaff) {
                     $dbError = "Cannot set follow-up. The time slot $fu_date @ " . convertTo12HourFormat($fu_time) . " is fully booked (Max $totalClinicStaff patients allowed). Vitals not saved.";
                     goto SkipVitalsSave;
                } else {
                     $sqlAppt = "INSERT INTO appointments (TenantID, patient_id, full_name, age, birthday, menarche, civil_status, religion, occupation, contact_number, address, husband_name, father_name, mother_name, appointment_date, appointment_time, service, assigned_staff, medical_history, status, payment_type, remaining_balance)
                                 VALUES (:tenant, :pid, :name, :age, :bday, :menarche, :status, :rel, :occ, :contact, :addr, :hname, :fname, :mname, :appt_date, :appt_time, :service, :staff, :history, 'Follow-up', 'Follow-up', :rem_bal)";

                     $stmtAppt = $pdo->prepare($sqlAppt);
                     $stmtAppt->execute([
                         ':tenant' => $tenant_id, ':pid' => $patData['patient_id'], ':name' => $patData['full_name'], ':age' => $patData['age'],
                         ':bday' => $patData['birthday'], ':menarche' => $patData['menarche'], ':status' => $patData['civil_status'],
                         ':rel' => $patData['religion'], ':occ' => $patData['occupation'], ':contact' => $patData['contact_number'],
                         ':addr' => $patData['address'], ':hname' => $patData['husband_name'], ':fname' => $patData['father_name'],
                         ':mname' => $patData['mother_name'], ':appt_date' => $fu_date, ':appt_time' => $fu_time,
                         ':service' => (!empty($fu_service) ? $fu_service : $reason), ':staff' => $fu_staff, ':history' => 'Follow-up appointment generated after check-up.',
                         ':rem_bal' => $price
                     ]);

                     $pdo->prepare("UPDATE patients SET payment_type = 'Follow-up' WHERE id = ?")->execute([$patient_db_id]);
                }
            }

            //  COMPUTE NEW total_price (existing stored + added ultrasound cost)
            $existing_total = ($exData['total_price'] !== null) ? (float)$exData['total_price'] : $price;
            $new_total_price = $existing_total + $added_cost;

            // Labor/Delivery path: save vitals first, then assign labor room.
            if ($isLaborOnlyService || $isDeliveryService) {
                $stmt = $pdo->prepare("UPDATE admissions SET bp=?, temp=?, weight=?, pulse=?, spo2=?, lab_cbc=?, lab_urinalysis=?, lab_blood_type=?, lab_blood_sugar=?, lab_hep_b=?, lab_syphilis=?, lab_transvaginal=?, lab_pelvic=?, fetal_aog=?, fetal_fundal_height=?, fetal_fht=?, fetal_presentation=?, total_price=?, status='Checked-up', stage='Labor Room Assignment', remaining_balance=? WHERE id=? AND TenantID=?");
                $stmt->execute([$bp, $temp, $weight, $pulse, $spo2, $lab_cbc, $lab_urinalysis, $lab_blood_type, $lab_blood_sugar, $lab_hep_b, $lab_syphilis, $lab_transvaginal_path, $lab_pelvic_path, $fetal_aog, $fetal_fundal_height, $fetal_fht, $fetal_presentation, $new_total_price, $balance, $adm_id, $tenant_id]);
                header("Location: {$currentPage}?msg=checkup_saved_assign_labor&adm_id=$adm_id"); exit();
            }

            //  I-SAVE NA ANG BAGO / IN-UPDATE NA BALANCE AT TOTAL_PRICE
            // Always proceed to Payment stage after vitals — receipt shows only after payment is processed
            releaseAdmissionBedToCleaning($pdo, $tenant_id, $adm_id);
            releaseAdmissionInfantBeds($pdo, $tenant_id, $adm_id);
            $stmt = $pdo->prepare("UPDATE admissions SET bp=?, temp=?, weight=?, pulse=?, spo2=?, lab_cbc=?, lab_urinalysis=?, lab_blood_type=?, lab_blood_sugar=?, lab_hep_b=?, lab_syphilis=?, lab_transvaginal=?, lab_pelvic=?, fetal_aog=?, fetal_fundal_height=?, fetal_fht=?, fetal_presentation=?, total_price=?, status='Checked-up', stage='Payment', remaining_balance=?, assigned_room_id = NULL, assigned_bed_id = NULL, assigned_room_type = NULL WHERE id=? AND TenantID=?");
            $stmt->execute([$bp, $temp, $weight, $pulse, $spo2, $lab_cbc, $lab_urinalysis, $lab_blood_type, $lab_blood_sugar, $lab_hep_b, $lab_syphilis, $lab_transvaginal_path, $lab_pelvic_path, $fetal_aog, $fetal_fundal_height, $fetal_fht, $fetal_presentation, $new_total_price, $balance, $adm_id, $tenant_id]);
            header("Location: {$currentPage}?msg=checkup_saved"); exit();

            SkipVitalsSave:
        } catch (PDOException $e) { $dbError = "Error saving vitals: " . $e->getMessage(); }
        skip_save_checkup:;
    }

    elseif (isset($_POST['process_payment'])) {
        $admission_id = intval($_POST['admission_id']);
        $payment_action = $_POST['payment_action'];
        $full_name = trim($_POST['d_patient_name'] ?? '');
        $service_name = trim($_POST['d_service_name']);
        $final_amount = floatval($_POST['d_final_amount']);
        $method = $_POST['payment_method'];
        $receipt_email = trim($_POST['receipt_email'] ?? '');
        $is_philhealth = intval($_POST['is_philhealth'] ?? 0);
        $philhealth_amount = floatval($_POST['philhealth_amount'] ?? 0);
        // Safety: PhilHealth only valid if amount > 0
        if ($philhealth_amount <= 0) { $is_philhealth = 0; }
        $current_date = date('Y-m-d H:i:s');
        $patient_id = '';

        $stmtBal = $pdo->prepare("SELECT remaining_balance, reason, patient_id FROM admissions WHERE id = ? AND TenantID = ?");
        $stmtBal->execute([$admission_id, $tenant_id]);
        $admBalData = $stmtBal->fetch();
        $currBal = $admBalData['remaining_balance'] !== null ? (float)$admBalData['remaining_balance'] : ((float)($servicePrices[$admBalData['reason']] ?? 0));
        $patient_id = trim((string)($admBalData['patient_id'] ?? ''));

        if ($patient_id === '' && $full_name !== '') {
            try {
                $stmtPatId = $pdo->prepare("SELECT patient_id FROM patients WHERE TenantID = ? AND full_name = ? LIMIT 1");
                $stmtPatId->execute([$tenant_id, $full_name]);
                $patient_id = trim((string)$stmtPatId->fetchColumn());
            } catch (PDOException $e) {}
        }

        $newBal = max(0, $currBal - $final_amount);
        $ph_front_path = '';
        $ph_back_path = '';
        if ($is_philhealth) {
            // PhilHealth covers only the configured package rate (from form), not the entire balance.
            $philhealth_amount = floatval($_POST['philhealth_amount'] ?? 0);
            if ($philhealth_amount < 0) $philhealth_amount = 0;
            if ($philhealth_amount > $currBal) $philhealth_amount = $currBal;
            // Remaining balance = current balance - PhilHealth coverage - cash paid in this transaction
            $newBal = max(0, $currBal - $philhealth_amount - $final_amount);

            // Check if using existing photos from patient records
            $hasFrontExisting = !empty($_POST['philhealth_id_front_existing']);
            $hasBackExisting = !empty($_POST['philhealth_id_back_existing']);

            // Validate: PhilHealth requires both front and back ID photos
            $hasFrontFile = !empty($_FILES['philhealth_id_front']['name']) && $_FILES['philhealth_id_front']['error'] === UPLOAD_ERR_OK;
            $hasFrontData = !empty($_POST['philhealth_id_front_data']);
            $hasBackFile = !empty($_FILES['philhealth_id_back']['name']) && $_FILES['philhealth_id_back']['error'] === UPLOAD_ERR_OK;
            $hasBackData = !empty($_POST['philhealth_id_back_data']);
            if (!$hasFrontFile && !$hasFrontData && !$hasFrontExisting) {
                $dbError = "PhilHealth ID Front photo is required when PhilHealth is enabled.";
                goto skip_process_payment;
            }
            if (!$hasBackFile && !$hasBackData && !$hasBackExisting) {
                $dbError = "PhilHealth ID Back photo is required when PhilHealth is enabled.";
                goto skip_process_payment;
            }

            // Use existing photo paths if selected (no new upload)
            if ($hasFrontExisting && !$hasFrontFile && !$hasFrontData) {
                $existFront = trim($_POST['philhealth_id_front_existing']);
                if (strpos($existFront, 'uploads/') === 0 && strpos($existFront, '..') === false) {
                    $ph_front_path = $existFront;
                }
            }
            if ($hasBackExisting && !$hasBackFile && !$hasBackData) {
                $existBack = trim($_POST['philhealth_id_back_existing']);
                if (strpos($existBack, 'uploads/') === 0 && strpos($existBack, '..') === false) {
                    $ph_back_path = $existBack;
                }
            }

            // Handle PhilHealth ID photo uploads
            $phDir = __DIR__ . '/uploads/philhealth_ids/';
            if (!is_dir($phDir)) { mkdir($phDir, 0777, true); }
            $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];

            // Front — file upload
            if (!empty($_FILES['philhealth_id_front']['name']) && $_FILES['philhealth_id_front']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['philhealth_id_front']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, $allowedExt)) {
                    $fn = 'ph_front_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (move_uploaded_file($_FILES['philhealth_id_front']['tmp_name'], $phDir . $fn)) {
                        $ph_front_path = 'uploads/philhealth_ids/' . $fn;
                    }
                }
            }
            // Front — camera capture (base64)
            if (empty($ph_front_path) && !empty($_POST['philhealth_id_front_data'])) {
                $data = $_POST['philhealth_id_front_data'];
                if (preg_match('/^data:image\/(jpeg|png|webp);base64,/', $data, $m)) {
                    $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
                    $fn = 'ph_front_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $decoded = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $data));
                    if ($decoded !== false) {
                        file_put_contents($phDir . $fn, $decoded);
                        $ph_front_path = 'uploads/philhealth_ids/' . $fn;
                    }
                }
            }

            // Back — file upload
            if (!empty($_FILES['philhealth_id_back']['name']) && $_FILES['philhealth_id_back']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['philhealth_id_back']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, $allowedExt)) {
                    $fn = 'ph_back_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (move_uploaded_file($_FILES['philhealth_id_back']['tmp_name'], $phDir . $fn)) {
                        $ph_back_path = 'uploads/philhealth_ids/' . $fn;
                    }
                }
            }
            // Back — camera capture (base64)
            if (empty($ph_back_path) && !empty($_POST['philhealth_id_back_data'])) {
                $data = $_POST['philhealth_id_back_data'];
                if (preg_match('/^data:image\/(jpeg|png|webp);base64,/', $data, $m)) {
                    $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
                    $fn = 'ph_back_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $decoded = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $data));
                    if ($decoded !== false) {
                        file_put_contents($phDir . $fn, $decoded);
                        $ph_back_path = 'uploads/philhealth_ids/' . $fn;
                    }
                }
            }
        }
        $receipt_id = 'REC-' . strtoupper(substr(uniqid(), -6));

        try {
            if ($payment_action === 'app_online_success') {
                $stmtBill = $pdo->prepare("INSERT INTO payments (TenantID, patient_id, admission_id, full_name, service, description, amount, status, payment_date, receipt, is_philhealth, philhealth_amount, philhealth_id_front, philhealth_id_back) VALUES (?, ?, ?, ?, ?, ?, ?, 'Paid', ?, ?, ?, ?, ?, ?)");
                $stmtBill->execute([$tenant_id, $patient_id, $admission_id, $full_name, $service_name, "Online Payment via App", $final_amount, $current_date, $receipt_id, $is_philhealth, $philhealth_amount, $ph_front_path, $ph_back_path]);
                $paymentId = (int)$pdo->lastInsertId();

                // Save PhilHealth ID photos to patients table
                if ($is_philhealth && (!empty($ph_front_path) || !empty($ph_back_path))) {
                    $pdo->prepare("UPDATE patients SET philhealth_id_pic_front = COALESCE(NULLIF(?, ''), philhealth_id_pic_front), philhealth_id_pic_back = COALESCE(NULLIF(?, ''), philhealth_id_pic_back), philhealth_status = 'Yes' WHERE patient_id = ? AND TenantID = ?")
                        ->execute([$ph_front_path, $ph_back_path, $patient_id, $tenant_id]);
                }

                //  Save receipt code to admissions immediately
                $stmtAdmRec = $pdo->prepare("UPDATE admissions SET receipt = ?, payment_type = 'Fully Paid', remaining_balance = 0 WHERE id = ? AND TenantID = ?");
                $stmtAdmRec->execute([$receipt_id, $admission_id, $tenant_id]);
                releaseAdmissionInfantBeds($pdo, $tenant_id, $admission_id);
                // Recovery room patient: only release the bed now (after full payment).
                releaseAdmissionBedToCleaning($pdo, $tenant_id, $admission_id);
                $pdo->prepare("UPDATE admissions SET status = 'Discharged', stage = 'Discharged', discharge_date = ?, assigned_room_id = NULL, assigned_bed_id = NULL, assigned_room_type = NULL WHERE id = ? AND TenantID = ?")
                    ->execute([$current_date, $admission_id, $tenant_id]);

                if (!empty($receipt_email) && $final_amount > 0) {
                    $r_title = "Official Receipt";
                    $r_body = "
                        <html><body style='font-family: Arial, sans-serif; background-color: #f4f7f6; padding: 30px; margin: 0;'>
                            <div style='background-color: #ffffff; max-width: 500px; margin: auto; border-radius: 12px; padding: 30px; border-top: 5px solid $themeColor;'>
                                <h2 style='color: $themeColor; margin-top: 0;'>$clinicName</h2>
                                <h3 style='color: #333;'>$r_title</h3>
                                <p><strong>Patient:</strong> $full_name</p>
                                <p><strong>Service:</strong> $service_name</p>
                                <p><strong>Amount Paid:</strong> PHP " . number_format($final_amount, 2) . "</p>
                                <p><strong>Method:</strong> Online Payment (App)</p>
                                <p><strong>Date:</strong> " . date('M d, Y h:i A') . "</p>
                                <hr style='border: none; border-top: 1px dashed #ccc; margin: 20px 0;'>
                                <p style='color: #777; font-size: 12px;'>Thank you for trusting $clinicName.</p>
                            </div>
                        </body></html>
                    ";
                    send_email_via_smtp_gmail($receipt_email, "$r_title - $clinicName", $r_body);
                }

                $qs = "&show_receipt=1&r_id=".$receipt_id."&r_name=".urlencode($full_name)."&r_svc=".urlencode($service_name)."&r_svc_amt=".(float)($servicePrices[$service_name] ?? 0)."&r_amt=".$final_amount."&r_type=discharge&r_method=Online%20Payment&adm_id=".$admission_id."&r_ph=".$is_philhealth."&r_ph_amt=".$philhealth_amount;
                $qs .= buildAdmissionRoomChargesParam($pdo, $tenant_id, $admission_id);
                if (!empty($paymentId)) { $qs .= "&pay_id=" . $paymentId; }
                header("Location: {$currentPage}?msg=payment_recorded" . $qs);
                exit();
            }

            if (!empty($receipt_email) && $final_amount > 0) {
                $r_title = ($payment_action === 'downpayment') ? "Downpayment Receipt" : "Official Receipt";
                $r_body = "
                    <html><body style='font-family: Arial, sans-serif; background-color: #f4f7f6; padding: 30px; margin: 0;'>
                        <div style='background-color: #ffffff; max-width: 500px; margin: auto; border-radius: 12px; padding: 30px; border-top: 5px solid $themeColor;'>
                            <h2 style='color: $themeColor; margin-top: 0;'>$clinicName</h2>
                            <h3 style='color: #333;'>$r_title</h3>
                            <p><strong>Patient:</strong> $full_name</p>
                            <p><strong>Service:</strong> $service_name</p>
                            <p><strong>Amount Paid:</strong> PHP " . number_format($final_amount, 2) . "</p>
                            <p><strong>Method:</strong> Over the Counter (Cash)</p>
                            <p><strong>Date:</strong> " . date('M d, Y h:i A') . "</p>
                            <hr style='border: none; border-top: 1px dashed #ccc; margin: 20px 0;'>
                            <p style='color: #777; font-size: 12px;'>Thank you for trusting $clinicName.</p>
                        </div>
                    </body></html>
                ";
                send_email_via_smtp_gmail($receipt_email, "$r_title - $clinicName", $r_body);
            }

            $qs = "&show_receipt=1&r_id=".$receipt_id."&r_name=".urlencode($full_name)."&r_svc=".urlencode($service_name)."&r_svc_amt=".(float)($servicePrices[$service_name] ?? 0)."&r_amt=".$final_amount."&r_type=".$payment_action."&r_method=Over%20the%20Counter&adm_id=".$admission_id."&r_ph=".$is_philhealth."&r_ph_amt=".$philhealth_amount;
            $qs .= buildAdmissionRoomChargesParam($pdo, $tenant_id, $admission_id);

            if ($payment_action === 'downpayment') {
                $stmtUpdate = $pdo->prepare("UPDATE admissions SET payment_method=?, payment_type='Downpayment', remaining_balance=? WHERE id = ? AND TenantID = ?");
                $stmtUpdate->execute([$method, $newBal, $admission_id, $tenant_id]);
                releaseAdmissionInfantBeds($pdo, $tenant_id, $admission_id);

                $paymentId = 0;
                $stmtBill = $pdo->prepare("INSERT INTO payments (TenantID, patient_id, admission_id, full_name, service, description, amount, status, payment_date, receipt, is_philhealth, philhealth_amount, philhealth_id_front, philhealth_id_back) VALUES (?, ?, ?, ?, ?, ?, ?, 'Paid', ?, ?, ?, ?, ?, ?)");
                $stmtBill->execute([$tenant_id, $patient_id, $admission_id, $full_name, $service_name, "Downpayment (Cash)", $final_amount, $current_date, $receipt_id, 0, 0, '', '']);
                $paymentId = (int)$pdo->lastInsertId();

                //  Save receipt code to admissions immediately
                $stmtAdmRec = $pdo->prepare("UPDATE admissions SET receipt = ? WHERE id = ? AND TenantID = ?");
                $stmtAdmRec->execute([$receipt_id, $admission_id, $tenant_id]);
                $qsWithId = $qs;
                if (!empty($paymentId)) { $qsWithId .= "&pay_id=" . $paymentId; }
                header("Location: {$currentPage}?msg=payment_recorded" . $qsWithId);
            } else {
                $stmtUpdate = $pdo->prepare("UPDATE admissions SET status = 'Discharged', stage='Discharged', payment_method=?, payment_type='Fully Paid', remaining_balance = 0, discharge_date = ? WHERE id = ? AND TenantID = ?");
                $stmtUpdate->execute([$method, $current_date, $admission_id, $tenant_id]);
                releaseAdmissionInfantBeds($pdo, $tenant_id, $admission_id);
                // Recovery room patient: release the bed only after full payment.
                releaseAdmissionBedToCleaning($pdo, $tenant_id, $admission_id);
                $pdo->prepare("UPDATE admissions SET assigned_room_id = NULL, assigned_bed_id = NULL, assigned_room_type = NULL WHERE id = ? AND TenantID = ?")
                    ->execute([$admission_id, $tenant_id]);

                $paymentId = 0;
                $desc = $is_philhealth ? 'PhilHealth Covered' : 'Final Payment (Cash)';
                $stmtBill = $pdo->prepare("INSERT INTO payments (TenantID, patient_id, admission_id, full_name, service, description, amount, status, payment_date, receipt, is_philhealth, philhealth_amount, philhealth_id_front, philhealth_id_back) VALUES (?, ?, ?, ?, ?, ?, ?, 'Paid', ?, ?, ?, ?, ?, ?)");
                $stmtBill->execute([$tenant_id, $patient_id, $admission_id, $full_name, $service_name, $desc, $final_amount, $current_date, $receipt_id, $is_philhealth, $philhealth_amount, $ph_front_path, $ph_back_path]);
                $paymentId = (int)$pdo->lastInsertId();

                // Save PhilHealth ID photos to patients table
                if ($is_philhealth && (!empty($ph_front_path) || !empty($ph_back_path))) {
                    $pdo->prepare("UPDATE patients SET philhealth_id_pic_front = COALESCE(NULLIF(?, ''), philhealth_id_pic_front), philhealth_id_pic_back = COALESCE(NULLIF(?, ''), philhealth_id_pic_back), philhealth_status = 'Yes' WHERE patient_id = ? AND TenantID = ?")
                        ->execute([$ph_front_path, $ph_back_path, $patient_id, $tenant_id]);
                }

                //  Save receipt code to admissions immediately
                $stmtAdmRec = $pdo->prepare("UPDATE admissions SET receipt = ?, payment_type = 'Fully Paid', remaining_balance = 0, status = 'Discharged', stage = 'Discharged', discharge_date = ? WHERE id = ? AND TenantID = ?");
                $stmtAdmRec->execute([$receipt_id, $current_date, $admission_id, $tenant_id]);

                $qsWithId = $qs;
                if (!empty($paymentId)) { $qsWithId .= "&pay_id=" . $paymentId; }
                header("Location: {$currentPage}?msg=payment_recorded" . $qsWithId);
            }
            exit();
        } catch (PDOException $e) { $dbError = "Failed to process payment: " . $e->getMessage(); }
        skip_process_payment:;
    }
}

// =========================================================================
// 3. FETCH ADMISSIONS DATA (WITH INFANT CHECK)
// =========================================================================
$admissions = [];
try {
    $stmtAdmissions = $pdo->prepare("
        SELECT a.*, p.description AS payment_desc,
               (SELECT MAX(pay_ph.is_philhealth) FROM payments pay_ph WHERE pay_ph.TenantID = a.TenantID AND pay_ph.admission_id = a.id AND pay_ph.status = 'Paid') as pay_is_philhealth,
               (SELECT MAX(pay_ph2.philhealth_amount) FROM payments pay_ph2 WHERE pay_ph2.TenantID = a.TenantID AND pay_ph2.admission_id = a.id AND pay_ph2.is_philhealth = 1) as pay_philhealth_amount,
               COALESCE(pat.full_name, a.full_name) as display_name,
               pat.payment_type AS pat_payment_type, pat.profile_pic_url, pat.birthday, pat.age, pat.civil_status, pat.religion, pat.contact_number, pat.address, pat.husband_name, pat.father_name, pat.mother_name,
               pat.philhealth_id_pic_front, pat.philhealth_id_pic_back,
               pat.last_menstrual_period, pat.estimated_delivery_date, pat.pregnancy_status, pat.gravida, pat.para,
               (SELECT SUM(amount) FROM payments pay WHERE pay.TenantID = a.TenantID AND pay.admission_id = a.id AND pay.status = 'Paid') as total_paid,
               (SELECT COUNT(id) FROM infants inf WHERE inf.mother_patient_id = a.patient_id AND inf.TenantID = a.TenantID AND (inf.admission_id = a.id OR inf.admission_id IS NULL)) as has_registered_baby,
               pat.email_address as account_email
        FROM admissions a
        LEFT JOIN patients pat ON a.TenantID = pat.TenantID AND a.patient_id = pat.patient_id
        LEFT JOIN payments p ON a.TenantID = p.TenantID AND p.full_name = COALESCE(pat.full_name, a.full_name) AND a.discharge_date = p.payment_date
        WHERE a.TenantID = ? AND (a.is_archived = 0 OR a.is_archived IS NULL)
        ORDER BY CASE WHEN a.status = 'Pending' THEN 1 WHEN a.status = 'Admitted' THEN 2 WHEN a.status = 'Checked-up' THEN 3 ELSE 4 END, a.admission_date DESC
    ");
    $stmtAdmissions->execute([$tenant_id]);
    $admissions = $stmtAdmissions->fetchAll(PDO::FETCH_ASSOC);

    foreach ($admissions as &$chkAdm) {
        $chkAdm['full_name'] = $chkAdm['display_name'];



        if ($chkAdm['stage'] === 'Payment' && (float)$chkAdm['remaining_balance'] <= 0 && $chkAdm['payment_method'] === 'PayMongo') {
                $pdo->prepare("UPDATE admissions SET stage = 'Discharged' WHERE id = ? AND TenantID = ?")->execute([$chkAdm['id'], $tenant_id]);
            $chkAdm['stage'] = 'Discharged';
        }
    }
    unset($chkAdm);

} catch (PDOException $e) {
    $dbError = "Database Error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Admission Overview - <?= htmlspecialchars($clinicName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = { theme: { extend: { colors: { "primary": "<?= htmlspecialchars($themeColor) ?>", "primary-dark": "color-mix(in srgb, <?= htmlspecialchars($themeColor) ?> 70%, black)", "primary-light": "color-mix(in srgb, <?= htmlspecialchars($themeColor) ?> 20%, white)", "background-light": "#f8fafc" }, fontFamily: { "display": ["Plus Jakarta Sans", "sans-serif"] }, boxShadow: { 'soft': '0 4px 20px -2px rgba(0,0,0,0.05)' } } } }
    </script>
    <style>
        html, body { margin: 0; padding: 0; scroll-behavior: smooth; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; overflow: hidden; background-color: #f8fafc;}
        .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        .icon-filled { font-variation-settings: 'FILL' 1; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .scrollable-box { scroll-behavior: smooth; }
        .table-row-card td { border-top: 1px solid #f1f5f9; border-bottom: 1px solid #f1f5f9; }
        .table-row-card td:first-child { border-left: 1px solid #f1f5f9; border-top-left-radius: 0.75rem; border-bottom-left-radius: 0.75rem; }
        .table-row-card td:last-child { border-right: 1px solid #f1f5f9; border-top-right-radius: 0.75rem; border-bottom-right-radius: 0.75rem; }
        .form-input { width: 100%; border-radius: 0.5rem; border: 1px solid #e2e8f0; padding: 0.5rem 0.75rem; font-size: 0.875rem; outline: none; transition: all 0.2s; }
        .form-input:focus:not([readonly]) { border-color: <?= htmlspecialchars($themeColor) ?>; box-shadow: 0 0 0 3px color-mix(in srgb, <?= htmlspecialchars($themeColor) ?> 20%, transparent); }
        .form-label { display: block; font-size: 0.75rem; font-weight: 700; color: #334155; margin-bottom: 0.25rem; }
        .req-star { color: #ef4444; }

        .toggle-checkbox:checked { right: 0; border-color: <?= htmlspecialchars($themeColor) ?>; }
        .toggle-checkbox:checked + .toggle-label { background-color: <?= htmlspecialchars($themeColor) ?>; }

        /* Receipt Jagged Edge */
        .receipt-edge {
            position: relative;
            background: #fff;
        }
        .receipt-edge::after {
            content: "";
            display: block;
            position: absolute;
            bottom: -10px;
            left: 0;
            width: 100%;
            height: 10px;
            background-size: 20px 20px;
            background-image: radial-gradient(circle at 10px 0, transparent 10px, #fff 11px);
        }
        @view-transition { navigation: auto; }
        header { view-transition-name: header; }
        aside { view-transition-name: sidebar; }
        ::view-transition-old(sidebar), ::view-transition-new(sidebar),
        ::view-transition-old(header), ::view-transition-new(header) { animation: none; }
    </style>
</head>
<body class="text-slate-800 h-screen flex flex-col relative text-sm antialiased font-display">

<?php if(isset($_GET['msg']) || isset($_GET['error']) || $dbError): ?>
    <?php
        $msgText = $dbError ? $dbError : ''; $msgColor = $dbError ? 'red' : 'emerald'; $icon = $dbError ? 'error' : 'check_circle';
        if(!$dbError) {
            if($_GET['msg'] == 'admitted') { $msgText = 'Patient successfully added to queue!'; }
            elseif($_GET['msg'] == 'checkup_saved') { $msgText = 'Check-up vitals recorded successfully!'; }
            elseif($_GET['msg'] == 'checkup_saved_assign_labor') { $msgText = 'Vitals recorded. Assign patient to a Labor Room next.'; }
            elseif($_GET['msg'] == 'checkup_saved_assign_delivery') { $msgText = 'Vitals recorded. Assign patient to a Delivery Room next.'; }
            elseif($_GET['msg'] == 'delivery_type_selected') { $msgText = 'Delivery type selected. Assign Delivery Room next.'; }
            elseif($_GET['msg'] == 'labor_room_assigned') { $msgText = 'Labor room assigned. You can now record vitals.'; }
            elseif($_GET['msg'] == 'delivery_room_assigned') { $msgText = 'Delivery room assigned. Assign Recovery Room next.'; }
            elseif($_GET['msg'] == 'recovery_room_assigned') { $msgText = 'Recovery room assigned. Register baby next.'; }
            elseif($_GET['msg'] == 'assign_labor_room') { $msgText = 'Assign patient to a Labor Room first before recording vitals.'; }
            elseif($_GET['msg'] == 'payment_recorded') { $msgText = 'Payment successfully processed!'; }
            elseif($_GET['msg'] == 'discharged') { $msgText = 'Patient discharged successfully!'; }
            elseif($_GET['msg'] == 'stage_updated') { $msgText = 'Patient stage updated successfully!'; }
            elseif($_GET['msg'] == 'newborn_registered') { $msgText = 'Newborn(s) successfully registered to mother!'; }
            elseif(isset($_GET['error']) && $_GET['error'] == 'contact') { $msgText = 'Contact number must be exactly 11 digits and start with 09.'; $msgColor = 'red'; $icon = 'error'; }
            elseif(isset($_GET['error']) && $_GET['error'] == 'no_staff') { $msgText = 'Please assign a staff/midwife first before admitting the patient.'; $msgColor = 'red'; $icon = 'error'; }
            elseif(isset($_GET['error']) && $_GET['error'] == 'midwife_only_delivery_type') { $msgText = 'Only a Midwife can select the delivery type. Receptionists are not allowed.'; $msgColor = 'red'; $icon = 'error'; }
        }
    ?>
    <?php if($msgText): ?>
    <div id="alertMsg" class="fixed top-24 left-1/2 -translate-x-1/2 z-[120] bg-white border-l-4 border-<?= $msgColor ?>-500 p-4 rounded-2xl shadow-2xl flex items-center gap-3 animate-bounce">
        <span class="material-symbols-outlined text-<?= $msgColor ?>-500"><?= $icon ?></span>
        <p class="text-xs font-black text-slate-800"><?= $msgText ?></p>
    </div>
    <script>setTimeout(() => { document.getElementById('alertMsg')?.remove(); }, 5000);</script>
    <?php endif; ?>
<?php endif; ?>

    <div id="receiptToast" class="fixed bottom-6 right-6 z-[130] hidden">
        <div class="bg-emerald-500 text-white px-4 py-3 rounded-2xl shadow-2xl flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px]">check_circle</span>
            <p class="text-xs font-black tracking-tight">Receipt saved successfully.</p>
        </div>
    </div>

<div id="loggingOutScreen" class="fixed inset-0 z-[110] hidden bg-white flex-col items-center justify-center">
    <div class="size-12 border-4 border-slate-200 border-t-primary rounded-full animate-spin mb-4"></div>
    <p class="font-bold text-slate-800 animate-pulse tracking-tight text-xs">Logging out safely...</p>
</div>

<div id="logoutModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm">
    <div class="bg-white rounded-[2rem] p-6 max-w-xs w-full shadow-2xl text-center border border-slate-100">
        <div class="size-12 rounded-2xl bg-red-50 text-red-500 flex items-center justify-center mx-auto mb-4">
            <span class="material-symbols-outlined text-2xl">logout</span>
        </div>
        <h3 class="text-base font-black text-slate-900 mb-1">Logout Account?</h3>
        <p class="text-slate-500 text-[11px] mb-6">Sigurado ka bang gusto mong lumabas?</p>
        <div class="flex gap-2">
            <button onclick="closeLogoutModal()" class="flex-1 py-2.5 rounded-xl font-bold text-slate-400 hover:bg-slate-100 transition-all text-[11px]">Cancel</button>
            <button onclick="confirmLogout()" class="flex-1 py-2.5 rounded-xl font-bold bg-red-500 text-white hover:bg-red-600 transition-all text-[11px] shadow-lg shadow-red-100">Logout</button>
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
            <a href="appointments.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all">
                <span class="material-symbols-outlined text-2xl">calendar_today</span> <span>Appointments</span>
            </a>
            <a href="admissions.php" onclick="event.preventDefault(); return false;" aria-current="page" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] <?= $sidebarActive ?> font-bold shadow-sm transition-all hover:scale-[1.02]">
                <span class="material-symbols-outlined text-2xl icon-filled">how_to_reg</span> <span>Admissions</span>
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

    <main class="flex-1 overflow-y-auto p-6 md:p-10 relative z-10">
        <div class="max-w-6xl mx-auto">

            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <h2 class="text-2xl font-black text-slate-900 tracking-tight">Admission Overview</h2>
                <button onclick="openAdmitModal()" class="bg-primary hover:bg-primary-dark text-white font-bold py-2.5 px-4 rounded-lg shadow-sm transition-all flex items-center justify-center gap-2 text-sm w-full sm:w-auto">
                    <span class="material-symbols-outlined text-[18px]">add</span> New Admission
                </button>
            </div>

            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-slate-200 mb-6 pb-2">
                <div class="flex items-center gap-6 overflow-x-auto hide-scrollbar">
                    <button onclick="switchTab('current')" id="tab-current" class="pb-2 border-b-2 border-primary text-primary font-bold text-sm transition-colors whitespace-nowrap">Current Patients</button>
                    <button onclick="switchTab('paid')" id="tab-paid" class="pb-2 border-b-2 border-transparent text-slate-400 hover:text-slate-700 font-bold text-sm transition-colors whitespace-nowrap">Paid History</button>
                </div>

                <div class="flex items-center gap-2 w-full md:w-auto">
                    <div class="relative flex-1 md:w-72">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-lg">search</span>
                        <input type="text" id="advSearchInput" onkeyup="filterAdmissionsTable()" placeholder="Search patient, service, or stage..." class="w-full pl-10 pr-4 py-2 rounded-xl border border-slate-200 text-sm focus:border-primary focus:ring-primary focus:ring-2 outline-none bg-white shadow-sm transition-all">
                    </div>
                    <select id="sortFilter" onchange="filterAdmissionsTable()" class="py-2 px-3 rounded-xl border border-slate-200 text-sm bg-white shadow-sm outline-none focus:border-primary focus:ring-primary focus:ring-2 cursor-pointer font-semibold text-slate-600">
                        <option value="newest">Newest</option>
                        <option value="oldest">Oldest</option>
                    </select>
                    <select id="serviceFilter" onchange="filterAdmissionsTable()" class="py-2 px-3 rounded-xl border border-slate-200 text-sm bg-white shadow-sm outline-none focus:border-primary focus:ring-primary focus:ring-2 cursor-pointer font-semibold text-slate-600">
                        <option value="all">All Services</option>
                        <?php
                        $uniqueServices = [];
                        foreach ($admissions as $a) {
                            $svc = trim($a['reason'] ?? '');
                            if ($svc !== '' && !in_array($svc, $uniqueServices)) {
                                $uniqueServices[] = $svc;
                            }
                        }
                        sort($uniqueServices);
                        foreach ($uniqueServices as $svc):
                        ?>
                        <option value="<?= htmlspecialchars($svc) ?>"><?= htmlspecialchars($svc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="overflow-x-auto w-full pb-10">
                <table class="w-full text-left border-separate border-spacing-y-2" id="admTable" style="min-width: 850px;">
                    <thead>
                        <tr>
                            <th class="px-5 py-2 text-[10px] font-black text-slate-400 uppercase tracking-widest">Patient</th>
                            <th class="px-5 py-2 text-[10px] font-black text-slate-400 uppercase tracking-widest">Service</th>
                            <th class="px-5 py-2 text-[10px] font-black text-slate-400 uppercase tracking-widest">Current Stage</th>
                            <th class="px-5 py-2 text-[10px] font-black text-slate-400 uppercase tracking-widest">Amount & Balance</th>
                            <th class="action-col px-5 py-2 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <?php
                        $totalPaidHistory = 0;
                        foreach($admissions as $adm) {
                            $isDischarged = $adm['status'] === 'Discharged' || $adm['stage'] === 'Discharged';
                            if ($isDischarged && !($adm['is_archived'] == 1)) {
                                $totalPaidHistory += (float)($adm['total_paid'] ?? 0);
                            }
                        }
                        ?>
                        <?php if(empty($admissions)): ?>
                            <tr>
                                <td colspan="5" class="py-10 text-center text-slate-400 font-medium bg-white rounded-xl shadow-sm border border-slate-100">
                                    No patient admission records found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($admissions as $adm): ?>
                            <?php
                               $initial = strtoupper(substr(trim($adm['full_name']), 0, 1));
                               $isArchived = ($adm['is_archived'] == 1);
                               $isDischarged = $adm['status'] === 'Discharged' || $adm['stage'] === 'Discharged';

                               if ($isArchived) { continue; }

                               $rowClass = $isDischarged ? 'adm-row-paid hidden' : 'adm-row-current';

                               $currentService = trim($adm['reason']);

                               //  USE STORED total_price IF AVAILABLE, FALLBACK TO RECALCULATE
                               $has_utz = (!empty($adm['lab_transvaginal']) || !empty($adm['lab_pelvic']));

                               // Compute PhilHealth coverage based on services the patient availed
                               $philhealthCoverageAmt = (float)($servicePhilhealthRates[$currentService] ?? 0);
                               if (!empty($adm['lab_transvaginal'])) {
                                   $philhealthCoverageAmt += (float)($servicePhilhealthRates['Transvaginal Ultrasound'] ?? 0);
                               }
                               if (!empty($adm['lab_pelvic'])) {
                                   $philhealthCoverageAmt += (float)($servicePhilhealthRates['Pelvic Ultrasound'] ?? 0);
                               }
                               if ($philhealthCoverageAmt < 0) $philhealthCoverageAmt = 0;

                               if (!empty($adm['total_price'])) {
                                   $servicePrice = (float)$adm['total_price'];
                               } else {
                                   $servicePrice = isset($servicePrices[$currentService]) ? (float)$servicePrices[$currentService] : 0;
                                   if (!empty($adm['lab_transvaginal']) && isset($servicePrices['Transvaginal Ultrasound'])) {
                                       $servicePrice += (float)$servicePrices['Transvaginal Ultrasound'];
                                   }
                                   if (!empty($adm['lab_pelvic']) && isset($servicePrices['Pelvic Ultrasound'])) {
                                       $servicePrice += (float)$servicePrices['Pelvic Ultrasound'];
                                   }
                               }

                               if ($has_utz) {
                                   $currentService .= ' + Ultrasounds';
                               }

                               // Always treat payment status based on actual payments table records.
                               $actualPaid = (float)($adm['total_paid'] ?? 0);
                               if (array_key_exists('remaining_balance', $adm) && $adm['remaining_balance'] !== null) {
                                   $runningTotal = (float)$adm['remaining_balance'];
                               } else {
                                   $runningTotal = $servicePrice - $actualPaid;
                               }

                               if ($runningTotal < 0) $runningTotal = 0;
                               $totalPaid = $actualPaid;
                               if ($totalPaid < 0) $totalPaid = 0;

                               //  Make displayed total bill reconcile with true paid + balance
                               // (covers room charges, philhealth, etc. that aren't in service catalog)
                               $reconciledTotal = $totalPaid + $runningTotal;
                               if ($reconciledTotal > $servicePrice) {
                                   $servicePrice = $reconciledTotal;
                               }

                               $isFollowUp = ($adm['payment_type'] === 'Follow-up');

                               $displayPrice = '₱ ' . number_format($servicePrice, 2);
                               $displayPaidAmount = $totalPaid > 0 ? ('₱ ' . number_format($totalPaid, 2)) : '—';
                               $displayBalanceAmount = '₱ ' . number_format($runningTotal, 2);

                               $runningTotalJS = number_format($runningTotal, 2, '.', '');
                               $servicePriceJS = number_format($servicePrice, 2, '.', '');
                               $admJson = htmlspecialchars(json_encode($adm), ENT_QUOTES, 'UTF-8');

                               $admitDateDisplay = 'N/A';
                               $admitTimeDisplay = '';
                               if (!empty($adm['admission_date'])) {
                                   try {
                                       $admitDt = new DateTime($adm['admission_date']);
                                       $admitDateDisplay = $admitDt->format('M d, Y');
                                       $admitTimeDisplay = $admitDt->format('h:i A');
                                   } catch (Exception $e) {
                                       $admitDateDisplay = htmlspecialchars((string)$adm['admission_date']);
                                   }
                               }

                               $stageColor = "bg-slate-100 text-slate-600 border-slate-200";
                               $stageLabel = strtoupper(trim($adm['stage'] ?: 'Waiting'));

                               if ($stageLabel === 'LABOR ROOM ASSIGNMENT' || $stageLabel === 'DELIVERY ROOM ASSIGNMENT' || $stageLabel === 'RECOVERY ROOM ASSIGNMENT') { $stageColor = "bg-purple-100 text-purple-700 border-purple-200"; }
                               elseif ($stageLabel === 'CHECKUP' || $stageLabel === 'LABOR') { $stageColor = "bg-blue-100 text-blue-700 border-blue-200"; }
                               elseif ($stageLabel === 'DELIVERY TYPE') { $stageColor = "bg-rose-100 text-rose-700 border-rose-200"; }
                               elseif ($stageLabel === 'NEWBORN') { $stageColor = "bg-pink-100 text-pink-700 border-pink-200"; }
                               elseif ($stageLabel === 'PAYMENT') { $stageColor = "bg-amber-100 text-amber-700 border-amber-200"; }
                               elseif ($stageLabel === 'DISCHARGED') {
                                   $stageColor = "bg-emerald-100 text-emerald-700 border-emerald-200";
                                   $stageLabel = "DISCHARGED";
                               }

                               // STATUS BADGE LOGIC
                               $paymentBadge = '';
                               if ($isFollowUp) {
                                   $paymentBadge = '<span class="text-[9px] bg-purple-100 text-purple-700 px-2 py-0.5 rounded border border-purple-200 font-black uppercase tracking-widest">Follow-up</span>';
                               } elseif ($totalPaid > 0 && $runningTotal <= 0) {
                                   $paymentBadge = '<span class="text-[9px] bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded border border-emerald-200 font-black uppercase tracking-widest">Fully Paid</span>';
                               } elseif (($adm['payment_type'] ?? '') === 'Downpayment') {
                                   $paymentBadge = '<span class="text-[9px] bg-blue-100 text-blue-700 px-2 py-0.5 rounded border border-blue-200 font-black uppercase tracking-widest">Downpayment</span>';
                               } else {
                                   $paymentBadge = '<span class="text-[9px] bg-slate-100 text-slate-500 px-2 py-0.5 rounded border border-slate-200 font-black uppercase tracking-widest">Unpaid</span>';
                               }

                               //  CHECK IF REASON IS DELIVERY
                               $isDelivery = (stripos($currentService, 'Delivery') !== false || stripos($currentService, 'Cesarean') !== false || stripos($currentService, 'C-Section') !== false || stripos($currentService, 'Birth') !== false || stripos($currentService, 'Labor') !== false);
                            ?>
                            <tr class="bg-white shadow-[0_2px_10px_-4px_rgba(0,0,0,0.05)] transition-all hover:shadow-md table-row-card <?= $rowClass ?>" data-date="<?= htmlspecialchars($adm['admission_date'] ?? '') ?>" data-service="<?= htmlspecialchars($adm['reason'] ?? '') ?>">
                                <td class="p-4">
                                    <div class="flex items-center gap-4">
                                        <?php if (!empty($adm['profile_pic_url'])): ?>
                                            <img src="<?= htmlspecialchars($adm['profile_pic_url']) ?>" alt="" class="size-9 rounded-full object-cover shrink-0 border border-blue-100">
                                        <?php else: ?>
                                            <div class="size-9 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center font-bold text-sm shrink-0 border border-blue-100"><?= $initial ?></div>
                                        <?php endif; ?>
                                        <div>
                                            <span class="font-bold text-slate-900 text-sm block patient-name-txt">
                                                <?= htmlspecialchars($adm['full_name']) ?>
                                                <?php if(isset($adm['age']) && $adm['age'] !== '' && (int)$adm['age'] < 18): ?>
                                                    <span class="text-[9px] bg-amber-100 text-amber-700 px-2 py-0.5 rounded border border-amber-200 font-black uppercase tracking-widest inline-flex items-center gap-0.5 ml-1 align-middle"><span class="material-symbols-outlined text-[11px]">shield_person</span> Minor</span>
                                                <?php endif; ?>
                                                <?php if(!empty($adm['patient_id'])): ?>
                                                    <br>
                                                    <span class="text-[10px] text-slate-400 font-normal">
                                                        <?php if(!empty($adm['patient_id'])): ?>
                                                            #<?= htmlspecialchars($adm['patient_id']) ?>
                                                        <?php endif; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </span>
                                            <span class="text-[10px] text-slate-400 font-bold flex items-center gap-1 mt-0.5"><span class="material-symbols-outlined text-[12px]">mail</span> <?= !empty($adm['account_email']) ? htmlspecialchars($adm['account_email']) : '<span class="italic text-slate-300">Patient has no account</span>' ?></span>
                                            <span class="text-[10px] text-slate-400 font-bold flex items-center gap-1 mt-0.5"><span class="material-symbols-outlined text-[12px]">badge</span> <?php $_dispStaff = trim((string)($adm['assigned_staff'] ?? '')); $_dispStaffLow = strtolower($_dispStaff); if ($_dispStaff === '' || $_dispStaffLow === 'unassigned' || $_dispStaffLow === 'assign later'): ?><span class="italic text-amber-600">Assign Later</span><?php else: ?><?= htmlspecialchars($_dispStaff) ?><?php endif; ?></span>
                                            <span class="text-[10px] text-slate-400 font-bold flex items-center gap-1 mt-0.5"><span class="material-symbols-outlined text-[12px]">schedule</span> <?= $admitDateDisplay ?><?= $admitTimeDisplay !== '' ? ' • ' . $admitTimeDisplay : '' ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-4 text-sm font-bold text-slate-700">
                                    <?= htmlspecialchars($currentService) ?>
                                </td>
                                <td class="p-4">
                                    <div class="flex items-center gap-2">
                                        <span class="px-3 py-1.5 rounded-md text-[10px] font-black uppercase tracking-widest border <?= $stageColor ?>">
                                            <?= $stageLabel ?>
                                        </span>
                                        <?php if (!$isDischarged && !$isArchived): ?>
                                            <button type="button" onclick="alert('Stage is updated automatically through the workflow buttons. Please use Checkup or Payment buttons.')" class="text-slate-300 cursor-not-allowed p-1 bg-slate-50 rounded transition-colors" title="Stage updates automatically"><span class="material-symbols-outlined text-[16px]">edit</span></button>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td class="p-4">
                                    <div class="flex flex-col items-start gap-1.5">
                                        <?php if($isDischarged): ?>
                                            <span class="font-mono text-sm font-bold text-slate-700">Price: <?= $displayPrice ?></span>
                                            <?php if (!empty($adm['pay_is_philhealth'])): ?>
                                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-green-50 border border-green-200 rounded-lg">
                                                    <img src="uploads/philhealth_logo.png" alt="PH" class="h-4 rounded" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex';">
                                                    <span class="material-symbols-outlined text-green-600 text-[14px] hidden">health_and_safety</span>
                                                    <span class="text-[9px] font-black text-green-700 uppercase tracking-widest">PhilHealth Covered</span>
                                                </span>
                                            <?php else: ?>
                                                <span class="font-mono text-sm font-bold text-emerald-700">Paid: <?= $displayPaidAmount ?></span>
                                            <?php endif; ?>
                                            <?php $pmDisplay = ($adm['payment_method'] === 'PayMongo' || $adm['payment_method'] === 'Online Payment') ? 'Online Payment' : 'Over the counter'; ?>
                                            <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest flex items-center gap-1 mt-0.5">
                                                <span class="material-symbols-outlined text-[11px]">payment</span> <?= $pmDisplay ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="font-mono text-sm font-black text-slate-800" title="Remaining Balance"><?= $displayBalanceAmount ?></span>
                                            <?= $paymentBadge ?>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td class="action-col p-4 text-right">
                                    <div class="flex flex-col justify-center gap-2 items-end w-full max-w-[140px] ml-auto">

                                        <?php if (!$isDischarged): ?>

                                            <?php if ($adm['stage'] === 'Waiting'): ?>
                                                <?php
                                                    $_staffVal = strtolower(trim($adm['assigned_staff'] ?? ''));
                                                    $staffAssigned = !empty($adm['assigned_staff']) && $_staffVal !== 'unassigned' && $_staffVal !== 'assign later' && strpos($_staffVal, 'no staff') === false;
                                                ?>
                                                <?php if (!$staffAssigned): ?>
                                                    <!-- No staff assigned: assign staff first -->
                                                    <button type="button" onclick="openAssignStaffModal(<?= $adm['id'] ?>, '<?= htmlspecialchars(addslashes($adm['full_name'])) ?>', '<?= htmlspecialchars(addslashes($adm['reason'])) ?>')" class="inline-flex w-full items-center justify-center gap-1.5 bg-indigo-500 hover:bg-indigo-600 text-white px-3 py-2 rounded-lg text-xs font-bold transition-all shadow-sm animate-pulse">
                                                        <span class="material-symbols-outlined text-[16px]">person_add</span> Assign Staff
                                                    </button>
                                                <?php else: ?>
                                                    <?php $_canCheckup = $canRecordVitalsFor($adm['reason'] ?? ''); ?>
                                                    <?php if ($_canCheckup): ?>
                                                    <form method="POST" action="<?= $currentPage ?>" class="w-full m-0">
                                                        <input type="hidden" name="start_checkup" value="1">
                                                        <input type="hidden" name="admission_id" value="<?= $adm['id'] ?>">
                                                        <button type="submit" class="inline-flex w-full items-center justify-center gap-1.5 bg-amber-500 hover:bg-amber-600 text-white px-3 py-2 rounded-lg text-xs font-bold transition-all shadow-sm">
                                                            <span class="material-symbols-outlined text-[16px]">stethoscope</span> <?= $isDelivery ? 'Record Vitals' : 'Checkup' ?>
                                                        </button>
                                                    </form>
                                                    <?php else: ?>
                                                    <?php $_blockTitle = ($isCurrentUserReceptionist && mh_is_labor_service($adm['reason'] ?? '')) ? 'Receptionists cannot perform Labor checkups — only a Midwife can.' : 'Only a Midwife (or Receptionist for non-labor services) can perform checkups'; ?>
                                                    <button type="button" disabled class="inline-flex w-full items-center justify-center gap-1.5 bg-slate-200 text-slate-400 px-3 py-2 rounded-lg text-xs font-bold cursor-not-allowed" title="<?= htmlspecialchars($_blockTitle) ?>">
                                                        <span class="material-symbols-outlined text-[16px]">stethoscope</span> <?= $isDelivery ? 'Record Vitals' : 'Checkup' ?>
                                                    </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>

                                                <?php elseif ($adm['stage'] === 'Labor Room Assignment'): ?>
                                                    <?php
                                                        $_staffValLR = strtolower(trim($adm['assigned_staff'] ?? ''));
                                                        $staffAssignedLR = !empty($adm['assigned_staff']) && $_staffValLR !== 'unassigned' && $_staffValLR !== 'assign later' && strpos($_staffValLR, 'no staff') === false;
                                                    ?>
                                                    <?php if (!$staffAssignedLR): ?>
                                                        <button type="button" onclick="openAssignStaffModal(<?= $adm['id'] ?>, '<?= htmlspecialchars(addslashes($adm['full_name'])) ?>', '<?= htmlspecialchars(addslashes($adm['reason'])) ?>')" class="inline-flex w-full items-center justify-center gap-1.5 bg-indigo-500 hover:bg-indigo-600 text-white px-3 py-2 rounded-lg text-xs font-bold transition-all shadow-sm">
                                                            <span class="material-symbols-outlined text-[16px]">person_add</span> Assign Staff
                                                        </button>
                                                    <?php endif; ?>
                                                    <button type="button" onclick="openBedAssignmentModal(<?= $adm['id'] ?>, 'labor')" class="inline-flex w-full items-center justify-center gap-1.5 bg-purple-500 hover:bg-purple-600 text-white px-3 py-2 rounded-lg text-xs font-bold transition-all shadow-sm animate-pulse">
                                                        <span class="material-symbols-outlined text-[16px]">bed</span> Assign Labor Room
                                                    </button>

                                                <?php elseif ($adm['stage'] === 'Delivery Room Assignment'): ?>
                                                    <button type="button" onclick="openBedAssignmentModal(<?= $adm['id'] ?>, 'delivery')" class="inline-flex w-full items-center justify-center gap-1.5 bg-fuchsia-500 hover:bg-fuchsia-600 text-white px-3 py-2 rounded-lg text-xs font-bold transition-all shadow-sm animate-pulse">
                                                        <span class="material-symbols-outlined text-[16px]">local_hospital</span> Assign Delivery Room
                                                    </button>

                                            <?php elseif ($adm['stage'] === 'Checkup' || $adm['stage'] === 'Labor'): ?>
                                                <?php
                                                    $_staffValHere = strtolower(trim($adm['assigned_staff'] ?? ''));
                                                    $staffAssignedHere = !empty($adm['assigned_staff']) && $_staffValHere !== 'unassigned' && $_staffValHere !== 'assign later' && strpos($_staffValHere, 'no staff') === false;
                                                ?>
                                                <?php if (!$staffAssignedHere): ?>
                                                    <button type="button" onclick="openAssignStaffModal(<?= $adm['id'] ?>, '<?= htmlspecialchars(addslashes($adm['full_name'])) ?>', '<?= htmlspecialchars(addslashes($adm['reason'])) ?>')" class="inline-flex w-full items-center justify-center gap-1.5 bg-indigo-500 hover:bg-indigo-600 text-white px-3 py-2 rounded-lg text-xs font-bold transition-all shadow-sm animate-pulse">
                                                        <span class="material-symbols-outlined text-[16px]">person_add</span> Assign Staff
                                                    </button>
                                                <?php elseif ($canRecordVitalsFor($adm['reason'] ?? '')): ?>
                                                    <button type="button" onclick='openCheckupModal(<?= $admJson ?>)' class="inline-flex w-full items-center justify-center gap-1.5 bg-blue-500 hover:bg-blue-600 text-white px-3 py-2 rounded-lg text-xs font-bold transition-all shadow-sm">
                                                        <span class="material-symbols-outlined text-[16px]">monitor_heart</span> Record Vitals
                                                    </button>
                                                <?php else: ?>
                                                    <?php $_blockTitle2 = ($isCurrentUserReceptionist && mh_is_labor_service($adm['reason'] ?? '')) ? 'Receptionists cannot record vitals for Labor — only a Midwife can.' : 'Only a Midwife (or Receptionist for non-labor services) can record vitals'; ?>
                                                    <button type="button" disabled class="inline-flex w-full items-center justify-center gap-1.5 bg-slate-200 text-slate-400 px-3 py-2 rounded-lg text-xs font-bold cursor-not-allowed" title="<?= htmlspecialchars($_blockTitle2) ?>">
                                                        <span class="material-symbols-outlined text-[16px]">monitor_heart</span> Record Vitals
                                                    </button>
                                                <?php endif; ?>

                                            <?php elseif ($adm['stage'] === 'Delivery Type'): ?>
                                                <?php if ($isCurrentUserMidwife): ?>
                                                <button type="button" onclick="openDeliveryTypeModal(<?= $adm['id'] ?>, '<?= htmlspecialchars(addslashes($adm['reason'])) ?>')" class="inline-flex w-full items-center justify-center gap-1.5 bg-rose-500 hover:bg-rose-600 text-white px-3 py-2 rounded-lg text-xs font-bold transition-all shadow-sm">
                                                    <span class="material-symbols-outlined text-[16px]">fact_check</span> Select Delivery Type
                                                </button>
                                                <?php else: ?>
                                                <button type="button" disabled class="inline-flex w-full items-center justify-center gap-1.5 bg-slate-200 text-slate-400 px-3 py-2 rounded-lg text-xs font-bold cursor-not-allowed" title="Only a Midwife can select the delivery type.">
                                                    <span class="material-symbols-outlined text-[16px]">fact_check</span> Midwife Only
                                                </button>
                                                <?php endif; ?>

                                            <?php elseif ($adm['stage'] === 'Recovery Room Assignment'): ?>
                                                <button type="button" onclick="openBedAssignmentModal(<?= $adm['id'] ?>, 'recovery')" class="inline-flex w-full items-center justify-center gap-1.5 bg-violet-500 hover:bg-violet-600 text-white px-3 py-2 rounded-lg text-xs font-bold transition-all shadow-sm animate-pulse">
                                                    <span class="material-symbols-outlined text-[16px]">hotel</span> Assign Recovery Room
                                                </button>

                                            <?php elseif ($adm['stage'] === 'Newborn'): ?>
                                                <?php if ($isCurrentUserMidwife): ?>
                                                <button type="button" onclick="openNewbornModal(<?= $adm['id'] ?>, '<?= $adm['patient_id'] ?>', '<?= htmlspecialchars(addslashes($adm['full_name'])) ?>', '<?= htmlspecialchars(addslashes($adm['reason'])) ?>', '<?= htmlspecialchars(addslashes($adm['assigned_staff'])) ?>')" class="inline-flex w-full items-center justify-center gap-1.5 bg-pink-500 hover:bg-pink-600 text-white px-3 py-2 rounded-lg text-xs font-bold transition-all shadow-sm animate-pulse">
                                                    <span class="material-symbols-outlined text-[16px]">child_care</span> Register Baby
                                                </button>
                                                <?php else: ?>
                                                <button type="button" disabled class="inline-flex w-full items-center justify-center gap-1.5 bg-slate-200 text-slate-400 px-3 py-2 rounded-lg text-xs font-bold cursor-not-allowed" title="Only Midwife can register baby">
                                                    <span class="material-symbols-outlined text-[16px]">child_care</span> Register Baby
                                                </button>
                                                <?php endif; ?>

                                            <?php elseif ($adm['stage'] === 'Payment'): ?>
                                                <div class="flex items-center gap-1.5 w-full justify-end flex-nowrap">
                                                    <button type="button" onclick='openCheckupModal(<?= $admJson ?>)' class="inline-flex items-center justify-center bg-slate-100 text-slate-600 hover:bg-slate-200 p-2 rounded-lg transition-all shadow-sm shrink-0" title="Review Vitals">
                                                        <span class="material-symbols-outlined text-[18px]">monitor_heart</span>
                                                    </button>
                                                    <button type="button" onclick="openPaymentModal(<?= $adm['id'] ?>, '<?= htmlspecialchars(addslashes($adm['full_name'])) ?>', '<?= htmlspecialchars(addslashes($currentService)) ?>', '<?= htmlspecialchars(addslashes($adm['reason'])) ?>', <?= $runningTotalJS ?>, 'discharge', '<?= htmlspecialchars(addslashes($adm['profile_pic_url'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($adm['philhealth_id_pic_front'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($adm['philhealth_id_pic_back'] ?? '')) ?>', <?= number_format($philhealthCoverageAmt, 2, '.', '') ?>)" class="inline-flex w-full items-center justify-center gap-1.5 bg-emerald-500 hover:bg-emerald-600 text-white px-3 py-2 rounded-lg text-xs font-bold transition-all shadow-sm whitespace-nowrap">
                                                        <span class="material-symbols-outlined text-[16px]">payments</span> Proceed to Payment
                                                    </button>
                                                </div>

                                                <?php if($isDelivery && $adm['has_registered_baby']): ?>
                                                    <span class="text-[9px] font-bold text-pink-500 uppercase tracking-widest w-full text-center block mt-1"><span class="material-symbols-outlined text-[11px] align-middle">check_circle</span> Baby Registered</span>
                                                <?php endif; ?>

                                            <?php else: ?>
                                                <span class="text-[10px] text-slate-400 italic">Processing...</span>
                                            <?php endif; ?>

                                        <?php endif; ?>

                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- PAGINATION -->
                <div id="paginationControls" class="flex items-center justify-between mt-4 px-1">
                    <p id="paginationInfo" class="text-[11px] text-slate-400 font-bold"></p>
                    <div id="paginationButtons" class="flex items-center gap-1"></div>
                </div>

                <div id="paidHistorySummary" class="hidden mt-4 bg-emerald-50 border border-emerald-200 rounded-2xl p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-widest text-emerald-600 mb-1">Total Amount Paid (History)</p>
                            <p class="text-3xl font-black text-emerald-700 tracking-tight">₱ <span id="totalPaidAmount"><?= number_format($totalPaidHistory, 2) ?></span></p>
                        </div>
                        <span class="material-symbols-outlined text-5xl text-emerald-200">verified</span>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<div id="checkupModal" class="fixed inset-0 z-[145] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
    <div id="checkupModalBox" class="bg-white rounded-[1.75rem] shadow-2xl border border-slate-100 w-full max-w-4xl flex flex-col max-h-[90vh] transform scale-95 opacity-0 transition-all duration-300">
        <div class="px-6 py-4 border-b border-amber-100 flex items-center justify-between bg-amber-50 rounded-t-[1.75rem]">
            <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-amber-500 text-2xl icon-filled">monitor_heart</span>
                <div>
                    <h3 class="text-lg font-black text-amber-900 tracking-tight leading-none">Record Vitals & Check-up</h3>
                    <p class="text-[10px] text-amber-700 mt-1 font-medium">Review patient information, encode vitals, and attach lab results.</p>
                </div>
            </div>
            <button type="button" onclick="closeCheckupModal()" class="size-8 rounded-full hover:bg-amber-100 text-amber-400 flex items-center justify-center transition-colors"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>

        <form method="POST" action="<?= $currentPage ?>" enctype="multipart/form-data" class="p-6 overflow-y-auto scrollable-box space-y-6">
            <input type="hidden" name="save_checkup_admission" value="1">
            <input type="hidden" name="adm_id" id="c_adm_id">

            <div class="bg-slate-50 border border-slate-200 rounded-xl p-5 shadow-sm">
                <div class="flex items-center gap-2 mb-4 border-b border-slate-100 pb-2">
                    <span class="material-symbols-outlined text-slate-500 icon-filled">badge</span>
                    <h4 class="text-sm font-black text-slate-800 tracking-tight">Patient Information</h4>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="form-label">Full Name</label>
                        <input type="text" id="c_full_name" class="form-input bg-slate-100" readonly>
                    </div>
                    <div>
                        <label class="form-label">Service</label>
                        <input type="text" id="c_service" class="form-input bg-slate-100" readonly>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div>
                        <label class="form-label">Spouse</label>
                        <input type="text" id="c_spouse_name" class="form-input bg-slate-100" readonly>
                    </div>
                    <div>
                        <label class="form-label">Date of Birth</label>
                        <input type="date" id="c_dob" class="form-input bg-slate-100" readonly>
                    </div>
                    <div>
                        <label class="form-label">Age</label>
                        <input type="number" id="c_age" class="form-input bg-slate-100" readonly>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div>
                        <label class="form-label">Religion</label>
                        <input type="text" id="c_religion" class="form-input bg-slate-100" readonly>
                    </div>
                    <div>
                        <label class="form-label">Father's Name</label>
                        <input type="text" id="c_father" class="form-input bg-slate-100" readonly>
                    </div>
                    <div>
                        <label class="form-label">Mother's Name</label>
                        <input type="text" id="c_mother" class="form-input bg-slate-100" readonly>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="form-label">Contact Number</label>
                        <input type="text" id="c_contact" class="form-input bg-slate-100" readonly>
                    </div>
                    <div>
                        <label class="form-label">Address</label>
                        <textarea id="c_address" rows="1" class="form-input bg-slate-100 min-h-[42px] resize-y" readonly></textarea>
                    </div>
                </div>

                <div id="c_pregnancy_block" class="mt-4 bg-pink-50 border border-pink-200 rounded-xl p-4 hidden">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="material-symbols-outlined text-pink-500 text-base icon-filled">pregnant_woman</span>
                        <h5 class="text-xs font-black text-pink-900 tracking-tight uppercase">Maternity / Pregnancy</h5>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="form-label text-pink-800">Last Menstrual Period</label>
                            <input type="date" id="c_lmp" name="last_menstrual_period" class="form-input bg-white border-pink-300 text-pink-900 font-bold focus:border-pink-500 focus:ring-pink-200" onchange="recomputeEddFromLmp()">
                            <p class="text-[10px] text-pink-500 mt-1">Editable. EDD auto-recomputes (Naegele's rule).</p>
                        </div>
                        <div id="c_edd_wrap" class="hidden">
                            <label class="form-label text-pink-800">Estimated Date of Delivery</label>
                            <input type="date" id="c_edd" class="form-input bg-pink-100/60 border-pink-200 text-pink-900 font-bold cursor-not-allowed" readonly>
                        </div>
                        <div id="c_pregnancy_status_wrap" class="hidden">
                            <label class="form-label text-pink-800">Pregnancy Status</label>
                            <select name="pregnancy_status" id="c_pregnancy_status" class="form-input border-pink-300 font-bold text-pink-900">
                                <option value="Pending Confirmation">Pending Confirmation</option>
                                <option value="Confirmed Pregnant">Confirmed Pregnant</option>
                                <option value="Not Pregnant">Not Pregnant</option>
                                <option value="Miscarriage">Miscarriage</option>
                            </select>
                            <p class="text-[10px] text-pink-500 mt-1">Set after doctor confirms or after delivery.</p>
                        </div>
                    </div>

                    <div id="c_gp_wrap" class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                        <div>
                            <label class="form-label text-pink-800">Gravida <span class="text-pink-400 font-normal">(total pregnancies)</span></label>
                            <div class="flex items-center gap-2">
                                <button type="button" onclick="adjustGP('gravida', -1)" class="size-9 rounded-lg bg-pink-100 hover:bg-pink-200 text-pink-700 font-black text-lg flex items-center justify-center transition-colors">−</button>
                                <input type="number" min="0" max="20" name="gravida" id="c_gravida" value="0" class="form-input text-center font-black text-pink-900 border-pink-300 w-20" onchange="syncGPSummary()">
                                <button type="button" onclick="adjustGP('gravida', 1)" class="size-9 rounded-lg bg-pink-100 hover:bg-pink-200 text-pink-700 font-black text-lg flex items-center justify-center transition-colors">+</button>
                                <span id="c_gp_summary" class="ml-3 text-sm font-black text-pink-700 bg-pink-100 border border-pink-200 px-3 py-1.5 rounded-lg">G0P0</span>
                            </div>
                        </div>
                        <div>
                            <label class="form-label text-pink-800">Para <span class="text-pink-400 font-normal">(births past 20 wks)</span></label>
                            <div class="flex items-center gap-2">
                                <button type="button" onclick="adjustGP('para', -1)" class="size-9 rounded-lg bg-pink-100 hover:bg-pink-200 text-pink-700 font-black text-lg flex items-center justify-center transition-colors">−</button>
                                <input type="number" min="0" max="20" name="para" id="c_para" value="0" class="form-input text-center font-black text-pink-900 border-pink-300 w-20" onchange="syncGPSummary()">
                                <button type="button" onclick="adjustGP('para', 1)" class="size-9 rounded-lg bg-pink-100 hover:bg-pink-200 text-pink-700 font-black text-lg flex items-center justify-center transition-colors">+</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-amber-50 border border-amber-200 rounded-xl p-5 shadow-sm">
                <div class="flex items-center gap-2 mb-4 border-b border-amber-100 pb-2">
                    <span class="material-symbols-outlined text-amber-500 icon-filled">stethoscope</span>
                    <h4 class="text-sm font-black text-amber-900 tracking-tight">Vital Signs</h4>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
                    <div>
                        <label class="form-label">Blood Pressure</label>
                        <input type="text" name="bp" id="c_bp" class="form-input font-mono text-sm" placeholder="120/80" required>
                    </div>
                    <div>
                        <label class="form-label">Temperature (°C)</label>
                        <input type="number" step="0.1" name="temp" id="c_temp" class="form-input font-mono text-sm" placeholder="36.8" required>
                    </div>
                    <div>
                        <label class="form-label" id="c_weight_label">Weight (kg)</label>
                        <input type="number" step="0.1" name="weight" id="c_weight" class="form-input font-mono text-sm" placeholder="60.0" required>
                    </div>
                    <div>
                        <label class="form-label">Pulse (bpm)</label>
                        <input type="number" name="pulse" id="c_pulse" class="form-input font-mono text-sm" placeholder="80" required>
                    </div>
                    <div>
                        <label class="form-label">SpO₂ (%)</label>
                        <input type="number" name="spo2" id="c_spo2" class="form-input font-mono text-sm" placeholder="98" required>
                    </div>
                </div>
            </div>

            <div id="fetalSection" class="bg-pink-50/60 border border-pink-200 rounded-xl p-5 shadow-sm hidden">
                <div class="flex items-center justify-between mb-3 border-b border-pink-100 pb-2">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-pink-500 icon-filled">child_care</span>
                        <h4 class="text-sm font-black text-pink-900 tracking-tight">Fetal Status</h4>
                    </div>
                    <div class="flex items-center gap-2 text-xs font-bold text-slate-600">
                        <span>Record Fetal Status</span>
                        <div class="relative inline-block w-10 align-middle select-none transition duration-200 ease-in">
                            <input type="checkbox" id="toggleFetal" name="enable_fetal" value="1" class="toggle-checkbox absolute block w-5 h-5 rounded-full bg-white border-4 appearance-none cursor-pointer z-10" onchange="toggleFetalFields()"/>
                            <label for="toggleFetal" class="toggle-label block overflow-hidden h-5 rounded-full bg-gray-300 cursor-pointer"></label>
                        </div>
                    </div>
                </div>
                <p class="text-[11px] text-pink-700 mb-3">Measurements taken to monitor the baby's growth and well-being inside the womb.</p>
                <div id="fetalInputs" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 opacity-50 pointer-events-none">
                    <div>
                        <label class="form-label text-[11px]">AOG (Age of Gestation)</label>
                        <input type="text" name="fetal_aog" id="c_fetal_aog" class="form-input text-sm" placeholder="e.g. 24 weeks and 2 days">
                        <p class="text-[10px] text-pink-500 mt-1">Auto-computed from LMP. Editable.</p>
                    </div>
                    <div>
                        <label class="form-label text-[11px]">Fundal Height (cm)</label>
                        <input type="number" step="0.1" min="0" name="fetal_fundal_height" id="c_fetal_fundal_height" class="form-input text-sm font-mono" placeholder="e.g. 24">
                        <p class="text-[10px] text-pink-500 mt-1">Measured from pubic bone to top of uterus.</p>
                    </div>
                    <div>
                        <label class="form-label text-[11px]">FHT (Fetal Heart Tones)</label>
                        <input type="text" name="fetal_fht" id="c_fetal_fht" class="form-input text-sm font-mono" placeholder="e.g. 140 bpm">
                        <p class="text-[10px] text-pink-500 mt-1">Normal range: 120–160 bpm.</p>
                    </div>
                    <div>
                        <label class="form-label text-[11px]">Fetal Presentation</label>
                        <select name="fetal_presentation" id="c_fetal_presentation" class="form-input text-sm">
                            <option value="">-- Select --</option>
                            <option value="Cephalic">Cephalic (Head-down)</option>
                            <option value="Breech">Breech (Bottom-first)</option>
                            <option value="Transverse">Transverse (Sideways)</option>
                            <option value="Oblique">Oblique (Diagonal)</option>
                            <option value="Unknown">Unknown</option>
                        </select>
                        <p class="text-[10px] text-pink-500 mt-1">Position of the baby in the womb.</p>
                    </div>
                </div>
            </div>

            <div class="bg-emerald-50/40 border border-emerald-200 rounded-xl p-5 shadow-sm">
                <div class="flex items-center justify-between mb-3 border-b border-emerald-100 pb-2">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-emerald-500 icon-filled">science</span>
                        <h4 class="text-sm font-black text-emerald-900 tracking-tight">Laboratory Results</h4>
                    </div>
                    <div class="flex items-center gap-2 text-xs font-bold text-slate-600">
                        <span>Enable Lab Fields</span>
                        <div class="relative inline-block w-10 align-middle select-none transition duration-200 ease-in">
                            <input type="checkbox" id="toggleLabs" class="toggle-checkbox absolute block w-5 h-5 rounded-full bg-white border-4 appearance-none cursor-pointer z-10" onchange="toggleLabFields()"/>
                            <label for="toggleLabs" class="toggle-label block overflow-hidden h-5 rounded-full bg-gray-300 cursor-pointer"></label>
                        </div>
                    </div>
                </div>

                <div id="labInputs" class="space-y-4 opacity-50 pointer-events-none">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="form-label text-[11px]">CBC</label>
                            <select name="lab_cbc" id="c_lab_cbc" class="form-input text-xs">
                                <option value="">-- Select --</option>
                                <option value="Normal">Normal</option>
                                <option value="Abnormal">Abnormal</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label text-[11px]">Urinalysis</label>
                            <select name="lab_urinalysis" id="c_lab_urinalysis" class="form-input text-xs">
                                <option value="">-- Select --</option>
                                <option value="Clear (Normal)">Clear (Normal)</option>
                                <option value="Hazy (Normal to Mild)">Hazy (Normal to Mild)</option>
                                <option value="Cloudy (Infection)">Cloudy (Infection)</option>
                                <option value="Turbid (High Indication or Crystals)">Turbid (High Indication or Crystals)</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label text-[11px]">Blood Type</label>
                            <select name="lab_blood_type" id="c_lab_blood_type" class="form-input text-xs">
                                <option value="">-- Select --</option>
                                <option value="A+">A+</option>
                                <option value="A-">A-</option>
                                <option value="B+">B+</option>
                                <option value="B-">B-</option>
                                <option value="O+">O+</option>
                                <option value="O-">O-</option>
                                <option value="AB+">AB+</option>
                                <option value="AB-">AB-</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="form-label text-[11px]">Blood Sugar</label>
                            <select name="lab_blood_sugar" id="c_lab_blood_sugar" class="form-input text-xs">
                                <option value="">-- Select --</option>
                                <option value="Normal">Normal</option>
                                <option value="Elevated">Elevated</option>
                                <option value="High">High</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label text-[11px]">Hep B</label>
                            <select name="lab_hep_b" id="c_lab_hep_b" class="form-input text-xs">
                                <option value="">-- Select --</option>
                                <option value="Non-Reactive">Non-Reactive</option>
                                <option value="Reactive">Reactive</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label text-[11px]">Syphilis</label>
                            <select name="lab_syphilis" id="c_lab_syphilis" class="form-input text-xs">
                                <option value="">-- Select --</option>
                                <option value="Non-Reactive">Non-Reactive</option>
                                <option value="Reactive">Reactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div id="tv_ultrasound_group" <?= !$isTransvaginalActive ? 'style="display:none;"' : '' ?>>
                            <label class="form-label text-[11px]">Transvaginal Ultrasound (Image/PDF)</label>
                            <input type="file" name="lab_transvaginal" id="c_lab_transvaginal" accept="image/*,application/pdf" class="form-input text-xs" <?= !$isTransvaginalActive ? 'disabled' : '' ?>>
                            <div id="tv_image_preview" class="mt-1 hidden"></div>
                        </div>
                        <div id="pelvic_ultrasound_group" <?= !$isPelvicActive ? 'style="display:none;"' : '' ?>>
                            <label class="form-label text-[11px]">Pelvic Ultrasound (Image/PDF)</label>
                            <input type="file" name="lab_pelvic" id="c_lab_pelvic" accept="image/*,application/pdf" class="form-input text-xs" <?= !$isPelvicActive ? 'disabled' : '' ?>>
                            <div id="pelvic_image_preview" class="mt-1 hidden"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="followupSection" class="bg-slate-50 border border-slate-200 rounded-xl p-5 shadow-sm">
                <div class="flex items-center justify-between mb-3 border-b border-slate-100 pb-2">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-slate-500 icon-filled">event</span>
                        <h4 class="text-sm font-black text-slate-800 tracking-tight">Schedule Follow-up Visit</h4>
                    </div>
                    <div class="flex items-center gap-2 text-xs font-bold text-slate-600">
                        <span>Enable Follow-up</span>
                        <div class="relative inline-block w-10 align-middle select-none transition duration-200 ease-in">
                            <input type="checkbox" id="toggleFollowup" name="enable_followup" value="1" class="toggle-checkbox absolute block w-5 h-5 rounded-full bg-white border-4 appearance-none cursor-pointer z-10" onchange="toggleFollowupFields()"/>
                            <label for="toggleFollowup" class="toggle-label block overflow-hidden h-5 rounded-full bg-gray-300 cursor-pointer"></label>
                        </div>
                    </div>
                </div>

                <div id="followupInputs" class="space-y-4 opacity-50 pointer-events-none">
                    <div id="fuServiceRow" class="hidden">
                        <label class="form-label">Follow-up Service</label>
                        <select name="followup_service" id="fu_service" class="form-input text-sm font-bold">
                            <option value="">-- Same as current service --</option>
                            <option value="Prenatal Checkup">Prenatal Checkup</option>
                            <option value="Normal Delivery">Normal Delivery</option>
                            <option value="Cesarean Delivery">Cesarean Delivery</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="form-label">Follow-up Date</label>
                            <input type="date" name="followup_date" id="fu_date" class="form-input" onchange="refreshFollowupStaff()">
                        </div>
                        <div>
                            <label class="form-label">Follow-up Time</label>
                            <div class="relative">
                                <button type="button" id="fuTimeSlotBtn" onclick="openTimeSlotPicker('followup')" class="form-input w-full text-left flex items-center justify-between cursor-pointer hover:border-primary transition-colors">
                                    <span id="fuTimeSlotLabel" class="text-slate-400">-- Select Time Slot --</span>
                                    <span class="material-symbols-outlined text-slate-400 text-lg">schedule</span>
                                </button>
                                <input type="hidden" name="followup_time" id="fu_time" value="">
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Assign Staff</label>
                        <select name="followup_staff" id="fu_staff" class="form-input text-sm font-bold">
                            <option value="Unassigned">-- Assign Later --</option>
                            <?php foreach ($clinicStaffList as $st): ?>
                                <?php
                                    $parts = array_filter([$st['first_name'] ?? '', $st['middle_name'] ?? '', $st['last_name'] ?? '']);
                                    $staffName = trim(implode(' ', $parts));
                                ?>
                                <option value="<?= htmlspecialchars($staffName) ?>"><?= htmlspecialchars($staffName) ?><?= !empty($st['role']) ? ' (' . htmlspecialchars($st['role']) . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p id="fuStaffStatus" class="text-[10px] mt-1 hidden"></p>
                    </div>
                </div>
                <p class="text-[11px] text-slate-500 mt-3">System will automatically check slot availability based on active clinic staff before saving the follow-up schedule.</p>
            </div>

            <div class="flex items-center justify-end gap-3 pt-2">
                <button type="button" onclick="closeCheckupModal()" class="px-5 py-2.5 rounded-lg font-bold text-slate-600 bg-white border border-slate-200 hover:bg-slate-50 transition-colors">Cancel</button>
                <button type="submit" id="btnSaveVitals" class="px-6 py-2.5 rounded-lg font-bold bg-amber-500 text-white hover:bg-amber-600 transition-all shadow-md flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-[18px]">monitor_heart</span>
                    Save Vitals
                </button>
            </div>
        </form>
    </div>
</div>

<div id="deliveryTypeModal" class="fixed inset-0 z-[149] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
    <div id="deliveryTypeModalBox" class="bg-white rounded-[1.75rem] shadow-2xl border border-slate-100 w-full max-w-lg flex flex-col transform scale-95 opacity-0 transition-all duration-300">
        <div class="px-6 py-4 border-b border-rose-100 flex items-center justify-between bg-rose-50 rounded-t-[1.75rem]">
            <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-rose-500 text-2xl icon-filled">fact_check</span>
                <div>
                    <h3 class="text-lg font-black text-rose-800 tracking-tight leading-none">Select Delivery Type</h3>
                    <p class="text-[10px] text-rose-600 mt-1 font-medium">Choose final delivery method to apply service pricing.</p>
                </div>
            </div>
            <button type="button" onclick="closeDeliveryTypeModal()" class="size-8 rounded-full hover:bg-rose-200 text-rose-400 flex items-center justify-center transition-colors"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>

        <form method="POST" action="<?= $currentPage ?>" class="p-6 space-y-5">
            <input type="hidden" name="choose_delivery_type" value="1">
            <input type="hidden" name="admission_id" id="dt_adm_id">

            <div>
                <label class="form-label">Delivery Type <span class="req-star">*</span></label>
                <select name="selected_delivery_type" id="dt_selected_delivery_type" required class="form-input font-bold">
                    <option value="Normal Delivery">Normal Delivery</option>
                    <option value="Cesarean Delivery">Cesarean Delivery</option>
                </select>
            </div>

            <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 text-xs text-slate-600">
                Price will be loaded from Financials and reflected to Amount and Balance automatically.
            </div>

            <div class="flex items-center justify-end gap-3 pt-2">
                <button type="button" onclick="closeDeliveryTypeModal()" class="px-5 py-2.5 rounded-lg font-bold text-slate-600 bg-white border border-slate-200 hover:bg-slate-50 transition-colors">Cancel</button>
                <button type="submit" class="px-6 py-2.5 rounded-lg font-bold bg-rose-500 text-white hover:bg-rose-600 transition-all shadow-md flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-[18px]">task_alt</span>
                    Confirm Delivery Type
                </button>
            </div>
        </form>
    </div>
</div>

<div id="bedAssignmentModal" class="fixed inset-0 z-[148] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
    <div id="bedAssignmentModalBox" class="bg-white rounded-[1.75rem] shadow-2xl border border-slate-100 w-full max-w-lg flex flex-col transform scale-95 opacity-0 transition-all duration-300">
        <div class="px-6 py-4 border-b border-purple-100 flex items-center justify-between bg-purple-50 rounded-t-[1.75rem]">
            <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-purple-500 text-2xl icon-filled">bed</span>
                <div>
                    <h3 id="ba_title" class="text-lg font-black text-purple-800 tracking-tight leading-none">Assign Labor Room Bed</h3>
                    <p id="ba_subtitle" class="text-[10px] text-purple-600 mt-1 font-medium">Select labor room and available bed before recording vitals.</p>
                </div>
            </div>
            <button type="button" onclick="closeBedAssignmentModal()" class="size-8 rounded-full hover:bg-purple-200 text-purple-400 flex items-center justify-center transition-colors"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>

        <form method="POST" action="<?= $currentPage ?>" class="p-6 space-y-5 max-h-[calc(90vh-200px)] overflow-y-auto">
            <input type="hidden" name="assign_bed_room" value="1">
            <input type="hidden" name="assignment_mode" id="ba_assignment_mode" value="labor">
            <input type="hidden" name="admission_id" id="ba_adm_id">

            <div>
                <label id="ba_room_label" class="form-label">Labor Room <span class="req-star text-red-500">*</span></label>
                <select name="room_id" id="ba_room_id" required onchange="updateBedOptions()" class="form-input font-bold border-slate-300">
                    <option value="">-- Select Room --</option>
                </select>
            </div>

            <div>
                <label class="form-label">Available Bed <span class="req-star text-red-500">*</span></label>
                <select name="bed_id" id="ba_bed_id" required class="form-input font-bold border-slate-300">
                    <option value="">-- Select Room First --</option>
                </select>
                <p id="ba_no_beds_msg" class="text-xs text-red-500 mt-1 hidden">No available beds in selected room.</p>
            </div>

            <div class="bg-purple-50 border border-purple-200 rounded-xl p-4 text-xs text-purple-700">
                <span class="material-symbols-outlined text-[14px] align-middle mr-1">info</span>
                Beds marked as occupied cannot be assigned.
            </div>

            <div class="flex items-center justify-end gap-3 pt-2">
                <button type="button" onclick="closeBedAssignmentModal()" class="px-5 py-2.5 rounded-lg font-bold text-slate-600 bg-white border border-slate-200 hover:bg-slate-50 transition-colors">Cancel</button>
                <button type="submit" id="ba_submit_btn" class="px-6 py-2.5 rounded-lg font-bold bg-purple-500 text-white hover:bg-purple-600 transition-all shadow-md flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-[18px]">task_alt</span>
                    Assign Bed & Start Labor
                </button>
            </div>
        </form>
    </div>
</div>

<div id="newbornModal" class="fixed inset-0 z-[150] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
    <div id="newbornModalBox" class="bg-white rounded-[1.75rem] shadow-2xl border border-slate-100 w-full max-w-2xl flex flex-col max-h-[90vh] transform scale-95 opacity-0 transition-all duration-300">
        <div class="px-6 py-4 border-b border-pink-100 flex items-center justify-between bg-pink-50 rounded-t-[1.75rem]">
            <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-pink-500 text-2xl icon-filled">child_friendly</span>
                <div>
                    <h3 class="text-lg font-black text-pink-800 tracking-tight leading-none">Register Newborn</h3>
                    <p class="text-[10px] text-pink-600 mt-1 font-medium">Link baby to mother: <span id="nb_mom_name" class="font-bold"></span></p>
                </div>
            </div>
            <button type="button" onclick="closeNewbornModal()" class="size-8 rounded-full hover:bg-pink-200 text-pink-400 flex items-center justify-center transition-colors"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>

        <form method="POST" action="<?= $currentPage ?>" id="newbornForm" onsubmit="if(!this.attending_staff.value || this.attending_staff.value === 'Unassigned'){ alert('Please select an Attending Midwife/Doctor.'); return false; }" class="p-6 overflow-y-auto scrollable-box space-y-6">
            <input type="hidden" name="register_newborn" value="1">
            <input type="hidden" name="nb_adm_id" id="nb_adm_id">
            <input type="hidden" name="nb_mom_patient_id" id="nb_mom_patient_id">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="form-label text-slate-500">Infant Name (Temporary/Final)</label>
                    <input type="text" name="infant_name" id="nb_infant_name" required class="form-input font-bold border-slate-300 focus:border-pink-500 focus:ring-pink-200">
                </div>
                <div>
                    <label class="form-label text-slate-500">Gender</label>
                    <select name="gender" id="nb_gender" required class="form-input border-slate-300 focus:border-pink-500 font-bold">
                        <option value="Male">Boy / Male</option>
                        <option value="Female">Girl / Female</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="form-label text-slate-500">Birth Date</label>
                    <input type="date" name="birth_date" id="nb_birth_date" required readonly class="form-input border-slate-300 bg-slate-50 focus:border-pink-500 cursor-not-allowed" value="<?= date('Y-m-d') ?>">
                </div>
                <div>
                    <label class="form-label text-slate-500">Birth Time</label>
                    <input type="time" name="birth_time" id="nb_birth_time" required class="form-input border-slate-300 focus:border-pink-500" value="<?= date('H:i') ?>">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label text-slate-500 text-[10px] tracking-widest uppercase">Weight (kg)</label>
                    <input type="number" step="0.01" name="weight_kg" id="nb_weight_kg" placeholder="3.2" required class="form-input font-mono font-bold text-center border-slate-300 focus:border-pink-500">
                </div>
                <div>
                    <label class="form-label text-slate-500 text-[10px] tracking-widest uppercase">Length (cm)</label>
                    <input type="number" step="0.1" name="length_cm" id="nb_length_cm" placeholder="45.5" class="form-input font-mono font-bold text-center border-slate-300 focus:border-pink-500">
                </div>
            </div>

            <div class="bg-pink-50/50 p-4 rounded-xl border border-pink-100">
                <div class="flex justify-between items-center mb-3 border-b border-pink-100 pb-2">
                    <label class="form-label text-pink-700 text-[10px] tracking-widest uppercase font-black m-0">APGAR Score Calculation</label>
                    <div class="text-right flex items-center">
                        <span id="apgarClassDisplay" class="mr-3 px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-widest bg-red-100 text-red-600 border border-red-200">Critically Low</span>
                        <span class="text-xl font-black text-pink-600 leading-none" id="apgarTotalDisplay">0</span><span class="text-xs text-pink-400 font-bold leading-none">/10</span>
                    </div>
                    <input type="hidden" name="apgar_score" id="apgar_score_final" value="0/10">
                </div>
                <div class="grid grid-cols-5 gap-2">
                    <div>
                        <label class="text-[9px] font-bold text-slate-500 uppercase block mb-1 text-center truncate" title="Appearance (Skin Color)">Appear.</label>
                        <select class="apgar-calc form-input px-1 py-1.5 text-xs text-center font-bold border-pink-200 focus:border-pink-500 w-full" onchange="calculateApgar()">
                            <option value="0">0</option><option value="1">1</option><option value="2">2</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[9px] font-bold text-slate-500 uppercase block mb-1 text-center truncate" title="Pulse (Heart Rate)">Pulse</label>
                        <select class="apgar-calc form-input px-1 py-1.5 text-xs text-center font-bold border-pink-200 focus:border-pink-500 w-full" onchange="calculateApgar()">
                            <option value="0">0</option><option value="1">1</option><option value="2">2</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[9px] font-bold text-slate-500 uppercase block mb-1 text-center truncate" title="Grimace (Reflex Irritability)">Grimace</label>
                        <select class="apgar-calc form-input px-1 py-1.5 text-xs text-center font-bold border-pink-200 focus:border-pink-500 w-full" onchange="calculateApgar()">
                            <option value="0">0</option><option value="1">1</option><option value="2">2</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[9px] font-bold text-slate-500 uppercase block mb-1 text-center truncate" title="Activity (Muscle Tone)">Activity</label>
                        <select class="apgar-calc form-input px-1 py-1.5 text-xs text-center font-bold border-pink-200 focus:border-pink-500 w-full" onchange="calculateApgar()">
                            <option value="0">0</option><option value="1">1</option><option value="2">2</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[9px] font-bold text-slate-500 uppercase block mb-1 text-center truncate" title="Respiration">Respir.</label>
                        <select class="apgar-calc form-input px-1 py-1.5 text-xs text-center font-bold border-pink-200 focus:border-pink-500 w-full" onchange="calculateApgar()">
                            <option value="0">0</option><option value="1">1</option><option value="2">2</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="form-label text-slate-500">Delivery Method</label>
                    <input type="text" id="nb_delivery_method_display" class="form-input border-slate-300 bg-slate-50 font-bold cursor-not-allowed" readonly>
                    <input type="hidden" name="delivery_method" id="nb_delivery_method">
                </div>
                <div>
                    <label class="form-label text-slate-500">Attending Midwife/Doctor <span class="req-star">*</span></label>
                    <select name="attending_staff" id="nb_attending_staff" class="form-input border-slate-300 focus:border-pink-500 font-bold">
                        <option value="Unassigned">Unassigned</option>
                        <?php foreach ($clinicStaffList as $st): ?>
                            <?php
                                $parts = array_filter([$st['first_name'] ?? '', $st['middle_name'] ?? '', $st['last_name'] ?? '']);
                                $staffName = trim(implode(' ', $parts));
                            ?>
                            <option value="<?= htmlspecialchars($staffName) ?>"><?= htmlspecialchars($staffName) ?><?= !empty($st['role']) ? ' (' . htmlspecialchars($st['role']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="bg-sky-50/50 p-4 rounded-xl border border-sky-100 space-y-3">
                <div>
                    <label class="form-label text-slate-500">Baby Location</label>
                    <select name="infant_location_option" id="nb_location_option" class="form-input border-slate-300 focus:border-pink-500 font-bold" onchange="toggleNurseryBedFields()">
                        <option value="rooming_in">A. Rooming-In (Default)</option>
                        <option value="nursery">B. Admit to Nursery / Infant Ward</option>
                    </select>
                </div>

                <div id="nb_nursery_bed_wrap" class="hidden">
                    <label class="form-label text-slate-500">Nursery Bed <span class="req-star text-red-500">*</span></label>
                    <select name="nursery_bed_id" id="nb_nursery_bed_id" class="form-input border-slate-300 focus:border-pink-500 font-bold">
                        <option value="">-- Select Available Nursery Bed --</option>
                    </select>
                    <p class="text-[10px] text-slate-500 mt-1">Makikita lang dito ang available beds sa Infant Ward.</p>
                </div>
            </div>

            <div class="flex items-center justify-between pt-4 border-t border-slate-100">
                <button type="button" onclick="registerAnotherBaby()" class="px-4 py-2.5 rounded-lg font-bold text-blue-600 bg-blue-50 hover:bg-blue-100 transition-colors flex items-center gap-1.5 text-xs">
                    <span class="material-symbols-outlined text-[16px]">add_circle</span> Register Another Baby (Twins)
                </button>
                <div class="flex gap-2">
                    <button type="button" onclick="closeNewbornModal()" class="px-5 py-2.5 rounded-lg font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 transition-colors">Cancel</button>
                    <button type="submit" class="px-6 py-2.5 rounded-lg font-bold bg-pink-500 text-white hover:bg-pink-600 transition-all shadow-md flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-[18px]">how_to_reg</span> Save Baby Record & Close
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="admitModal" class="fixed inset-0 z-[95] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
    <div id="admitModalBox" class="bg-white rounded-[1.75rem] shadow-2xl border border-slate-100 w-full max-w-3xl flex flex-col max-h-[90vh] transform scale-95 opacity-0 transition-all duration-300">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50 rounded-t-[1.75rem]">
            <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-primary text-2xl icon-filled">person_add</span>
                <div>
                    <h3 class="text-lg font-black text-slate-800 tracking-tight leading-none">New Admission</h3>
                    <p class="text-[10px] text-slate-500 mt-1">Register a new patient admission or use an existing patient record.</p>
                </div>
            </div>
            <button type="button" onclick="closeAdmitModal()" class="size-8 rounded-full hover:bg-slate-200 text-slate-400 flex items-center justify-center transition-colors"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>

        <form method="POST" action="<?= $currentPage ?>" enctype="multipart/form-data" class="p-6 overflow-y-auto scrollable-box space-y-6">
            <input type="hidden" name="admit_patient" value="1">
            <input type="hidden" name="selected_patient_id" id="selectedPatientId" value="">

            <div class="bg-slate-50 border border-slate-200 rounded-xl p-5 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-slate-500 icon-filled">badge</span>
                        <h4 class="text-sm font-black text-slate-800 tracking-tight">Patient Information</h4>
                    </div>
                    <button type="button" onclick="openSelectPatientModal()" class="inline-flex items-center gap-1.5 text-xs font-bold px-3 py-1.5 rounded-lg border border-blue-200 text-blue-600 bg-blue-50 hover:bg-blue-600 hover:text-white transition-colors relative z-10 cursor-pointer">
                        <span class="material-symbols-outlined text-[16px]">person_search</span>
                        Use Existing Patient
                    </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="form-label">Full Name <span class="req-star">*</span></label>
                        <input type="text" name="full_name" required class="form-input font-bold" placeholder="Patient full name">
                    </div>
                    <div>
                        <label class="form-label">Spouse Name</label>
                        <input type="text" name="spouse_name" class="form-input" placeholder="Spouse / Partner name">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div>
                        <label class="form-label">Date of Birth</label>
                        <input type="date" name="dob" id="dobInput" class="form-input" onchange="calculateAge()">
                    </div>
                    <div>
                        <label class="form-label">Age</label>
                        <input type="number" id="ageDisplay" class="form-input bg-slate-50" readonly>
                    </div>
                    <div>
                        <label class="form-label">Religion</label>
                        <input type="text" name="religion" class="form-input" placeholder="Religion">
                    </div>
                </div>

                <div id="guardianIdDiv" class="mb-4 hidden">
                    <div class="p-4 rounded-xl border border-amber-200 bg-amber-50/50">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="material-symbols-outlined text-amber-600 text-[18px]">shield_person</span>
                            <span class="text-xs font-black text-amber-700 uppercase tracking-widest">Minor Patient — Guardian Required</span>
                        </div>
                        <div id="existingGuardianDiv" class="hidden mb-3">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="material-symbols-outlined text-emerald-500 text-[16px]">verified</span>
                                <span class="text-xs font-bold text-emerald-700">Guardian ID already on file</span>
                            </div>
                            <img id="existingGuardianImg" src="" alt="Existing Guardian ID" class="max-h-40 rounded-lg border border-emerald-200 shadow-sm">
                            <p class="text-[10px] text-emerald-600 mt-1">This patient already has a guardian ID on record. You may upload a new one below to replace it.</p>
                            <input type="hidden" name="existing_guardian_id_url" id="existingGuardianIdUrl" value="">
                        </div>
                        <label class="form-label text-amber-800">Guardian ID Photo <span id="guardianReqStar" class="req-star">*</span></label>
                        <div class="relative">
                            <input type="file" name="guardian_id_photo" id="guardianIdInput" accept="image/*" capture="environment" class="form-input border-amber-300 focus:border-amber-500 file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-amber-100 file:text-amber-700 hover:file:bg-amber-200">
                        </div>
                        <div id="guardianPreview" class="mt-2 hidden">
                            <img id="guardianPreviewImg" src="" alt="Guardian ID Preview" class="max-h-40 rounded-lg border border-amber-200 shadow-sm">
                        </div>
                        <p id="guardianUploadHint" class="text-[10px] text-amber-500 mt-1">Patient is under 18 years old. Please upload a photo of the guardian's valid ID.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="form-label">Father's Name</label>
                        <input type="text" name="father_name" class="form-input" placeholder="Father's name">
                    </div>
                    <div>
                        <label class="form-label">Mother's Maiden Name</label>
                        <input type="text" name="mother_maiden_name" class="form-input" placeholder="Mother's maiden name">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="form-label">Contact Number</label>
                        <input type="text" name="contact_number" id="contact_number" class="form-input" placeholder="09XXXXXXXXX" maxlength="11" pattern="^09\d{9}$" title="Must be 11 digits starting with 09"
                            oninput="var v=this.value.replace(/[^0-9]/g,''); if(!v.startsWith('09')){v='09'+v.replace(/^0*9?/,'');} this.value=v.substring(0,11);"
                            onkeydown="if(this.selectionStart<=1&&(event.key==='Backspace'||event.key==='Delete'))event.preventDefault();"
                            onfocus="if(!this.value.startsWith('09'))this.value='09';"
                            onblur="if(!/^09\d{9}$/.test(this.value)){this.setCustomValidity('Must be 11 digits starting with 09');}else{this.setCustomValidity('');}"
                        >
                    </div>
                    <div>
                        <label class="form-label">Address</label>
                        <textarea name="address" rows="1" class="form-input min-h-[42px] resize-y" placeholder="Complete address"></textarea>
                    </div>
                </div>

                <div id="maternityFormBlock" class="mt-4 bg-pink-50 border border-pink-200 rounded-xl p-4">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="material-symbols-outlined text-pink-500 text-base icon-filled">pregnant_woman</span>
                        <h5 class="text-xs font-black text-pink-900 tracking-tight uppercase">Maternity / Pregnancy Information</h5>
                        <span class="text-[10px] text-pink-500 font-bold ml-auto">Optional</span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="form-label text-pink-800">Last Menstrual Period (LMP)</label>
                            <input type="date" name="last_menstrual_period" id="lmpInput" class="form-input border-pink-200 focus:border-pink-500" max="<?= date('Y-m-d') ?>" onchange="calculateEdd()">
                            <p class="text-[10px] text-pink-500 mt-1">First day of the patient's last period.</p>
                        </div>
                        <div>
                            <label class="form-label text-pink-800">Estimated Date of Delivery (EDD)</label>
                            <input type="date" id="eddInput" class="form-input bg-pink-100/60 cursor-not-allowed border-pink-200 text-pink-900 font-bold" readonly>
                            <p class="text-[10px] text-pink-500 mt-1">Auto-computed (Naegele's rule: LMP + 7 days − 3 months + 1 year).</p>
                        </div>
                    </div>
                </div>

                <div id="patientEmailRow" class="hidden">
                    <label class="form-label flex items-center gap-1">
                        <span class="material-symbols-outlined text-[15px] text-slate-400">mail</span>
                        Account Email
                    </label>
                    <input type="text" id="patientEmailDisplay" class="form-input bg-slate-100 text-slate-500 cursor-not-allowed" readonly disabled placeholder="—">
                    <p class="text-[10px] text-slate-400 mt-1">This is the patient's registered account email and cannot be changed here.</p>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
                <div class="flex items-center gap-2 mb-4 border-b border-slate-100 pb-3">
                    <span class="material-symbols-outlined text-amber-500 icon-filled">local_hospital</span>
                    <h4 class="text-sm font-black text-slate-800 tracking-tight">Admission Details</h4>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="form-label">Service Type <span class="req-star">*</span></label>
                        <select name="service_type" id="admitServiceType" required class="form-input text-sm font-bold" onchange="toggleMaternityBlock()">
                            <?php if (empty($clinicServices)): ?>
                                <option value="General Admission">General Admission</option>
                            <?php else: ?>
                                <?php foreach ($clinicServices as $srv): ?>
                                    <option value="<?= htmlspecialchars($srv['service_name']) ?>"><?= htmlspecialchars($srv['service_name']) ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Assigned Staff</label>
                        <select name="assigned_staff" id="admitAssignedStaff" class="form-input text-sm border-slate-300 focus:border-primary font-bold">
                            <option value="Assign Later">-- Assign Later --</option>
                            <?php foreach ($clinicStaffList as $st): ?>
                                <?php
                                    $parts = array_filter([$st['first_name'] ?? '', $st['middle_name'] ?? '', $st['last_name'] ?? '']);
                                    $staffName = trim(implode(' ', $parts));
                                    $staffKey = strtolower($staffName);
                                    $isBusyStaff = isset($busyAssignedStaff[$staffKey]);
                                    $busyPatientName = $isBusyStaff ? ($busyAssignedStaff[$staffKey]['patient_name'] ?? '') : '';
                                ?>
                                <option value="<?= htmlspecialchars($staffName) ?>" <?= $isBusyStaff ? 'disabled' : '' ?>>
                                    <?= htmlspecialchars($staffName) ?><?= !empty($st['role']) ? ' (' . htmlspecialchars($st['role']) . ')' : '' ?><?= $isBusyStaff ? ' - Busy' . (!empty($busyPatientName) ? ' (Patient: ' . htmlspecialchars($busyPatientName) . ')' : '') : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="form-label">Admission Date</label>
                        <input type="date" name="admission_date_only" id="admissionDateOnly" class="form-input" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>">
                        <input type="hidden" name="admission_date" id="admissionDateCombined" value="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                    <div>
                        <label class="form-label">Admission Time</label>
                        <input type="time" id="admTimeValue" class="form-input text-sm font-bold" value="<?= date('H:i') ?>" onchange="updateAdmissionDateCombined()">
                    </div>
                </div>
                <p class="text-[11px] text-slate-500 italic mt-2">Service price and remaining balance will be computed automatically based on selected service.</p>
            </div>

            <div class="flex items-center justify-end gap-3 pt-2">
                <button type="button" onclick="closeAdmitModal()" class="px-5 py-2.5 rounded-lg font-bold text-slate-600 bg-white border border-slate-200 hover:bg-slate-50 transition-colors">Cancel</button>
                <button type="submit" class="px-6 py-2.5 rounded-lg font-bold bg-primary text-white hover:bg-primary-dark transition-all shadow-md flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-[18px]">done_all</span>
                    Save Admission
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ASSIGN STAFF MODAL -->
<div id="assignStaffModal" class="fixed inset-0 z-[95] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
    <div id="assignStaffBox" class="bg-white rounded-[1.75rem] shadow-2xl border border-slate-100 w-full max-w-md transform scale-95 opacity-0 transition-all duration-300">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50 rounded-t-[1.75rem]">
            <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-indigo-500 text-2xl icon-filled">person_add</span>
                <div>
                    <h3 class="text-lg font-black text-slate-800 tracking-tight leading-none">Assign Staff</h3>
                    <p id="assignStaffPatientInfo" class="text-[10px] text-slate-500 mt-1"></p>
                </div>
            </div>
            <button type="button" onclick="closeAssignStaffModal()" class="size-8 rounded-full hover:bg-slate-200 text-slate-400 flex items-center justify-center transition-colors"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>
        <form method="POST" action="<?= $currentPage ?>" class="p-6 space-y-5">
            <input type="hidden" name="assign_staff_to_admission" value="1">
            <input type="hidden" name="admission_id" id="assignStaffAdmId" value="">

            <div>
                <label class="text-xs font-black uppercase tracking-widest text-slate-500 mb-2 block">Select Staff Member <span class="text-red-500">*</span></label>
                <select name="assign_staff_name" required class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none font-bold text-slate-700">
                    <option value="" disabled selected>-- Select Staff --</option>
                    <?php foreach ($clinicStaffList as $st): ?>
                        <?php
                            $parts = array_filter([$st['first_name'] ?? '', $st['middle_name'] ?? '', $st['last_name'] ?? '']);
                            $staffName = trim(implode(' ', $parts));
                            $staffKey = strtolower($staffName);
                            $isBusyStaff = isset($busyAssignedStaff[$staffKey]);
                            $busyPatientName = $isBusyStaff ? ($busyAssignedStaff[$staffKey]['patient_name'] ?? '') : '';
                        ?>
                        <option value="<?= htmlspecialchars($staffName) ?>" <?= $isBusyStaff ? 'disabled' : '' ?>>
                            <?= htmlspecialchars($staffName) ?><?= !empty($st['role']) ? ' (' . htmlspecialchars($st['role']) . ')' : '' ?><?= $isBusyStaff ? ' - Busy' . (!empty($busyPatientName) ? ' (' . htmlspecialchars($busyPatientName) . ')' : '') : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex items-center justify-end gap-3 pt-2">
                <button type="button" onclick="closeAssignStaffModal()" class="px-5 py-2.5 rounded-lg font-bold text-slate-600 bg-white border border-slate-200 hover:bg-slate-50 transition-colors">Cancel</button>
                <button type="submit" class="px-6 py-2.5 rounded-lg font-bold bg-indigo-500 text-white hover:bg-indigo-600 transition-all shadow-md flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-[18px]">person_add</span>
                    Assign Staff
                </button>
            </div>
        </form>
    </div>
</div>

<div id="selectPatientModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
    <div id="selectPatientBox" class="bg-white rounded-[1.75rem] shadow-2xl border border-slate-100 w-full max-w-3xl flex flex-col max-h-[85vh] transform scale-95 opacity-0 transition-all duration-300">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50 rounded-t-[1.75rem]">
            <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-primary text-2xl icon-filled">person_search</span>
                <div>
                    <h3 class="text-lg font-black text-slate-800 tracking-tight leading-none">Select Existing Patient</h3>
                    <p class="text-[10px] text-slate-500 mt-1">Search and choose from previously registered patients.</p>
                </div>
            </div>
            <button type="button" onclick="closeSelectPatientModal()" class="size-8 rounded-full hover:bg-slate-200 text-slate-400 flex items-center justify-center transition-colors"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>

        <div class="p-6 flex flex-col gap-4 overflow-y-auto scrollable-box">
            <div class="relative">
                <input type="text" id="patientSearchInput" oninput="filterPatientList()" placeholder="Search patient by name" class="w-full form-input pl-9 text-sm" />
                <span class="material-symbols-outlined text-slate-400 text-[18px] absolute left-2 top-1/2 -translate-y-1/2">search</span>
            </div>

            <div class="mt-2 space-y-2 max-h-[55vh] overflow-y-auto">
                <?php if (!empty($existingPatients)): ?>
                    <?php foreach ($existingPatients as $idx => $p): ?>
                        <?php $isAdmitted = !empty($p['is_admitted']); ?>
                        <button type="button" class="patient-item w-full text-left p-3 rounded-xl border <?= $isAdmitted ? 'border-amber-200 bg-amber-50/50 opacity-60 cursor-not-allowed' : 'border-slate-200 hover:border-primary hover:bg-primary/5' ?> transition-all flex flex-col gap-1" <?= $isAdmitted ? 'disabled' : 'onclick="choosePatient(' . $idx . ')"' ?>>
                            <div class="flex items-center gap-2">
                                <span class="patient-name-txt font-bold text-sm <?= $isAdmitted ? 'text-slate-500' : 'text-slate-800' ?>"><?= htmlspecialchars($p['full_name']) ?></span>
                                <?php if ($isAdmitted): ?>
                                    <span class="text-[9px] bg-amber-100 text-amber-700 px-2 py-0.5 rounded border border-amber-200 font-black uppercase tracking-widest">Currently Admitted</span>
                                <?php endif; ?>
                            </div>
                            <div class="flex flex-wrap items-center gap-2 text-[11px] text-slate-500">
                                <?php if (!empty($p['patient_id'])): ?>
                                    <span class="font-mono text-slate-400">#<?= htmlspecialchars($p['patient_id']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($p['age'])): ?>
                                    <span><?= (int)$p['age'] ?> yrs</span>
                                <?php endif; ?>
                                <?php if (!empty($p['contact_number'])): ?>
                                    <span class="inline-flex items-center gap-1"><span class="material-symbols-outlined text-[13px]">call</span><?= htmlspecialchars($p['contact_number']) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($p['address'])): ?>
                                <p class="text-[11px] text-slate-400 mt-1 line-clamp-1"><?= htmlspecialchars($p['address']) ?></p>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="py-10 text-center text-slate-400 text-sm">
                        <span class="material-symbols-outlined text-4xl mb-2 text-slate-200">patient_list</span>
                        <p>No existing patients found for this clinic.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- TIME SLOT PICKER MODAL -->
<div id="timeSlotPickerModal" class="fixed inset-0 z-[200] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
    <div id="timeSlotPickerBox" class="bg-white rounded-[1.75rem] shadow-2xl border border-slate-100 w-full max-w-sm transform scale-95 opacity-0 transition-all duration-300">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50 rounded-t-[1.75rem]">
            <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-primary text-2xl icon-filled">schedule</span>
                <div>
                    <h3 class="text-lg font-black text-slate-800 tracking-tight leading-none">Select Time Slot</h3>
                    <p class="text-[10px] text-slate-500 mt-1">Clinic hours: <?= date('g:i A', strtotime($clinicOpeningTime)) ?> – <?= date('g:i A', strtotime($clinicClosingTime)) ?></p>
                </div>
            </div>
            <button type="button" onclick="closeTimeSlotPicker()" class="size-8 rounded-full hover:bg-slate-200 text-slate-400 flex items-center justify-center transition-colors"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>
        <div class="p-4 max-h-[60vh] overflow-y-auto scrollable-box">
            <div id="timeSlotList" class="space-y-2">
                <?php foreach ($timeSlots as $slot): ?>
                    <button type="button" class="time-slot-option w-full text-left px-4 py-3 rounded-xl border border-slate-200 hover:border-primary hover:bg-primary/5 transition-all flex items-center justify-between group" data-value="<?= htmlspecialchars($slot['value']) ?>" data-label="<?= htmlspecialchars($slot['label']) ?>" onclick="selectTimeSlot(this)">
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-slate-400 group-hover:text-primary text-lg">schedule</span>
                            <span class="font-bold text-sm text-slate-700 group-hover:text-primary"><?= htmlspecialchars($slot['label']) ?></span>
                        </div>
                        <span class="material-symbols-outlined text-slate-300 group-hover:text-primary text-lg slot-check hidden">check_circle</span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<div id="receiptModal" class="fixed inset-0 z-[120] hidden items-center justify-center p-4 bg-slate-900/80 backdrop-blur-sm">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md flex flex-col max-h-[90vh] transform scale-95 opacity-0 transition-all duration-300" id="receiptModalBox">
        <div class="flex justify-between items-center p-4 border-b border-slate-100 bg-slate-50 rounded-t-2xl shrink-0">
            <h3 class="font-black text-slate-800 text-sm">Transaction Complete</h3>
        </div>

        <div id="receiptContent" class="receipt-edge px-8 py-10 bg-white flex-1 overflow-y-auto">
            <div class="text-center mb-8 border-b border-dashed border-slate-300 pb-6">
                <?php if ($clinicLogo): ?>
                    <img src="<?= htmlspecialchars($clinicLogo) ?>" alt="<?= htmlspecialchars($clinicName) ?> Logo" class="mx-auto mb-3 h-16 w-16 object-contain rounded-full border border-slate-200 bg-white p-1 shadow-sm" onerror="this.outerHTML='<span class=\'material-symbols-outlined text-[48px] text-emerald-500 mb-2 drop-shadow-sm\'>verified</span>';">
                <?php else: ?>
                    <span class="material-symbols-outlined text-[48px] text-emerald-500 mb-2 drop-shadow-sm">verified</span>
                <?php endif; ?>
                <h2 class="text-2xl font-black text-slate-800 uppercase tracking-tight" id="rec_clinic"><?= htmlspecialchars($clinicName) ?></h2>
                <div class="flex items-center justify-center gap-2 mt-1">
                    <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest bg-slate-100 px-2 py-0.5 rounded">CODE: <?= htmlspecialchars($clinicCode) ?></p>
                </div>
            </div>

            <div class="text-center mb-6">
                <p class="text-lg font-black text-slate-800 uppercase tracking-widest" id="rec_title">Official Receipt</p>
                <p class="text-xs text-slate-500 mt-1 font-mono" id="rec_id_display"></p>
            </div>

            <div class="space-y-4 text-sm font-medium">
                <div class="flex justify-between items-end border-b border-slate-100 pb-2">
                    <span class="text-slate-500 text-xs uppercase tracking-widest font-bold">Date & Time</span>
                    <span class="font-bold text-slate-800 text-right" id="rec_date"></span>
                </div>
                <div class="flex justify-between items-end border-b border-slate-100 pb-2">
                    <span class="text-slate-500 text-xs uppercase tracking-widest font-bold">Patient Name</span>
                    <span class="font-black text-slate-800 text-right" id="rec_patient"></span>
                </div>
                <div class="flex justify-between items-end border-b border-slate-100 pb-2">
                    <span class="text-slate-500 text-xs uppercase tracking-widest font-bold">Service Rendered</span>
                    <div class="text-right">
                        <span class="font-bold text-primary block max-w-[200px] truncate" id="rec_service"></span>
                        <span class="text-[11px] font-black text-slate-700 hidden" id="rec_service_amt"></span>
                    </div>
                </div>
                <div class="flex justify-between items-end border-b border-slate-100 pb-2">
                    <span class="text-slate-500 text-xs uppercase tracking-widest font-bold">Payment Method</span>
                    <span class="font-bold text-slate-800 text-right" id="rec_method"></span>
                </div>
                <div id="rec_rooms_section" class="hidden space-y-2 pt-2 pb-2 border-b border-amber-200 bg-amber-50 p-3 rounded-xl">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="material-symbols-outlined text-amber-700 text-[16px]">hotel</span>
                        <span class="text-[10px] font-black text-amber-800 uppercase tracking-widest">Room Charges</span>
                    </div>
                    <div id="rec_rooms_list" class="space-y-1"></div>
                </div>
                <div id="rec_philhealth_section" class="hidden space-y-2 pt-2 pb-2 border-b border-green-200 bg-green-50 p-3 rounded-xl">
                    <div class="flex items-center gap-2 mb-1">
                        <img src="uploads/philhealth_logo.png" alt="PH" class="h-5 rounded" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex';">
                        <span class="material-symbols-outlined text-green-600 text-[16px] hidden">health_and_safety</span>
                        <span class="text-[10px] font-black text-green-700 uppercase tracking-widest">PhilHealth Coverage</span>
                    </div>
                    <div class="flex justify-between items-end">
                        <span class="text-green-600 text-xs font-bold">Original Amount</span>
                        <span class="font-black text-green-800" id="rec_ph_original">₱ 0.00</span>
                    </div>
                    <div class="flex justify-between items-end">
                        <span class="text-green-600 text-xs font-bold">PhilHealth Covered</span>
                        <span class="font-black text-green-600" id="rec_ph_covered">- ₱ 0.00</span>
                    </div>
                </div>
                <div class="flex justify-between items-end pt-4 bg-slate-50 p-4 rounded-xl border border-slate-100">
                    <span class="font-black text-slate-800 uppercase tracking-widest text-xs">Total Amount</span>
                    <span class="font-black text-emerald-600 text-2xl" id="rec_amount"></span>
                </div>
            </div>
            <div class="mt-10 text-center">
                <p class="text-[10px] text-slate-400 italic">This is an electronically generated receipt.<br>Thank you for trusting <?= htmlspecialchars($clinicName) ?>!</p>
            </div>
        </div>

        <div class="p-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl flex gap-3 shrink-0">
            <button onclick="saveReceipt(false, true)" class="flex-1 bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold py-3 rounded-xl flex items-center justify-center gap-2 transition-all">
                <span class="material-symbols-outlined text-[18px]">close</span> Close
            </button>
            <button onclick="saveReceipt(true, true)" class="flex-1 bg-primary hover:bg-primary-dark text-white font-bold py-3 rounded-xl flex items-center justify-center gap-2 transition-all shadow-md">
                <span class="material-symbols-outlined text-[18px]">save</span> Download Receipt
            </button>
        </div>
    </div>
</div>

<div id="paymentModal" class="fixed inset-0 z-[90] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
    <div class="bg-white rounded-[2rem] shadow-2xl border border-slate-100 w-full max-w-xl flex flex-col max-h-[90vh] transform scale-95 opacity-0 transition-all duration-300" id="paymentModalBox">
        <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between bg-slate-50/50 rounded-t-[2rem] shrink-0">
            <div class="flex items-center gap-3">
                <div class="size-10 rounded-xl bg-primary/10 text-primary flex items-center justify-center border border-primary/20"><span class="material-symbols-outlined icon-filled">receipt_long</span></div>
                <div>
                    <h3 id="modalTitle" class="text-lg font-black text-slate-800 tracking-tight leading-none">Process Payment</h3>
                    <p id="modalSubtitle" class="text-[10px] text-slate-500 mt-1">Payment processing module</p>
                </div>
            </div>
            <button type="button" onclick="closePaymentModal()" class="size-8 rounded-full hover:bg-slate-200 text-slate-400 flex items-center justify-center transition-colors"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>

        <form method="POST" id="paymentModalForm" action="<?= $currentPage ?>" enctype="multipart/form-data" class="p-6 flex flex-col gap-6 overflow-y-auto scrollable-box">
            <input type="hidden" name="process_payment" value="1">
            <input type="hidden" name="payment_action" id="payment_action" value="">
            <input type="hidden" name="admission_id" id="d_adm_id">
            <input type="hidden" name="d_patient_name" id="d_patient_name_input">
            <input type="hidden" name="d_service_name" id="d_service_name_input">
            <input type="hidden" name="d_final_amount" id="d_final_amount_input">
            <input type="hidden" id="billingBasePrice" value="0">
            <input type="hidden" name="is_philhealth" id="isPhilhealthInput" value="0">
            <input type="hidden" name="philhealth_amount" id="philhealthAmountInput" value="0">

            <div class="flex items-center gap-4 bg-background-light p-4 rounded-xl border border-slate-200">
                <img id="d_patient_pic" src="" alt="" class="size-12 rounded-full object-cover border border-slate-200 hidden">
                <div class="size-12 rounded-full bg-primary/20 flex items-center justify-center text-primary font-bold text-lg" id="d_patient_initial">P</div>
                <div><p class="font-bold text-lg text-slate-800" id="d_patient_display">Patient Name</p></div>
            </div>

            <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200 space-y-4">
                <div class="flex justify-between items-center">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Rendered Service</p>
                </div>
                <input type="text" id="displayServiceName" readonly class="w-full rounded-xl border border-slate-300 bg-slate-100 p-3 text-sm font-bold text-slate-800 cursor-not-allowed shadow-inner">

                <div id="discountDiv" class="pt-3 border-t border-dashed border-slate-300 hidden">
                    <div class="flex justify-between items-center text-sm font-bold text-slate-600 mb-2">
                        <div class="flex items-center gap-2">
                            <span>Apply Discount (%)</span>
                            <div class="relative inline-block w-10 mr-2 align-middle select-none transition duration-200 ease-in">
                                <input type="checkbox" id="toggleDiscount" name="toggle_discount" class="toggle-checkbox absolute block w-5 h-5 rounded-full bg-white border-4 appearance-none cursor-pointer z-10" onchange="calculateBilling()"/>
                                <label for="toggleDiscount" class="toggle-label block overflow-hidden h-5 rounded-full bg-gray-300 cursor-pointer"></label>
                            </div>
                        </div>
                        <div class="relative w-24">
                            <input type="number" step="1" id="billingDiscountPercent" value="0" min="0" max="100" oninput="calculateBilling()" class="w-full pl-2 pr-2 py-1 text-right text-xs rounded border border-slate-300 outline-none focus:border-primary disabled:bg-slate-100 disabled:text-slate-400 disabled:cursor-not-allowed" disabled>
                        </div>
                    </div>
                </div>

                <div id="downpaymentDiv" class="pt-3 border-t border-dashed border-slate-300 hidden">
                    <div class="flex justify-between items-center text-sm font-bold text-slate-600 mb-2">
                        <span>Amount to Pay Now (₱)</span>
                        <div class="relative w-28">
                            <input type="number" step="0.01" id="billingDownpayment" value="0" oninput="calculateBilling()" class="w-full pl-2 pr-2 py-1.5 text-right text-sm font-black rounded border border-slate-300 outline-none focus:border-primary text-blue-600 bg-blue-50">
                        </div>
                    </div>
                </div>

                <div id="philhealthDiv" class="pt-3 border-t border-dashed border-slate-300 hidden">
                    <div class="flex justify-between items-center text-sm font-bold text-slate-600 mb-2">
                        <div class="flex items-center gap-2">
                            <img src="uploads/philhealth_logo.png" alt="PH" class="h-5 rounded" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex';">
                            <span class="material-symbols-outlined text-green-600 text-[18px] hidden">health_and_safety</span>
                            <span>PhilHealth Member</span>
                            <div class="relative inline-block w-10 mr-2 align-middle select-none transition duration-200 ease-in">
                                <input type="checkbox" id="togglePhilhealth" class="toggle-checkbox absolute block w-5 h-5 rounded-full bg-white border-4 appearance-none cursor-pointer z-10" onchange="calculateBilling()"/>
                                <label for="togglePhilhealth" class="toggle-label block overflow-hidden h-5 rounded-full bg-gray-300 cursor-pointer"></label>
                            </div>
                        </div>
                    </div>
                    <div id="philhealthInfoBox" class="hidden mt-2 p-3 rounded-xl bg-green-50 border border-green-200">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="material-symbols-outlined text-green-600 text-[16px]">verified</span>
                            <span class="text-[11px] font-black text-green-700 uppercase tracking-widest">PhilHealth Covered</span>
                        </div>
                        <p class="text-xs text-green-600 font-medium">PhilHealth covers a fixed package rate per availed service. Any remaining balance must be paid by the patient.</p>
                        <p class="text-lg font-black text-green-700 mt-1">Covered: ₱ <span id="philhealthCoveredDisplay">0.00</span></p>
                        <div id="phPhotoWarning" class="mt-2 p-2 rounded-lg bg-red-50 border border-red-200 flex items-center gap-2">
                            <span class="material-symbols-outlined text-red-500 text-[16px]">warning</span>
                            <p class="text-[11px] font-bold text-red-600">Upload both Front and Back PhilHealth ID photos to proceed.</p>
                        </div>

                        <!-- PhilHealth ID Front -->
                        <div class="mt-4 pt-3 border-t border-green-200">
                            <label class="block text-[10px] font-black text-green-700 uppercase tracking-widest mb-2">PhilHealth ID — Front</label>
                            <!-- Existing photo on file -->
                            <div id="phFrontExistingBox" class="hidden mb-2 p-2 rounded-lg bg-emerald-50 border border-emerald-200">
                                <div class="flex items-center gap-2 mb-1.5">
                                    <span class="material-symbols-outlined text-emerald-500 text-[14px]">verified</span>
                                    <span class="text-[10px] font-bold text-emerald-700">Existing photo on file</span>
                                </div>
                                <img id="phFrontExistingImg" src="" alt="Existing Front" class="w-full h-28 object-cover rounded-lg border border-emerald-200 mb-1.5">
                                <button type="button" onclick="useExistingPhPhoto('front')" class="w-full flex items-center justify-center gap-1.5 py-1.5 px-3 rounded-lg bg-emerald-500 text-white text-xs font-bold hover:bg-emerald-600 transition-all">
                                    <span class="material-symbols-outlined text-[14px]">check_circle</span> Gamitin Ito
                                </button>
                            </div>
                            <div id="phFrontUploadBox">
                                <div class="flex gap-2 mb-2">
                                    <label class="flex-1 cursor-pointer flex items-center justify-center gap-1.5 py-2 px-3 rounded-lg border border-green-300 bg-white text-green-700 text-xs font-bold hover:bg-green-100 transition-all">
                                        <span class="material-symbols-outlined text-[16px]">upload_file</span> Choose File
                                        <input type="file" name="philhealth_id_front" id="phFrontFile" accept="image/*" class="hidden" onchange="document.getElementById('phFrontExisting').value=''; previewPhFile(this, 'phFrontPreview', 'phFrontPreviewImg'); validatePhilhealthPhotos();">
                                    </label>
                                    <button type="button" onclick="openPhCamera('front')" class="flex-1 flex items-center justify-center gap-1.5 py-2 px-3 rounded-lg border border-green-300 bg-white text-green-700 text-xs font-bold hover:bg-green-100 transition-all">
                                        <span class="material-symbols-outlined text-[16px]">photo_camera</span> Take Photo
                                    </button>
                                </div>
                            </div>
                            <div id="phFrontPreview" class="hidden relative">
                                <img id="phFrontPreviewImg" src="" alt="Front" class="w-full h-32 object-cover rounded-lg border border-green-200">
                                <button type="button" onclick="clearPhPreview('front')" class="absolute top-1 right-1 size-6 rounded-full bg-red-500 text-white flex items-center justify-center text-xs shadow"><span class="material-symbols-outlined text-[14px]">close</span></button>
                            </div>
                            <input type="hidden" name="philhealth_id_front_data" id="phFrontData" value="">
                            <input type="hidden" name="philhealth_id_front_existing" id="phFrontExisting" value="">
                        </div>

                        <!-- PhilHealth ID Back -->
                        <div class="mt-3">
                            <label class="block text-[10px] font-black text-green-700 uppercase tracking-widest mb-2">PhilHealth ID — Back</label>
                            <!-- Existing photo on file -->
                            <div id="phBackExistingBox" class="hidden mb-2 p-2 rounded-lg bg-emerald-50 border border-emerald-200">
                                <div class="flex items-center gap-2 mb-1.5">
                                    <span class="material-symbols-outlined text-emerald-500 text-[14px]">verified</span>
                                    <span class="text-[10px] font-bold text-emerald-700">Existing photo on file</span>
                                </div>
                                <img id="phBackExistingImg" src="" alt="Existing Back" class="w-full h-28 object-cover rounded-lg border border-emerald-200 mb-1.5">
                                <button type="button" onclick="useExistingPhPhoto('back')" class="w-full flex items-center justify-center gap-1.5 py-1.5 px-3 rounded-lg bg-emerald-500 text-white text-xs font-bold hover:bg-emerald-600 transition-all">
                                    <span class="material-symbols-outlined text-[14px]">check_circle</span> Gamitin Ito
                                </button>
                            </div>
                            <div id="phBackUploadBox">
                                <div class="flex gap-2 mb-2">
                                    <label class="flex-1 cursor-pointer flex items-center justify-center gap-1.5 py-2 px-3 rounded-lg border border-green-300 bg-white text-green-700 text-xs font-bold hover:bg-green-100 transition-all">
                                        <span class="material-symbols-outlined text-[16px]">upload_file</span> Choose File
                                        <input type="file" name="philhealth_id_back" id="phBackFile" accept="image/*" class="hidden" onchange="document.getElementById('phBackExisting').value=''; previewPhFile(this, 'phBackPreview', 'phBackPreviewImg'); validatePhilhealthPhotos();">
                                    </label>
                                    <button type="button" onclick="openPhCamera('back')" class="flex-1 flex items-center justify-center gap-1.5 py-2 px-3 rounded-lg border border-green-300 bg-white text-green-700 text-xs font-bold hover:bg-green-100 transition-all">
                                        <span class="material-symbols-outlined text-[16px]">photo_camera</span> Take Photo
                                    </button>
                                </div>
                            </div>
                            <div id="phBackPreview" class="hidden relative">
                                <img id="phBackPreviewImg" src="" alt="Back" class="w-full h-32 object-cover rounded-lg border border-green-200">
                                <button type="button" onclick="clearPhPreview('back')" class="absolute top-1 right-1 size-6 rounded-full bg-red-500 text-white flex items-center justify-center text-xs shadow"><span class="material-symbols-outlined text-[14px]">close</span></button>
                            </div>
                            <input type="hidden" name="philhealth_id_back_data" id="phBackData" value="">
                            <input type="hidden" name="philhealth_id_back_existing" id="phBackExisting" value="">
                        </div>
                    </div>
                </div>

                <div class="space-y-1.5 pt-3 border-t border-slate-200 mt-2" id="emailDiv">
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest">Email Receipt To (Optional)</label>
                    <input type="email" id="receiptEmail" name="receipt_email" placeholder="patient@email.com" class="w-full rounded-xl border border-slate-300 bg-white p-3 text-sm font-bold text-slate-700 focus:border-primary outline-none transition-all shadow-inner">
                </div>
            </div>

            <div class="space-y-3">
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest">Payment Method</label>
                <select name="payment_method" id="paymentMethod" onchange="togglePaymentFields()" class="w-full rounded-xl border border-slate-300 bg-white p-3 text-sm font-bold text-slate-700 focus:border-primary outline-none transition-all shadow-inner cursor-pointer">
                    <option value="Cash">Over the Counter</option>
                    <option value="PayMongo">Online Payment</option>
                </select>
                <p class="text-[10px] font-medium text-slate-500 italic ml-1" id="methodHint">Patient will pay over the counter.</p>
            </div>

            <div class="flex justify-between items-end border-t border-slate-200 pt-4 mt-auto">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Transaction</p>
                <p class="text-4xl font-black text-slate-800 tracking-tighter" id="displayTotal">₱ 0.00</p>
            </div>

            <button type="submit" id="btnDischargeSubmit" class="w-full py-3.5 rounded-xl bg-primary hover:bg-primary-dark text-white font-black text-sm shadow-lg shadow-primary/30 transition-all flex justify-center items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                Process Payment
            </button>

            <div id="btnWaitingOnline" class="hidden w-full py-3.5 rounded-xl bg-slate-100 text-slate-400 font-black text-sm flex justify-center items-center gap-2 cursor-not-allowed border border-slate-200">
                <div class="size-4 border-2 border-slate-200 border-t-slate-400 rounded-full animate-spin"></div>
                Waiting for Online Payment via App...
            </div>

        </form>
    </div>
</div>

<script>
    let currentReceiptId = null, currentPaymentId = null, currentAdmissionId = null, currentReceiptMethod = null, currentReceiptType = null;
    const patientsList = <?= json_encode($existingPatients ?: []) ?>;
    function calculateAge() {
        const dobInput = document.getElementById('dobInput').value;
        if (dobInput) {
            const dob = new Date(dobInput);
            const today = new Date();
            let age = today.getFullYear() - dob.getFullYear();
            const m = today.getMonth() - dob.getMonth();
            if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) { age--; }
            document.getElementById('ageDisplay').value = age > 0 ? age : 0;
            toggleGuardianField(age);
        } else { document.getElementById('ageDisplay').value = ''; toggleGuardianField(null); }
    }

    // Hide the Maternity / Pregnancy block when the chosen service is Postnatal
    function toggleMaternityBlock() {
        const sel = document.getElementById('admitServiceType');
        const block = document.getElementById('maternityFormBlock');
        if (!sel || !block) return;
        const v = (sel.value || '').toLowerCase();
        if (v.includes('postnatal')) {
            block.classList.add('hidden');
            const lmp = document.getElementById('lmpInput');
            const edd = document.getElementById('eddInput');
            if (lmp) lmp.value = '';
            if (edd) edd.value = '';
        } else {
            block.classList.remove('hidden');
        }
    }

    // Compute Estimated Date of Delivery (EDD) using Naegele's rule:
    //   EDD = LMP + 7 days - 3 months + 1 year
    // Uses explicit Y/M/D arithmetic with day-clamping to avoid JS setMonth overflow.
    function calculateEdd() {
        const lmpVal = document.getElementById('lmpInput')?.value;
        const eddOut = document.getElementById('eddInput');
        if (!eddOut) return;
        if (!lmpVal) { eddOut.value = ''; return; }
        const parts = lmpVal.split('-');
        if (parts.length !== 3) { eddOut.value = ''; return; }
        let y = parseInt(parts[0], 10);
        let m = parseInt(parts[1], 10);
        let d = parseInt(parts[2], 10);
        if (!y || !m || !d) { eddOut.value = ''; return; }
        // Step 1: + 7 days (use Date for safe day-overflow handling)
        const tmp = new Date(y, m - 1, d);
        tmp.setDate(tmp.getDate() + 7);
        y = tmp.getFullYear();
        m = tmp.getMonth() + 1;
        d = tmp.getDate();
        // Step 2: - 3 months
        m -= 3;
        if (m <= 0) { m += 12; y -= 1; }
        // Step 3: + 1 year
        y += 1;
        // Clamp day to last day of target month
        const lastDay = new Date(y, m, 0).getDate();
        if (d > lastDay) d = lastDay;
        eddOut.value = `${String(y).padStart(4,'0')}-${String(m).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
    }

    function toggleGuardianField(age, existingUrl) {
        const div = document.getElementById('guardianIdDiv');
        const input = document.getElementById('guardianIdInput');
        const preview = document.getElementById('guardianPreview');
        const previewImg = document.getElementById('guardianPreviewImg');
        const existingDiv = document.getElementById('existingGuardianDiv');
        const existingImg = document.getElementById('existingGuardianImg');
        const existingHidden = document.getElementById('existingGuardianIdUrl');
        const reqStar = document.getElementById('guardianReqStar');
        const uploadHint = document.getElementById('guardianUploadHint');
        if (age !== null && age < 18) {
            div.classList.remove('hidden');
            if (existingUrl) {
                let src = existingUrl;
                if (!src.startsWith('http') && !src.startsWith('uploads/')) { src = 'uploads/guardian_ids/' + src; }
                existingDiv.classList.remove('hidden');
                existingImg.src = src;
                existingHidden.value = existingUrl;
                input.required = false;
                reqStar.classList.add('hidden');
                uploadHint.textContent = 'Optional — upload only if you want to replace the existing guardian ID.';
            } else {
                existingDiv.classList.add('hidden');
                existingImg.src = '';
                existingHidden.value = '';
                input.required = true;
                reqStar.classList.remove('hidden');
                uploadHint.textContent = 'Patient is under 18 years old. Please upload a photo of the guardian\'s valid ID.';
            }
        } else {
            div.classList.add('hidden');
            input.required = false;
            input.value = '';
            preview.classList.add('hidden');
            previewImg.src = '';
            existingDiv.classList.add('hidden');
            existingImg.src = '';
            existingHidden.value = '';
            reqStar.classList.remove('hidden');
            uploadHint.textContent = 'Patient is under 18 years old. Please upload a photo of the guardian\'s valid ID.';
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const guardianInput = document.getElementById('guardianIdInput');
        if (guardianInput) {
            guardianInput.addEventListener('change', function() {
                const preview = document.getElementById('guardianPreview');
                const previewImg = document.getElementById('guardianPreviewImg');
                if (this.files && this.files[0]) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        previewImg.src = e.target.result;
                        preview.classList.remove('hidden');
                    };
                    reader.readAsDataURL(this.files[0]);
                } else {
                    preview.classList.add('hidden');
                    previewImg.src = '';
                }
            });
        }
    });

    const ROWS_PER_PAGE = 5;
    let _activeTab = 'current';
    let _currentPage = 1;

    function getFilteredRows(tab) {
        const targetClass = tab === 'current' ? 'adm-row-current' : 'adm-row-paid';
        const input = document.getElementById('advSearchInput').value.toLowerCase();
        const sortOrder = document.getElementById('sortFilter').value;
        const serviceVal = document.getElementById('serviceFilter').value;
        let allRows = Array.from(document.querySelectorAll('#tableBody .table-row-card.' + targetClass));
        if (input) {
            allRows = allRows.filter(row => row.innerText.toLowerCase().includes(input));
        }
        if (serviceVal !== 'all') {
            allRows = allRows.filter(row => (row.getAttribute('data-service') || '') === serviceVal);
        }
        allRows.sort((a, b) => {
            const dateA = new Date(a.getAttribute('data-date') || 0);
            const dateB = new Date(b.getAttribute('data-date') || 0);
            return sortOrder === 'oldest' ? dateA - dateB : dateB - dateA;
        });
        return allRows;
    }

    function renderPagination(filteredRows, page) {
        const total = filteredRows.length;
        const totalPages = Math.max(1, Math.ceil(total / ROWS_PER_PAGE));
        if (page > totalPages) page = totalPages;
        _currentPage = page;

        // Hide ALL rows first
        document.querySelectorAll('#tableBody .table-row-card').forEach(r => { r.classList.add('hidden'); r.style.display = 'none'; });

        // Show only rows for current page
        const start = (page - 1) * ROWS_PER_PAGE;
        const end = start + ROWS_PER_PAGE;
        filteredRows.forEach((row, i) => {
            if (i >= start && i < end) {
                row.classList.remove('hidden');
                row.style.display = '';
            }
        });

        // Pagination info
        const infoEl = document.getElementById('paginationInfo');
        if (total === 0) {
            infoEl.textContent = 'No records found';
        } else {
            infoEl.textContent = `Showing ${start + 1}–${Math.min(end, total)} of ${total} records`;
        }

        // Pagination buttons
        const btnContainer = document.getElementById('paginationButtons');
        btnContainer.innerHTML = '';

        if (totalPages <= 1) return;

        // Prev button
        const prevBtn = document.createElement('button');
        prevBtn.type = 'button';
        prevBtn.innerHTML = '<span class="material-symbols-outlined text-sm">chevron_left</span>';
        prevBtn.className = 'size-8 rounded-lg flex items-center justify-center transition-all text-xs font-bold ' + (page <= 1 ? 'text-slate-300 cursor-not-allowed' : 'text-slate-600 hover:bg-primary hover:text-white');
        prevBtn.disabled = page <= 1;
        prevBtn.onclick = function() { if (page > 1) goToPage(page - 1); };
        btnContainer.appendChild(prevBtn);

        // Page number buttons (max 5 visible, sliding window)
        const maxVisible = 5;
        let startPage = 1;
        let endPage = totalPages;
        if (totalPages > maxVisible) {
            startPage = Math.max(1, page - Math.floor(maxVisible / 2));
            endPage = startPage + maxVisible - 1;
            if (endPage > totalPages) { endPage = totalPages; startPage = endPage - maxVisible + 1; }
        }

        if (startPage > 1) {
            const firstBtn = document.createElement('button');
            firstBtn.type = 'button'; firstBtn.textContent = '1';
            firstBtn.className = 'size-8 rounded-lg flex items-center justify-center transition-all text-xs font-bold text-slate-500 hover:bg-slate-100';
            firstBtn.onclick = function() { goToPage(1); };
            btnContainer.appendChild(firstBtn);
            if (startPage > 2) {
                const dots = document.createElement('span');
                dots.textContent = '…';
                dots.className = 'size-8 flex items-center justify-center text-slate-400 text-xs';
                btnContainer.appendChild(dots);
            }
        }

        for (let i = startPage; i <= endPage; i++) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = i;
            btn.className = 'size-8 rounded-lg flex items-center justify-center transition-all text-xs font-bold ' + (i === page ? 'bg-primary text-white shadow-sm' : 'text-slate-500 hover:bg-slate-100');
            btn.onclick = (function(pg) { return function() { goToPage(pg); }; })(i);
            btnContainer.appendChild(btn);
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                const dots = document.createElement('span');
                dots.textContent = '…';
                dots.className = 'size-8 flex items-center justify-center text-slate-400 text-xs';
                btnContainer.appendChild(dots);
            }
            const lastBtn = document.createElement('button');
            lastBtn.type = 'button'; lastBtn.textContent = totalPages;
            lastBtn.className = 'size-8 rounded-lg flex items-center justify-center transition-all text-xs font-bold text-slate-500 hover:bg-slate-100';
            lastBtn.onclick = function() { goToPage(totalPages); };
            btnContainer.appendChild(lastBtn);
        }

        // Next button
        const nextBtn = document.createElement('button');
        nextBtn.type = 'button';
        nextBtn.innerHTML = '<span class="material-symbols-outlined text-sm">chevron_right</span>';
        nextBtn.className = 'size-8 rounded-lg flex items-center justify-center transition-all text-xs font-bold ' + (page >= totalPages ? 'text-slate-300 cursor-not-allowed' : 'text-slate-600 hover:bg-primary hover:text-white');
        nextBtn.disabled = page >= totalPages;
        nextBtn.onclick = function() { if (page < totalPages) goToPage(page + 1); };
        btnContainer.appendChild(nextBtn);
    }

    function goToPage(page) {
        const rows = getFilteredRows(_activeTab);
        renderPagination(rows, page);
    }

    function filterAdmissionsTable() {
        _currentPage = 1;
        const rows = getFilteredRows(_activeTab);
        renderPagination(rows, 1);
    }

    function switchTab(tab) {
        _activeTab = tab;
        _currentPage = 1;
        document.getElementById('advSearchInput').value = '';
        document.getElementById('tab-current').className = tab === 'current' ? 'pb-2 border-b-2 border-primary text-primary font-bold text-sm transition-colors whitespace-nowrap' : 'pb-2 border-b-2 border-transparent text-slate-400 hover:text-slate-700 font-bold text-sm transition-colors whitespace-nowrap';
        document.getElementById('tab-paid').className = tab === 'paid' ? 'pb-2 border-b-2 border-primary text-primary font-bold text-sm transition-colors whitespace-nowrap' : 'pb-2 border-b-2 border-transparent text-slate-400 hover:text-slate-700 font-bold text-sm transition-colors whitespace-nowrap';

        const paidSummary = document.getElementById('paidHistorySummary');
        if (tab === 'current') {
            if (paidSummary) paidSummary.classList.add('hidden');
        } else {
            if (paidSummary) paidSummary.classList.remove('hidden');
        }

        // HIDE/SHOW ACTION COLUMN BASED ON TAB
        const actionCols = document.querySelectorAll('.action-col');
        if (tab === 'paid') {
            actionCols.forEach(col => col.classList.add('hidden'));
        } else {
            actionCols.forEach(col => col.classList.remove('hidden'));
        }

        const rows = getFilteredRows(tab);
        renderPagination(rows, 1);
    }

    // Initialize pagination on page load
    document.addEventListener('DOMContentLoaded', function() {
        switchTab('current');
    });

    function openSelectPatientModal() { document.getElementById('selectPatientModal').classList.replace('hidden', 'flex'); setTimeout(() => { document.getElementById('selectPatientBox').classList.remove('scale-95', 'opacity-0'); }, 10); }
    function closeSelectPatientModal() { document.getElementById('selectPatientBox').classList.add('scale-95', 'opacity-0'); setTimeout(() => { document.getElementById('selectPatientModal').classList.replace('flex', 'hidden'); }, 300); }

    // ── TIME SLOT PICKER ──
    let timeSlotTarget = null; // 'admission' or 'followup'

    function openTimeSlotPicker(target) {
        timeSlotTarget = target;
        // Highlight currently selected slot
        const currentVal = target === 'admission'
            ? document.getElementById('admTimeSlotValue').value
            : document.getElementById('fu_time').value;
        document.querySelectorAll('.time-slot-option').forEach(btn => {
            const check = btn.querySelector('.slot-check');
            if (btn.dataset.value === currentVal) {
                btn.classList.add('border-primary', 'bg-primary/5');
                check.classList.remove('hidden');
            } else {
                btn.classList.remove('border-primary', 'bg-primary/5');
                check.classList.add('hidden');
            }
        });
        document.getElementById('timeSlotPickerModal').classList.replace('hidden', 'flex');
        setTimeout(() => { document.getElementById('timeSlotPickerBox').classList.remove('scale-95', 'opacity-0'); }, 10);
    }

    function closeTimeSlotPicker() {
        document.getElementById('timeSlotPickerBox').classList.add('scale-95', 'opacity-0');
        setTimeout(() => { document.getElementById('timeSlotPickerModal').classList.replace('flex', 'hidden'); }, 300);
    }

    function selectTimeSlot(el) {
        const value = el.dataset.value;
        const label = el.dataset.label;

        if (timeSlotTarget === 'followup') {
            document.getElementById('fu_time').value = value;
            document.getElementById('fuTimeSlotLabel').textContent = label;
            document.getElementById('fuTimeSlotLabel').classList.remove('text-slate-400');
            document.getElementById('fuTimeSlotLabel').classList.add('text-slate-800', 'font-bold');
            refreshFollowupStaff();
        }

        closeTimeSlotPicker();
    }

    function refreshFollowupStaff() {
        const fuDate = document.getElementById('fu_date').value;
        const fuTime = document.getElementById('fu_time').value;
        const fuStaff = document.getElementById('fu_staff');
        const fuStatus = document.getElementById('fuStaffStatus');
        if (!fuDate || !fuTime) return;

        fuStatus.classList.remove('hidden');
        fuStatus.className = 'text-[10px] mt-1 text-slate-400 font-bold';
        fuStatus.textContent = 'Checking staff availability...';

        fetch('<?= basename($_SERVER["SCRIPT_FILENAME"]) ?>?action=check_staff_availability&date=' + encodeURIComponent(fuDate) + '&time=' + encodeURIComponent(fuTime))
            .then(r => r.json())
            .then(staffList => {
                const currentVal = fuStaff.value;
                fuStaff.innerHTML = '<option value="Unassigned">-- Assign Later --</option>';
                staffList.forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s.name;
                    opt.textContent = s.name + (s.role ? ' (' + s.role + ')' : '') + (s.busy ? ' - Busy (Patient: ' + s.busy_patient + ')' : '');
                    opt.disabled = s.busy;
                    if (s.name === currentVal && !s.busy) opt.selected = true;
                    fuStaff.appendChild(opt);
                });
                const busyCount = staffList.filter(s => s.busy).length;
                if (busyCount === staffList.length && staffList.length > 0) {
                    fuStatus.className = 'text-[10px] mt-1 text-amber-600 font-bold';
                    fuStatus.textContent = 'All staff are busy at this time slot.';
                } else {
                    fuStatus.className = 'text-[10px] mt-1 text-emerald-600 font-bold';
                    fuStatus.textContent = (staffList.length - busyCount) + ' of ' + staffList.length + ' staff available.';
                }
            })
            .catch(() => {
                fuStatus.className = 'text-[10px] mt-1 text-red-500 font-bold';
                fuStatus.textContent = 'Failed to check availability.';
            });
    }

    function updateAdmissionDateCombined() {
        const dateInput = document.getElementById('admissionDateOnly');
        const timeInput = document.getElementById('admTimeValue');
        const dateVal = dateInput.value;
        const timeVal = timeInput.value;

        const now = new Date();
        const todayStr = now.getFullYear() + '-' + String(now.getMonth()+1).padStart(2,'0') + '-' + String(now.getDate()).padStart(2,'0');
        const nowTime = String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0');

        // Prevent past date
        if (dateVal < todayStr) {
            dateInput.value = todayStr;
            alert('Hindi pwedeng pumili ng nakaraang petsa.');
        }

        // If today, prevent past time
        if (dateInput.value === todayStr && timeVal < nowTime) {
            timeInput.value = nowTime;
            alert('Hindi pwedeng pumili ng nakaraang oras.');
        }

        if (dateInput.value && timeInput.value) {
            document.getElementById('admissionDateCombined').value = dateInput.value + 'T' + timeInput.value;
        }
    }

    // Keep combined field updated when date changes
    document.addEventListener('DOMContentLoaded', function() {
        const dateInput = document.getElementById('admissionDateOnly');
        if (dateInput) { dateInput.addEventListener('change', updateAdmissionDateCombined); }
    });

    function filterPatientList() {
        const input = document.getElementById('patientSearchInput').value.toLowerCase();
        const items = document.querySelectorAll('.patient-item');
        items.forEach(item => {
            const name = item.querySelector('.patient-name-txt').innerText.toLowerCase();
            if (name.includes(input)) { item.style.display = ''; } else { item.style.display = 'none'; }
        });
    }

    function choosePatient(index) {
        if (!patientsList[index]) return;
        const p = patientsList[index];
        if (parseInt(p.is_admitted)) { alert('This patient is currently admitted. Please discharge them first before creating a new admission.'); return; }
        document.getElementById('selectedPatientId').value = p.patient_id || '';
        document.querySelector('input[name="full_name"]').value = p.full_name || '';
        document.querySelector('input[name="spouse_name"]').value = p.husband_name || '';
        if (p.birthday) { document.querySelector('input[name="dob"]').value = p.birthday; }
        document.getElementById('ageDisplay').value = p.age || '';
        toggleGuardianField(p.age ? parseInt(p.age) : null, p.guardian_id_url || '');
        document.querySelector('input[name="religion"]').value = p.religion || '';
        document.querySelector('input[name="father_name"]').value = p.father_name || '';
        document.querySelector('input[name="mother_maiden_name"]').value = p.mother_name || '';
        var cn = p.contact_number || '09'; if(!cn.startsWith('09')) cn = '09'; document.querySelector('input[name="contact_number"]').value = cn;
        document.querySelector('textarea[name="address"]').value = p.address || '';
        // Pre-fill LMP / EDD from existing patient record
        const lmpEl = document.getElementById('lmpInput');
        if (lmpEl) {
            lmpEl.value = p.last_menstrual_period || '';
            calculateEdd();
        }
        const emailRow = document.getElementById('patientEmailRow');
        const emailDisplay = document.getElementById('patientEmailDisplay');
        if (p.email_address) {
            emailDisplay.value = p.email_address;
        } else {
            emailDisplay.value = '';
            emailDisplay.placeholder = 'Patient has no account';
        }
        emailRow.classList.remove('hidden');
        closeSelectPatientModal();
    }

    function openAdmitModal() { document.getElementById('admitModal').classList.replace('hidden', 'flex'); setTimeout(() => { document.getElementById('admitModalBox').classList.remove('scale-95', 'opacity-0'); }, 10); toggleMaternityBlock(); }
    function closeAdmitModal() {
        document.getElementById('admitModalBox').classList.add('scale-95', 'opacity-0');
        setTimeout(() => { document.getElementById('admitModal').classList.replace('flex', 'hidden'); }, 300);
        const emailRow = document.getElementById('patientEmailRow');
        const emailDisplay = document.getElementById('patientEmailDisplay');
        if (emailRow) { emailRow.classList.add('hidden'); }
        if (emailDisplay) { emailDisplay.value = ''; emailDisplay.placeholder = '—'; }
    }

    function openAssignStaffModal(admId, patientName, reason) {
        document.getElementById('assignStaffAdmId').value = admId;
        document.getElementById('assignStaffPatientInfo').textContent = patientName + ' — ' + reason;
        document.getElementById('assignStaffModal').classList.replace('hidden', 'flex');
        setTimeout(() => { document.getElementById('assignStaffBox').classList.remove('scale-95', 'opacity-0'); }, 10);
    }
    function closeAssignStaffModal() {
        document.getElementById('assignStaffBox').classList.add('scale-95', 'opacity-0');
        setTimeout(() => { document.getElementById('assignStaffModal').classList.replace('flex', 'hidden'); }, 300);
    }

    // STAGE MODAL REMOVED FROM ACTION BUTTONS BUT KEPT FUNCTION IN CASE CALLED PROGRAMMATICALLY
    function openStageModal(id, currentStage) {
        document.getElementById('stage_adm_id').value = id;
        let select = document.getElementById('stageSelect');
        for(let i=0; i < select.options.length; i++) {
            if(select.options[i].value === currentStage) { select.selectedIndex = i; break; }
        }
        document.getElementById('stageModal').classList.replace('hidden', 'flex');
        setTimeout(() => { document.getElementById('stageModalBox').classList.remove('scale-95', 'opacity-0'); }, 10);
    }
    function closeStageModal() {
        document.getElementById('stageModalBox').classList.add('scale-95', 'opacity-0');
        setTimeout(() => { document.getElementById('stageModal').classList.replace('flex', 'hidden'); }, 300);
    }

    function openDeliveryTypeModal(admId, currentReason) {
        <?php if (!$isCurrentUserMidwife): ?>
        alert('Only a Midwife can select the delivery type. Receptionists are not allowed to perform this action.');
        return;
        <?php endif; ?>
        document.getElementById('dt_adm_id').value = admId;
        const select = document.getElementById('dt_selected_delivery_type');
        const r = (currentReason || '').toLowerCase();
        if (r.includes('cesarean') || r.includes('c-section')) {
            select.value = 'Cesarean Delivery';
        } else {
            select.value = 'Normal Delivery';
        }

        document.getElementById('deliveryTypeModal').classList.replace('hidden', 'flex');
        setTimeout(() => { document.getElementById('deliveryTypeModalBox').classList.remove('scale-95', 'opacity-0'); }, 10);
    }

    function closeDeliveryTypeModal() {
        document.getElementById('deliveryTypeModalBox').classList.add('scale-95', 'opacity-0');
        setTimeout(() => { document.getElementById('deliveryTypeModal').classList.replace('flex', 'hidden'); }, 300);
    }

    function openBedAssignmentModal(admId, mode = 'labor') {
        const laborRoomsData = <?= json_encode($laborRoomsWithBeds) ?>;
        const deliveryRoomsData = <?= json_encode($deliveryRoomsWithBeds) ?>;
        const recoveryRoomsData = <?= json_encode($recoveryRoomsWithBeds) ?>;
        const defaultRoomPrices = <?= json_encode($defaultRoomPrices) ?>;
        const roomData = mode === 'delivery' ? deliveryRoomsData : (mode === 'recovery' ? recoveryRoomsData : laborRoomsData);

        document.getElementById('ba_assignment_mode').value = mode;
        document.getElementById('ba_adm_id').value = admId;

        const title = document.getElementById('ba_title');
        const subtitle = document.getElementById('ba_subtitle');
        const roomLabel = document.getElementById('ba_room_label');
        const submitBtn = document.getElementById('ba_submit_btn');
        const roomSelect = document.getElementById('ba_room_id');

        if (mode === 'delivery') {
            title.textContent = 'Assign Delivery Room Bed';
            subtitle.textContent = 'Select delivery room and available bed after vitals.';
            roomLabel.innerHTML = 'Delivery Room <span class="req-star text-red-500">*</span>';
            submitBtn.innerHTML = '<span class="material-symbols-outlined text-[18px]">task_alt</span>Assign Bed & Continue';
        } else if (mode === 'recovery') {
            title.textContent = 'Assign Recovery Room Bed';
            subtitle.textContent = 'Select recovery room bed after delivery type.';
            roomLabel.innerHTML = 'Recovery Room <span class="req-star text-red-500">*</span>';
            submitBtn.innerHTML = '<span class="material-symbols-outlined text-[18px]">task_alt</span>Assign Bed & Register Baby';
        } else {
            title.textContent = 'Assign Labor Room Bed';
            subtitle.textContent = 'Select labor room and available bed before recording vitals.';
            roomLabel.innerHTML = 'Labor Room <span class="req-star text-red-500">*</span>';
            submitBtn.innerHTML = '<span class="material-symbols-outlined text-[18px]">task_alt</span>Assign Bed & Start Labor';
        }

        roomSelect.innerHTML = '<option value="">-- Select Room --</option>';
        roomData.forEach(room => {
            const option = document.createElement('option');
            option.value = room.id;
            let label = room.room_name;
            if (mode === 'recovery' && room.subtype_label) {
                const priceTxt = (typeof room.subtype_price !== 'undefined') ? Number(room.subtype_price).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '0.00';
                label = '[' + room.subtype_label + ' • ₱' + priceTxt + '] ' + room.room_name;
            } else if (mode === 'labor' && room.subtype_label) {
                const priceTxt = (typeof room.subtype_price !== 'undefined') ? Number(room.subtype_price).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '0.00';
                label = '[' + room.subtype_label + ' • ₱' + priceTxt + '] ' + room.room_name;
            } else if (mode === 'labor' || mode === 'delivery') {
                const priceKey = mode === 'labor' ? 'labor_room' : 'delivery_room';
                const p = (defaultRoomPrices && typeof defaultRoomPrices[priceKey] !== 'undefined') ? Number(defaultRoomPrices[priceKey]) : 0;
                if (p > 0) {
                    label = '[₱' + p.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '] ' + room.room_name;
                }
            }
            option.textContent = label;
            roomSelect.appendChild(option);
        });

        const bedSelect = document.getElementById('ba_bed_id');
        bedSelect.innerHTML = '<option value="">-- Select Room First --</option>';
        bedSelect.disabled = true;

        if (!roomData || roomData.length === 0) {
            alert(mode === 'delivery' ? 'No delivery rooms available.' : (mode === 'recovery' ? 'No recovery rooms available.' : 'No labor rooms available.'));
            return;
        }

        document.getElementById('bedAssignmentModal').classList.replace('hidden', 'flex');
        setTimeout(() => { document.getElementById('bedAssignmentModalBox').classList.remove('scale-95', 'opacity-0'); }, 10);
    }

    function closeBedAssignmentModal() {
        document.getElementById('bedAssignmentModalBox').classList.add('scale-95', 'opacity-0');
        setTimeout(() => { document.getElementById('bedAssignmentModal').classList.replace('flex', 'hidden'); }, 300);
    }

    function updateBedOptions() {
        const mode = document.getElementById('ba_assignment_mode').value || 'labor';
        const laborRoomsData = <?= json_encode($laborRoomsWithBeds) ?>;
        const deliveryRoomsData = <?= json_encode($deliveryRoomsWithBeds) ?>;
        const recoveryRoomsData = <?= json_encode($recoveryRoomsWithBeds) ?>;
        const roomData = mode === 'delivery' ? deliveryRoomsData : (mode === 'recovery' ? recoveryRoomsData : laborRoomsData);
        const roomId = document.getElementById('ba_room_id').value;
        const bedSelect = document.getElementById('ba_bed_id');
        const noBedMsg = document.getElementById('ba_no_beds_msg');

        bedSelect.innerHTML = '<option value="">-- Select Bed --</option>';
        noBedMsg.classList.add('hidden');

        if (!roomId) {
            bedSelect.innerHTML = '<option value="">-- Select Room First --</option>';
            bedSelect.disabled = true;
            return;
        }

        const selectedRoom = roomData.find(r => r.id == roomId);

        if (!selectedRoom || !selectedRoom.beds || selectedRoom.beds.length === 0) {
            noBedMsg.classList.remove('hidden');
            bedSelect.disabled = true;
            return;
        }

        const availableBeds = selectedRoom.beds.filter(b => b.bed_status === 'available');

        if (availableBeds.length === 0) {
            noBedMsg.classList.remove('hidden');
            bedSelect.disabled = true;
            return;
        }

        bedSelect.disabled = false;
        availableBeds.forEach(bed => {
            const option = document.createElement('option');
            option.value = bed.id;
            option.textContent = bed.bed_label + ' (Available)';
            bedSelect.appendChild(option);
        });
    }

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'assign_labor_room' && isset($_GET['adm_id'])): ?>
    document.addEventListener('DOMContentLoaded', function () {
        openBedAssignmentModal(<?= (int)$_GET['adm_id'] ?>, 'labor');
    });
    <?php endif; ?>

    function populateNurseryBedOptions() {
        const nurseryBeds = <?= json_encode($infantWardAvailableBeds) ?>;
        const bedSelect = document.getElementById('nb_nursery_bed_id');
        if (!bedSelect) return;

        bedSelect.innerHTML = '<option value="">-- Select Available Nursery Bed --</option>';
        nurseryBeds.forEach((bed) => {
            const opt = document.createElement('option');
            opt.value = bed.bed_id;
            opt.textContent = bed.room_name + ' - ' + bed.bed_label;
            bedSelect.appendChild(opt);
        });
    }

    function toggleNurseryBedFields() {
        const locationSelect = document.getElementById('nb_location_option');
        const nurseryWrap = document.getElementById('nb_nursery_bed_wrap');
        const nurserySelect = document.getElementById('nb_nursery_bed_id');
        const methodValue = (document.getElementById('nb_delivery_method').value || '').toLowerCase();
        const isCesarean = methodValue.includes('cesarean') || methodValue.includes('c-section');

        // Check APGAR score
        let apgarTotal = 0;
        document.querySelectorAll('.apgar-calc').forEach(s => { apgarTotal += parseInt(s.value) || 0; });
        const isLowApgar = (apgarTotal <= 6);

        if (isCesarean || isLowApgar) {
            locationSelect.value = 'nursery';
            locationSelect.disabled = true;
        } else {
            locationSelect.disabled = false;
        }

        if (locationSelect.value === 'nursery') {
            nurseryWrap.classList.remove('hidden');
            nurserySelect.required = true;
            populateNurseryBedOptions();
        } else {
            nurseryWrap.classList.add('hidden');
            nurserySelect.required = false;
            nurserySelect.value = '';
        }
    }

    //  NEW: SHOW NEWBORN MODAL WITH CONTEXT
    function openNewbornModal(admId, momPatientId, momName, serviceName, assignedStaff) {
        document.getElementById('nb_adm_id').value = admId;
        document.getElementById('nb_mom_patient_id').value = momPatientId || '';
        document.getElementById('nb_mom_name').innerText = momName;

        let defaultName = "Baby " + momName.split(" ").pop();
        document.getElementById('nb_infant_name').value = defaultName;

        // Reset fields
        document.getElementById('nb_weight_kg').value = '';
        document.getElementById('nb_length_cm').value = '';
        document.getElementById('nb_gender').selectedIndex = 0;

        // Delivery Method is static based on the selected delivery type.
        let methodValue = 'Normal Spontaneous Delivery';
        if (serviceName && (serviceName.toLowerCase().includes('cesarean') || serviceName.toLowerCase().includes('c-section'))) {
            methodValue = 'Cesarean Section';
        }

        document.getElementById('nb_delivery_method').value = methodValue;
        document.getElementById('nb_delivery_method_display').value = methodValue;

        const locationSelect = document.getElementById('nb_location_option');
        const isCesarean = methodValue.toLowerCase().includes('cesarean');
        locationSelect.value = isCesarean ? 'nursery' : 'rooming_in';
        toggleNurseryBedFields();

        // Auto-assign staff from vitals
        let staffSelect = document.getElementById('nb_attending_staff');
        if (assignedStaff) {
            let found = false;
            for(let i=0; i<staffSelect.options.length; i++) {
                if(staffSelect.options[i].value === assignedStaff) {
                    staffSelect.selectedIndex = i;
                    found = true; break;
                }
            }
            if(!found) staffSelect.value = 'Unassigned';
        }

        document.querySelectorAll('.apgar-calc').forEach(s => s.value = "0");
        calculateApgar();

        document.getElementById('newbornModal').classList.replace('hidden', 'flex');
        setTimeout(() => { document.getElementById('newbornModalBox').classList.remove('scale-95', 'opacity-0'); }, 10);
    }

    function closeNewbornModal() {
        document.getElementById('newbornModalBox').classList.add('scale-95', 'opacity-0');
        setTimeout(() => { document.getElementById('newbornModal').classList.replace('flex', 'hidden'); }, 300);
    }

    //  NEW: AJAX HANDLER PARA SA "REGISTER ANOTHER BABY"
    // NOTE: As per latest requirement, newborns should NOT create their own
    // separate patient records. They are only stored under the mother's
    // record via the infants table.
    function registerAnotherBaby() {
        const form = document.getElementById('newbornForm');
        if (!form.reportValidity()) return;

        if(!form.attending_staff.value || form.attending_staff.value === 'Unassigned') {
            alert('Please select an Attending Midwife/Doctor.');
            return;
        }

        const formData = new FormData(form);
        formData.append('ajax_register_infant', '1');

        fetch('<?= $currentPage ?>', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                alert(data.message);
                // Clear inputs for next baby
                document.getElementById('nb_infant_name').value = "Baby 2 " + document.getElementById('nb_mom_name').innerText.split(" ").pop();
                document.getElementById('nb_weight_kg').value = '';
                document.getElementById('nb_length_cm').value = '';
                document.querySelectorAll('.apgar-calc').forEach(s => s.value = "0");
                calculateApgar();
            }
        })
        .catch(err => alert('Network error. Please try again.'));
    }


    function calculateApgar() {
        let total = 0;
        document.querySelectorAll('.apgar-calc').forEach(select => {
            total += parseInt(select.value) || 0;
        });

        document.getElementById('apgarTotalDisplay').innerText = total;
        document.getElementById('apgar_score_final').value = total + '/10';

        const classBadge = document.getElementById('apgarClassDisplay');
        const locationSelect = document.getElementById('nb_location_option');
        if (total <= 3) {
            classBadge.innerText = 'Critically Low';
            classBadge.className = 'mr-3 px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-widest bg-red-100 text-red-600 border border-red-200';
        } else if (total <= 6) {
            classBadge.innerText = 'Moderately Abnormal';
            classBadge.className = 'mr-3 px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-widest bg-amber-100 text-amber-600 border border-amber-200';
        } else {
            classBadge.innerText = 'Reassuring';
            classBadge.className = 'mr-3 px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-widest bg-emerald-100 text-emerald-600 border border-emerald-200';
        }

        // Force nursery when APGAR is critically low or moderately abnormal
        if (locationSelect) {
            const methodVal = (document.getElementById('nb_delivery_method').value || '').toLowerCase();
            const isCesarean = methodVal.includes('cesarean') || methodVal.includes('c-section');
            if (total <= 6) {
                locationSelect.value = 'nursery';
                locationSelect.disabled = true;
                toggleNurseryBedFields();
            } else if (!isCesarean) {
                // Only re-enable if not cesarean (cesarean also forces nursery)
                locationSelect.disabled = false;
            }
        }
    }

    function toggleFollowupFields() {
        const isChecked = document.getElementById('toggleFollowup').checked;
        const container = document.getElementById('followupInputs');
        const dInput = document.getElementById('fu_date');
        const tInput = document.getElementById('fu_time');

        if (isChecked) {
            container.style.opacity = '1';
            container.style.pointerEvents = 'auto';
            dInput.disabled = false;
            dInput.required = true;
            tInput.disabled = false;
            tInput.required = true;
        } else {
            container.style.opacity = '0.5';
            container.style.pointerEvents = 'none';
            dInput.disabled = true;
            dInput.required = false;
            dInput.value = '';
            tInput.disabled = true;
            tInput.required = false;
            tInput.value = '';
        }
    }

    function toggleLabFields() {
        const isChecked = document.getElementById('toggleLabs').checked;
        const container = document.getElementById('labInputs');
        const inputs = container.querySelectorAll('input');

        if (isChecked) {
            container.style.opacity = '1';
            container.style.pointerEvents = 'auto';
            inputs.forEach(i => i.disabled = false);
        } else {
            container.style.opacity = '0.5';
            container.style.pointerEvents = 'none';
            inputs.forEach(i => {
                i.disabled = true;
                if(i.type !== 'file') i.value = '';
            });
        }
    }

    function toggleFetalFields() {
        const cb = document.getElementById('toggleFetal');
        const container = document.getElementById('fetalInputs');
        if (!cb || !container) return;
        const fields = container.querySelectorAll('input, select');
        if (cb.checked) {
            container.style.opacity = '1';
            container.style.pointerEvents = 'auto';
            fields.forEach(f => f.disabled = false);
            // Auto-fill AOG from LMP when enabling and AOG is empty.
            const aogInput = document.getElementById('c_fetal_aog');
            const lmpInput = document.getElementById('c_lmp');
            if (aogInput && !aogInput.value && lmpInput && lmpInput.value) {
                const computed = computeAogFromLmp(lmpInput.value);
                if (computed) aogInput.value = computed;
            }
        } else {
            container.style.opacity = '0.5';
            container.style.pointerEvents = 'none';
            fields.forEach(f => { f.disabled = true; f.value = ''; });
        }
    }

    // Gravida / Para steppers
    function adjustGP(which, delta) {
        const id = which === 'gravida' ? 'c_gravida' : 'c_para';
        const inp = document.getElementById(id);
        if (!inp) return;
        let val = parseInt(inp.value, 10);
        if (isNaN(val)) val = 0;
        val = Math.max(0, Math.min(20, val + delta));
        inp.value = val;
        syncGPSummary();
    }
    function syncGPSummary() {
        const g = parseInt((document.getElementById('c_gravida') || {}).value, 10);
        const p = parseInt((document.getElementById('c_para') || {}).value, 10);
        const summary = document.getElementById('c_gp_summary');
        if (summary) summary.textContent = 'G' + (isNaN(g) ? 0 : g) + 'P' + (isNaN(p) ? 0 : p);
    }

    // Compute AOG (Age of Gestation) from LMP date string (YYYY-MM-DD).
    // Returns "X weeks and Y days" or null if invalid.
    function computeAogFromLmp(lmpStr) {
        if (!lmpStr) return null;
        const parts = String(lmpStr).split('-');
        if (parts.length !== 3) return null;
        const y = parseInt(parts[0], 10);
        const m = parseInt(parts[1], 10);
        const d = parseInt(parts[2], 10);
        if (!y || !m || !d) return null;
        const lmp = new Date(y, m - 1, d);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        lmp.setHours(0, 0, 0, 0);
        const totalDays = Math.floor((today - lmp) / 86400000);
        if (totalDays < 0) return null;
        const weeks = Math.floor(totalDays / 7);
        const days = totalDays % 7;
        return weeks + ' weeks and ' + days + ' days';
    }

    // Compute EDD from LMP using Naegele's rule: LMP + 7 days - 3 months + 1 year.
    // Returns YYYY-MM-DD string or '' if invalid.
    function computeEddFromLmp(lmpStr) {
        if (!lmpStr) return '';
        const parts = String(lmpStr).split('-');
        if (parts.length !== 3) return '';
        let y = parseInt(parts[0], 10);
        let m = parseInt(parts[1], 10);
        let d = parseInt(parts[2], 10);
        if (!y || !m || !d) return '';
        const tmp = new Date(y, m - 1, d);
        tmp.setDate(tmp.getDate() + 7);
        y = tmp.getFullYear(); m = tmp.getMonth() + 1; d = tmp.getDate();
        m -= 3;
        if (m <= 0) { m += 12; y -= 1; }
        y += 1;
        const lastDay = new Date(y, m, 0).getDate();
        if (d > lastDay) d = lastDay;
        return `${String(y).padStart(4,'0')}-${String(m).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
    }

    // Called when the user edits LMP inside the Checkup modal — recomputes EDD and AOG.
    function recomputeEddFromLmp() {
        const cLmp = document.getElementById('c_lmp');
        const cEdd = document.getElementById('c_edd');
        const cFetalAog = document.getElementById('c_fetal_aog');
        if (!cLmp) return;
        if (cEdd) cEdd.value = computeEddFromLmp(cLmp.value);
        if (cFetalAog && !cFetalAog.disabled) {
            const aog = computeAogFromLmp(cLmp.value);
            if (aog) cFetalAog.value = aog;
        }
    }

    function openCheckupModal(admData) {
        document.getElementById('c_adm_id').value = admData.id;
        document.getElementById('c_full_name').value = admData.full_name || '';
        document.getElementById('c_service').value = admData.reason || '';
        document.getElementById('c_spouse_name').value = admData.husband_name || '';
        document.getElementById('c_dob').value = admData.birthday || '';
        document.getElementById('c_age').value = admData.age || '';
        document.getElementById('c_religion').value = admData.religion || '';
        document.getElementById('c_father').value = admData.father_name || '';
        document.getElementById('c_mother').value = admData.mother_name || '';
        document.getElementById('c_contact').value = admData.contact_number || '';
        document.getElementById('c_address').value = admData.address || '';

        // LMP / EDD / Pregnancy Status
        const cLmp = document.getElementById('c_lmp');
        const cEdd = document.getElementById('c_edd');
        const cPregBlock = document.getElementById('c_pregnancy_block');
        const cPregStatusWrap = document.getElementById('c_pregnancy_status_wrap');
        const cPregStatus = document.getElementById('c_pregnancy_status');
        if (cLmp) cLmp.value = admData.last_menstrual_period || '';
        if (cEdd) {
            if (admData.estimated_delivery_date) {
                cEdd.value = admData.estimated_delivery_date;
            } else if (admData.last_menstrual_period) {
                const parts = String(admData.last_menstrual_period).split('-');
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
                        cEdd.value = `${String(y).padStart(4,'0')}-${String(m).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
                    } else { cEdd.value = ''; }
                } else { cEdd.value = ''; }
            } else { cEdd.value = ''; }
        }
        if (cPregStatus) cPregStatus.value = admData.pregnancy_status || 'Pending Confirmation';

        // Load Gravida / Para counts
        const cGravida = document.getElementById('c_gravida');
        const cPara = document.getElementById('c_para');
        if (cGravida) cGravida.value = (admData.gravida !== undefined && admData.gravida !== null) ? admData.gravida : 0;
        if (cPara) cPara.value = (admData.para !== undefined && admData.para !== null) ? admData.para : 0;
        if (typeof syncGPSummary === 'function') syncGPSummary();

        // Load Fetal Status
        const cFetalAog = document.getElementById('c_fetal_aog');
        const cFetalFh = document.getElementById('c_fetal_fundal_height');
        const cFetalFht = document.getElementById('c_fetal_fht');
        const cFetalPres = document.getElementById('c_fetal_presentation');
        const toggleFetalCb = document.getElementById('toggleFetal');
        if (cFetalAog) cFetalAog.value = admData.fetal_aog || '';
        if (cFetalFh) cFetalFh.value = admData.fetal_fundal_height || '';
        if (cFetalFht) cFetalFht.value = admData.fetal_fht || '';
        if (cFetalPres) cFetalPres.value = admData.fetal_presentation || '';
        const hasFetalData = !!(admData.fetal_aog || admData.fetal_fundal_height || admData.fetal_fht || admData.fetal_presentation);
        if (toggleFetalCb) toggleFetalCb.checked = hasFetalData;
        if (typeof toggleFetalFields === 'function') toggleFetalFields();

        // Auto-compute AOG from LMP if field is empty (user can still override).
        // NOTE: must run AFTER toggleFetalFields() because that clears values when toggle is off.
        if (cFetalAog && !cFetalAog.value && admData.last_menstrual_period) {
            const computed = computeAogFromLmp(admData.last_menstrual_period);
            if (computed) cFetalAog.value = computed;
        }

        // Load Vitals
        document.getElementById('c_bp').value = admData.bp || '';
        document.getElementById('c_temp').value = admData.temp || '';
        document.getElementById('c_weight').value = admData.weight || '';
        document.getElementById('c_pulse').value = admData.pulse || '';
        document.getElementById('c_spo2').value = admData.spo2 || '';

        // Load Lab Results
        document.getElementById('c_lab_cbc').value = admData.lab_cbc || '';
        document.getElementById('c_lab_urinalysis').value = admData.lab_urinalysis || '';
        document.getElementById('c_lab_blood_type').value = admData.lab_blood_type || '';
        document.getElementById('c_lab_blood_sugar').value = admData.lab_blood_sugar || '';
        document.getElementById('c_lab_hep_b').value = admData.lab_hep_b || '';
        document.getElementById('c_lab_syphilis').value = admData.lab_syphilis || '';

        // Load Image Preview Links
        const tvPreview = document.getElementById('tv_image_preview');
        if (admData.lab_transvaginal) {
            tvPreview.innerHTML = `<a href="${admData.lab_transvaginal}" target="_blank" class="text-[10px] font-bold text-blue-500 hover:text-blue-700 flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">visibility</span> View Current File</a>`;
            tvPreview.classList.remove('hidden');
        } else {
            tvPreview.innerHTML = '';
            tvPreview.classList.add('hidden');
        }

        const pelvicPreview = document.getElementById('pelvic_image_preview');
        if (admData.lab_pelvic) {
            pelvicPreview.innerHTML = `<a href="${admData.lab_pelvic}" target="_blank" class="text-[10px] font-bold text-blue-500 hover:text-blue-700 flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">visibility</span> View Current File</a>`;
            pelvicPreview.classList.remove('hidden');
        } else {
            pelvicPreview.innerHTML = '';
            pelvicPreview.classList.add('hidden');
        }

        //  LOGIC FOR HIDING/SHOWING PANELS BASED ON SERVICE
        const serviceName = (admData.reason || '').toLowerCase();
        const hasUltrasoundWord = serviceName.includes('ultrasound') || serviceName.includes('utz') || serviceName.includes('transvaginal') || serviceName.includes('pelvic');
        const isMixedCheckup = serviceName.includes('prenatal') || serviceName.includes('check') || serviceName.includes('delivery') || serviceName.includes('labor');
        const isUltrasoundOnly = hasUltrasoundWord && !isMixedCheckup;
        const isPostnatal = serviceName.includes('postnatal');
        const isLaborService = serviceName.includes('labor');
        const isTransvaginalService = serviceName.includes('transvaginal');
        const isPelvicService = serviceName.includes('pelvic');
        const isTransvaginalActive = <?= json_encode($isTransvaginalActive) ?>;
        const isPelvicActive = <?= json_encode($isPelvicActive) ?>;

        // Show pregnancy block whenever the patient has LMP info OR the service is maternity-related (excluding postnatal).
        const isPostnatalService = serviceName.includes('postnatal');
        const isMaternityService = !isPostnatalService && (serviceName.includes('prenatal') || serviceName.includes('delivery') || serviceName.includes('labor'));
        if (cPregBlock) {
            if (isPostnatalService) {
                cPregBlock.classList.add('hidden');
            } else if (admData.last_menstrual_period || isMaternityService) {
                cPregBlock.classList.remove('hidden');
            } else {
                cPregBlock.classList.add('hidden');
            }
        }
        // Pregnancy status dropdown is editable only for prenatal / delivery / labor services (not postnatal).
        if (cPregStatusWrap) {
            if (isMaternityService) {
                cPregStatusWrap.classList.remove('hidden');
            } else {
                cPregStatusWrap.classList.add('hidden');
            }
        }

        // Fetal Status section: show only when pregnancy_status === 'Confirmed Pregnant' AND on a maternity service.
        const fetalSection = document.getElementById('fetalSection');
        const eddWrap = document.getElementById('c_edd_wrap');
        const refreshFetalVisibility = () => {
            const status = cPregStatus ? cPregStatus.value : '';
            const isConfirmed = status === 'Confirmed Pregnant';
            // EDD only shown when confirmed pregnant.
            if (eddWrap) eddWrap.classList.toggle('hidden', !isConfirmed);
            if (!fetalSection) return;
            const visible = isMaternityService && isConfirmed;
            fetalSection.classList.toggle('hidden', !visible);
            if (!visible && toggleFetalCb) {
                toggleFetalCb.checked = false;
                if (typeof toggleFetalFields === 'function') toggleFetalFields();
            }
        };
        refreshFetalVisibility();
        if (cPregStatus && !cPregStatus.dataset.fetalBound) {
            cPregStatus.addEventListener('change', refreshFetalVisibility);
            cPregStatus.dataset.fetalBound = '1';
        }

        const tvGroup = document.getElementById('tv_ultrasound_group');
        const pelvicGroup = document.getElementById('pelvic_ultrasound_group');

        const enforceUltrasoundAvailability = () => {
            if (tvGroup) {
                const tvInput = tvGroup.querySelector('input[type="file"]');
                if (!isTransvaginalActive) {
                    tvGroup.style.display = 'none';
                    if (tvInput) {
                        tvInput.disabled = true;
                        tvInput.value = '';
                    }
                }
            }

            if (pelvicGroup) {
                const pelvicInput = pelvicGroup.querySelector('input[type="file"]');
                if (!isPelvicActive) {
                    pelvicGroup.style.display = 'none';
                    if (pelvicInput) {
                        pelvicInput.disabled = true;
                        pelvicInput.value = '';
                    }
                }
            }
        };

        // Default: show both ultrasound upload groups
        if (tvGroup) tvGroup.style.display = '';
        if (pelvicGroup) pelvicGroup.style.display = '';

        // When Pelvic Ultrasound, hide Transvaginal; when Transvaginal, hide Pelvic
        if (isTransvaginalService && !isPelvicService) {
            if (tvGroup) tvGroup.style.display = '';
            if (pelvicGroup) pelvicGroup.style.display = 'none';
        } else if (isPelvicService && !isTransvaginalService) {
            if (tvGroup) tvGroup.style.display = 'none';
            if (pelvicGroup) pelvicGroup.style.display = '';
        }
        enforceUltrasoundAvailability();

        const vitalsPanel = document.querySelector('.bg-amber-50.border-amber-200');
        const labsPanel = document.querySelector('.border-emerald-200');
        const followupPanel = document.getElementById('followupSection');
        const weightInput = document.getElementById('c_weight');
        const weightLabel = document.getElementById('c_weight_label');

        const setVitalsRequired = (isRequired, isWeightRequired = isRequired) => {
            document.getElementById('c_bp').required = isRequired;
            document.getElementById('c_temp').required = isRequired;
            weightInput.required = isWeightRequired;
            document.getElementById('c_pulse').required = isRequired;
            document.getElementById('c_spo2').required = isRequired;
            if (weightLabel) {
                weightLabel.textContent = isWeightRequired ? 'Weight (kg)' : 'Weight (kg) - Optional';
            }
        };

        // Reset display
        if (vitalsPanel) vitalsPanel.style.display = '';
        if (labsPanel) labsPanel.style.display = '';
        if (followupPanel) followupPanel.style.display = '';

        if (isUltrasoundOnly) {
            if (vitalsPanel) vitalsPanel.style.display = 'none';
            if (followupPanel) followupPanel.style.display = 'none';
            document.getElementById('toggleLabs').checked = true;
            toggleLabFields();
            setVitalsRequired(false, false);
        } else if (isPostnatal) {
            if (labsPanel) labsPanel.style.display = 'none';
            setVitalsRequired(true, true);
        } else {
            // Normal (Prenatal/Delivery)
            setVitalsRequired(true, !isLaborService);
            if (admData.lab_cbc || admData.lab_urinalysis || admData.lab_blood_type || admData.lab_blood_sugar || admData.lab_hep_b || admData.lab_syphilis || admData.lab_transvaginal || admData.lab_pelvic) {
                document.getElementById('toggleLabs').checked = true;
            } else {
                document.getElementById('toggleLabs').checked = false;
            }
            toggleLabFields();
        }

        enforceUltrasoundAvailability();

        // Show follow-up service picker for Prenatal Checkup
        const fuServiceRow = document.getElementById('fuServiceRow');
        if (fuServiceRow) {
            if (serviceName.includes('prenatal')) {
                fuServiceRow.classList.remove('hidden');
            } else {
                fuServiceRow.classList.add('hidden');
                document.getElementById('fu_service').value = '';
            }
        }

        // UI Logic check: Kung tapos na ang checkup, itago ang Save Button at Follow-up Section
        if (admData.stage !== 'Waiting' && admData.stage !== 'Checkup' && admData.stage !== 'Labor') {
            document.getElementById('btnSaveVitals').classList.add('hidden');
            followupPanel.classList.add('hidden');
        } else {
            document.getElementById('btnSaveVitals').classList.remove('hidden');
            if(!isUltrasoundOnly) followupPanel.classList.remove('hidden');
            // Reset follow up form
            document.getElementById('toggleFollowup').checked = false;
            toggleFollowupFields();
        }

        document.getElementById('checkupModal').classList.replace('hidden', 'flex');
        setTimeout(() => { document.getElementById('checkupModalBox').classList.remove('scale-95', 'opacity-0'); }, 10);
    }

    function closeCheckupModal() {
        document.getElementById('checkupModalBox').classList.add('scale-95', 'opacity-0');
        setTimeout(() => { document.getElementById('checkupModal').classList.replace('flex', 'hidden'); }, 300);
    }

    function openPaymentModal(id, name, displayServiceName, serviceKey, balance, type, profilePicUrl, existingPhFront, existingPhBack, philhealthCoverage) {
        document.getElementById('d_adm_id').value = id;
        document.getElementById('d_patient_name_input').value = name;
        document.getElementById('d_patient_display').innerText = name;

        // Store existing PhilHealth photos for this patient
        window._existingPhFront = existingPhFront || '';
        window._existingPhBack = existingPhBack || '';
        // Store PhilHealth coverage amount (based on services availed)
        window._philhealthCoverage = parseFloat(philhealthCoverage) || 0;

        const picEl = document.getElementById('d_patient_pic');
        const initialEl = document.getElementById('d_patient_initial');
        if (profilePicUrl) {
            picEl.src = profilePicUrl;
            picEl.classList.remove('hidden');
            initialEl.classList.add('hidden');
        } else {
            picEl.classList.add('hidden');
            initialEl.classList.remove('hidden');
            initialEl.innerText = name.charAt(0).toUpperCase();
        }
        document.getElementById('d_service_name_input').value = serviceKey || displayServiceName || "General Admission";
        document.getElementById('billingBasePrice').value = balance;
        document.getElementById('payment_action').value = type;

        if (type === 'downpayment') {
            document.getElementById('modalTitle').innerText = 'Process Initial Payment';
            document.getElementById('modalSubtitle').innerText = 'Collect downpayment before proceeding to check-up.';
            document.getElementById('displayServiceName').value = (displayServiceName || "General Admission") + ' (Total Price: ₱' + parseFloat(balance).toLocaleString('en-US', {minimumFractionDigits: 2}) + ')';

            document.getElementById('discountDiv').classList.add('hidden');
            document.getElementById('downpaymentDiv').classList.remove('hidden');
            document.getElementById('philhealthDiv').classList.add('hidden');

            document.getElementById('billingDiscountPercent').value = "0";
            document.getElementById('toggleDiscount').checked = false;
            document.getElementById('togglePhilhealth').checked = false;
            document.getElementById('billingDownpayment').value = balance;
            document.getElementById('btnDischargeSubmit').innerHTML = '<span class="material-symbols-outlined text-lg">payments</span> Process Downpayment';
        } else {
            document.getElementById('modalTitle').innerText = 'Process Payment';
            document.getElementById('modalSubtitle').innerText = 'Process the remaining balance of the patient.';
            document.getElementById('displayServiceName').value = (displayServiceName || "General Admission") + ' (Remaining Balance: ₱' + parseFloat(balance).toLocaleString('en-US', {minimumFractionDigits: 2}) + ')';

            document.getElementById('discountDiv').classList.remove('hidden');
            document.getElementById('downpaymentDiv').classList.add('hidden');
            document.getElementById('philhealthDiv').classList.remove('hidden');

            document.getElementById('billingDiscountPercent').value = "0";
            document.getElementById('toggleDiscount').checked = false;
            document.getElementById('togglePhilhealth').checked = false;
            document.getElementById('philhealthInfoBox').classList.add('hidden');
            document.getElementById('billingDownpayment').value = "0";
            document.getElementById('btnDischargeSubmit').innerHTML = '<span class="material-symbols-outlined text-lg">check_circle</span> Process Payment';
        }

        document.getElementById('paymentMethod').value = "Cash";
        // Reset PhilHealth photo previews
        clearPhPreview('front');
        clearPhPreview('back');
        // Reset "use existing" hidden inputs
        document.getElementById('phFrontExisting').value = '';
        document.getElementById('phBackExisting').value = '';
        // Show/hide existing photo sections
        updateExistingPhilhealthUI();
        calculateBilling();
        togglePaymentFields();

        document.getElementById('paymentModal').classList.replace('hidden', 'flex');
        setTimeout(() => { document.getElementById('paymentModalBox').classList.remove('scale-95', 'opacity-0'); }, 10);
    }

    function closePaymentModal() {
        document.getElementById('paymentModalBox').classList.add('scale-95', 'opacity-0');
        setTimeout(() => { document.getElementById('paymentModal').classList.replace('flex', 'hidden'); }, 300);
    }

    let _calculatingBilling = false;
    function calculateBilling() {
        if (_calculatingBilling) return;
        _calculatingBilling = true;
        try {
        const type = document.getElementById('payment_action').value;
        const basePrice = parseFloat(document.getElementById('billingBasePrice').value) || 0;
        let finalAmount = 0;
        let newBalanceForApp = basePrice;

        const isDiscountEnabled = document.getElementById('toggleDiscount').checked;
        const isPhilhealthEnabled = document.getElementById('togglePhilhealth').checked;
        const discInput = document.getElementById('billingDiscountPercent');
        const methodSelect = document.getElementById('paymentMethod');

        // Update PhilHealth hidden inputs
        document.getElementById('isPhilhealthInput').value = isPhilhealthEnabled ? '1' : '0';

        // When PhilHealth is ON, force Cash only — no online payment
        if (isPhilhealthEnabled) {
            methodSelect.value = 'Cash';
            methodSelect.querySelector('option[value="PayMongo"]').disabled = true;
            togglePaymentFields();
        } else {
            methodSelect.querySelector('option[value="PayMongo"]').disabled = false;
        }

        if (type === 'downpayment') {
            finalAmount = parseFloat(document.getElementById('billingDownpayment').value) || 0;
            discInput.disabled = true;
            document.getElementById('philhealthAmountInput').value = '0';
        } else {
            if (isPhilhealthEnabled) {
                // PhilHealth covers only the configured package rate (per service).
                // Discount (if toggled ON) is applied on top of PhilHealth coverage,
                // computed on the remaining balance AFTER PhilHealth deduction.
                let coverage = parseFloat(window._philhealthCoverage) || 0;
                if (coverage < 0) coverage = 0;
                // Cap coverage at the remaining balance
                if (coverage > basePrice) coverage = basePrice;

                document.getElementById('philhealthInfoBox').classList.remove('hidden');
                document.getElementById('philhealthCoveredDisplay').innerText = coverage.toLocaleString('en-US', {minimumFractionDigits: 2});
                document.getElementById('philhealthAmountInput').value = coverage;

                let afterPh = basePrice - coverage;
                if (afterPh < 0) afterPh = 0;

                if (isDiscountEnabled) {
                    discInput.disabled = false;
                    let pct = parseFloat(discInput.value) || 0;
                    if (pct > 100) pct = 100;
                    if (pct < 0) pct = 0;
                    const discountAmount = afterPh * (pct / 100);
                    finalAmount = afterPh - discountAmount;
                } else {
                    discInput.disabled = true;
                    finalAmount = afterPh;
                }
                newBalanceForApp = finalAmount;
            } else if (isDiscountEnabled) {
                discInput.disabled = false;
                let pct = parseFloat(discInput.value) || 0;
                if(pct > 100) pct = 100;
                if(pct < 0) pct = 0;

                const discountAmount = basePrice * (pct / 100);
                finalAmount = basePrice - discountAmount;
                newBalanceForApp = finalAmount;

                document.getElementById('philhealthInfoBox').classList.add('hidden');
                document.getElementById('philhealthAmountInput').value = '0';
            } else {
                discInput.disabled = true;
                finalAmount = basePrice;
                newBalanceForApp = basePrice;

                document.getElementById('philhealthInfoBox').classList.add('hidden');
                document.getElementById('philhealthAmountInput').value = '0';
            }
        }

        if (finalAmount < 0) finalAmount = 0;
        if (newBalanceForApp < 0) newBalanceForApp = 0;

        document.getElementById('d_final_amount_input').value = finalAmount;
        document.getElementById('displayTotal').innerText = '₱ ' + finalAmount.toLocaleString('en-US', {minimumFractionDigits: 2});

        // Always allow submit — even when amount is 0
        document.getElementById('btnDischargeSubmit').disabled = false;

        // When PhilHealth is ON, validate that photos are uploaded
        if (isPhilhealthEnabled) {
            validatePhilhealthPhotos();
        }

        if (type === 'discharge' && !isPhilhealthEnabled) {
            const admId = document.getElementById('d_adm_id').value;
            fetch('<?= $currentPage ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    'ajax_update_balance': '1',
                    'adm_id': admId,
                    'new_balance': newBalanceForApp
                })
            });
        }
        } finally { _calculatingBilling = false; }
    }

    function togglePaymentFields() {
        const method = document.getElementById('paymentMethod').value;
        const hint = document.getElementById('methodHint');
        const action = document.getElementById('payment_action').value;
        const btnSubmit = document.getElementById('btnDischargeSubmit');
        const btnWaiting = document.getElementById('btnWaitingOnline');
        const admId = document.getElementById('d_adm_id').value;

        fetch('<?= $currentPage ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                'ajax_update_method': '1',
                'adm_id': admId,
                'method': method === 'PayMongo' ? 'Online Payment' : 'Cash'
            })
        });

        if (window.paymentPollingInterval) { clearInterval(window.paymentPollingInterval); }

        if (method === 'PayMongo') {
            btnSubmit.classList.add('hidden');
            btnWaiting.classList.remove('hidden');
            document.getElementById('emailDiv').classList.add('hidden');

            window.paymentPollingInterval = setInterval(() => {
                fetch('<?= $currentPage ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ 'ajax_check_stage': '1', 'adm_id': admId })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.stage === 'Discharged') {
                        clearInterval(window.paymentPollingInterval);
                        document.getElementById('payment_action').value = 'app_online_success';
                        document.getElementById('paymentModalForm').submit();
                    }
                });
            }, 2000);

        } else {
            btnSubmit.classList.remove('hidden');
            btnWaiting.classList.add('hidden');
            document.getElementById('emailDiv').classList.remove('hidden');
            hint.innerText = action === 'downpayment' ? "Record cash downpayment over the counter." : "Patient will pay over the counter.";
        }
        calculateBilling();
    }

    function showReceiptSavedToast() {
        const toast = document.getElementById('receiptToast');
        if (!toast) return;
        toast.classList.remove('hidden');
        // Auto-hide after a short delay
        setTimeout(() => {
            toast.classList.add('hidden');
        }, 3000);
    }

    //  HTML2CANVAS RECEIPT LOGIC
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('show_receipt') && urlParams.get('show_receipt') == '1') {
        document.getElementById('rec_patient').innerText = urlParams.get('r_name');
        document.getElementById('rec_service').innerText = urlParams.get('r_svc');
        document.getElementById('rec_method').innerText = urlParams.get('r_method');
        // computedTotal = service price + sum(room charges) - PhilHealth coverage
        let computedTotal = 0;

        // Show service price (from clinic_services / financials.php)
        const svcAmt = parseFloat(urlParams.get('r_svc_amt') || '0');
        const svcAmtEl = document.getElementById('rec_service_amt');
        if (svcAmtEl) {
            if (svcAmt > 0) {
                svcAmtEl.innerText = '\u20b1 ' + svcAmt.toLocaleString('en-US', {minimumFractionDigits: 2});
                svcAmtEl.classList.remove('hidden');
            } else {
                svcAmtEl.classList.add('hidden');
            }
        }
        computedTotal += svcAmt;

        // PhilHealth receipt info — only show if explicitly flagged AND has coverage amount
        const isPhilhealth = urlParams.get('r_ph') === '1';
        const phAmount = parseFloat(urlParams.get('r_ph_amt') || '0');
        if (isPhilhealth && phAmount > 0) {
            document.getElementById('rec_philhealth_section').classList.remove('hidden');
            // "Original Amount" will be set below AFTER room charges are summed (gross total before PhilHealth).
            document.getElementById('rec_ph_covered').innerText = '- ₱ ' + phAmount.toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById('rec_method').innerText = 'PhilHealth';
        } else {
            document.getElementById('rec_philhealth_section').classList.add('hidden');
        }

        // Room Charges breakdown (Labor / Delivery / Recovery / Infant Ward)
        const roomsSection = document.getElementById('rec_rooms_section');
        const roomsList = document.getElementById('rec_rooms_list');
        if (roomsSection && roomsList) {
            roomsList.innerHTML = '';
            try {
                const rRoomsRaw = urlParams.get('r_rooms');
                if (rRoomsRaw) {
                    const rooms = JSON.parse(rRoomsRaw);
                    if (Array.isArray(rooms) && rooms.length > 0) {
                        rooms.forEach(rm => {
                            const row = document.createElement('div');
                            row.className = 'flex justify-between items-end';
                            row.innerHTML =
                                '<span class="text-amber-700 text-xs font-bold">' + (rm.label || 'Room') + '</span>' +
                                '<span class="font-black text-amber-800">\u20b1 ' + Number(rm.price || 0).toLocaleString('en-US', {minimumFractionDigits: 2}) + '</span>';
                            roomsList.appendChild(row);
                            computedTotal += Number(rm.price || 0);
                        });
                        roomsSection.classList.remove('hidden');
                    } else {
                        roomsSection.classList.add('hidden');
                    }
                } else {
                    roomsSection.classList.add('hidden');
                }
            } catch (e) {
                roomsSection.classList.add('hidden');
            }
        }

        // Subtract PhilHealth coverage from computed total (if applicable)
        if (isPhilhealth && phAmount > 0) {
            // At this point computedTotal = service price + all room charges (gross before PhilHealth).
            // Display the true gross as the "Original Amount" on the receipt.
            document.getElementById('rec_ph_original').innerText = '₱ ' + computedTotal.toLocaleString('en-US', {minimumFractionDigits: 2});
            computedTotal -= phAmount;
        }
        if (computedTotal < 0) { computedTotal = 0; }
        document.getElementById('rec_amount').innerText = '\u20b1 ' + computedTotal.toLocaleString('en-US', {minimumFractionDigits: 2});

        let recTypeStr = 'Official Receipt';
        if (urlParams.get('r_type') === 'downpayment') { recTypeStr = 'Downpayment Receipt'; }
        if (urlParams.get('r_type') === 'fully_paid') { recTypeStr = 'Official Receipt'; }
        document.getElementById('rec_title').innerText = recTypeStr;

        document.getElementById('rec_id_display').innerText = 'Receipt No: ' + urlParams.get('r_id');

        const today = new Date();
        document.getElementById('rec_date').innerText = today.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });

        document.getElementById('receiptModal').classList.replace('hidden', 'flex');
        setTimeout(() => { document.getElementById('receiptModalBox').classList.remove('scale-95', 'opacity-0'); }, 10);

        // Store for Save Receipt button
        currentReceiptId = urlParams.get('r_id');
        currentPaymentId = urlParams.get('pay_id');
        currentAdmissionId = urlParams.get('adm_id');
        currentReceiptMethod = urlParams.get('r_method') || '';
        currentReceiptType = urlParams.get('r_type') || '';

        window.history.replaceState({}, document.title, window.location.pathname);
    }

    function closeReceiptModal() {
        document.getElementById('receiptModalBox').classList.add('scale-95', 'opacity-0');
        setTimeout(() => { document.getElementById('receiptModal').classList.replace('flex', 'hidden'); }, 300);
    }

    async function saveReceipt(shouldDownload = true, shouldClose = false) {
        const receiptEl = document.getElementById('receiptContent');

        if (!currentReceiptId) {
            alert('Missing receipt reference; cannot save.');
            return;
        }

        try {
            const canvas = await html2canvas(receiptEl, { scale: 2 });

            if (!window.jspdf || !window.jspdf.jsPDF) {
                alert('PDF generator not loaded. Please refresh and try again.');
                return;
            }

            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('p', 'pt', 'a5');

            const pageWidth = doc.internal.pageSize.getWidth();
            const pageHeight = doc.internal.pageSize.getHeight();
            const imgData = canvas.toDataURL('image/jpeg', 0.95);
            const imgWidth = canvas.width;
            const imgHeight = canvas.height;
            const scale = Math.min((pageWidth - 24) / imgWidth, (pageHeight - 24) / imgHeight);
            const renderWidth = imgWidth * scale;
            const renderHeight = imgHeight * scale;
            const x = (pageWidth - renderWidth) / 2;
            const y = (pageHeight - renderHeight) / 2;

            doc.addImage(imgData, 'JPEG', x, y, renderWidth, renderHeight);

            // 1) Optional: Download as PDF to user's device
            if (shouldDownload) {
                try {
                    const fileName = 'Receipt_' + document.getElementById('rec_patient').innerText.replace(/\s+/g, '_') + '.pdf';
                    doc.save(fileName);
                } catch (e) {
                    console.error('Error triggering PDF download', e);
                }
            }

            // 2) Upload PDF to server and save path in DB
            const pdfBlob = doc.output('blob');
            if (pdfBlob) {
                const formData = new FormData();
                formData.append('ajax_save_receipt_pdf', '1');
                formData.append('receipt_id', currentReceiptId);
                // payment_id optional; send 0 if wala
                formData.append('payment_id', String(currentPaymentId || 0));
                if (currentAdmissionId) {
                    formData.append('adm_id', String(currentAdmissionId));
                }
                formData.append('receipt_pdf', pdfBlob, (currentReceiptId || 'receipt') + '.pdf');

                try {
                    const response = await fetch('admissions.php', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await response.json().catch(() => null);
                    if (data && data.success) {
                        showReceiptSavedToast();

                        if (currentAdmissionId && (currentReceiptMethod === 'Online Payment' || currentReceiptType === 'discharge')) {
                            try {
                                await fetch('admissions.php', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                    body: new URLSearchParams({
                                        'ajax_finalize_discharge': '1',
                                        'adm_id': String(currentAdmissionId),
                                        'payment_method': currentReceiptMethod || 'Online Payment'
                                    })
                                });
                            } catch (finalizeErr) {
                                console.error('Failed to finalize discharge after receipt save', finalizeErr);
                            }
                        }

                        if (shouldClose) closeReceiptModal();
                    } else {
                        console.warn('Server did not confirm receipt PDF save.', data);
                        alert('Saving receipt to database failed.');
                    }
                } catch (err) {
                    console.error('Error sending receipt PDF to server', err);
                    alert('Saving receipt to database failed.');
                }
            }
        } catch (e) {
            console.error('Error generating PDF receipt', e);
            alert('Failed to generate receipt PDF.');
        }
    }

    async function autoSaveReceiptPdf(receiptId, paymentId, admissionId) {
        if (!window.jspdf || !window.jspdf.jsPDF) {
            console.warn('jsPDF not loaded; skipping receipt PDF save.');
            return;
        }

        try {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('p', 'pt', 'a5');

            const marginX = 40;
            let y = 50;

            const clinicName = (document.getElementById('rec_clinic')?.innerText || '').trim();
            const recTitle = (document.getElementById('rec_title')?.innerText || 'Official Receipt').trim();
            const recNoText = (document.getElementById('rec_id_display')?.innerText || '').trim();
            const recDate = (document.getElementById('rec_date')?.innerText || '').trim();
            const patient = (document.getElementById('rec_patient')?.innerText || '').trim();
            const service = (document.getElementById('rec_service')?.innerText || '').trim();
            const method = (document.getElementById('rec_method')?.innerText || '').trim();
            const amount = (document.getElementById('rec_amount')?.innerText || '').trim();

            doc.setFont('helvetica', 'bold');
            doc.setFontSize(16);
            doc.text(clinicName || 'Clinic Receipt', marginX, y);
            y += 20;

            doc.setFontSize(11);
            doc.setFont('helvetica', 'normal');
            doc.text(recTitle, marginX, y);
            y += 16;

            doc.setFontSize(9);
            if (recNoText) { doc.text(recNoText, marginX, y); y += 12; }
            if (recDate) { doc.text(recDate, marginX, y); y += 18; }

            doc.setDrawColor(200);
            doc.line(marginX, y, 400, y);
            y += 16;

            doc.setFontSize(10);
            doc.setFont('helvetica', 'bold');
            doc.text('Patient Name:', marginX, y);
            doc.setFont('helvetica', 'normal');
            doc.text(patient || '-', marginX + 95, y);
            y += 14;

            doc.setFont('helvetica', 'bold');
            doc.text('Service:', marginX, y);
            doc.setFont('helvetica', 'normal');
            doc.text(service || '-', marginX + 95, y);
            y += 14;

            doc.setFont('helvetica', 'bold');
            doc.text('Payment Method:', marginX, y);
            doc.setFont('helvetica', 'normal');
            doc.text(method || '-', marginX + 95, y);
            y += 20;

            doc.setFont('helvetica', 'bold');
            doc.text('Total Amount:', marginX, y);
            doc.setFontSize(14);
            doc.setFont('helvetica', 'normal');
            const amountText = amount ? amount.replace('₱', 'PHP').trim() : 'PHP 0.00';
            doc.text(amountText, marginX + 95, y);

            const pdfBlob = doc.output('blob');

            const formData = new FormData();
            formData.append('ajax_save_receipt_pdf', '1');
            formData.append('receipt_id', receiptId);
            formData.append('payment_id', String(paymentId));
            if(admissionId) {
                formData.append('adm_id', String(admissionId));
            }
            formData.append('receipt_pdf', pdfBlob, (receiptId || 'receipt') + '.pdf');

            try {
                const response = await fetch('admissions.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json().catch(() => null);
                if (!data || !data.success) {
                    console.warn('Server did not confirm receipt PDF save.', data);
                }
            } catch (err) {
                console.error('Error sending receipt PDF to server', err);
            }
        } catch (err) {
            console.error('Error generating receipt PDF', err);
        }
    }

    function openLogoutModal() { document.getElementById('logoutModal').classList.replace('hidden', 'flex'); }
    function closeLogoutModal() { document.getElementById('logoutModal').classList.replace('flex', 'hidden'); }
    function confirmLogout() {
        document.getElementById('logoutModal').classList.replace('flex', 'hidden');
        document.getElementById('loggingOutScreen').classList.replace('hidden', 'flex');
        setTimeout(() => {
            window.location.href = '?action=logout&c=<?= urlencode($clinicCode) ?>';
        }, 1500);
    }

    // =========== PhilHealth Camera & File Functions ===========
    let phCameraSide = 'front'; // 'front' or 'back'
    let phCameraStream = null;

    function openPhCamera(side) {
        phCameraSide = side;
        document.getElementById('phCameraTitle').innerText = 'Capture PhilHealth ID — ' + (side === 'front' ? 'Front' : 'Back');
        document.getElementById('phCameraLive').classList.remove('hidden');
        document.getElementById('phCameraPreview').classList.add('hidden');
        document.getElementById('phCameraModal').classList.replace('hidden', 'flex');

        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
            .then(stream => {
                phCameraStream = stream;
                document.getElementById('phCameraVideo').srcObject = stream;
            })
            .catch(() => {
                alert('Unable to access camera. Please allow camera permission or use "Choose File" instead.');
                closePhCamera();
            });
    }

    function closePhCamera() {
        if (phCameraStream) {
            phCameraStream.getTracks().forEach(t => t.stop());
            phCameraStream = null;
        }
        document.getElementById('phCameraVideo').srcObject = null;
        document.getElementById('phCameraModal').classList.replace('flex', 'hidden');
    }

    function capturePhPhoto() {
        const video = document.getElementById('phCameraVideo');
        const canvas = document.getElementById('phCameraCanvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0);
        document.getElementById('phCameraCaptured').src = canvas.toDataURL('image/jpeg', 0.85);
        document.getElementById('phCameraLive').classList.add('hidden');
        document.getElementById('phCameraPreview').classList.remove('hidden');
        if (phCameraStream) {
            phCameraStream.getTracks().forEach(t => t.stop());
            phCameraStream = null;
        }
    }

    function retakePhPhoto() {
        document.getElementById('phCameraPreview').classList.add('hidden');
        document.getElementById('phCameraLive').classList.remove('hidden');
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
            .then(stream => {
                phCameraStream = stream;
                document.getElementById('phCameraVideo').srcObject = stream;
            });
    }

    function usePhPhoto() {
        const dataUrl = document.getElementById('phCameraCaptured').src;
        if (phCameraSide === 'front') {
            document.getElementById('phFrontData').value = dataUrl;
            document.getElementById('phFrontPreviewImg').src = dataUrl;
            document.getElementById('phFrontPreview').classList.remove('hidden');
            document.getElementById('phFrontExisting').value = '';
        } else {
            document.getElementById('phBackData').value = dataUrl;
            document.getElementById('phBackPreviewImg').src = dataUrl;
            document.getElementById('phBackPreview').classList.remove('hidden');
            document.getElementById('phBackExisting').value = '';
        }
        closePhCamera();
        validatePhilhealthPhotos();
    }

    function previewPhFile(input, previewId, imgId) {
        const preview = document.getElementById(previewId);
        const img = document.getElementById(imgId);
        const side = previewId.includes('Front') ? 'front' : 'back';
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                img.src = e.target.result;
                preview.classList.remove('hidden');
                // Also set data hidden input (clear camera data since file takes priority)
                document.getElementById(side === 'front' ? 'phFrontData' : 'phBackData').value = '';
                // Hide existing photo box since new file replaces it
                document.getElementById(side === 'front' ? 'phFrontExistingBox' : 'phBackExistingBox').classList.add('hidden');
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    function clearPhPreview(side) {
        if (side === 'front') {
            document.getElementById('phFrontPreview').classList.add('hidden');
            document.getElementById('phFrontPreviewImg').src = '';
            document.getElementById('phFrontFile').value = '';
            document.getElementById('phFrontData').value = '';
            document.getElementById('phFrontExisting').value = '';
            document.getElementById('phFrontUploadBox').classList.remove('hidden');
            // Re-show existing box if there's an existing photo on file
            if (window._existingPhFront) {
                document.getElementById('phFrontExistingBox').classList.remove('hidden');
            }
        } else {
            document.getElementById('phBackPreview').classList.add('hidden');
            document.getElementById('phBackPreviewImg').src = '';
            document.getElementById('phBackFile').value = '';
            document.getElementById('phBackData').value = '';
            document.getElementById('phBackExisting').value = '';
            document.getElementById('phBackUploadBox').classList.remove('hidden');
            if (window._existingPhBack) {
                document.getElementById('phBackExistingBox').classList.remove('hidden');
            }
        }
        validatePhilhealthPhotos();
    }

    function validatePhilhealthPhotos() {
        const isPhOn = document.getElementById('togglePhilhealth').checked;
        if (!isPhOn) return true;
        const hasFront = (document.getElementById('phFrontFile').files.length > 0) || (document.getElementById('phFrontData').value !== '') || (document.getElementById('phFrontExisting').value !== '');
        const hasBack = (document.getElementById('phBackFile').files.length > 0) || (document.getElementById('phBackData').value !== '') || (document.getElementById('phBackExisting').value !== '');
        const btn = document.getElementById('btnDischargeSubmit');
        const warn = document.getElementById('phPhotoWarning');
        if (!hasFront || !hasBack) {
            btn.disabled = true;
            if (warn) warn.classList.remove('hidden');
            return false;
        } else {
            btn.disabled = false;
            if (warn) warn.classList.add('hidden');
            return true;
        }
    }

    function updateExistingPhilhealthUI() {
        const frontBox = document.getElementById('phFrontExistingBox');
        const backBox = document.getElementById('phBackExistingBox');
        const frontImg = document.getElementById('phFrontExistingImg');
        const backImg = document.getElementById('phBackExistingImg');
        if (window._existingPhFront) {
            frontImg.src = window._existingPhFront;
            frontBox.classList.remove('hidden');
        } else {
            frontBox.classList.add('hidden');
        }
        if (window._existingPhBack) {
            backImg.src = window._existingPhBack;
            backBox.classList.remove('hidden');
        } else {
            backBox.classList.add('hidden');
        }
    }

    function useExistingPhPhoto(side) {
        if (side === 'front') {
            document.getElementById('phFrontExisting').value = window._existingPhFront;
            document.getElementById('phFrontPreviewImg').src = window._existingPhFront;
            document.getElementById('phFrontPreview').classList.remove('hidden');
            document.getElementById('phFrontExistingBox').classList.add('hidden');
            document.getElementById('phFrontUploadBox').classList.add('hidden');
        } else {
            document.getElementById('phBackExisting').value = window._existingPhBack;
            document.getElementById('phBackPreviewImg').src = window._existingPhBack;
            document.getElementById('phBackPreview').classList.remove('hidden');
            document.getElementById('phBackExistingBox').classList.add('hidden');
            document.getElementById('phBackUploadBox').classList.add('hidden');
        }
        validatePhilhealthPhotos();
    }

    // Intercept form submit: validate PhilHealth photos
    document.addEventListener('DOMContentLoaded', () => {
        const payForm = document.getElementById('paymentModalForm');
        if (payForm) {
            payForm.addEventListener('submit', function(e) {
                const isPhOn = document.getElementById('togglePhilhealth').checked;
                if (isPhOn && !validatePhilhealthPhotos()) {
                    e.preventDefault();
                    alert('Please upload both PhilHealth ID photos (Front and Back) before processing payment.');
                }
            });
        }
    });

    // Initial setup on load
    window.addEventListener('DOMContentLoaded', () => {
        switchTab('current');
    });
</script>

<!-- PhilHealth Camera Modal -->
<div id="phCameraModal" class="fixed inset-0 z-[200] hidden items-center justify-center p-4 bg-slate-900/80 backdrop-blur-sm">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between bg-green-50">
            <div class="flex items-center gap-2">
                <img src="uploads/philhealth_logo.png" alt="PH" class="h-6 rounded" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex';">
                <span class="material-symbols-outlined text-green-600 hidden">photo_camera</span>
                <h3 class="font-black text-slate-800 text-sm" id="phCameraTitle">Capture PhilHealth ID</h3>
            </div>
            <button type="button" onclick="closePhCamera()" class="size-8 rounded-full hover:bg-slate-200 text-slate-400 flex items-center justify-center"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>
        <div class="p-4">
            <div id="phCameraLive" class="relative">
                <video id="phCameraVideo" autoplay playsinline class="w-full rounded-xl bg-black aspect-[4/3] object-cover"></video>
                <button type="button" onclick="capturePhPhoto()" class="absolute bottom-3 left-1/2 -translate-x-1/2 size-14 rounded-full bg-white border-4 border-green-500 shadow-lg flex items-center justify-center hover:scale-105 transition-transform">
                    <span class="material-symbols-outlined text-green-600 text-2xl">camera</span>
                </button>
            </div>
            <div id="phCameraPreview" class="hidden relative">
                <canvas id="phCameraCanvas" class="hidden"></canvas>
                <img id="phCameraCaptured" src="" alt="Captured" class="w-full rounded-xl aspect-[4/3] object-cover">
                <div class="flex gap-2 mt-3">
                    <button type="button" onclick="retakePhPhoto()" class="flex-1 py-2.5 rounded-xl border border-slate-300 text-slate-600 font-bold text-sm flex items-center justify-center gap-1.5 hover:bg-slate-50 transition-all">
                        <span class="material-symbols-outlined text-[16px]">refresh</span> Retake
                    </button>
                    <button type="button" onclick="usePhPhoto()" class="flex-1 py-2.5 rounded-xl bg-green-600 text-white font-bold text-sm flex items-center justify-center gap-1.5 hover:bg-green-700 transition-all shadow-md">
                        <span class="material-symbols-outlined text-[16px]">check</span> Use Photo
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>