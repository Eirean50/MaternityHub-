<?php
date_default_timezone_set('Asia/Manila');
ob_start();
session_start();

// --- LOGOUT HANDLER ---
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    if (!isset($pdo)) {
        require_once 'db.php';
        if (!isset($pdo) && isset($conn)) { $pdo = $conn; }
    }
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

$displayName = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'User');
$displayRole = $_SESSION['role'] ?? 'Clinic Staff';

$tenant_id = $_SESSION['TenantID'] ?? null; 

// Basic clinic + UI variables to match other tenant-themed pages
$clinicName = 'MaternityHub Clinic';
$clinicCode = 'N/A';
$clinicLogo = null;
$themeColor = "#15803d"; // Fallback primary theme (overridden by tenant)

// Fetch Clinic Info based on Tenant
if ($tenant_id) {
    try {
        $stmtClinic = $pdo->prepare("SELECT clinic_name, clinic_code, clinic_logo, theme_color FROM tenants WHERE TenantID = ?");
        $stmtClinic->execute([$tenant_id]);
        $clinicData = $stmtClinic->fetch(PDO::FETCH_ASSOC);

        if ($clinicData) {
            $clinicName = $clinicData['clinic_name'];
            $clinicCode = $clinicData['clinic_code'] ?? 'N/A';

            if (!empty($clinicData['clinic_logo']) && file_exists(__DIR__ . '/uploads/logos/' . $clinicData['clinic_logo'])) {
                $clinicLogo = 'uploads/logos/' . $clinicData['clinic_logo'];
            }

            if (!empty($clinicData['theme_color'])) {
                $themeColor = $clinicData['theme_color'];
            }
        }
    } catch (PDOException $e) {}
}

// =========================================================
// 🔥 DYNAMIC CONTRAST CALCULATOR PARA SA HEADER & SIDEBAR 🔥
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

$normalizedRole = strtolower(trim((string)($_SESSION['role'] ?? 'Staff')));
$isStaffRole = ($normalizedRole === 'staff' || $normalizedRole === 'clinic staff');

// --- OWNER / STAFF ADMIN PERMISSION SYSTEM ---
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

// FETCH CURRENT PROFILE PICTURE & FULL NAME
$userId = $_SESSION['user_id'];
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

// --- DATE FILTER LOGIC ---
$selectedMonth = $_GET['month'] ?? date('m');
$selectedYear = $_GET['year'] ?? date('Y');
$showAll = (isset($_GET['view']) && $_GET['view'] === 'all');

$periodLabel = $showAll ? 'All Time' : date('F', mktime(0, 0, 0, $selectedMonth, 1)) . ' ' . $selectedYear;

