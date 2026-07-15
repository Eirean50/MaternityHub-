<?php
// 1. SETTINGS & SESSION
date_default_timezone_set('Asia/Manila');
ob_start();
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

session_start();
require_once 'db.php';

// --- LOGOUT HANDLER ---
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
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

// 🔥 AUTO-FIX: CREATE FEEDBACKS TABLE KUNG WALA PA 🔥
try {
    $pdo->query("SELECT 1 FROM feedbacks LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS feedbacks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            TenantID VARCHAR(50) NOT NULL,
            patient_id VARCHAR(50) NULL,
            appointment_id INT NULL,
            full_name VARCHAR(100) NULL,
            service_name VARCHAR(100) NOT NULL,
            rating INT NOT NULL DEFAULT 5,
            service_rating INT NULL,
            staff_rating INT NULL,
            doctor_rating INT NULL,
            comments TEXT NULL,
            is_anonymous TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (PDOException $ex) {}
}

// 🔥 AUTO-FIX: ADD patient_id IF IT DOESN'T EXIST IN OLD TABLE 🔥
try {
    $pdo->query("SELECT patient_id FROM feedbacks LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE feedbacks ADD patient_id VARCHAR(50) NULL AFTER TenantID");
        // Try to backfill missing patient IDs based on name matching
        $pdo->exec("UPDATE feedbacks f JOIN patients p ON f.full_name = p.full_name AND f.TenantID = p.TenantID SET f.patient_id = p.patient_id WHERE f.patient_id IS NULL");
    } catch (PDOException $ex) {}
}

// 🔥 AUTO-FIX: ADD doctor_rating IF IT DOESN'T EXIST IN OLD TABLE 🔥
try {
    $pdo->query("SELECT doctor_rating FROM feedbacks LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE feedbacks ADD doctor_rating INT NULL AFTER staff_rating");
    } catch (PDOException $ex) {}
}

// --- SYSTEM SETTINGS (JSON BASED) ---
$settingsFile = __DIR__ . '/maternityhub_settings.json';
if (!file_exists($settingsFile)) {
    file_put_contents($settingsFile, json_encode([
        'maintenance_mode' => false,
        'super_theme_color' => '#10b981',
        'super_logo' => null, 
        'system_email' => 'support@maternityhub.com',
        'session_timeout' => 30
    ]));
}

$settings = json_decode(file_get_contents($settingsFile), true);
$superThemeColor = $settings['super_theme_color'] ?? '#10b981';

// --- SECURITY CHECK ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'Patient') {
    header("Location: registration.php");
    exit();
}

$displayName = $_SESSION['full_name'] ?? 'Admin';
$userRole    = $_SESSION['role'] ?? 'Clinic Administrator';
$displayRole = $userRole;
$normalizedRole = strtolower(trim((string)$userRole));
$isStaffRole = ($normalizedRole === 'staff');
$userId      = $_SESSION['user_id'];
$tenant_id   = $_SESSION['TenantID'] ?? null; 
$currentPage = basename($_SERVER['PHP_SELF']); 

// --- OWNER / STAFF ADMIN PERMISSION SYSTEM ---
$currentUserIsOwner = in_array($normalizedRole, ['admin', 'administrator', 'owner', 'owner/midwife'], true);
$currentUserIsStaffAdmin = false;
$currentUserGrantedFeatures = [];

// =========================================================================
// 1. FETCH CLINIC DATA
// =========================================================================
$clinicName = "MaternityHub";
$clinicCode = "N/A"; 
$clinicLogo = null;
$themeColor = $superThemeColor; 

