<?php
// 1. SETTINGS & SESSION
date_default_timezone_set('Asia/Manila');
ini_set('display_errors', 0);
error_reporting(E_ALL);

ob_start();
session_start();
require_once 'db.php';

// --- LOGOUT HANDLER ---
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    // Audit logging
    $currentTime = date('Y-m-d H:i:s');
    if (isset($_SESSION['full_name']) && isset($pdo)) {
        try {
            $logoutName = $_SESSION['full_name'];
            $logoutRole = $_SESSION['role'] ?? 'User';
            $auditTenant = $_SESSION['TenantID'] ?? null;
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $auditDetails = 'User securely logged out of their clinic portal.';
            $stmtLog = $pdo->prepare("INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, 'Logout', ?, ?, ?)");
            $stmtLog->execute([$auditTenant, $logoutName, $logoutRole, $auditDetails, $ip, $currentTime]);
        } catch (Throwable $e) {
            // Log error if audit fails, but don't block logout
            error_log('Logout audit logging failed in support.php: ' . $e->getMessage());
        }
    }

    // Standard session destruction
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();

    // Redirect with clinic code if available
    $clinicCode = $_GET['c'] ?? null;
    if ($clinicCode) {
        header("Location: tenant_login.php?c=" . urlencode($clinicCode));
    } else {
        header("Location: tenant_login.php");
    }
    exit();
}

// SECURITY CHECK: Siguraduhin na Admin o Staff ang nakalogin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'Patient') {
    header("Location: index.php");
    exit();
}

$displayName = $_SESSION['full_name'] ?? 'Admin';
$userRole    = $_SESSION['role'] ?? 'Clinic Administrator';
$displayRole = $userRole; 
$normalizedRole = strtolower(trim((string)$userRole));
$isStaffRole = ($normalizedRole === 'staff');
$userId      = $_SESSION['user_id'];
$tenant_id   = $_SESSION['TenantID'] ?? null; 
$msgError    = null; 
$msgSuccess  = $_GET['msg'] ?? null;

// --- OWNER / STAFF ADMIN PERMISSION SYSTEM ---
$currentUserIsOwner = in_array($normalizedRole, ['admin', 'administrator', 'owner', 'owner/midwife'], true);
$currentUserIsStaffAdmin = false;
$currentUserGrantedFeatures = [];
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

// --- FETCH CLINIC NAME, LOGO & THEME COLOR ---
$clinicName = "MaternityHub";
$clinicCode = "N/A";
$clinicLogo = null;
$themeColor = "#15803d"; // Default Green

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

// Dynamic contrast + header/sidebar classes (align with tenantsettings)
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

// --- SUBMIT TICKET LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_ticket'])) {
    $subject  = trim($_POST['subject']);
    $category = trim($_POST['category']);
    $priority = trim($_POST['priority']);
    $message  = trim($_POST['message']);

    // Kukunin ang email address ng nag-send mula sa database
    $senderEmail = 'No email provided';
    try {
        if ($isStaffRole) {
            $eStmt = $pdo->prepare("SELECT * FROM clinic_staff WHERE id = ?");
            $eStmt->execute([$userId]);
            $userRow = $eStmt->fetch(PDO::FETCH_ASSOC);
            if ($userRow) {
                $senderEmail = $userRow['email_address'] ?? $userRow['email'] ?? 'No email provided';
            }
        } else {
            $eStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $eStmt->execute([$userId]);
            $userRow = $eStmt->fetch(PDO::FETCH_ASSOC);
            if ($userRow) {
                $senderEmail = $userRow['email'] ?? $userRow['email_address'] ?? 'No email provided';
            }
        }
    } catch(PDOException $e) {}

    if (empty($subject) || empty($category) || empty($message)) {
        $msgError = "Please fill in all required fields.";
    } else {
        try {
            // 🔥 TANGGAL NA ANG user_id. GAGAMITIN ANG sender_name AT email_address 🔥
            $stmt = $pdo->prepare("INSERT INTO support_tickets (TenantID, sender_name, email_address, subject, category, priority, message, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Open')");
            $stmt->execute([$tenant_id, $displayName, $senderEmail, $subject, $category, $priority, $message]);
            
            header("Location: support.php?msg=ticket_sent");
            exit();
        } catch (PDOException $e) {
            $msgError = "Error submitting ticket: " . $e->getMessage();
        }
    }
}