try {
    // 1. TOTAL PATIENTS (OVERALL)
    $stmtPatients = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE TenantID = ?");
    $stmtPatients->execute([$tenant_id]);
    $totalPatients = $stmtPatients->fetchColumn();

    // 2. TOTAL STAFF
    $stmtStaff = $pdo->prepare("SELECT COUNT(*) FROM users WHERE TenantID = ?");
    $stmtStaff->execute([$tenant_id]);
    $totalStaff = $stmtStaff->fetchColumn();

    // 3. DATA PARA SA AGE DEMOGRAPHICS
    $ageGroupsQuery = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN age < 18 THEN 1 ELSE 0 END) as minors,
            SUM(CASE WHEN age BETWEEN 18 AND 25 THEN 1 ELSE 0 END) as young_adults,
            SUM(CASE WHEN age BETWEEN 26 AND 35 THEN 1 ELSE 0 END) as adults,
            SUM(CASE WHEN age > 35 THEN 1 ELSE 0 END) as seniors
        FROM patients WHERE TenantID = ?
    ");
    $ageGroupsQuery->execute([$tenant_id]);
    $ageData = $ageGroupsQuery->fetch(PDO::FETCH_ASSOC);

    $minors = $ageData['minors'] ?? 0;
    $youngAdults = $ageData['young_adults'] ?? 0;
    $adults = $ageData['adults'] ?? 0;
    $seniors = $ageData['seniors'] ?? 0;

    // 4. FETCH TREND DATA (LAST 6 MONTHS FOR CHARTS)
    $trendLabels = [];
    $patientGrowthData = [];
    $salesTrendData = [];

    for ($i = 5; $i >= 0; $i--) {
        $m = (int)date('m', strtotime("-$i months"));
        $y = (int)date('Y', strtotime("-$i months"));
        $monthName = date('M', strtotime("-$i months"));
        $trendLabels[] = $monthName;

        // Count New Patients (based on registration date)
        $stmtPg = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE TenantID = ? AND MONTH(created_at) = ? AND YEAR(created_at) = ?");
        $stmtPg->execute([$tenant_id, $m, $y]);
        $patientGrowthData[] = (int)$stmtPg->fetchColumn();

        // Count Total Sales
        $stmtRev = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE TenantID = ? AND status IN ('Paid', 'Completed') AND MONTH(payment_date) = ? AND YEAR(payment_date) = ?");
        $stmtRev->execute([$tenant_id, $m, $y]);
        $salesTrendData[] = (float)$stmtRev->fetchColumn();
    }

    // 5. FETCH PATIENTS (FILTERED OR ALL, BASED ON REGISTRATION DATE)
    if ($showAll) {
        $stmtMonthList = $pdo->prepare("SELECT full_name, patient_id, age, created_at, contact_number, address FROM patients WHERE TenantID = ? ORDER BY created_at DESC");
        $stmtMonthList->execute([$tenant_id]);
    } else {
        $stmtMonthList = $pdo->prepare("SELECT full_name, patient_id, age, created_at, contact_number, address FROM patients WHERE TenantID = ? AND MONTH(created_at) = ? AND YEAR(created_at) = ? ORDER BY created_at ASC");
        $stmtMonthList->execute([$tenant_id, $selectedMonth, $selectedYear]);
    }
    $monthlyPatients = $stmtMonthList->fetchAll(PDO::FETCH_ASSOC);
    $monthlyCount = count($monthlyPatients);

    // 6. FETCH INFANTS DATA (For Report)
    $monthlyInfants = [];
    $infantMale = 0;
    $infantFemale = 0;
    $infantUnknown = 0;
    try {
        if ($showAll) {
            $stmtInfants = $pdo->prepare("SELECT infant_name, gender, birth_date, mother_name FROM infants WHERE TenantID = ? ORDER BY created_at DESC");
            $stmtInfants->execute([$tenant_id]);
        } else {
            // Use registration timestamp (created_at) so counts reflect when infants were actually recorded in the system
            $stmtInfants = $pdo->prepare("SELECT infant_name, gender, birth_date, mother_name FROM infants WHERE TenantID = ? AND MONTH(created_at) = ? AND YEAR(created_at) = ? ORDER BY created_at ASC");
            $stmtInfants->execute([$tenant_id, $selectedMonth, $selectedYear]);
        }
        $monthlyInfants = $stmtInfants->fetchAll(PDO::FETCH_ASSOC);
        foreach($monthlyInfants as $inf) {
            $g = strtolower(trim((string)$inf['gender']));
            if ($g === 'male') {
                $infantMale++;
            } elseif ($g === 'female') {
                $infantFemale++;
            } else {
                $infantUnknown++;
            }
        }
    } catch (PDOException $e) {} 

    $totalInfants = $infantMale + $infantFemale + $infantUnknown;

    // 7. FETCH FINANCIAL DATA (For Report)
    $monthlyTxns = [];
    $totalIncome = 0;
    try {
        if ($showAll) {
            $stmtTxn = $pdo->prepare("SELECT id, receipt, full_name, payment_date, amount FROM payments WHERE TenantID = ? AND status IN ('Paid', 'Completed') ORDER BY payment_date DESC");
            $stmtTxn->execute([$tenant_id]);
        } else {
            $stmtTxn = $pdo->prepare("SELECT id, receipt, full_name, payment_date, amount FROM payments WHERE TenantID = ? AND MONTH(payment_date) = ? AND YEAR(payment_date) = ? AND status IN ('Paid', 'Completed') ORDER BY payment_date ASC");
            $stmtTxn->execute([$tenant_id, $selectedMonth, $selectedYear]);
        }
        $monthlyTxns = $stmtTxn->fetchAll(PDO::FETCH_ASSOC);
        foreach($monthlyTxns as $txn) {
            $totalIncome += (float)$txn['amount'];
        }
    } catch (PDOException $e) {}

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
// Sidebar active style based on theme brightness (match tenantsettings)
$sidebarActive = $isLightTheme ? "bg-slate-800 text-white shadow-md" : "bg-primary/10 text-primary";

