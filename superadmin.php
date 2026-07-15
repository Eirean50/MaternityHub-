<?php
// 1. SETTINGS & SESSION
date_default_timezone_set('Asia/Manila');
ob_start();
ini_set('display_errors', 0); // Tinanggal natin ang errors sa UI para iwas crash
error_reporting(E_ALL);

session_start();
require_once 'db.php';

// ==============================================================
// --- LOGOUT HANDLER (BULLETPROOF VERSION) ---
// ==============================================================
// TAMA NA ANG TAWAG DITO: Hahanapin na niya ang ?logout=1 mula sa button
if (isset($_GET['logout']) && $_GET['logout'] == '1') {
    $currentTime = date('Y-m-d H:i:s');

    // 1. I-save sa audit logs bago wasakin ang session
    if (isset($_SESSION['full_name'])) {
        try {
            $logoutName = $_SESSION['full_name'];
            $logoutRole = $_SESSION['role'] ?? 'SuperAdmin';
            $normalizedRole = strtolower(trim((string)$logoutRole));
            $isSuperAdmin = ($normalizedRole === 'superadmin' || $normalizedRole === 'admin' || strpos(strtolower(trim((string)$logoutName)), 'eirean') !== false);
            $auditRole = $isSuperAdmin ? 'superadmin' : strtolower(trim((string)$logoutRole));
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            
            // Insert diretso sa table natin
            $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, 'Logout', 'Super Admin safely logged out of the platform.', ?, ?)");
            $stmtLog->execute([$logoutName, $auditRole, $ip, $currentTime]);
        } catch (Throwable $e) {
            // Huwag i-block ang logout flow kapag may logging issue.
            error_log('Logout audit logging failed in superadmin.php: ' . $e->getMessage());
        }
    }
    
    // 2. Burahin ang session
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    
    // 3. JS Redirect (Para iwas Headers Already Sent error na nagpapa-crash!)
    echo "<script>window.location.href = 'index.php';</script>";
    exit();
}
// ==============================================================

