<?php
// 1. SETTINGS & SESSION
date_default_timezone_set('Asia/Manila');
ini_set('display_errors', 0); // Naka-off para hindi mag-crash ang session
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

ob_start();
session_start();
require_once 'db.php';
try { $pdo->exec("SET time_zone = '+08:00'"); } catch (Exception $e) {}

// ==============================================================
// 🔥 AUTO-FIX 1: CREATE AUDIT LOGS TABLE KUNG WALA PA 🔥
// ==============================================================
try {
    $pdo->query("SELECT 1 FROM audit_logs LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            TenantID VARCHAR(50) NULL,
            user_name VARCHAR(100) NOT NULL,
            role VARCHAR(50) NOT NULL,
            action_type VARCHAR(50) NOT NULL,
            details TEXT NOT NULL,
            ip_address VARCHAR(45) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (PDOException $ex) {}
}

// ==============================================================
// 🔥 AUTO-FIX 1.5: ADD TenantID KUNG LUMA ANG TABLE 🔥
// ==============================================================
try {
    $pdo->query("SELECT TenantID FROM audit_logs LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE audit_logs ADD TenantID VARCHAR(50) NULL AFTER id");
    } catch (PDOException $ex) {}
}

// ==============================================================
// 🔥 AUTO-FIX 2: CREATE TRIGGER TO LOG CLINIC INFO CHANGES 🔥
// ==============================================================
try {
    // Burahin ang luma para i-replace ng mas advanced na trigger
    $pdo->exec("DROP TRIGGER IF EXISTS log_clinic_name_change");
    $pdo->exec("DROP TRIGGER IF EXISTS log_clinic_info_change");
    
    // Trigger na nagmomonitor ng Name, Address, at Contact
    $pdo->exec("
        CREATE TRIGGER log_clinic_info_change 
        AFTER UPDATE ON tenants
        FOR EACH ROW 
        BEGIN
            -- Monitor Clinic Name
            IF NOT (OLD.clinic_name <=> NEW.clinic_name) THEN
                INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at)
                VALUES (
                    NEW.TenantID,
                    'System Action', 
                    'Admin', 
                    'Update', 
                    CONCAT('\"', IFNULL(OLD.clinic_name,'Clinic'), '\" changed their name to \"', IFNULL(NEW.clinic_name,'N/A'), '\"'), 
                    'System Trigger', 
                    NOW()
                );
            END IF;

            -- Monitor Clinic Address
            IF NOT (OLD.complete_address <=> NEW.complete_address) THEN
                INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at)
                VALUES (
                    NEW.TenantID,
                    'System Action', 
                    'Admin', 
                    'Update', 
                    CONCAT('\"', IFNULL(NEW.clinic_name, OLD.clinic_name), '\" changed their address to \"', IFNULL(NEW.complete_address, 'N/A'), '\"'), 
                    'System Trigger', 
                    NOW()
                );
            END IF;

            -- Monitor Clinic Contact Number
            IF NOT (OLD.clinic_contact <=> NEW.clinic_contact) THEN
                INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at)
                VALUES (
                    NEW.TenantID,
                    'System Action', 
                    'Admin', 
                    'Update', 
                    CONCAT('\"', IFNULL(NEW.clinic_name, OLD.clinic_name), '\" changed their contact number to \"', IFNULL(NEW.clinic_contact, 'N/A'), '\"'), 
                    'System Trigger', 
                    NOW()
                );
            END IF;
        END;
    ");
} catch (PDOException $e) {
    // Silent fail if user lacks trigger privileges
}