if ($tenant_id) {
    try {
        $stmtClinic = $pdo->prepare("SELECT clinic_name, clinic_code, clinic_logo, theme_color FROM tenants WHERE TenantID = ?");
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

// FETCH CURRENT PROFILE PICTURE & FULL NAME
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

// Finish Owner/StaffAdmin detection
if (!$currentUserIsOwner && $tenant_id) {
    try {
        $stmtCurAccess = $pdo->prepare("SELECT cs.is_admin, cs.granted_features FROM clinic_staff cs INNER JOIN users u ON LOWER(TRIM(cs.email_address)) = LOWER(TRIM(u.email)) WHERE cs.TenantID = ? AND u.id = ? LIMIT 1");
        $stmtCurAccess->execute([$tenant_id, $userId]);
        $curAccess = $stmtCurAccess->fetch(PDO::FETCH_ASSOC);
        if ($curAccess) {
            $currentUserIsStaffAdmin = (int)($curAccess['is_admin'] ?? 0) === 1;
            $currentUserGrantedFeatures = json_decode($curAccess['granted_features'] ?? '[]', true) ?: [];
        }
    } catch (PDOException $e) {}
}
$_ownerAlsoMidwife = false;
if ($currentUserIsOwner && $tenant_id) {
    try { $_stmtMw = $pdo->prepare("SELECT COALESCE(also_midwife, 0) FROM users WHERE id = ? AND TenantID = ? LIMIT 1"); $_stmtMw->execute([$userId, $tenant_id]); $_ownerAlsoMidwife = ((int)$_stmtMw->fetchColumn() === 1); } catch (PDOException $e) {}
}
if ($currentUserIsOwner) { $displayRole = $_ownerAlsoMidwife ? 'Owner / Midwife' : 'Owner'; }
elseif ($currentUserIsStaffAdmin) { $displayRole = $userRole . ' | Admin'; }

// =========================================================================
// 2. FETCH CLINIC SERVICES (PARA SA FILTER DROPDOWN)
// =========================================================================
$clinicServices = [];
try {
    $stmtSrv = $pdo->prepare("SELECT service_name FROM clinic_services WHERE TenantID = ? ORDER BY service_name ASC");
    $stmtSrv->execute([$tenant_id]);
    $clinicServices = $stmtSrv->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

// =========================================================================
// 3. FETCH FEEDBACKS WITH FILTERING (DYNAMIC NAME SYNC VIA JOIN)
// =========================================================================
$serviceFilter = $_GET['service'] ?? '';

$feedbacks = [];
$totalReviews = 0;
$averageRating = 0;

try {
    // 🔥 OPTIMIZED JOIN QUERY: staff via appointments, fallback via admissions table 🔥
    $query = "
        SELECT f.*, p.full_name as synced_name,
               COALESCE(
                   NULLIF(TRIM(a.assigned_staff), ''), 
                   NULLIF(TRIM(a.assigned_midwife), ''),
                   NULLIF(TRIM(a2.assigned_staff), ''), 
                   NULLIF(TRIM(a2.assigned_midwife), ''),
                   NULLIF(TRIM(adm.assigned_staff), '')
               ) as staff_name
        FROM feedbacks f 
        LEFT JOIN patients p ON f.patient_id = p.patient_id AND f.TenantID = p.TenantID
        LEFT JOIN appointments a ON f.appointment_id = a.id AND f.TenantID = a.TenantID
        LEFT JOIN (
            SELECT ap.TenantID, ap.patient_id, ap.service, ap.assigned_staff, ap.assigned_midwife,
                   ROW_NUMBER() OVER (PARTITION BY ap.TenantID, ap.patient_id, ap.service ORDER BY ap.appointment_date DESC) as rn
            FROM appointments ap
        ) a2 ON a2.TenantID = f.TenantID 
            AND a2.patient_id = f.patient_id 
            AND a2.service = f.service_name 
            AND a2.rn = 1 
            AND f.appointment_id IS NULL
        LEFT JOIN (
            SELECT ad.TenantID, ad.patient_id, ad.assigned_staff,
                   ROW_NUMBER() OVER (PARTITION BY ad.TenantID, ad.patient_id ORDER BY ad.admission_date DESC) as rn
            FROM admissions ad
            WHERE ad.assigned_staff IS NOT NULL AND TRIM(ad.assigned_staff) != ''
        ) adm ON adm.TenantID = f.TenantID 
            AND adm.patient_id = f.patient_id 
            AND adm.rn = 1
        WHERE f.TenantID = ?
    ";
    
    $params = [$tenant_id];

    if (!empty($serviceFilter)) {
        $query .= " AND f.service_name = ?";
        $params[] = $serviceFilter;
    }

    $query .= " ORDER BY f.created_at DESC";
    
    $stmtFb = $pdo->prepare($query);
    $stmtFb->execute($params);
    $feedbacks = $stmtFb->fetchAll(PDO::FETCH_ASSOC);

    $totalReviews = count($feedbacks);
    if ($totalReviews > 0) {
        $sum = 0;
        foreach ($feedbacks as $fb) {
            $sum += (int)$fb['rating'];
        }
        $averageRating = round($sum / $totalReviews, 1);
    }
} catch (PDOException $e) {}

// AJAX handler - return JSON without page reload
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'feedbacks' => $feedbacks,
        'totalReviews' => $totalReviews,
        'averageRating' => number_format($averageRating, 1)
    ]);
    exit();
}