?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Reports & Analytics - <?= htmlspecialchars($clinicName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>

    <script>
        tailwind.config = { 
            theme: { 
                extend: { 
                    colors: { "primary": "<?= htmlspecialchars($themeColor) ?>", "background-light": "#f6f7f8" }, 
                    fontFamily: { "display": ["Plus Jakarta Sans", "sans-serif"] } 
                } 
            } 
        }
    </script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; overflow: hidden; }
        .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
                    <?php
                        $receiptLabel = $txn['receipt'] ?? '';
                        if (!empty($receiptLabel) && strpos($receiptLabel, '|') !== false) {
                            $parts = explode('|', $receiptLabel, 2);
                            $receiptLabel = $parts[0];
                        }
                    ?>
        .icon-filled { font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
                        <td style="font-family: monospace;"><?= htmlspecialchars($receiptLabel ?: ('INV-'.str_pad($txn['id'], 5, '0', STR_PAD_LEFT))) ?></td>
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        
        /* Container used for autoTable reading (Hidden from UI) */
        .hidden-pdf-tables { display: none; }
        @view-transition { navigation: auto; }
        header { view-transition-name: header; }
        aside { view-transition-name: sidebar; }
        ::view-transition-old(sidebar), ::view-transition-new(sidebar),
        ::view-transition-old(header), ::view-transition-new(header) { animation: none; }
    </style>
</head>
<body class="bg-background-light text-slate-800 h-screen overflow-hidden flex flex-col relative text-sm antialiased font-display">

<header class="h-20 bg-primary <?= $isLightTheme ? 'border-b border-slate-200' : 'border-b border-primary-dark' ?> flex items-center justify-between px-6 md:px-12 sticky top-0 z-50 shrink-0 shadow-sm relative transition-colors duration-300">
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
                <p class="<?= $headerBadgeBg ?> px-2 py-0.5 rounded text-[10px] font-black tracking-widest flex items-center gap-1">
                    CODE: <?= htmlspecialchars($clinicCode) ?>
                </p>
            </div>
        </div>
    </div>
    
    <div class="flex items-center gap-4 ml-auto">
        <div class="hidden sm:flex flex-col text-right justify-center <?= $headerTextPrimary ?>">
            <p class="text-sm font-bold leading-none"><?= htmlspecialchars($displayName) ?></p>
            <p class="<?= $headerTextSecondary ?> text-[9px] italic opacity-80 mt-1 uppercase tracking-tighter"><?= htmlspecialchars($displayRole) ?></p>
        </div>
        <button onclick="if(confirm('Logout?')) window.location.href='report.php?action=logout&c=<?= urlencode($clinicCode) ?>'" class="flex items-center gap-2 <?= $headerBtn ?> border px-4 py-2 rounded-xl text-xs font-bold transition-all active:scale-95">
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
                <a href="report.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] <?= $sidebarActive ?> font-bold shadow-sm transition-all hover:scale-[1.02]">
                    <span class="material-symbols-outlined text-2xl icon-filled">bar_chart</span> <span>Reports</span>
                </a>
                <?php endif; ?>
                <?php if ($currentUserIsOwner || $currentUserIsStaffAdmin || in_array('help_support', $currentUserGrantedFeatures)): ?>
                <a href="support.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">support_agent</span> <span>Help &amp; Support</span>
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

    <main class="flex-1 overflow-y-auto p-6 md:p-10">
        
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-8">
            <div>
                <h2 class="text-3xl font-black text-slate-800 tracking-tighter uppercase leading-tight">Data Analytics & Reports</h2>
                <p class="text-slate-500 text-sm font-medium tracking-tight">View clinic performance, patient trends, and extract records.</p>
            </div>
            
            <div class="flex flex-wrap items-center gap-3">
                <form method="GET" class="flex items-center gap-2">
                    <select name="month" class="text-xs rounded-xl border-slate-200 py-2.5 focus:ring-primary shadow-sm outline-none">
                        <?php for($m=1; $m<=12; $m++): ?>
                            <option value="<?php echo sprintf('%02d', $m); ?>" <?php echo ($selectedMonth == $m) ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <select name="year" class="text-xs rounded-xl border-slate-200 py-2.5 focus:ring-primary shadow-sm outline-none">
                        <?php for($y=date('Y'); $y>=2020; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo ($selectedYear == $y) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="bg-slate-800 text-white p-2.5 rounded-xl hover:bg-slate-900 transition-all flex items-center shadow-md active:scale-95">
                        <span class="material-symbols-outlined text-sm">filter_alt</span>
                    </button>
                </form>

                <a href="report.php?view=all" class="text-[10px] bg-white px-4 py-2.5 rounded-xl text-slate-500 font-black uppercase hover:bg-slate-100 transition-all border border-slate-200 shadow-sm">
                    All Time
                </a>
                
                <button id="exportPdfBtn" onclick="generatePDF()" class="bg-primary text-white py-2.5 px-5 rounded-xl hover:bg-primary-dark transition-all flex items-center gap-2 shadow-md active:scale-95 text-xs font-bold uppercase tracking-wide">
                    <span class="material-symbols-outlined text-[18px]">picture_as_pdf</span> Generate PDF
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 mb-8 text-sm">
            <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm">
                <div class="size-12 rounded-xl bg-blue-50 flex items-center justify-center text-blue-600 mb-4 shadow-inner">
                    <span class="material-symbols-outlined icon-filled">groups</span>
                </div>
                <p class="text-slate-500 text-[10px] font-black uppercase tracking-widest">Total Registered Patients</p>
                <h3 class="text-4xl font-black text-slate-800 tracking-tighter mt-1"><?php echo number_format($totalPatients); ?></h3>
            </div>

            <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm">
                <div class="size-12 rounded-xl bg-amber-50 flex items-center justify-center text-amber-600 mb-4 shadow-inner">
                    <span class="material-symbols-outlined icon-filled">calendar_today</span>
                </div>
                <p class="text-slate-500 text-[10px] font-black uppercase tracking-widest"><?php echo $showAll ? 'Patients Displayed (All Time)' : 'New Patients ('.$periodLabel.')'; ?></p>
                <h3 class="text-4xl font-black text-slate-800 tracking-tighter mt-1"><?php echo number_format($monthlyCount); ?></h3>
            </div>

            <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm">
                <div class="size-12 rounded-xl bg-emerald-50 flex items-center justify-center text-emerald-600 mb-4 shadow-inner">
                    <span class="material-symbols-outlined icon-filled">payments</span>
                </div>
                <p class="text-slate-500 text-[10px] font-black uppercase tracking-widest">Total Sales (<?= htmlspecialchars($periodLabel) ?>)</p>
                <h3 class="text-4xl font-black text-emerald-600 tracking-tighter mt-1">₱<?php echo number_format($totalIncome, 2); ?></h3>
            </div>

            <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm">
                <div class="size-12 rounded-xl bg-rose-50 flex items-center justify-center text-rose-600 mb-4 shadow-inner">
                    <span class="material-symbols-outlined icon-filled">child_care</span>
                </div>
                <p class="text-slate-500 text-[10px] font-black uppercase tracking-widest"><?php echo $showAll ? 'Recorded Infant Births (All Time)' : 'Infant Births ('.$periodLabel.')'; ?></p>
                <h3 class="text-4xl font-black text-slate-800 tracking-tighter mt-1"><?php echo number_format($totalInfants); ?></h3>
                <p class="text-[10px] text-slate-400 font-bold mt-2">
                    Male: <span class="text-sky-600"><?php echo $infantMale; ?></span>
                    &bull;
                    Female: <span class="text-rose-500"><?php echo $infantFemale; ?></span>
                    <?php if ($infantUnknown > 0): ?>
                        &bull;
                        Unknown: <span class="text-slate-500"><?php echo $infantUnknown; ?></span>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm flex flex-col">
                <h3 class="font-black text-slate-800 tracking-tight uppercase text-xs mb-4">Age Demographics</h3>
                <div class="flex-1 flex items-center justify-center min-h-[200px]">
                    <canvas id="demographicsChart"></canvas>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-2">
                    <?php 
                    $labels = [['< 18', $minors, '#f43f5e'], ['18-25', $youngAdults, '#3b82f6'], ['26-35', $adults, '#10b981'], ['> 35', $seniors, '#a855f7']];
                    foreach($labels as $label): 
                        $pct = ($totalPatients > 0) ? round(($label[1]/$totalPatients)*100) : 0;
                    ?>
                    <div class="flex items-center gap-2 bg-slate-50 p-2 rounded-lg border border-slate-100">
                        <div class="size-3 rounded-full" style="background-color: <?php echo $label[2]; ?>"></div>
                        <div class="flex flex-col">
                            <span class="text-[10px] font-black text-slate-700"><?php echo $label[0]; ?> yrs</span>
                            <span class="text-[9px] text-slate-500 font-bold"><?php echo $pct; ?>% (<?php echo $label[1]; ?>)</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm">
                <h3 class="font-black text-slate-800 tracking-tight uppercase text-xs mb-1">Patient Growth Trend</h3>
                <p class="text-[10px] text-slate-400 font-medium mb-4">Number of new patients in the last 6 months</p>
                <div class="relative h-[220px] w-full"><canvas id="growthChart"></canvas></div>
            </div>

            <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm">
                <h3 class="font-black text-slate-800 tracking-tight uppercase text-xs mb-1">Clinic Revenue Trend</h3>
                <p class="text-[10px] text-slate-400 font-medium mb-4">Total income generated in the last 6 months</p>
                <div class="relative h-[220px] w-full"><canvas id="salesChart"></canvas></div>
            </div>
        </div>

        <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm flex flex-col overflow-hidden mb-10">
            <div class="p-6 border-b border-slate-100 bg-slate-50 flex justify-between items-center">
                <h3 class="font-black text-slate-800 tracking-tight uppercase text-sm">
                    <span class="material-symbols-outlined align-middle mr-1 icon-filled text-primary">folder_shared</span>
                    <?php echo $showAll ? 'Complete Patient Registry' : 'Patient Registry (' . htmlspecialchars($periodLabel) . ')'; ?>
                </h3>
            </div>
            
            <div class="flex-1 overflow-x-auto max-h-[400px] scrollable-box">
                <table class="w-full text-left">
                    <thead class="bg-white sticky top-0 shadow-sm text-[10px] uppercase font-black text-slate-400 tracking-widest z-10">
                        <tr>
                            <th class="px-6 py-4">Full Name</th>
                            <th class="px-6 py-4">Patient ID</th>
                            <th class="px-6 py-4">Registration Date</th>
                            <th class="px-6 py-4">Contact</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if($monthlyCount > 0): ?>
                            <?php foreach($monthlyPatients as $p): ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-6 py-4 font-bold text-slate-800"><?php echo htmlspecialchars($p['full_name']); ?></td>
                                <td class="px-6 py-4 text-[11px] font-medium text-slate-500 font-mono border-l border-slate-100"><?php echo $p['patient_id']; ?></td>
                                <td class="px-6 py-4 text-xs font-bold text-primary border-l border-slate-100">
                                    <?php echo !empty($p['created_at']) ? date('M d, Y', strtotime($p['created_at'])) : 'N/A'; ?>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-600 border-l border-slate-100"><?php echo htmlspecialchars($p['contact_number']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="px-6 py-12 text-center text-slate-400 italic font-medium">No records found for the selected period.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="p-4 bg-slate-50 border-t border-slate-100 flex justify-end">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">
                    Records displayed: <span class="text-primary text-sm ml-1"><?php echo $monthlyCount; ?></span>
                </p>
            </div>
        </div>

    </main>
</div>

<div class="hidden-pdf-tables">
    <table id="pdfPatientTable">
        <thead>
            <tr>
                <th>Patient ID</th>
                <th>Full Name</th>
                <th>Age</th>
                <th>Contact</th>
                <th>Reg. Date</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($monthlyPatients as $p): ?>
            <tr>
                <td><?= htmlspecialchars($p['patient_id']) ?></td>
                <td><?= htmlspecialchars($p['full_name']) ?></td>
                <td><?= htmlspecialchars($p['age']) ?></td>
                <td><?= htmlspecialchars($p['contact_number']) ?></td>
                <td><?= !empty($p['created_at']) ? date('M d, Y', strtotime($p['created_at'])) : 'N/A' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <table id="pdfInfantTable">
        <thead>
            <tr>
                <th>Infant Name</th>
                <th>Sex</th>
                <th>Birth Date</th>
                <th>Mother's Name</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($monthlyInfants as $inf): ?>
            <tr>
                <td><?= htmlspecialchars($inf['infant_name']) ?></td>
                <td><?= htmlspecialchars($inf['gender']) ?></td>
                <td><?= date('M d, Y', strtotime($inf['birth_date'])) ?></td>
                <td><?= htmlspecialchars($inf['mother_name']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <table id="pdfFinancialTable">
        <thead>
            <tr>
                <th>Reference No.</th>
                <th>Patient Name</th>
                <th>Date & Time</th>
                <th>Amount (PHP)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($monthlyTxns as $txn): ?>
            <tr>
                <td><?= htmlspecialchars($txn['receipt'] ?: ('INV-'.str_pad($txn['id'], 5, '0', STR_PAD_LEFT))) ?></td>
                <td><?= htmlspecialchars($txn['full_name']) ?></td>
                <td><?= date('M d, Y h:i A', strtotime($txn['payment_date'])) ?></td>
                <td><?= number_format($txn['amount'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
    // --- 1. RENDER CHARTS ---
    const themeColor = '<?= htmlspecialchars($themeColor) ?>';
    
    // Doughnut Chart (Demographics)
    const ctxDoughnut = document.getElementById('demographicsChart').getContext('2d');
    new Chart(ctxDoughnut, {
        type: 'doughnut',
        data: {
            labels: ['Minor', 'Young Adult', 'Adult', 'Senior'],
            datasets: [{
                data: [<?php echo "$minors, $youngAdults, $adults, $seniors"; ?>],
                backgroundColor: ['#f43f5e', '#3b82f6', '#10b981', '#a855f7'],
                borderWidth: 0,
                hoverOffset: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '75%',
            plugins: { legend: { display: false } }
        }
    });

    const trendLabels = <?= json_encode($trendLabels) ?>;
    const growthData = <?= json_encode($patientGrowthData) ?>;
    const salesData = <?= json_encode($salesTrendData) ?>;

    // Bar Chart (Patient Growth)
    const ctxBar = document.getElementById('growthChart').getContext('2d');
    new Chart(ctxBar, {
        type: 'bar',
        data: {
            labels: trendLabels,
            datasets: [{
                label: 'New Patients',
                data: growthData,
                backgroundColor: themeColor,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: '#f1f5f9', drawBorder: false }, ticks: { stepSize: 1, color: '#94a3b8', font: {size: 10} } },
                x: { grid: { display: false }, ticks: { color: '#94a3b8', font: {size: 10, weight: 'bold'} } }
            }
        }
    });

    // Line Chart (Sales Trend)
    const ctxLine = document.getElementById('salesChart').getContext('2d');
    new Chart(ctxLine, {
        type: 'line',
        data: {
            labels: trendLabels,
            datasets: [{
                label: 'Revenue (₱)',
                data: salesData,
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                borderWidth: 3,
                pointBackgroundColor: '#fff',
                pointBorderColor: '#3b82f6',
                pointRadius: 4,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: '#f1f5f9', drawBorder: false }, ticks: { color: '#94a3b8', font: {size: 10}, callback: function(v){ return '₱' + v; } } },
                x: { grid: { display: false }, ticks: { color: '#94a3b8', font: {size: 10, weight: 'bold'} } }
            }
        }
    });

    // --- 2. GENERATE PROFESSIONAL PDF USING jspdf & autoTable ---
    function generatePDF() {
        const btn = document.getElementById('exportPdfBtn');
        const originalHtml = btn.innerHTML;

        btn.innerHTML = '<span class="material-symbols-outlined text-[18px] animate-spin">autorenew</span> Generating...';
        btn.classList.add('opacity-75', 'cursor-wait');
        btn.disabled = true;

        setTimeout(() => {
            try {
                const { jsPDF } = window.jspdf;
                const doc = new jsPDF('l', 'pt', 'a4'); // Landscape A4
                
                const themeColor = '<?= $themeColor ?>';
                const r = parseInt(themeColor.slice(1, 3), 16);
                const g = parseInt(themeColor.slice(3, 5), 16);
                const b = parseInt(themeColor.slice(5, 7), 16);
                
                const pageWidth = doc.internal.pageSize.width;

                // --- HEADER ---
                doc.setFontSize(22);
                doc.setFont("helvetica", "bold");
                doc.setTextColor(r, g, b);
                doc.text("<?= htmlspecialchars($clinicName) ?>", pageWidth/2, 45, { align: 'center' });
                
                doc.setFontSize(12);
                doc.setFont("helvetica", "bold");
                doc.setTextColor(100, 100, 100);
                doc.text("COMPREHENSIVE DATA REPORTS", pageWidth/2, 65, { align: 'center' });
                
                doc.setFontSize(9);
                doc.setFont("helvetica", "normal");
                doc.setTextColor(100, 116, 139);
                doc.text("Report Period: <?= htmlspecialchars($periodLabel) ?>  |  Generated: <?= date('M d, Y h:i A') ?>", pageWidth/2, 80, { align: 'center' });

                let finalY = 110;

                // --- TABLE 1: PATIENTS ---
                doc.setFontSize(12);
                doc.setFont("helvetica", "bold");
                doc.setTextColor(255, 255, 255);
                doc.setFillColor(r, g, b);
                doc.rect(40, finalY, pageWidth - 80, 20, 'F');
                doc.text("1. PATIENT REGISTRY RECORDS", 50, finalY + 14);

                doc.autoTable({
                    html: '#pdfPatientTable',
                    startY: finalY + 20,
                    theme: 'grid',
                    styles: { fontSize: 9, cellPadding: 5, font: 'helvetica', lineColor: [203, 213, 225], lineWidth: 0.5 },
                    headStyles: { fillColor: [241, 245, 249], textColor: [15, 23, 42], fontStyle: 'bold' }
                });
                
                finalY = doc.lastAutoTable.finalY;
                doc.setFillColor(220, 252, 231); // Light green summary background
                doc.rect(40, finalY, pageWidth - 80, 20, 'FD');
                doc.setFontSize(10);
                doc.setTextColor(6, 78, 59);
                doc.text("Total Patient Records: <?= $monthlyCount ?>", 50, finalY + 14);
                
                finalY += 50;

                // --- TABLE 2: INFANTS ---
                // Add new page if necessary
                if(finalY > doc.internal.pageSize.height - 150) { doc.addPage(); finalY = 50; }

                doc.setFontSize(12);
                doc.setFont("helvetica", "bold");
                doc.setTextColor(255, 255, 255);
                doc.setFillColor(r, g, b);
                doc.rect(40, finalY, pageWidth - 80, 20, 'F');
                doc.text("2. INFANT BIRTH RECORDS", 50, finalY + 14);

                doc.autoTable({
                    html: '#pdfInfantTable',
                    startY: finalY + 20,
                    theme: 'grid',
                    styles: { fontSize: 9, cellPadding: 5, font: 'helvetica', lineColor: [203, 213, 225], lineWidth: 0.5 },
                    headStyles: { fillColor: [241, 245, 249], textColor: [15, 23, 42], fontStyle: 'bold' },
                    emptyRows: 'No records found'
                });

                finalY = doc.lastAutoTable.finalY;
                doc.setFillColor(220, 252, 231);
                doc.rect(40, finalY, pageWidth - 80, 20, 'FD');
                doc.setFontSize(10);
                doc.setTextColor(6, 78, 59);
                doc.text("Total Infants: <?= count($monthlyInfants) ?> (Male: <?= $infantMale ?>, Female: <?= $infantFemale ?>)", 50, finalY + 14);

                finalY += 50;

                // --- TABLE 3: FINANCIALS ---
                if(finalY > doc.internal.pageSize.height - 150) { doc.addPage(); finalY = 50; }

                doc.setFontSize(12);
                doc.setFont("helvetica", "bold");
                doc.setTextColor(255, 255, 255);
                doc.setFillColor(r, g, b);
                doc.rect(40, finalY, pageWidth - 80, 20, 'F');
                doc.text("3. FINANCIAL TRANSACTIONS", 50, finalY + 14);

                doc.autoTable({
                    html: '#pdfFinancialTable',
                    startY: finalY + 20,
                    theme: 'grid',
                    styles: { fontSize: 9, cellPadding: 5, font: 'helvetica', lineColor: [203, 213, 225], lineWidth: 0.5 },
                    headStyles: { fillColor: [241, 245, 249], textColor: [15, 23, 42], fontStyle: 'bold' },
                    columnStyles: { 0: {fontStyle: 'bold'}, 3: {halign: 'right', fontStyle: 'bold'} }
                });

                finalY = doc.lastAutoTable.finalY;
                doc.setFillColor(220, 252, 231);
                doc.rect(40, finalY, pageWidth - 80, 20, 'FD');
                doc.setFontSize(10);
                doc.setTextColor(6, 78, 59);
                doc.text("TOTAL TRANSACTIONS (<?= count($monthlyTxns) ?>)", 50, finalY + 14);
                doc.text("PHP <?= number_format($totalIncome, 2) ?>", pageWidth - 50, finalY + 14, {align: 'right'});

                // --- FOOTER FOR ALL PAGES ---
                const pageCount = doc.internal.getNumberOfPages();
                for(let i = 1; i <= pageCount; i++) {
                    doc.setPage(i);
                    doc.setFontSize(8);
                    doc.setFont("helvetica", "normal");
                    doc.setTextColor(150, 150, 150);
                    doc.text("<?= htmlspecialchars($clinicName) ?> Management System | Computer Generated Report | Page " + i + " of " + pageCount, pageWidth / 2, doc.internal.pageSize.height - 20, {align: 'center'});
                }

                // Save PDF
                doc.save('Clinic_Comprehensive_Report_<?= date('Y-m-d') ?>.pdf');
                
                // Reset button
                btn.innerHTML = originalHtml;
                btn.classList.remove('opacity-75', 'cursor-wait');
                btn.disabled = false;

            } catch (err) {
                console.error("PDF Error: ", err);
                alert("Failed to generate PDF. Check console for details.");
                btn.innerHTML = originalHtml;
                btn.classList.remove('opacity-75', 'cursor-wait');
                btn.disabled = false;
            }
        }, 500); 
    }
</script>
</body>
</html>