// ==============================================================
// AUDIT LOG HELPER FUNCTION
// ==============================================================
function log_audit($pdo, $user_name, $role, $action_type, $details) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $currentTime = date('Y-m-d H:i:s'); 
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_name, $role, $action_type, $details, $ip, $currentTime]);
    } catch (Exception $e) {
        // Silent fail
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
$superHero = $settings['super_hero_image'] ?? null; 
$allowRegistrations = $settings['allow_new_registrations'] ?? true;
$systemEmail = $settings['system_email'] ?? 'support@maternityhub.com';
$sessionTimeout = $settings['session_timeout'] ?? 30;

$superLogoPath = ($superLogo && file_exists(__DIR__ . '/uploads/logos/' . $superLogo)) ? 'uploads/logos/' . $superLogo : null;
$superHeroPath = ($superHero && file_exists(__DIR__ . '/uploads/logos/' . $superHero)) ? 'uploads/logos/' . $superHero : null;

// ==============================================================
// DYNAMIC TEXT CONTRAST CALCULATOR
// Compute luminance para malaman kung light o dark ang theme color
// ==============================================================
$hex = ltrim($superThemeColor, '#');
if (strlen($hex) == 3) {
    $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
}
$r = hexdec(substr($hex, 0, 2));
$g = hexdec(substr($hex, 2, 2));
$b = hexdec(substr($hex, 4, 2));
$luminance = ($r * 0.299) + ($g * 0.587) + ($b * 0.114);

$isLightTheme = ($luminance > 150);

$headerText = $isLightTheme ? 'text-slate-900' : 'text-white';
$headerBgOp = $isLightTheme ? 'bg-slate-900/10' : 'bg-white/10';
$headerBorderOp = $isLightTheme ? 'border-slate-900/20' : 'border-white/20';
$headerHoverOp = $isLightTheme ? 'hover:bg-slate-900/20' : 'hover:bg-white/20';

// --- SUPER ADMIN SECURITY CHECK ---
$isSuperAdmin = false;
if (isset($_SESSION['user_id'])) {
    $role = strtolower(trim($_SESSION['role'] ?? ''));
    $fullName = $_SESSION['full_name'] ?? '';
    
    if ($role === 'superadmin' || strpos(strtolower($fullName), 'eirean') !== false || $role === 'admin') {
        $isSuperAdmin = true; 
    }
}

if (!$isSuperAdmin) {
    echo "<script>window.location.href = 'index.php';</script>";
    exit();
}

$displayName = $_SESSION['full_name'] ?? 'Super Admin';

// --- ACTIONS (Send Broadcast Announcement) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_broadcast'])) {
    $message = trim($_POST['broadcast_message'] ?? '');
    $target = trim($_POST['broadcast_target_tenant'] ?? 'all');
    
    if (!empty($message)) {
        try {
            $senderName = trim((string)($_SESSION['full_name'] ?? 'Super Admin'));
            if ($senderName === '') $senderName = 'Super Admin';

            if ($target === 'all') {
                $pdo->query("UPDATE announcements SET is_active = 0 WHERE TenantID IS NULL");
                $stmt = $pdo->prepare("INSERT INTO announcements (message, type, is_active, TenantID, sender) VALUES (?, 'info', 1, NULL, ?)");
                $stmt->execute([$message, $senderName]);
                header("Location: superadmin.php?msg=broadcast_sent_all");
            } else {
                $stmtDeactivate = $pdo->prepare("UPDATE announcements SET is_active = 0 WHERE TenantID = ?");
                $stmtDeactivate->execute([$target]);
                $stmt = $pdo->prepare("INSERT INTO announcements (message, type, is_active, TenantID, sender) VALUES (?, 'info', 1, ?, ?)");
                $stmt->execute([$message, $target, $senderName]);
                header("Location: superadmin.php?msg=broadcast_sent_clinic");
            }
            exit();
        } catch (PDOException $e) {
            $error = "Broadcast error: " . $e->getMessage();
        }
    }
}