// ==============================================================
// 🔥 AUTO-FIX 3: CREATE TRIGGER TO LOG NEW APPOINTMENTS (FROM APP OR WEB) 🔥
// ==============================================================
try {
    $pdo->exec("DROP TRIGGER IF EXISTS log_appointment_booked");
    $pdo->exec("
        CREATE TRIGGER log_appointment_booked
        AFTER INSERT ON appointments
        FOR EACH ROW
        BEGIN
            INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at)
            VALUES (
                NEW.TenantID,
                IFNULL(NEW.full_name, 'Patient'),
                'Patient',
                'Appointment Booked',
                CONCAT(
                    'Appointment booked for: ', IFNULL(NEW.full_name, 'Unknown'),
                    ' | Service: ', IFNULL(NEW.service, 'N/A'),
                    ' | Date: ', IFNULL(DATE_FORMAT(NEW.appointment_date, '%M %d, %Y'), 'N/A'),
                    IF(NEW.appointment_time IS NOT NULL AND NEW.appointment_time != '',
                        CONCAT(' ', DATE_FORMAT(CAST(NEW.appointment_time AS TIME), '%h:%i %p')),
                        '')
                ),
                'App/Web',
                NOW()
            );
        END;
    ");
} catch (PDOException $e) {
    // Silent fail if user lacks trigger privileges
}
// ==============================================================


// ==============================================================
// --- LOGOUT HANDLER ---
// ==============================================================
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $c = $_GET['c'] ?? '';

    if (isset($_SESSION['full_name'])) {
        try {
            $logoutName = $_SESSION['full_name'];
            $logoutRole = $_SESSION['role'] ?? 'User';
            $auditRole = $logoutRole;
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $currentTime = date('Y-m-d H:i:s');
            $logoutTenant = $_SESSION['TenantID'] ?? null;

            $stmtLog = $pdo->prepare("INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, 'Logout', 'User securely logged out of their clinic portal.', ?, ?)");
            $stmtLog->execute([$logoutTenant, $logoutName, $auditRole, $ip, $currentTime]);
        } catch (Exception $e) {}
    }
    
    // Burahin ang session
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();

    if (!empty($c) && $c !== 'N/A') {
        header("Location: tenant_login.php?c=" . urlencode($c));
    } else {
        header("Location: tenant_login.php");
    }
    exit();
}
// ==============================================================

// ==============================================================
// AUDIT LOG HELPER FUNCTION
// ==============================================================
function log_audit($pdo, $tenant_id, $user_name, $role, $action_type, $details) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $currentTime = date('Y-m-d H:i:s'); 
        $stmt = $pdo->prepare("INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$tenant_id, $user_name, $role, $action_type, $details, $ip, $currentTime]);
    } catch (Exception $e) {}
}
// ==============================================================

// --- TENANT SECURITY CHECK ---
$tenant_id = $_SESSION['TenantID'] ?? null;
if (!isset($_SESSION['user_id']) || empty($tenant_id) || strtolower(trim((string)($_SESSION['role'] ?? ''))) === 'patient') {
    if (!isset($_SESSION['user_id']) && isset($_GET['c']) && $_GET['c'] !== '') {
        header("Location: tenant_login.php?c=" . urlencode($_GET['c']));
    } else {
        header("Location: tenant_login.php");
    }
    exit();
}

$displayName = $_SESSION['full_name'] ?? 'Tenant User';
$displayRole = $_SESSION['role'] ?? 'Clinic Administrator';
$isStaffRole = (strtolower(trim((string)$displayRole)) === 'staff');