// Dynamic contrast calculator for header & sidebar (match tenantsettings logic)
$hex = ltrim($themeColor, '#');
if (strlen($hex) == 3) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; }
$r = hexdec(substr($hex, 0, 2));
$g = hexdec(substr($hex, 2, 2));
$b = hexdec(substr($hex, 4, 2));
$luminance = ($r * 0.299) + ($g * 0.587) + ($b * 0.114);
$isLightTheme = ($luminance > 150);

$headerTextPrimary   = $isLightTheme ? "text-slate-900" : "text-white";
$headerTextSecondary = $isLightTheme ? "text-slate-700" : "text-primary-light";
$headerTextMuted     = $isLightTheme ? "text-slate-400" : "text-white/50";
$headerBadgeBg       = $isLightTheme ? "bg-slate-200 text-slate-800" : "bg-black/20 text-white";
$headerIconBox       = $isLightTheme ? "bg-white border-slate-200" : "bg-white/15 border-white/25";
$headerIconColor     = $isLightTheme ? "text-slate-700" : "text-white/90";
$headerBtn           = $isLightTheme ? "bg-white hover:bg-slate-50 text-slate-800 border-slate-200 shadow-sm" : "bg-white/15 hover:bg-white/25 text-white border-white/30";

$sidebarActive = $isLightTheme ? "bg-slate-800 text-white shadow-md" : "bg-primary/10 text-primary";

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Patient Feedback - <?= htmlspecialchars($clinicName) ?></title>
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
                        "background-light": "color-mix(in srgb, <?= htmlspecialchars($themeColor) ?> 3%, white)" 
                    }, 
                    fontFamily: { "display": ["Plus Jakarta Sans", "sans-serif"] }, 
                    boxShadow: { 'soft': '0 4px 20px -2px rgba(0,0,0,0.05)' } 
                } 
            } 
        }
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
        .star-rating { color: #f59e0b; }
        .material-symbols-outlined.icon-filled { font-variation-settings: 'FILL' 1, 'wght' 600, 'GRAD' 0, 'opsz' 24; }
        .star-rating .material-symbols-outlined.icon-filled { color: #f59e0b !important; }
        @view-transition { navigation: auto; }
        header { view-transition-name: header; }
        aside { view-transition-name: sidebar; }
        ::view-transition-old(sidebar), ::view-transition-new(sidebar),
        ::view-transition-old(header), ::view-transition-new(header) { animation: none; }
    </style>
</head>
<body class="bg-background-light text-slate-800 h-screen flex flex-col relative text-sm antialiased font-display">

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
</script>

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
        <button onclick="window.location.href='?action=logout&c=<?= urlencode($clinicCode) ?>'" class="flex items-center gap-2 <?= $headerBtn ?> border px-4 py-2 rounded-xl text-xs font-bold transition-all active:scale-95">
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
                <a href="feedback.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] <?= $sidebarActive ?> font-bold shadow-sm transition-all hover:scale-[1.02]">
                    <span class="material-symbols-outlined text-2xl icon-filled">feedback</span> <span>Feedback</span>
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
        <div class="max-w-5xl mx-auto space-y-6">
            
            <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                <div>
                    <h2 class="text-3xl font-black text-slate-900 tracking-tight">Patient Feedback</h2>
                    <p class="text-slate-500 mt-1">Read reviews securely submitted via the MaternityHub Mobile App.</p>
                </div>
                <div class="w-full sm:w-64">
                    <form method="GET" action="feedback.php" id="filterForm">
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Filter by Service</label>
                        <select name="service" onchange="loadFeedbacks(this.value);" id="serviceFilter" class="w-full border-slate-200 text-slate-700 font-bold text-sm rounded-xl focus:border-primary focus:ring-primary py-2.5 cursor-pointer bg-white shadow-sm">
                            <option value="">All Services</option>
                            <?php foreach ($clinicServices as $srv): ?>
                                <option value="<?= htmlspecialchars($srv) ?>" <?= ($serviceFilter === $srv) ? 'selected' : '' ?>><?= htmlspecialchars($srv) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-white p-6 rounded-3xl shadow-sm border border-slate-100 flex items-center gap-5">
                    <div class="size-14 rounded-2xl bg-amber-50 text-amber-500 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined icon-filled text-3xl">star</span>
                    </div>
                    <div>
                        <p class="text-xs font-black text-slate-400 uppercase tracking-widest">Average Rating</p>
                        <p class="text-3xl font-black text-slate-800 mt-1" id="avgRating"><?= number_format($averageRating, 1) ?> <span class="text-lg text-slate-400 font-medium">/ 5.0</span></p>
                    </div>
                </div>
                <div class="bg-white p-6 rounded-3xl shadow-sm border border-slate-100 flex items-center gap-5">
                    <div class="size-14 rounded-2xl bg-blue-50 text-blue-500 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined icon-filled text-3xl">reviews</span>
                    </div>
                    <div>
                        <p class="text-xs font-black text-slate-400 uppercase tracking-widest">Total Reviews</p>
                        <p class="text-3xl font-black text-slate-800 mt-1" id="totalReviews"><?= $totalReviews ?></p>
                    </div>
                </div>
                <div class="bg-primary/10 p-6 rounded-3xl border border-primary/20 flex items-center gap-5 relative overflow-hidden">
                    <span class="material-symbols-outlined absolute -right-4 -bottom-4 text-8xl text-primary/20 rotate-12">health_and_safety</span>
                    <div class="relative z-10">
                        <p class="text-xs font-black text-primary-dark uppercase tracking-widest">Feedback Protection</p>
                        <p class="text-sm font-bold text-primary mt-2 leading-tight">Patient data is hidden if they chose to submit anonymously via the mobile app.</p>
                    </div>
                </div>
            </div>

            <div class="space-y-4" id="feedbackContainer">
                <?php if (empty($feedbacks)): ?>
                    <div class="py-20 text-center bg-white rounded-[2rem] border border-dashed border-slate-300">
                        <span class="material-symbols-outlined text-slate-300 text-6xl mb-4">speaker_notes_off</span>
                        <h3 class="text-lg font-black text-slate-800">No feedback found</h3>
                        <p class="text-sm text-slate-500 mt-1">Check back later or try selecting a different service.</p>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <?php foreach ($feedbacks as $fb): ?>
                            <?php 
                                $isAnon = ($fb['is_anonymous'] == 1);
                                
                                // 🔥 STRICT NAME SYNCING: Titingnan muna yung updated name mula sa patients table, 
                                // kung wala dun yung name sa lumang feedback records
                                $realName = !empty($fb['synced_name']) ? $fb['synced_name'] : (!empty($fb['full_name']) ? $fb['full_name'] : 'Unknown Patient');
                                
                                $displayPatientName = $isAnon ? 'Anonymous Patient' : htmlspecialchars($realName);
                                $avatarInitial = $isAnon ? '?' : strtoupper(substr($displayPatientName, 0, 1));
                                $avatarColor = $isAnon ? 'bg-slate-100 text-slate-400 border-slate-200' : 'bg-primary/10 text-primary border-primary/20';
                            ?>
                            <div class="bg-white p-6 rounded-3xl shadow-[0_2px_15px_-3px_rgba(0,0,0,0.07)] border border-slate-100 hover:shadow-lg transition-all flex flex-col h-full">
                                <div class="flex justify-between items-start mb-4">
                                    <div class="flex items-center gap-3">
                                        <div class="size-10 rounded-full <?= $avatarColor ?> border flex items-center justify-center font-black text-sm shrink-0 overflow-hidden">
                                            <?= $isAnon ? '<span class="material-symbols-outlined text-[18px]">person_off</span>' : $avatarInitial ?>
                                        </div>
                                        <div>
                                            <p class="font-black text-slate-800 leading-tight"><?= $displayPatientName ?></p>
                                            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-0.5"><?= date('M d, Y', strtotime($fb['created_at'])) ?></p>
                                            <?php if (!empty($fb['staff_name'])): ?>
                                                <p class="text-[10px] text-violet-600 font-semibold mt-0.5 flex items-center gap-0.5" title="Assigned Staff: <?= htmlspecialchars($fb['staff_name']) ?>">
                                                    <span class="material-symbols-outlined text-[12px]">person</span>
                                                    Assigned to: <?= htmlspecialchars($fb['staff_name']) ?>
                                                </p>
                                            <?php else: ?>
                                                <p class="text-[10px] text-slate-400 italic mt-0.5">No assigned staff</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-1 star-rating">
                                        <?php 
                                            $rating = (int)$fb['rating'];
                                            for($i=1; $i<=5; $i++) {
                                                echo $i <= $rating ? '<span class="material-symbols-outlined icon-filled text-lg">star</span>' : '<span class="material-symbols-outlined text-slate-200 text-lg">star</span>';
                                            }
                                        ?>
                                    </div>
                                </div>
                                
                                <div class="mb-4 inline-block">
                                    <span class="bg-blue-50 text-blue-600 border border-blue-100 px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-widest">
                                        <?= htmlspecialchars($fb['service_name']) ?>
                                    </span>
                                </div>

                                <!-- Service Rating, Staff Rating & Midwife Rating -->
                                <div class="grid grid-cols-3 gap-3 mb-4">
                                    <div class="bg-emerald-50 border border-emerald-100 rounded-xl px-3 py-2">
                                        <p class="text-[9px] font-black text-emerald-500 uppercase tracking-widest mb-1">Service Rating</p>
                                        <div class="flex items-center gap-0.5">
                                            <?php 
                                                $svcRating = (int)($fb['service_rating'] ?? 0);
                                                if ($svcRating > 0) {
                                                    for($i=1; $i<=5; $i++) {
                                                        echo $i <= $svcRating ? '<span class="material-symbols-outlined icon-filled text-amber-400 text-sm">star</span>' : '<span class="material-symbols-outlined text-slate-200 text-sm">star</span>';
                                                    }
                                                } else {
                                                    echo '<span class="text-[10px] text-slate-400 italic">N/A</span>';
                                                }
                                            ?>
                                        </div>
                                    </div>
                                    <div class="bg-violet-50 border border-violet-100 rounded-xl px-3 py-2">
                                        <p class="text-[9px] font-black text-violet-500 uppercase tracking-widest mb-1">Staff Rating</p>
                                        <div class="flex items-center gap-0.5">
                                            <?php 
                                                $staffRating = (int)($fb['staff_rating'] ?? 0);
                                                if ($staffRating > 0) {
                                                    for($i=1; $i<=5; $i++) {
                                                        echo $i <= $staffRating ? '<span class="material-symbols-outlined icon-filled text-amber-400 text-sm">star</span>' : '<span class="material-symbols-outlined text-slate-200 text-sm">star</span>';
                                                    }
                                                } else {
                                                    echo '<span class="text-[10px] text-slate-400 italic">N/A</span>';
                                                }
                                            ?>
                                        </div>
                                        <?php if (!empty($fb['staff_name'])): ?>
                                            <p class="text-[10px] text-violet-600 font-semibold mt-1 truncate" title="<?= htmlspecialchars($fb['staff_name']) ?>">
                                                <span class="material-symbols-outlined text-[12px] align-middle">person</span>
                                                <?= htmlspecialchars($fb['staff_name']) ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="bg-sky-50 border border-sky-100 rounded-xl px-3 py-2">
                                        <p class="text-[9px] font-black text-sky-500 uppercase tracking-widest mb-1">Midwife Rating</p>
                                        <div class="flex items-center gap-0.5">
                                            <?php 
                                                $docRating = (int)($fb['doctor_rating'] ?? 0);
                                                if ($docRating > 0) {
                                                    for($i=1; $i<=5; $i++) {
                                                        echo $i <= $docRating ? '<span class="material-symbols-outlined icon-filled text-amber-400 text-sm">star</span>' : '<span class="material-symbols-outlined text-slate-200 text-sm">star</span>';
                                                    }
                                                } else {
                                                    echo '<span class="text-[10px] text-slate-400 italic">N/A</span>';
                                                }
                                            ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex-1">
                                    <p class="text-slate-600 text-sm leading-relaxed italic">
                                        "<?= nl2br(htmlspecialchars($fb['comments'] ?: 'No additional comments provided.')) ?>"
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </main>
</div>

<script>
function escapeHtml(text) {
    const d = document.createElement('div');
    d.textContent = text;
    return d.innerHTML;
}

function renderStars(count, size) {
    let html = '';
    const cls = size === 'lg' ? 'text-lg' : 'text-sm';
    const color = size === 'lg' ? '' : 'text-amber-400 ';
    for (let i = 1; i <= 5; i++) {
        html += i <= count
            ? '<span class="material-symbols-outlined icon-filled ' + color + cls + '">star</span>'
            : '<span class="material-symbols-outlined text-slate-200 ' + cls + '">star</span>';
    }
    return html;
}

function renderFeedbackCard(fb) {
    const isAnon = fb.is_anonymous == 1;
    const realName = fb.synced_name || fb.full_name || 'Unknown Patient';
    const displayName = isAnon ? 'Anonymous Patient' : escapeHtml(realName);
    const avatarColor = isAnon ? 'bg-slate-100 text-slate-400 border-slate-200' : 'bg-primary/10 text-primary border-primary/20';
    const avatarContent = isAnon
        ? '<span class="material-symbols-outlined text-[18px]">person_off</span>'
        : escapeHtml((displayName.charAt(0) || '?').toUpperCase());

    const rating = parseInt(fb.rating) || 0;
    const svcRating = parseInt(fb.service_rating) || 0;
    const staffRating = parseInt(fb.staff_rating) || 0;
    const docRating = parseInt(fb.doctor_rating) || 0;

    const date = new Date(fb.created_at);
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const day = String(date.getDate()).padStart(2, '0');
    const dateStr = months[date.getMonth()] + ' ' + day + ', ' + date.getFullYear();

    const staffAssigned = fb.staff_name
        ? '<p class="text-[10px] text-violet-600 font-semibold mt-0.5 flex items-center gap-0.5" title="Assigned Staff: ' + escapeHtml(fb.staff_name) + '"><span class="material-symbols-outlined text-[12px]">person</span>Assigned to: ' + escapeHtml(fb.staff_name) + '</p>'
        : '<p class="text-[10px] text-slate-400 italic mt-0.5">No assigned staff</p>';

    const staffRatingName = fb.staff_name
        ? '<p class="text-[10px] text-violet-600 font-semibold mt-1 truncate" title="' + escapeHtml(fb.staff_name) + '"><span class="material-symbols-outlined text-[12px] align-middle">person</span>' + escapeHtml(fb.staff_name) + '</p>'
        : '';

    const comments = fb.comments || 'No additional comments provided.';

    return '<div class="bg-white p-6 rounded-3xl shadow-[0_2px_15px_-3px_rgba(0,0,0,0.07)] border border-slate-100 hover:shadow-lg transition-all flex flex-col h-full">'
        + '<div class="flex justify-between items-start mb-4">'
        + '<div class="flex items-center gap-3">'
        + '<div class="size-10 rounded-full ' + avatarColor + ' border flex items-center justify-center font-black text-sm shrink-0 overflow-hidden">' + avatarContent + '</div>'
        + '<div>'
        + '<p class="font-black text-slate-800 leading-tight">' + displayName + '</p>'
        + '<p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-0.5">' + dateStr + '</p>'
        + staffAssigned
        + '</div></div>'
        + '<div class="flex items-center gap-1 star-rating">' + renderStars(rating, 'lg') + '</div>'
        + '</div>'
        + '<div class="mb-4 inline-block"><span class="bg-blue-50 text-blue-600 border border-blue-100 px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-widest">' + escapeHtml(fb.service_name) + '</span></div>'
        + '<div class="grid grid-cols-3 gap-3 mb-4">'
        + '<div class="bg-emerald-50 border border-emerald-100 rounded-xl px-3 py-2">'
        + '<p class="text-[9px] font-black text-emerald-500 uppercase tracking-widest mb-1">Service Rating</p>'
        + '<div class="flex items-center gap-0.5">' + (svcRating > 0 ? renderStars(svcRating, 'sm') : '<span class="text-[10px] text-slate-400 italic">N/A</span>') + '</div>'
        + '</div>'
        + '<div class="bg-violet-50 border border-violet-100 rounded-xl px-3 py-2">'
        + '<p class="text-[9px] font-black text-violet-500 uppercase tracking-widest mb-1">Staff Rating</p>'
        + '<div class="flex items-center gap-0.5">' + (staffRating > 0 ? renderStars(staffRating, 'sm') : '<span class="text-[10px] text-slate-400 italic">N/A</span>') + '</div>'
        + staffRatingName
        + '</div>'
        + '<div class="bg-sky-50 border border-sky-100 rounded-xl px-3 py-2">'
        + '<p class="text-[9px] font-black text-sky-500 uppercase tracking-widest mb-1">Midwife Rating</p>'
        + '<div class="flex items-center gap-0.5">' + (docRating > 0 ? renderStars(docRating, 'sm') : '<span class="text-[10px] text-slate-400 italic">N/A</span>') + '</div>'
        + '</div></div>'
        + '<div class="flex-1"><p class="text-slate-600 text-sm leading-relaxed italic">"' + escapeHtml(comments).replace(/\n/g, '<br>') + '"</p></div>'
        + '</div>';
}

function renderFeedbacks(data) {
    const container = document.getElementById('feedbackContainer');
    const avgEl = document.getElementById('avgRating');
    const totalEl = document.getElementById('totalReviews');

    avgEl.innerHTML = data.averageRating + ' <span class="text-lg text-slate-400 font-medium">/ 5.0</span>';
    totalEl.textContent = data.totalReviews;

    if (data.feedbacks.length === 0) {
        container.innerHTML = '<div class="py-20 text-center bg-white rounded-[2rem] border border-dashed border-slate-300">'
            + '<span class="material-symbols-outlined text-slate-300 text-6xl mb-4">speaker_notes_off</span>'
            + '<h3 class="text-lg font-black text-slate-800">No feedback found</h3>'
            + '<p class="text-sm text-slate-500 mt-1">Check back later or try selecting a different service.</p>'
            + '</div>';
    } else {
        let html = '<div class="grid grid-cols-1 md:grid-cols-2 gap-6">';
        data.feedbacks.forEach(function(fb) { html += renderFeedbackCard(fb); });
        html += '</div>';
        container.innerHTML = html;
    }
}

function loadFeedbacks(service) {
    const url = 'feedback.php?ajax=1' + (service ? '&service=' + encodeURIComponent(service) : '');
    fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(data) { renderFeedbacks(data); })
        .catch(function(err) { console.error('Failed to load feedbacks:', err); });
}

// Auto-refresh every 30 seconds para real-time ang updates
setInterval(function() {
    const filter = document.getElementById('serviceFilter');
    loadFeedbacks(filter ? filter.value : '');
}, 30000);
</script>

</body>
</html>