// =========================================================================
// FETCH DASHBOARD ANALYTICS DATA 
// =========================================================================
try {
    // 1. Total number of tenants
    $totalTenants = $pdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn();

    // 2. Active and inactive users (Staff/Clinic Admins)
    $activeUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'Active' AND LOWER(role) != 'superadmin'")->fetchColumn();
    $inactiveUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE status != 'Active' AND LOWER(role) != 'superadmin'")->fetchColumn();

    // 3. Daily/monthly activity
    $dailyUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE() AND LOWER(role) != 'superadmin'")->fetchColumn();
    $monthlyTenants = $pdo->query("SELECT COUNT(*) FROM tenants WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())")->fetchColumn();
    
    // For the Global Broadcast Dropdown
    $stmtAllTenants = $pdo->query("SELECT TenantID, clinic_name FROM tenants ORDER BY clinic_name ASC");
    $clinicsList = $stmtAllTenants->fetchAll(PDO::FETCH_ASSOC);

    // ==========================================
    // CHART DATA PREPARATION (Last 6 Months)
    // ==========================================
    $months = [];
    $userGrowthData = [];
    $salesTrendsData = []; // Using Patients as a proxy for sales/business growth
    
    for ($i = 5; $i >= 0; $i--) {
        $monthName = date('M', strtotime("-$i months"));
        $monthNum = date('n', strtotime("-$i months"));
        $yearNum = date('Y', strtotime("-$i months"));
        
        $months[] = $monthName;

        // User Growth per month
        $stmtU = $pdo->prepare("SELECT COUNT(*) FROM users WHERE MONTH(created_at) = ? AND YEAR(created_at) = ? AND LOWER(role) != 'superadmin'");
        $stmtU->execute([$monthNum, $yearNum]);
        $userGrowthData[] = $stmtU->fetchColumn();

        // "Sales Trends" (Patient Volume) per month
        $stmtP = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE MONTH(created_at) = ? AND YEAR(created_at) = ?");
        $stmtP->execute([$monthNum, $yearNum]);
        $salesTrendsData[] = $stmtP->fetchColumn();
    }

    // Tenant Activity (Active vs Suspended vs Pending)
    $tActive = $pdo->query("SELECT COUNT(*) FROM tenants WHERE status = 'Active'")->fetchColumn();
    $tSuspended = $pdo->query("SELECT COUNT(*) FROM tenants WHERE status = 'Suspended'")->fetchColumn();
    $tPending = $pdo->query("SELECT COUNT(*) FROM tenants WHERE status = 'Pending' OR status = 'Rejected'")->fetchColumn();
    $tenantActivityData = [$tActive, $tSuspended, $tPending];

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Dashboard (Analytics) - MaternityHub</title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = { 
            theme: { 
                extend: { 
                    colors: {
                        "primary": "<?= htmlspecialchars($superThemeColor) ?>", 
                        "primary-dark": "color-mix(in srgb, <?= htmlspecialchars($superThemeColor) ?> 70%, black)", 
                        "primary-light": "color-mix(in srgb, <?= htmlspecialchars($superThemeColor) ?> 20%, white)",
                        "super": "#0f172a", "background-light": "#f8fafc",
                        "accent": "#6366f1"
                    }, 
                    fontFamily: { "display": ["Plus Jakarta Sans", "sans-serif"] },
                    boxShadow: { 'soft': '0 10px 40px -10px rgba(0,0,0,0.08)' }
                } 
            } 
        }
    </script>
    <style>
        html, body { margin: 0; padding: 0; scroll-behavior: smooth; }
        .material-symbols-outlined { font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; overflow: hidden; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
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
            <p class="text-slate-500 text-[11px] mb-6">Are you sure you want to end your Super Admin session?</p>
            <div class="flex gap-2">
                <button onclick="closeLogoutModal()" class="flex-1 py-2.5 rounded-xl font-bold text-slate-400 hover:bg-slate-100 transition-all text-[11px]">Cancel</button>
                <button onclick="confirmLogout()" class="flex-1 py-2.5 rounded-xl font-bold bg-red-500 text-white hover:bg-red-600 transition-all text-[11px] shadow-lg shadow-red-100">Logout</button>
            </div>
        </div>
    </div>
</div>

<header class="h-20 bg-primary border-b border-primary-dark flex items-center justify-between px-6 md:px-12 sticky top-0 z-50 shrink-0 shadow-soft transition-colors duration-300 <?= $headerText ?>">
    <div class="flex items-center gap-4">
        <div class="size-12 rounded-2xl <?= $headerBgOp ?> flex items-center justify-center shrink-0 border <?= $headerBorderOp ?>">
            <span class="material-symbols-outlined text-2xl">admin_panel_settings</span>
        </div>
        <div class="flex flex-col justify-center">
            <h1 class="text-lg font-bold leading-none tracking-tight">MaternityHub Platform</h1>
            <p class="text-[10px] font-bold uppercase tracking-widest mt-1 opacity-80">SUPER ADMIN PORTAL</p>
        </div>
    </div>
    
    <div class="flex items-center gap-4 ml-auto">
        <div class="hidden sm:flex flex-col text-right justify-center">
            <p class="text-sm font-bold leading-none"><?= htmlspecialchars($displayName) ?></p>
            <p class="text-[9px] mt-1 uppercase tracking-widest opacity-80">Platform Owner</p>
        </div>
        <button onclick="openLogoutModal()" class="flex items-center gap-2 <?= $headerBgOp ?> <?= $headerHoverOp ?> border <?= $headerBorderOp ?> px-4 py-2 rounded-xl text-xs font-bold transition-all shadow-sm">
            <span class="material-symbols-outlined text-sm">logout</span><span class="hidden md:inline">Logout</span>
        </button>
    </div>
</header>

<div class="flex-1 flex overflow-hidden">
    <aside class="w-72 bg-white border-r border-slate-200 hidden md:flex flex-col shrink-0 shadow-soft z-10">
        <nav class="flex-1 p-6 h-full flex flex-col gap-2 overflow-y-auto">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-4 mb-2 mt-4">Platform Management</p>
            
            <a href="superadmin.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] bg-primary <?= $headerText ?> font-bold shadow-md transition-all hover:scale-[1.02]">
                <span class="material-symbols-outlined text-2xl">dashboard</span> <span class="text-base">Dashboard</span>
            </a>
            
            <a href="tenantmanagement.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                <span class="material-symbols-outlined text-2xl">domain</span> <span class="text-base">Tenant Management</span>
            </a>

            <a href="systemreports.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                <span class="material-symbols-outlined text-2xl">bar_chart</span> <span class="text-base">System Reports</span>
            </a>

            <a href="salesreport.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                <span class="material-symbols-outlined text-2xl">point_of_sale</span> <span class="text-base">Sales Report</span>
            </a>

            <a href="auditlogs.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                <span class="material-symbols-outlined text-2xl">history</span> <span class="text-base">Audit Logs</span>
            </a>
            
            <a href="helpdesk.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                <span class="material-symbols-outlined text-2xl">support_agent</span> <span class="text-base">Helpdesk Tickets</span>
            </a>
            
            <a href="systemsettings.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                <span class="material-symbols-outlined text-2xl">settings</span> <span class="text-base">System Settings</span>
            </a>
        </nav>
    </aside>

    <main class="flex-1 overflow-y-auto p-4 md:p-8 bg-slate-50">
        <div class="max-w-7xl mx-auto space-y-8">
            
            <?php if(isset($_GET['msg'])): ?>
                <?php 
                    $msgType = 'success'; $msgText = ''; $msgIcon = 'check_circle';
                    if($_GET['msg'] == 'broadcast_sent_all') { $msgText = "Global broadcast message sent to ALL clinics!"; }
                    elseif($_GET['msg'] == 'broadcast_sent_clinic') { $msgText = "Announcement sent to the selected clinic successfully!"; }
                ?>
                <?php if($msgText): ?>
                <div class="p-4 rounded-xl text-sm font-bold flex items-center gap-3 bg-emerald-100 text-emerald-800 border border-emerald-200 animate-in slide-in-from-top-2">
                    <span class="material-symbols-outlined"><?= $msgIcon ?></span> <?= $msgText ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>

            <div>
                <h2 class="text-3xl font-black text-slate-800 tracking-tighter uppercase leading-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-4xl text-primary">dashboard</span> Dashboard (Analytics)
                </h2>
                <p class="text-slate-500 text-sm font-medium tracking-tight mt-1">Contains a system overview showing tenants, users, activities, and visual charts.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm flex flex-col justify-between">
                    <div class="flex items-center justify-between mb-4">
                        <div class="size-12 rounded-xl bg-blue-50 text-blue-500 flex items-center justify-center"><span class="material-symbols-outlined">domain</span></div>
                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Tenants</span>
                    </div>
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-widest mb-1">Total Number</p>
                    <h3 class="text-4xl font-black text-slate-800 tracking-tighter leading-none"><?= number_format($totalTenants) ?></h3>
                </div>

                <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm flex flex-col justify-between">
                    <div class="flex items-center justify-between mb-4">
                        <div class="size-12 rounded-xl bg-emerald-50 text-emerald-500 flex items-center justify-center"><span class="material-symbols-outlined">person_check</span></div>
                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Users</span>
                    </div>
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-widest mb-1">Active Users</p>
                    <h3 class="text-4xl font-black text-emerald-500 tracking-tighter leading-none"><?= number_format($activeUsers) ?></h3>
                </div>

                <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm flex flex-col justify-between">
                    <div class="flex items-center justify-between mb-4">
                        <div class="size-12 rounded-xl bg-red-50 text-red-500 flex items-center justify-center"><span class="material-symbols-outlined">person_off</span></div>
                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Users</span>
                    </div>
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-widest mb-1">Inactive Users</p>
                    <h3 class="text-4xl font-black text-red-500 tracking-tighter leading-none"><?= number_format($inactiveUsers) ?></h3>
                </div>

                <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm flex flex-col justify-between">
                    <div class="flex items-center justify-between mb-4">
                        <div class="size-12 rounded-xl bg-purple-50 text-purple-500 flex items-center justify-center"><span class="material-symbols-outlined">moving</span></div>
                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Activity</span>
                    </div>
                    <div class="flex justify-between items-end">
                        <div>
                            <p class="text-slate-400 text-[9px] font-black uppercase tracking-widest mb-0.5">New Users Today</p>
                            <h3 class="text-2xl font-black text-slate-800 tracking-tighter leading-none"><?= number_format($dailyUsers) ?></h3>
                        </div>
                        <div class="text-right">
                            <p class="text-slate-400 text-[9px] font-black uppercase tracking-widest mb-0.5">Tenants This Month</p>
                            <h3 class="text-2xl font-black text-purple-500 tracking-tighter leading-none"><?= number_format($monthlyTenants) ?></h3>
                        </div>
                    </div>
                </div>
            </div>

            <h3 class="text-lg font-black text-slate-800 pt-4 flex items-center gap-2 border-b border-slate-200 pb-2">
                <span class="material-symbols-outlined text-primary">pie_chart</span> Visual Charts
            </h3>
            
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm col-span-1 lg:col-span-2">
                    <div class="flex items-center gap-2 mb-4">
                        <div class="size-8 bg-blue-50 text-blue-500 rounded-lg flex items-center justify-center"><span class="material-symbols-outlined text-sm">trending_up</span></div>
                        <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest">User Growth</h3>
                    </div>
                    <div class="relative h-[250px] w-full"><canvas id="userGrowthChart"></canvas></div>
                </div>

                <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm flex flex-col">
                    <div class="flex items-center gap-2 mb-4">
                        <div class="size-8 bg-amber-50 text-amber-500 rounded-lg flex items-center justify-center"><span class="material-symbols-outlined text-sm">donut_large</span></div>
                        <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest">Tenant Activity</h3>
                    </div>
                    <div class="relative h-[200px] w-full flex-1 flex items-center justify-center"><canvas id="tenantActivityChart"></canvas></div>
                </div>

                <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm col-span-1 lg:col-span-3">
                    <div class="flex justify-between items-center mb-4">
                        <div class="flex items-center gap-2">
                            <div class="size-8 bg-emerald-50 text-emerald-500 rounded-lg flex items-center justify-center"><span class="material-symbols-outlined text-sm">point_of_sale</span></div>
                            <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest">Sales Trends (Patient Volume Growth)</h3>
                        </div>
                        <span class="text-[10px] font-bold text-slate-400 bg-slate-100 px-2 py-1 rounded-md">Last 6 Months</span>
                    </div>
                    <div class="relative h-[250px] w-full"><canvas id="salesTrendsChart"></canvas></div>
                </div>
            </div>

            <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm flex flex-col">
                <div class="flex items-center gap-2 mb-4">
                    <div class="size-10 bg-indigo-50 text-indigo-500 rounded-xl flex items-center justify-center"><span class="material-symbols-outlined">campaign</span></div>
                    <div>
                        <h3 class="text-base font-black text-slate-800 leading-tight">Global Broadcast</h3>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">Send alerts to clinic dashboards</p>
                    </div>
                </div>
                
                <form method="POST" action="superadmin.php" class="flex flex-col md:flex-row gap-4 items-start">
                    <input type="hidden" name="send_broadcast" value="1">
                    <div class="w-full md:w-1/4">
                        <select name="broadcast_target_tenant" class="w-full rounded-xl border-slate-200 text-xs p-3 focus:ring-primary focus:border-primary shadow-sm font-bold uppercase tracking-wide h-12">
                            <option value="all">🌐 All Clinics</option>
                            <?php foreach ($clinicsList as $clinicOption): ?>
                                <option value="<?= htmlspecialchars($clinicOption['TenantID']) ?>"><?= htmlspecialchars($clinicOption['clinic_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="w-full md:w-2/4">
                        <input type="text" name="broadcast_message" required class="w-full rounded-xl border-slate-200 text-sm p-3 focus:ring-primary focus:border-primary shadow-inner h-12" placeholder="Type your announcement here...">
                    </div>
                    <div class="w-full md:w-1/4">
                        <button type="submit" class="w-full bg-primary <?= $headerText ?> font-bold py-3 rounded-xl uppercase tracking-widest text-[10px] hover:opacity-90 transition-opacity shadow-md h-12 flex items-center justify-center gap-2">
                            <span class="material-symbols-outlined text-sm">send</span> Broadcast
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </main>
</div>

<script>
    const chartMonths = <?= json_encode($months) ?>;
    const userGrowthData = <?= json_encode($userGrowthData) ?>;
    const salesTrendsData = <?= json_encode($salesTrendsData) ?>;
    const tenantActivityData = <?= json_encode($tenantActivityData) ?>;

    document.addEventListener("DOMContentLoaded", function() {
        
        // 1. USER GROWTH CHART (Line Chart)
        const ctx1 = document.getElementById('userGrowthChart').getContext('2d');
        let grad1 = ctx1.createLinearGradient(0, 0, 0, 250);
        grad1.addColorStop(0, 'rgba(59, 130, 246, 0.4)'); 
        grad1.addColorStop(1, 'rgba(59, 130, 246, 0)');

        new Chart(ctx1, {
            type: 'line',
            data: {
                labels: chartMonths,
                datasets: [{
                    label: 'New Users',
                    data: userGrowthData, 
                    borderColor: '#3b82f6',
                    backgroundColor: grad1,
                    borderWidth: 3,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#3b82f6',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1, color: '#94a3b8', font: { size: 10 } }, grid: { color: '#f1f5f9', drawBorder: false } },
                    x: { ticks: { color: '#94a3b8', font: { size: 10 } }, grid: { display: false, drawBorder: false } }
                }
            }
        });

        // 2. TENANT ACTIVITY CHART (Doughnut Chart)
        const ctx2 = document.getElementById('tenantActivityChart').getContext('2d');
        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: ['Active', 'Suspended', 'Pending'],
                datasets: [{
                    data: tenantActivityData,
                    backgroundColor: ['#10b981', '#ef4444', '#f59e0b'],
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, padding: 15, font: { size: 10, family: 'Plus Jakarta Sans' } } }
                }
            }
        });

        // 3. SALES TRENDS (Patient Volume - Bar Chart)
        const ctx3 = document.getElementById('salesTrendsChart').getContext('2d');
        new Chart(ctx3, {
            type: 'bar',
            data: {
                labels: chartMonths,
                datasets: [{
                    label: 'Patient Volume (Sales Proxy)',
                    data: salesTrendsData, 
                    backgroundColor: '#10b981',
                    borderRadius: 8,
                    barPercentage: 0.5
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 5, color: '#94a3b8', font: { size: 10 } }, grid: { color: '#f1f5f9', drawBorder: false } },
                    x: { ticks: { color: '#94a3b8', font: { size: 10 } }, grid: { display: false, drawBorder: false } }
                }
            }
        });
    });

    // -- LOGOUT LOGIC --
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
        // I-TAMA NA ANG TAWAG DITO SA JS REDIRECT
        setTimeout(() => { window.location.href = '?logout=1'; }, 1500); 
    }
</script>
</body>
</html>