// --- OWNER / STAFF ADMIN PERMISSION SYSTEM ---
$normalizedRole = strtolower(trim((string)$displayRole));
$currentUserIsOwner = in_array($normalizedRole, ['admin', 'administrator', 'owner', 'owner/midwife'], true);
$currentUserIsStaffAdmin = false;
$currentUserGrantedFeatures = [];
if (!$currentUserIsOwner && $tenant_id) {
    try {
        $stmtCurAccess = $pdo->prepare("SELECT cs.is_admin, cs.granted_features FROM clinic_staff cs INNER JOIN users u ON LOWER(TRIM(cs.email_address)) = LOWER(TRIM(u.email)) WHERE cs.TenantID = ? AND u.id = ? LIMIT 1");
        $stmtCurAccess->execute([$tenant_id, $_SESSION['user_id']]);
        $curAccess = $stmtCurAccess->fetch(PDO::FETCH_ASSOC);
        if ($curAccess) {
            $currentUserIsStaffAdmin = (int)($curAccess['is_admin'] ?? 0) === 1;
            $currentUserGrantedFeatures = json_decode($curAccess['granted_features'] ?? '[]', true) ?: [];
        }
    } catch (PDOException $e) {}
}
$_ownerAlsoMidwife = false;
if ($currentUserIsOwner && $tenant_id) {
    try { $_stmtMw = $pdo->prepare("SELECT COALESCE(also_midwife, 0) FROM users WHERE id = ? AND TenantID = ? LIMIT 1"); $_stmtMw->execute([$_SESSION['user_id'], $tenant_id]); $_ownerAlsoMidwife = ((int)$_stmtMw->fetchColumn() === 1); } catch (PDOException $e) {}
}
if ($currentUserIsOwner) { $displayRole = $_ownerAlsoMidwife ? 'Owner / Midwife' : 'Owner'; }
elseif ($currentUserIsStaffAdmin) { $displayRole = ($_SESSION['role'] ?? 'Staff') . ' | Admin'; }
else {
    // Non-admin/non-owner users cannot access audit logs
    header("Location: admissions.php");
    exit();
}

// --- FETCH TENANT LOOK & META ---
$themeColor = '#15803d';
$clinicName = 'MaternityHub';
$clinicCode = 'N/A';
$clinicLogo = null;

try {
    $stmtClinic = $pdo->prepare("SELECT clinic_name, clinic_code, clinic_logo, theme_color FROM tenants WHERE TenantID = ? LIMIT 1");
    $stmtClinic->execute([$tenant_id]);
    $clinicData = $stmtClinic->fetch(PDO::FETCH_ASSOC);

    if ($clinicData) {
        if (!empty($clinicData['clinic_name'])) $clinicName = $clinicData['clinic_name'];
        if (!empty($clinicData['clinic_code'])) $clinicCode = $clinicData['clinic_code'];
        if (!empty($clinicData['theme_color'])) $themeColor = $clinicData['theme_color'];
        if (!empty($clinicData['clinic_logo']) && file_exists(__DIR__ . '/uploads/logos/' . $clinicData['clinic_logo'])) {
            $clinicLogo = 'uploads/logos/' . $clinicData['clinic_logo'];
        }
    }
} catch (PDOException $e) {}

// Contrast Calculator
$hex = ltrim($themeColor, '#');
if (strlen($hex) == 3) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; }
$r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
$luminance = ($r * 0.299) + ($g * 0.587) + ($b * 0.114);

$isLightTheme = ($luminance > 150);

$headerTextPrimary = $isLightTheme ? 'text-slate-900' : 'text-white';
$headerTextSecondary = $isLightTheme ? 'text-slate-700' : 'text-primary-light';
$headerTextMuted = $isLightTheme ? 'text-slate-400' : 'text-white/50';
$headerBadgeBg = $isLightTheme ? 'bg-slate-200 text-slate-800' : 'bg-black/20 text-white';
$headerIconBox = $isLightTheme ? 'bg-white border border-slate-200' : 'bg-white/15 border border-white/25';
$headerIconColor = $isLightTheme ? 'text-slate-900' : 'text-white';
$headerBtn = $isLightTheme ? 'bg-white hover:bg-slate-50 text-slate-800 border-slate-200 shadow-sm' : 'bg-white/15 hover:bg-white/25 text-white border-white/30';
$sidebarActive = $isLightTheme ? 'bg-slate-800 text-white shadow-md' : 'bg-primary/10 text-primary';