// --- FETCH TICKET HISTORY FOR THIS CLINIC ---
$tickets = [];
try {
    $stmtTickets = $pdo->prepare("SELECT * FROM support_tickets WHERE TenantID = ? ORDER BY created_at DESC");
    $stmtTickets->execute([$tenant_id]);
    $tickets = $stmtTickets->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $msgError = "Could not load ticket history.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Help & Support - <?= htmlspecialchars($clinicName) ?></title>
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
                    boxShadow: { 'soft': '0 10px 40px -10px rgba(0,0,0,0.08)' }
                } 
            } 
        }
    </script>
    <style>
        html, body { margin: 0; padding: 0; scroll-behavior: smooth; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; overflow: hidden; }
        .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        .icon-filled { font-variation-settings: 'FILL' 1; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        @view-transition { navigation: auto; }
        header { view-transition-name: header; }
        aside { view-transition-name: sidebar; }
        ::view-transition-old(sidebar), ::view-transition-new(sidebar),
        ::view-transition-old(header), ::view-transition-new(header) { animation: none; }
    </style>
</head>
<body class="bg-background-light text-slate-800 h-screen flex flex-col relative text-sm antialiased font-display">

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
        <p class="text-slate-500 text-[11px] mb-6">Are you sure you want to end your session?</p>
        <div class="flex gap-2">
            <button onclick="closeLogoutModal()" class="flex-1 py-2.5 rounded-xl font-bold text-slate-400 hover:bg-slate-50 transition-all text-[11px]">Cancel</button>
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
        <div class="hidden sm:flex flex-col text-right justify-center <?= $headerTextPrimary ?> border-l border-white/20 pl-4">
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
                <a href="support.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] <?= $sidebarActive ?> font-bold shadow-sm transition-all hover:scale-[1.02]">
                    <span class="material-symbols-outlined text-2xl icon-filled">support_agent</span> <span>Help &amp; Support</span>
                </a>
                <?php endif; ?>
                <?php if ($currentUserIsOwner || $currentUserIsStaffAdmin || in_array('feedback', $currentUserGrantedFeatures)): ?>
                <a href="feedback.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">feedback</span> <span>Feedback</span>
                </a>
                <?php endif; ?>
                <a href="tenantauditlogs.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">history</span> <span>Audit Logs</span>
                </a>
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

    <main class="flex-1 overflow-y-auto p-8 bg-background-light relative z-10">
        <div class="max-w-6xl mx-auto space-y-8">

            <?php if($msgSuccess === 'ticket_sent'): ?>
            <div class="p-4 rounded-xl text-sm font-bold flex items-center gap-3 bg-emerald-100 text-emerald-800 border border-emerald-200 animate-in slide-in-from-top-2">
                <span class="material-symbols-outlined">check_circle</span>
                Your support ticket has been submitted. Platform admins will review it shortly.
            </div>
            <?php endif; ?>

            <?php if($msgError): ?>
            <div class="p-4 rounded-xl text-sm font-bold flex items-center gap-3 bg-red-100 text-red-800 border border-red-200 animate-in slide-in-from-top-2">
                <span class="material-symbols-outlined">error</span>
                <?= htmlspecialchars($msgError) ?>
            </div>
            <?php endif; ?>

            <div>
                <h2 class="text-3xl font-black text-slate-800 tracking-tighter uppercase leading-tight">Helpdesk & Support</h2>
                <p class="text-slate-500 text-sm font-medium tracking-tight">Report bugs, request features, or get help with your billing.</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <div class="lg:col-span-1 bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm h-max">
                    <div class="flex items-center gap-2 mb-4 pb-4 border-b border-slate-100">
                        <div class="size-10 rounded-xl bg-primary/10 text-primary flex items-center justify-center">
                            <span class="material-symbols-outlined text-xl">forum</span>
                        </div>
                        <div>
                            <h3 class="text-base font-black text-slate-800 uppercase tracking-tight">Submit a Ticket</h3>
                            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest">Contact Platform Admins</p>
                        </div>
                    </div>
                    
                    <form method="POST" action="support.php" class="flex flex-col gap-4">
                        <input type="hidden" name="submit_ticket" value="1">
                        
                        <div>
                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-1 mb-1 block">Subject / Title</label>
                            <input type="text" name="subject" required class="w-full rounded-xl border-slate-200 text-sm p-3 focus:ring-primary focus:border-primary shadow-sm bg-slate-50" placeholder="e.g. Cannot add new patient">
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-1 mb-1 block">Category</label>
                                <select name="category" class="w-full rounded-xl border-slate-200 text-xs font-bold text-slate-700 p-3 focus:ring-primary focus:border-primary shadow-sm bg-slate-50">
                                    <option value="Bug Report">🐛 Bug Report</option>
                                    <option value="Billing">💳 Billing / Payment</option>
                                    <option value="Feature Request">✨ Feature Request</option>
                                    <option value="General Inquiry">❓ General Inquiry</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-1 mb-1 block">Priority</label>
                                <select name="priority" class="w-full rounded-xl border-slate-200 text-xs font-bold text-slate-700 p-3 focus:ring-primary focus:border-primary shadow-sm bg-slate-50">
                                    <option value="Low">Low</option>
                                    <option value="Medium" selected>Medium</option>
                                    <option value="High">High (Urgent)</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-1 mb-1 block">Detailed Message</label>
                            <textarea name="message" required rows="4" class="w-full rounded-xl border-slate-200 text-sm p-3 focus:ring-primary focus:border-primary shadow-sm bg-slate-50 resize-none" placeholder="Please describe your issue in detail..."></textarea>
                        </div>

                        <button type="submit" class="w-full bg-primary text-white font-bold py-3.5 rounded-xl uppercase tracking-widest text-[11px] hover:bg-primary-dark transition-colors shadow-md mt-2 flex items-center justify-center gap-2">
                            <span class="material-symbols-outlined text-sm">send</span> Send Ticket
                        </button>
                    </form>
                </div>

                <div class="lg:col-span-2 bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden flex flex-col h-max">
                    <div class="p-6 border-b border-slate-100 bg-white">
                        <h3 class="text-lg font-black text-slate-800">Your Support Tickets</h3>
                        <p class="text-xs font-medium text-slate-500">History of your reports and their current resolution status.</p>
                    </div>
                    
                    <div class="overflow-x-auto flex-1">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-slate-50 text-slate-400 text-[10px] uppercase tracking-widest border-b border-slate-100">
                                    <th class="p-5 font-black">Ticket Info</th>
                                    <th class="p-5 font-black">Category</th>
                                    <th class="p-5 font-black">Date Submitted</th>
                                    <th class="p-5 font-black text-right">Status</th>
                                </tr>
                            </thead>
                            <tbody class="text-sm font-medium text-slate-600 divide-y divide-slate-100">
                                <?php if (empty($tickets)): ?>
                                    <tr>
                                        <td colspan="4" class="p-10 text-center text-slate-400">
                                            <span class="material-symbols-outlined text-4xl mb-2 opacity-50">inbox</span>
                                            <p class="italic">You haven't submitted any support tickets yet.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($tickets as $t): ?>
                                        <tr class="hover:bg-slate-50 transition-colors">
                                            <td class="p-5">
                                                <p class="font-black text-slate-800 leading-tight text-sm tracking-tight"><?= htmlspecialchars($t['subject']) ?></p>
                                                <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mt-1 truncate max-w-[200px]"><?= htmlspecialchars($t['message']) ?></p>
                                            </td>
                                            <td class="p-5">
                                                <div class="flex items-center gap-1.5">
                                                    <span class="text-[10px] font-black uppercase tracking-widest bg-slate-100 px-2 py-1 rounded-md text-slate-600 border border-slate-200"><?= htmlspecialchars($t['category']) ?></span>
                                                    <?php if($t['priority'] === 'High'): ?>
                                                        <span class="text-[10px] font-black uppercase tracking-widest bg-red-100 px-2 py-1 rounded-md text-red-600">Urgent</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="p-5">
                                                <p class="text-xs font-bold text-slate-700"><?= date('M d, Y', strtotime($t['created_at'])) ?></p>
                                                <p class="text-[9px] text-slate-400 uppercase tracking-widest"><?= date('h:i A', strtotime($t['created_at'])) ?></p>
                                            </td>
                                            <td class="p-5 text-right">
                                                <?php if($t['status'] === 'Open'): ?>
                                                    <span class="bg-amber-50 text-amber-600 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest border border-amber-200">Open</span>
                                                <?php elseif($t['status'] === 'In Progress'): ?>
                                                    <span class="bg-blue-50 text-blue-600 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest border border-blue-200">In Progress</span>
                                                <?php else: ?>
                                                    <span class="bg-emerald-50 text-emerald-600 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest border border-emerald-200">Resolved</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
            </div>
            
        </div>
    </main>
</div>

</body>
</html>

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

    function openLogoutModal() {
        document.getElementById('logoutModal').classList.remove('hidden');
        document.getElementById('logoutModal').classList.add('flex');
    }
    function closeLogoutModal() {
        document.getElementById('logoutModal').classList.remove('flex');
        document.getElementById('logoutModal').classList.add('hidden');
    }
    function confirmLogout() {
        closeLogoutModal(); 
        const loading = document.getElementById('loggingOutScreen');
        loading.classList.remove('hidden');
        loading.classList.add('flex');
        // Pass clinic code to logout URL
        const clinicCode = '<?= htmlspecialchars($clinicCode, ENT_QUOTES, 'UTF-8') ?>';
        setTimeout(() => { 
            window.location.href = `?action=logout&c=${clinicCode}`; 
        }, 1500); 
    }
</script>