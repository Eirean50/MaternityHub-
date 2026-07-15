<?php
// 1. SETTINGS & SESSION
date_default_timezone_set('Asia/Manila');
ob_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'db.php';

// ==============================================================
// AUDIT LOG HELPER FUNCTION
// ==============================================================
function log_audit($pdo, $user_name, $role, $action_type, $details) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $currentTime = date('Y-m-d H:i:s'); 
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_name, $role, $action_type, $details, $ip, $currentTime]);
    } catch (PDOException $e) {
        // Silent fail
    }
}
// ==============================================================

// ==============================================================
// --- LOGOUT HANDLER (WITH AUDIT TRACKING) ---
// ==============================================================
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    // I-save muna sa audit logs bago burahin ang session
    if (isset($_SESSION['full_name']) && isset($_SESSION['role'])) {
        $logoutName = $_SESSION['full_name'];
        $logoutRole = $_SESSION['role'];
        $isSuperAdmin = (strtolower($logoutRole) === 'superadmin' || strpos(strtolower($logoutName), 'eirean') !== false);
        
        $auditRole = $isSuperAdmin ? 'SuperAdmin' : $logoutRole;
        $auditDetails = $isSuperAdmin ? 'Super Admin safely logged out of the platform.' : 'User securely logged out of the system reports.';
        
        log_audit($pdo, $logoutName, $auditRole, 'Logout', $auditDetails);
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
    
    // Redirect sa login page
    header("Location: index.php");
    exit();
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

// Fetch settings with fallbacks
$maintenanceMode = $settings['maintenance_mode'] ?? false;
$superThemeColor = $settings['super_theme_color'] ?? '#10b981';
$superLogo = $settings['super_logo'] ?? null; 
$superHero = $settings['super_hero_image'] ?? null; // Kunin ang hero image
$allowRegistrations = $settings['allow_new_registrations'] ?? true;
$systemEmail = $settings['system_email'] ?? 'support@maternityhub.com';
$sessionTimeout = $settings['session_timeout'] ?? 30;

// Path checks para sa Logo at Hero Image
$superLogoPath = ($superLogo && file_exists(__DIR__ . '/uploads/logos/' . $superLogo)) ? 'uploads/logos/' . $superLogo : null;
$superHeroPath = ($superHero && file_exists(__DIR__ . '/uploads/logos/' . $superHero)) ? 'uploads/logos/' . $superHero : null;

// ==============================================================
// DYNAMIC TEXT CONTRAST CALCULATOR
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

// Dynamic Tailwind Classes
$headerText = $isLightTheme ? 'text-slate-900' : 'text-white';
$headerBgOp = $isLightTheme ? 'bg-slate-900/10' : 'bg-white/10';
$headerBorderOp = $isLightTheme ? 'border-slate-900/20' : 'border-white/20';
$headerHoverOp = $isLightTheme ? 'hover:bg-slate-900/20' : 'hover:bg-white/20';
$badgeTheme = $isLightTheme ? 'text-amber-800 bg-amber-400/40 border-amber-500/30' : 'text-amber-300 bg-amber-400/20 border-amber-300/30';
// ==============================================================

// --- SUPER ADMIN SECURITY CHECK ---
$isSuperAdmin = false;
if (isset($_SESSION['user_id'])) {
    $role = strtolower(trim($_SESSION['role'] ?? ''));
    $fullName = $_SESSION['full_name'] ?? '';
    
    // Check if user is Super Admin
    if ($role === 'superadmin' || strpos(strtolower($fullName), 'eirean') !== false || $role === 'admin') {
        $isSuperAdmin = true; 
    }
}

if (!$isSuperAdmin) {
    header("Location: index.php");
    exit();
}

$displayName = $_SESSION['full_name'] ?? 'Super Admin';

// =========================================================================
// PRE-INITIALIZE VARIABLES (TO PREVENT UNDEFINED/NULL ERRORS)
// =========================================================================
$totalClinics = 0;
$activeClinics = 0;
$totalUsers = 0;
$totalPatients = 0;

$recentTenants = [];
$recentUsers = [];

// =========================================================================
// 1. USAGE STATISTICS (Overall Metrics)
// =========================================================================
try {
    $totalClinics = (int) $pdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
    $activeClinics = (int) $pdo->query("SELECT COUNT(*) FROM tenants WHERE status = 'Active'")->fetchColumn();
    
    // Hindi kasama ang SuperAdmin sa bilang ng Registered Users
    $totalUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE LOWER(role) != 'superadmin'")->fetchColumn();
    
    $totalPatients = (int) $pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();
} catch (PDOException $e) {
    // Kung mag-error, mananatiling 0 yung mga values sa itaas
}

// =========================================================================
// 2. TENANT ACTIVITY & 3. USER REGISTRATION REPORTS (Recent Data)
// =========================================================================
try {
    // Recent Tenant Activity
    $stmtRecentTenants = $pdo->query("SELECT TenantID, clinic_name, status, created_at FROM tenants ORDER BY created_at DESC LIMIT 25");
    $recentTenants = $stmtRecentTenants->fetchAll(PDO::FETCH_ASSOC);

    // Recent User Registrations (Staff/Clinic Admins ONLY, NO SuperAdmin)
    $stmtRecentUsers = $pdo->query("
        SELECT u.first_name, u.last_name, u.role, u.TenantID, u.created_at, t.clinic_name 
        FROM users u 
        LEFT JOIN tenants t ON u.TenantID = t.TenantID 
        WHERE LOWER(u.role) != 'superadmin'
        ORDER BY u.created_at DESC LIMIT 25
    ");
    $recentUsers = $stmtRecentUsers->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Defaults to empty arrays if query fails
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>System Reports - MaternityHub</title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = { 
            theme: { 
                extend: { 
                    colors: {
                        "primary": "<?= htmlspecialchars($superThemeColor) ?>",
                        "primary-dark": "color-mix(in srgb, <?= htmlspecialchars($superThemeColor) ?> 70%, black)",
                        "primary-light": "color-mix(in srgb, <?= htmlspecialchars($superThemeColor) ?> 20%, white)",
                        "super": "#0f172a", "background-light": "#f8fafc"
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
        
        /* Custom Scrollbar for inner elements */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        /* Scrollable List for items */
        .scrollable-list {
            max-height: 230px;
            overflow-y: auto;
        }
        
        /* Print Styles */
        @media print {
            aside, header, .no-print, #logoutModal, #loggingOutScreen { display: none !important; }
            main { padding: 0 !important; margin: 0 !important; background: white !important; overflow: visible !important; }
            .print-container { width: 100% !important; border: none !important; box-shadow: none !important; max-width: 100% !important; }
            .scrollable-list { max-height: none !important; overflow: visible !important; }
            body { overflow: auto !important; }
        }
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

<header class="h-20 bg-primary border-b border-primary-dark flex items-center justify-between px-6 md:px-12 sticky top-0 z-50 shrink-0 shadow-soft transition-colors duration-300 <?= $headerText ?> no-print">
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
    <aside class="w-72 bg-white border-r border-slate-200 hidden md:flex flex-col shrink-0 shadow-soft z-10 no-print">
        <nav class="flex-1 p-6 h-full flex flex-col gap-2 overflow-y-auto">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-4 mb-2 mt-4">Platform Management</p>
            
            <a href="superadmin.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                <span class="material-symbols-outlined text-2xl">dashboard</span> <span class="text-base">Dashboard</span>
            </a>
            
            <a href="tenantmanagement.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                <span class="material-symbols-outlined text-2xl">domain</span> <span class="text-base">Tenant Management</span>
            </a>

            <a href="systemreports.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] bg-primary <?= $headerText ?> font-bold shadow-md transition-all hover:scale-[1.02]">
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
        <div class="max-w-7xl mx-auto space-y-8 print-container">
            
            <div id="dashboardMetrics">
                <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 border-b border-slate-200 pb-4 mb-6">
                    <div>
                        <h2 class="text-3xl font-black text-slate-800 tracking-tighter uppercase leading-tight flex items-center gap-2">
                            <span class="material-symbols-outlined text-4xl text-primary">analytics</span> System Reports
                        </h2>
                        <p class="text-slate-500 text-sm font-medium tracking-tight mt-1">Contains generated summaries of system data.</p>
                    </div>
                </div>

                <div class="space-y-4 mb-8">
                    <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">monitoring</span> Usage Statistics</h3>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                        <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm flex flex-col justify-between">
                            <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-2">Total Clinics</p>
                            <h3 class="text-4xl font-black text-slate-800 tracking-tighter leading-none"><?= number_format($totalClinics) ?></h3>
                        </div>
                        <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm flex flex-col justify-between">
                            <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-2">Active Clinics</p>
                            <h3 class="text-4xl font-black text-emerald-500 tracking-tighter leading-none"><?= number_format($activeClinics) ?></h3>
                        </div>
                        <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm flex flex-col justify-between">
                            <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-2">Registered Clinic Staff</p>
                            <h3 class="text-4xl font-black text-blue-500 tracking-tighter leading-none"><?= number_format($totalUsers) ?></h3>
                        </div>
                        <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm flex flex-col justify-between">
                            <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-2">Total Patients</p>
                            <h3 class="text-4xl font-black text-purple-500 tracking-tighter leading-none"><?= number_format($totalPatients) ?></h3>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden flex flex-col">
                        <div class="p-6 border-b border-slate-100 flex items-center gap-3 bg-white z-10 relative">
                            <div class="size-10 bg-amber-50 text-amber-500 rounded-xl flex items-center justify-center"><span class="material-symbols-outlined">domain_add</span></div>
                            <div>
                                <h3 class="text-base font-black text-slate-800 leading-tight">Tenant Activity Reports</h3>
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">Most recent clinic signups</p>
                            </div>
                        </div>
                        <div class="p-4 flex-1 scrollable-list bg-slate-50/30">
                            <ul class="space-y-3 pr-2">
                                <?php if(empty($recentTenants)): ?>
                                    <li class="text-center text-slate-400 text-xs italic py-4">No recent tenant activity.</li>
                                <?php else: ?>
                                    <?php foreach($recentTenants as $rt): ?>
                                        <li class="flex items-center justify-between p-3 bg-white rounded-xl border border-slate-200 shadow-sm hover:border-amber-200 transition-colors">
                                            <div>
                                                <p class="font-bold text-slate-800 text-sm"><?= htmlspecialchars($rt['clinic_name']) ?></p>
                                                <p class="text-[10px] text-slate-500 font-medium uppercase tracking-wider">ID: <?= htmlspecialchars($rt['TenantID']) ?></p>
                                            </div>
                                            <div class="text-right">
                                                <?php if($rt['status'] == 'Active'): ?>
                                                    <span class="text-[9px] font-black text-emerald-600 bg-emerald-50 px-2 py-1 rounded uppercase tracking-widest">Active</span>
                                                <?php else: ?>
                                                    <span class="text-[9px] font-black text-amber-600 bg-amber-50 px-2 py-1 rounded uppercase tracking-widest"><?= htmlspecialchars($rt['status']) ?></span>
                                                <?php endif; ?>
                                                <p class="text-[9px] text-slate-400 font-bold mt-1"><?= date('M d, Y', strtotime($rt['created_at'])) ?></p>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>

                    <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden flex flex-col">
                        <div class="p-6 border-b border-slate-100 flex items-center gap-3 bg-white z-10 relative">
                            <div class="size-10 bg-blue-50 text-blue-500 rounded-xl flex items-center justify-center"><span class="material-symbols-outlined">badge</span></div>
                            <div>
                                <h3 class="text-base font-black text-slate-800 leading-tight">User Registration Reports</h3>
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">Most recent staff/admin accounts</p>
                            </div>
                        </div>
                        <div class="p-4 flex-1 scrollable-list bg-slate-50/30">
                            <ul class="space-y-3 pr-2">
                                <?php if(empty($recentUsers)): ?>
                                    <li class="text-center text-slate-400 text-xs italic py-4">No recent user registrations.</li>
                                <?php else: ?>
                                    <?php foreach($recentUsers as $ru): ?>
                                        <li class="flex items-center justify-between p-3 bg-white rounded-xl border border-slate-200 shadow-sm hover:border-blue-200 transition-colors">
                                            <div>
                                                <p class="font-bold text-slate-800 text-sm"><?= htmlspecialchars($ru['first_name'] . ' ' . $ru['last_name']) ?></p>
                                                <p class="text-[10px] text-slate-500 font-medium uppercase tracking-wider">Role: <?= htmlspecialchars($ru['role']) ?></p>
                                            </div>
                                            <div class="text-right">
                                                <span class="text-[9px] font-black text-slate-600 bg-slate-100 px-2 py-1 rounded uppercase tracking-widest truncate max-w-[120px] inline-block"><?= htmlspecialchars($ru['clinic_name'] ?? 'N/A') ?></span>
                                                <p class="text-[9px] text-slate-400 font-bold mt-1"><?= date('M d, Y', strtotime($ru['created_at'])) ?></p>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<script>
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
        setTimeout(() => { window.location.href = 'systemreports.php?action=logout'; }, 1500); 
    }
</script>
</body>
</html>