// FETCH CURRENT PROFILE PICTURE & FULL NAME
try {
    $stmtPic = $pdo->prepare("SELECT u.first_name, u.middle_name, u.last_name, COALESCE(u.profile_image, cs.profile_image) AS profile_image FROM users u LEFT JOIN clinic_staff cs ON cs.TenantID = u.TenantID AND LOWER(TRIM(COALESCE(cs.email_address, ''))) = LOWER(TRIM(COALESCE(u.email, ''))) WHERE u.id = ? LIMIT 1");
    $stmtPic->execute([$_SESSION['user_id']]);
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

// ==============================================================
// 🔥 AUTO-LOG LOGIN EVENT (once per session) 🔥
// ==============================================================
if (!isset($_SESSION['login_audit_logged']) && $tenant_id) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $currentTime = date('Y-m-d H:i:s');
        $loginDetails = $displayName . ' logged in to the clinic portal.';
        $stmtLoginLog = $pdo->prepare("INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, 'Login', ?, ?, ?)");
        $stmtLoginLog->execute([$tenant_id, $displayName, $displayRole, $loginDetails, $ip, $currentTime]);
        $_SESSION['login_audit_logged'] = true;
    } catch (Exception $e) {
        // Silent fail
    }
}
// ==============================================================

// Para sa AJAX Auto-Refresh
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// --- AUTO-FIX: ENSURE appointments.created_at EXISTS WITH DEFAULT CURRENT_TIMESTAMP ---
try {
    $pdo->query("SELECT created_at FROM appointments LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE appointments ADD created_at DATETIME DEFAULT CURRENT_TIMESTAMP"); } catch (PDOException $ex) {}
}

// --- FETCH TENANT AUDIT LOGS ONLY ---
$tenantLogs = [];
try {
    $stmtTenant = $pdo->prepare("
        SELECT a.*, 
               t.clinic_name as linked_clinic_name
        FROM audit_logs a 
        LEFT JOIN tenants t ON a.TenantID = t.TenantID
        WHERE a.TenantID = ?
        AND action_type != 'Appointment Booked'
        ORDER BY a.created_at DESC LIMIT 300
    ");
    $stmtTenant->execute([$tenant_id]);
    $tenantLogs = $stmtTenant->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* Silent fail */ }

// --- FETCH APPOINTMENTS DIRECTLY FROM TABLE ---
try {
    $stmtAppts = $pdo->prepare("
        SELECT
            ap.full_name AS user_name,
            'Patient' AS role,
            'Appointment Booked' AS action_type,
            CONCAT(
                'Appointment booked for: ', IFNULL(ap.full_name, 'Unknown'),
                ' | Service: ', IFNULL(ap.service, 'N/A'),
                ' | Date: ', DATE_FORMAT(ap.appointment_date, '%M %d, %Y'),
                IF(ap.appointment_time IS NOT NULL AND ap.appointment_time != '',
                    CONCAT(' ', TIME_FORMAT(ap.appointment_time, '%h:%i %p')), '')
            ) AS details,
            'App/Web' AS ip_address,
            COALESCE(ap.created_at, ap.appointment_date) AS created_at,
            t.clinic_name AS linked_clinic_name,
            NULL AS legacy_clinic_name,
            NULL AS exact_clinic_name
        FROM appointments ap
        LEFT JOIN tenants t ON ap.TenantID = t.TenantID
        WHERE ap.TenantID = ?
        ORDER BY COALESCE(ap.created_at, ap.appointment_date) DESC
        LIMIT 200
    ");
    $stmtAppts->execute([$tenant_id]);
    $apptRows = $stmtAppts->fetchAll(PDO::FETCH_ASSOC);

    // Merge and sort by created_at descending
    $tenantLogs = array_merge($tenantLogs, $apptRows);
    usort($tenantLogs, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    $tenantLogs = array_slice($tenantLogs, 0, 300);
} catch (PDOException $e) { /* Silent fail */ }

// ==============================================================
// 🎨 ACTION BADGE COLOR ASSIGNMENT
// ==============================================================
function getActionBadge($action) {
    $action = strtolower($action);
    if (strpos($action, 'login') !== false) { return 'bg-blue-100 text-blue-700 border-blue-200'; }
    elseif (strpos($action, 'subscription expired') !== false) { return 'bg-rose-100 text-rose-700 border-rose-200'; }
    elseif (strpos($action, 'subscription renewed') !== false) { return 'bg-emerald-100 text-emerald-700 border-emerald-200'; }
    elseif (strpos($action, 'subscription activated') !== false) { return 'bg-emerald-100 text-emerald-700 border-emerald-200'; }
    elseif (strpos($action, 'staff fired') !== false) { return 'bg-red-100 text-red-700 border-red-200'; }
    elseif (strpos($action, 'staff restored') !== false) { return 'bg-emerald-100 text-emerald-700 border-emerald-200'; }
    elseif (strpos($action, 'patient admitted') !== false) { return 'bg-violet-100 text-violet-700 border-violet-200'; }
    elseif (strpos($action, 'appointment booked') !== false) { return 'bg-sky-100 text-sky-700 border-sky-200'; }
    elseif (strpos($action, 'patient archived') !== false || strpos($action, 'archived') !== false || strpos($action, 'fired') !== false) { return 'bg-amber-100 text-amber-700 border-amber-200'; }
    elseif (strpos($action, 'patient restored') !== false || strpos($action, 'restored') !== false) { return 'bg-emerald-100 text-emerald-700 border-emerald-200'; }
    elseif (strpos($action, 'service enabled') !== false) { return 'bg-teal-100 text-teal-700 border-teal-200'; }
    elseif (strpos($action, 'service disabled') !== false) { return 'bg-orange-100 text-orange-700 border-orange-200'; }
    elseif (strpos($action, 'logout') !== false || strpos($action, 'delete') !== false || strpos($action, 'remove') !== false || strpos($action, 'failed') !== false || strpos($action, 'suspend') !== false || strpos($action, 'reject') !== false) { return 'bg-red-100 text-red-700 border-red-200'; }
    elseif (strpos($action, 'update') !== false || strpos($action, 'change') !== false || strpos($action, 'reactivate') !== false) { return 'bg-amber-100 text-amber-700 border-amber-200'; }
    elseif (strpos($action, 'create') !== false || strpos($action, 'register') !== false || strpos($action, 'approve') !== false) { return 'bg-emerald-100 text-emerald-700 border-emerald-200'; }
    return 'bg-slate-100 text-slate-700 border-slate-200';
}
// ==============================================================
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Audit Logs - <?= htmlspecialchars($clinicName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = { 
            theme: { extend: { colors: { "primary": "<?= htmlspecialchars($themeColor) ?>", "primary-dark": "color-mix(in srgb, <?= htmlspecialchars($themeColor) ?> 70%, black)", "primary-light": "color-mix(in srgb, <?= htmlspecialchars($themeColor) ?> 20%, white)", "background-light": "#f8fafc" }, fontFamily: { "display": ["Plus Jakarta Sans", "sans-serif"] }, boxShadow: { 'soft': '0 10px 40px -10px rgba(0,0,0,0.08)' } } } 
        }
    </script>
    <style>
        html, body { margin: 0; padding: 0; scroll-behavior: smooth; }
        .material-symbols-outlined { font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; overflow: hidden; }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .scrollable-box { scroll-behavior: smooth; }
        @media print { aside, header, .no-print, #logoutModal, #loggingOutScreen { display: none !important; } main { padding: 0 !important; margin: 0 !important; background: white !important; overflow: visible !important; } .print-container { width: 100% !important; border: none !important; box-shadow: none !important; max-width: 100% !important; } .scrollable-box { max-height: none !important; overflow: visible !important; } body { overflow: auto !important; } }
        @view-transition { navigation: auto; }
        header { view-transition-name: header; }
        aside { view-transition-name: sidebar; }
        ::view-transition-old(sidebar), ::view-transition-new(sidebar),
        ::view-transition-old(header), ::view-transition-new(header) { animation: none; }
    </style>
</head>
<body class="bg-background-light text-slate-800 h-screen overflow-hidden flex flex-col relative text-sm antialiased font-display">

<div id="loggingOutScreen" class="fixed inset-0 z-[110] hidden bg-white flex-col items-center justify-center">
    <div class="size-12 border-4 border-slate-200 border-t-primary rounded-full animate-spin mb-4"></div>
    <p class="font-bold text-slate-800 animate-pulse tracking-tight text-xs">Closing session safely...</p>
</div>

<div id="logoutModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm">
    <div class="bg-white rounded-[2rem] p-6 max-w-xs w-full shadow-2xl border border-slate-100">
        <div class="text-center">
            <div class="size-12 rounded-2xl bg-red-50 text-red-500 flex items-center justify-center mx-auto mb-4">
                <span class="material-symbols-outlined text-2xl">logout</span>
            </div>
            <h3 class="text-base font-black text-slate-900 mb-1">Logout Account?</h3>
            <p class="text-slate-500 text-[11px] mb-6">Sigurado ka bang gusto mong mag-logout?</p>
            <div class="flex gap-2">
                <button onclick="closeLogoutModal()" class="flex-1 py-2.5 rounded-xl font-bold text-slate-400 hover:bg-slate-100 transition-all text-[11px]">Cancel</button>
                <button onclick="confirmLogout()" class="flex-1 py-2.5 rounded-xl font-bold bg-red-500 text-white hover:bg-red-600 transition-all text-[11px] shadow-lg shadow-red-100">Logout</button>
            </div>
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
            <span class="material-symbols-outlined text-sm">logout</span><span class="hidden md:inline">Logout</span>
        </button>
    </div>
</header>

<div class="flex-1 flex overflow-hidden">
    <aside class="w-80 bg-white border-r border-slate-200 hidden md:flex flex-col shrink-0 shadow-soft z-10 no-print" style="visibility:hidden">
        <nav id="sidebarNav" class="space-y-3 flex-1 p-6 overflow-y-auto">
            <p class="text-xs font-black text-slate-400 uppercase tracking-widest px-4 mb-2">Main Menu</p>
            <a href="dashboard.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all">
                <span class="material-symbols-outlined text-2xl">dashboard</span> <span>Dashboard</span>
            </a>
            <a href="appointments.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all">
                <span class="material-symbols-outlined text-2xl">calendar_today</span> <span>Appointments</span>
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
                <a href="tenantauditlogs.php" onclick="event.preventDefault(); return false;" aria-current="page" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] <?= $sidebarActive ?> font-bold shadow-sm transition-all hover:scale-[1.02]">
                    <span class="material-symbols-outlined text-2xl icon-filled">history</span> <span>Audit Logs</span>
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

    <main class="flex-1 overflow-y-auto p-4 md:p-8 bg-slate-50 relative">
        <div class="absolute top-4 right-8 flex items-center gap-2 px-3 py-1 bg-white border border-slate-200 shadow-sm rounded-full no-print z-20">
            <span class="size-2 rounded-full bg-emerald-500 animate-pulse"></span>
            <span class="text-[10px] font-bold text-slate-500 tracking-widest uppercase">Live Updates Active</span>
        </div>

        <div class="max-w-7xl mx-auto space-y-6 print-container">
            
            <div class="flex flex-col md:flex-row md:items-start justify-between gap-4 border-b border-slate-200 pb-6 mt-4">
                <div class="flex flex-col">
                    <h2 class="text-[26px] font-bold text-[#2b5797] tracking-tight mb-2">Audit Logs</h2>
                    <p class="text-[15px] text-slate-800" style="font-family: 'Georgia', serif;">Records for your clinic activities only.</p>
                </div>
                <div class="no-print mt-4 md:mt-0">
                    <button onclick="window.print()" class="bg-white hover:bg-slate-100 text-slate-700 font-bold py-2.5 px-5 rounded-xl shadow-sm transition-all flex items-center gap-2 text-sm border border-slate-300">
                        <span class="material-symbols-outlined text-lg">print</span> Print Logs
                    </button>
                </div>
            </div>

            <div class="p-4 bg-white rounded-2xl border border-slate-200 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-4 no-print mb-2">
                <div class="relative w-full max-w-md">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">search</span>
                    <input type="text" id="logSearch" onkeyup="debounceSearch()" placeholder="Search your clinic logs..." class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-primary focus:border-primary outline-none bg-slate-50">
                </div>
                <div class="relative w-full sm:w-48">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-lg">swap_vert</span>
                    <select id="logSort" onchange="applyLogFilters()" class="pl-10 pr-8 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-primary focus:border-primary shadow-sm w-full font-medium bg-white outline-none cursor-pointer">
                        <option value="newest">Newest First</option>
                        <option value="oldest">Oldest First</option>
                    </select>
                </div>
            </div>

            <div id="dataTablesContainer">
                <div class="mt-8">
                    <h3 class="text-lg font-black text-slate-800 flex items-center gap-2 mb-3">
                        <span class="material-symbols-outlined text-blue-500">local_hospital</span> Your Clinic Activity
                    </h3>
                    <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden flex flex-col">
                        <div class="overflow-y-auto max-h-[450px] scrollable-box w-full">
                            <table class="w-full text-left border-collapse relative logsTable" id="tenantLogsTable">
                                <thead class="sticky top-0 z-10 bg-white shadow-sm">
                                    <tr class="text-[#2b5797] text-[13px] font-bold border-b-2 border-slate-200">
                                        <th class="p-5 w-48">Timestamp</th>
                                        <th class="p-5">User / Actor</th>
                                        <th class="p-5 w-[300px]">Clinic / Tenant</th>
                                        <th class="p-5">Action Category</th>
                                        <th class="p-5">Event Details</th>
                                    </tr>
                                </thead>
                                <tbody class="text-sm font-medium text-slate-600 divide-y divide-slate-100">
                                    <?php if(empty($tenantLogs)): ?>
                                        <tr><td colspan="5" class="p-10 text-center text-slate-400 italic">No clinic tenant activity recorded yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($tenantLogs as $log): ?>
                                            <?php 
                                                // 🔥 THE ULTIMATE CLINIC NAME RESOLUTION LOGIC 🔥
                                                $logClinicName = "N/A";
                                                $detailsText = $log['details'];
                                                
                                                // Pagandahin ang Actor Name
                                                $actorName = ($log['user_name'] === 'System Action') ? 'System Auto-Trigger' : $log['user_name'];
                                                $actorRole = ($log['user_name'] === 'System Action') ? 'System' : $log['role'];

                                                // 1. PRIMARY: Extract directly from the connected database relation
                                                if (!empty($log['linked_clinic_name'])) {
                                                    $logClinicName = $log['linked_clinic_name'];
                                                } elseif (!empty($log['legacy_clinic_name'])) {
                                                    $logClinicName = $log['legacy_clinic_name'];
                                                } elseif (!empty($log['exact_clinic_name'])) {
                                                    $logClinicName = $log['exact_clinic_name'];
                                                }

                                                // Clean up legacy bracket formats if they exist in details
                                                if (preg_match('/^\[(.*?)\]/', $detailsText, $matches)) {
                                                    if ($logClinicName === "N/A") $logClinicName = trim($matches[1]);
                                                    $detailsText = trim(preg_replace('/^\[.*?\]\s*/', '', $detailsText)); 
                                                }

                                                // Fallback parsing from string
                                                if ($logClinicName === "N/A" || $logClinicName === "") {
                                                    if (preg_match('/"(.*?)" changed their/i', $detailsText, $matches)) {
                                                        $logClinicName = trim($matches[1]); 
                                                    } elseif (preg_match('/Clinic "(.*?)" changed/i', $detailsText, $matches)) {
                                                        $logClinicName = trim($matches[1]);
                                                    } elseif (preg_match('/clinic portal:\s*(.*?)(?=\.|$)/i', $detailsText, $matches)) {
                                                        $logClinicName = trim($matches[1]);
                                                    }
                                                }
                                            ?>
                                            <tr class="hover:bg-slate-50/80 transition-colors log-row" data-created-at="<?= strtotime($log['created_at']) ?>">
                                                <td class="p-5 text-xs font-bold text-slate-500 whitespace-nowrap">
                                                    <?= date('M d, Y', strtotime($log['created_at'])) ?><br>
                                                    <span class="text-[10px] text-slate-400"><?= date('h:i A', strtotime($log['created_at'])) ?></span>
                                                </td>
                                                <td class="p-5">
                                                    <p class="font-bold text-slate-800"><?= htmlspecialchars($actorName) ?></p>
                                                    <p class="text-[9px] text-slate-500 uppercase tracking-wider mt-0.5"><?= htmlspecialchars($actorRole) ?></p>
                                                </td>
                                                
                                                <td class="p-5">
                                                    <?php if($logClinicName !== "N/A" && $logClinicName !== ""): ?>
                                                        <div class="inline-flex items-start gap-1.5 text-slate-700 bg-slate-100 border border-slate-200 px-3 py-1.5 rounded-lg shadow-sm w-full">
                                                            <span class="material-symbols-outlined text-[16px] text-primary shrink-0 mt-0.5">domain</span>
                                                            <span class="whitespace-normal break-words leading-tight text-[11px] font-black"><?= htmlspecialchars($logClinicName) ?></span>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-xs text-slate-400 italic">N/A</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td class="p-5">
                                                    <span class="border px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-widest <?= getActionBadge($log['action_type']) ?> inline-block"><?= htmlspecialchars($log['action_type']) ?></span>
                                                </td>
                                                <td class="p-5 text-slate-600 max-w-xs break-words leading-relaxed"><?= htmlspecialchars($detailsText) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const currentPage = window.location.pathname.split('/').pop().toLowerCase();

        document.querySelectorAll('aside a[href]').forEach((link) => {
            const href = (link.getAttribute('href') || '').split('?')[0].toLowerCase();
            if (href && href === currentPage) {
                link.addEventListener('click', (event) => {
                    event.preventDefault();
                });
            }
        });
    });

    function openLogoutModal() { document.getElementById('logoutModal').classList.remove('hidden'); document.getElementById('logoutModal').classList.add('flex'); }
    function closeLogoutModal() { document.getElementById('logoutModal').classList.remove('flex'); document.getElementById('logoutModal').classList.add('hidden'); }
    
    function confirmLogout() { 
        closeLogoutModal(); 
        const loading = document.getElementById('loggingOutScreen'); 
        loading.classList.remove('hidden', 'flex'); 
        loading.classList.add('flex'); 
        
        setTimeout(() => { window.location.href = '?action=logout&c=<?= urlencode($clinicCode) ?>'; }, 1500); 
    }

    let searchTimeout;
    function debounceSearch() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(applyLogFilters, 200); 
    }

    function applyLogFilters() {
        const input = document.getElementById("logSearch").value.toLowerCase();
        const sortOrder = document.getElementById("logSort").value || "newest";
        const tables = document.querySelectorAll(".logsTable");
        
        tables.forEach(table => {
            const tbody = table.querySelector("tbody");
            if (!tbody) return;
            let rows = Array.from(tbody.querySelectorAll(".log-row"));
            if(rows.length === 0) return;

            const fragment = document.createDocumentFragment();

            rows.sort((a, b) => {
                const aCreated = Number(a.dataset.createdAt || 0);
                const bCreated = Number(b.dataset.createdAt || 0);
                return sortOrder === "oldest" ? aCreated - bCreated : bCreated - aCreated;
            });

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(input) ? "" : "none";
                fragment.appendChild(row);
            });

            tbody.innerHTML = '';
            tbody.appendChild(fragment);
        });
    }

    setInterval(() => {
        if (document.activeElement === document.getElementById('logSearch')) return;

        fetch('tenantauditlogs.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(response => response.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newTenantBody = doc.querySelector('#tenantLogsTable tbody');

                if (newTenantBody) {
                    document.querySelector('#tenantLogsTable tbody').innerHTML = newTenantBody.innerHTML;
                }
                
                applyLogFilters();
            })
            .catch(error => console.error('Auto-refresh error:', error));
    }, 5000); 
</script>
</body>
</html>