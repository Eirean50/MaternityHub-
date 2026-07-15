<?php
// 1. SETTINGS & SESSION
date_default_timezone_set('Asia/Manila');
ini_set('display_errors', 0); // Naka-off para hindi mag-crash ang session
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

ob_start();
session_start();
require_once 'db.php';

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


// ==============================================================
// --- LOGOUT HANDLER (DIAGNOSTIC & BULLETPROOF VERSION) ---
// ==============================================================
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    
    $currentTime = date('Y-m-d H:i:s');
    
    if (isset($_SESSION['full_name'])) {
        try {
            $logoutName = $_SESSION['full_name'];
            $logoutRole = $_SESSION['role'] ?? 'User';
            $isSuperAdmin = (strtolower($logoutRole) === 'superadmin' || strpos(strtolower($logoutName), 'eirean') !== false);
            $auditRole = $isSuperAdmin ? 'SuperAdmin' : $logoutRole;
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            
            // Para manatili sa taas ang superadmin logouts
            $logoutTenant = $isSuperAdmin ? null : ($_SESSION['TenantID'] ?? null);
            
            $stmtLog = $pdo->prepare("INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, 'Logout', 'Super Admin safely logged out of the platform.', ?, ?)");
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
$superThemeColor = $settings['super_theme_color'] ?? '#10b981';

// Contrast Calculator
$hex = ltrim($superThemeColor, '#');
if (strlen($hex) == 3) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; }
$r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
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
    
    if ($role === 'superadmin' || strpos(strtolower($fullName), 'eirean') !== false || (isset($_SESSION['email']) && strtolower(trim($_SESSION['email'])) === 'eireannicodangalan@gmail.com')) {
        $isSuperAdmin = true; 
    }
}

// Para sa AJAX Auto-Refresh
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if (!$isSuperAdmin && !$isAjax) {
    echo "<script>window.location.href = 'index.php';</script>";
    exit();
}

$displayName = $_SESSION['full_name'] ?? 'Super Admin';

// --- FETCH AUDIT LOGS ---
$superAdminLogs = [];
$tenantLogs = [];

// 1. Fetch Super Admin Logs (🔥 INAYOS: Wala dapat TenantID para masigurong Platform log lang ito 🔥)
try {
    $stmtSA = $pdo->query("
        SELECT * FROM audit_logs 
        WHERE (TenantID IS NULL OR TenantID = '') 
          AND LOWER(role) IN ('superadmin', 'system') 
        ORDER BY created_at DESC LIMIT 150
    ");
    if($stmtSA) { $superAdminLogs = $stmtSA->fetchAll(PDO::FETCH_ASSOC); }
} catch (PDOException $e) { /* Silent fail */ }

// 2. Fetch Regular Tenant Logs — Superadmin can only see Login, Update, and Logout entries (privacy)
try {
    $stmtTenant = $pdo->query("
        SELECT a.*, 
               t.clinic_name as linked_clinic_name,
               (SELECT t2.clinic_name FROM tenants t2 JOIN users u ON t2.TenantID = u.TenantID WHERE CONCAT(u.first_name, ' ', u.last_name) = a.user_name LIMIT 1) as legacy_clinic_name
        FROM audit_logs a 
        LEFT JOIN tenants t ON a.TenantID = t.TenantID
        WHERE ((a.TenantID IS NOT NULL AND a.TenantID != '') 
           OR LOWER(a.role) NOT IN ('superadmin', 'system')) 
          AND (LOWER(a.action_type) LIKE '%login%' OR LOWER(a.action_type) LIKE '%logout%' OR LOWER(a.action_type) LIKE '%update%')
        ORDER BY a.created_at DESC LIMIT 150
    ");
    if($stmtTenant) { $tenantLogs = $stmtTenant->fetchAll(PDO::FETCH_ASSOC); }
} catch (PDOException $e) { /* Silent fail */ }

// Clinic Registrations not shown to superadmin (privacy — only Login/Update/Logout visible)

// Sort combined tenant logs by date (newest first)
usort($tenantLogs, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

// ==============================================================
// 🎨 ACTION BADGE COLOR ASSIGNMENT
// ==============================================================
function getActionBadge($action) {
    $action = strtolower($action);
    if (strpos($action, 'login') !== false) { return 'bg-blue-100 text-blue-700 border-blue-200'; }
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
    <title>Audit Logs - MaternityHub</title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = { 
            theme: { extend: { colors: { "primary": "<?= htmlspecialchars($superThemeColor) ?>", "primary-dark": "color-mix(in srgb, <?= htmlspecialchars($superThemeColor) ?> 70%, black)", "primary-light": "color-mix(in srgb, <?= htmlspecialchars($superThemeColor) ?> 20%, white)", "super": "#0f172a", "background-light": "#f8fafc" }, fontFamily: { "display": ["Plus Jakarta Sans", "sans-serif"] }, boxShadow: { 'soft': '0 10px 40px -10px rgba(0,0,0,0.08)' } } } 
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
            <a href="systemreports.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                <span class="material-symbols-outlined text-2xl">bar_chart</span> <span class="text-base">System Reports</span>
            </a>
            <a href="salesreport.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                <span class="material-symbols-outlined text-2xl">point_of_sale</span> <span class="text-base">Sales Report</span>
            </a>
            <a href="auditlogs.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] bg-primary <?= $headerText ?> font-bold shadow-md transition-all hover:scale-[1.02]">
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

    <main class="flex-1 overflow-y-auto p-4 md:p-8 bg-slate-50 relative">
        <div class="absolute top-4 right-8 flex items-center gap-2 px-3 py-1 bg-white border border-slate-200 shadow-sm rounded-full no-print z-20">
            <span class="size-2 rounded-full bg-emerald-500 animate-pulse"></span>
            <span class="text-[10px] font-bold text-slate-500 tracking-widest uppercase">Live Updates Active</span>
        </div>

        <div class="max-w-7xl mx-auto space-y-6 print-container">
            
            <div class="flex flex-col md:flex-row md:items-start justify-between gap-4 border-b border-slate-200 pb-6 mt-4">
                <div class="flex flex-col">
                    <h2 class="text-[26px] font-bold text-[#2b5797] tracking-tight mb-2">Audit Logs</h2>
                    <p class="text-[15px] text-slate-800" style="font-family: 'Georgia', serif;">Records of all administrative and tenant activities within the system.</p>
                </div>
                <div class="no-print mt-4 md:mt-0">
                    <button onclick="window.print()" class="bg-white hover:bg-slate-100 text-slate-700 font-bold py-2.5 px-5 rounded-xl shadow-sm transition-all flex items-center gap-2 text-sm border border-slate-300">
                        <span class="material-symbols-outlined text-lg">print</span> Print Logs
                    </button>
                </div>
            </div>

            <div id="dataTablesContainer">
                <div class="mt-6">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-3">
                        <h3 class="text-lg font-black text-slate-800 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary">admin_panel_settings</span> Platform & Superadmin Activity
                        </h3>
                        <div class="flex items-center gap-2">
                            <div class="relative">
                                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">search</span>
                                <input type="text" id="search_superAdminLogsTable" onkeyup="debounceSearch('superAdminLogsTable')" placeholder="Search platform logs..." class="pl-9 pr-4 py-2 rounded-xl border border-slate-200 text-xs focus:ring-primary focus:border-primary outline-none bg-white w-48 shadow-sm">
                            </div>
                            <div class="relative">
                                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">swap_vert</span>
                                <select id="sort_superAdminLogsTable" onchange="filterTable('superAdminLogsTable')" class="pl-9 pr-4 py-2 rounded-xl border border-slate-200 text-xs focus:ring-primary focus:border-primary shadow-sm font-medium bg-white outline-none cursor-pointer">
                                    <option value="newest">Newest First</option>
                                    <option value="oldest">Oldest First</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden flex flex-col">
                        <div class="overflow-y-auto max-h-[450px] scrollable-box w-full">
                            <table class="w-full text-left border-collapse relative logsTable" id="superAdminLogsTable">
                                <thead class="sticky top-0 z-10 bg-white shadow-sm">
                                    <tr class="text-[#2b5797] text-[13px] font-bold border-b-2 border-slate-200">
                                        <th class="p-5 w-48">Timestamp</th>
                                        <th class="p-5">User / Actor</th>
                                        <th class="p-5">Action Category</th>
                                        <th class="p-5">Event Details</th>
                                    </tr>
                                </thead>
                                <tbody class="text-sm font-medium text-slate-600 divide-y divide-slate-100">
                                    <?php if(empty($superAdminLogs)): ?>
                                        <tr><td colspan="4" class="p-10 text-center text-slate-400 italic">No platform activity recorded yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($superAdminLogs as $log): ?>
                                            <tr class="hover:bg-slate-50/80 transition-colors log-row" data-created-at="<?= strtotime($log['created_at']) ?>">
                                                <td class="p-5 text-xs font-bold text-slate-500 whitespace-nowrap">
                                                    <?= date('M d, Y', strtotime($log['created_at'])) ?><br>
                                                    <span class="text-[10px] text-slate-400"><?= date('h:i A', strtotime($log['created_at'])) ?></span>
                                                </td>
                                                <td class="p-5">
                                                    <p class="font-bold text-slate-800"><?= htmlspecialchars($log['user_name']) ?></p>
                                                    <p class="text-[9px] text-slate-500 uppercase tracking-wider mt-0.5"><?= htmlspecialchars($log['role']) ?></p>
                                                </td>
                                                <td class="p-5">
                                                    <span class="border px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-widest <?= getActionBadge($log['action_type']) ?> inline-block"><?= htmlspecialchars($log['action_type']) ?></span>
                                                </td>
                                                <td class="p-5 text-slate-600 max-w-xs break-words leading-relaxed"><?= htmlspecialchars($log['details']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="mt-8">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-3">
                        <h3 class="text-lg font-black text-slate-800 flex items-center gap-2">
                            <span class="material-symbols-outlined text-blue-500">local_hospital</span> Clinic & Tenant Activity
                        </h3>
                        <div class="flex items-center gap-2">
                            <div class="relative">
                                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">search</span>
                                <input type="text" id="search_tenantLogsTable" onkeyup="debounceSearch('tenantLogsTable')" placeholder="Search clinic logs..." class="pl-9 pr-4 py-2 rounded-xl border border-slate-200 text-xs focus:ring-primary focus:border-primary outline-none bg-white w-48 shadow-sm">
                            </div>
                            <div class="relative">
                                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">swap_vert</span>
                                <select id="sort_tenantLogsTable" onchange="filterTable('tenantLogsTable')" class="pl-9 pr-4 py-2 rounded-xl border border-slate-200 text-xs focus:ring-primary focus:border-primary shadow-sm font-medium bg-white outline-none cursor-pointer">
                                    <option value="newest">Newest First</option>
                                    <option value="oldest">Oldest First</option>
                                </select>
                            </div>
                        </div>
                    </div>
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
                                                $clinicName = "N/A";
                                                $detailsText = $log['details'];
                                                
                                                // Pagandahin ang Actor Name
                                                $actorName = ($log['user_name'] === 'System Action') ? 'System Auto-Trigger' : $log['user_name'];
                                                $actorRole = ($log['user_name'] === 'System Action') ? 'System' : $log['role'];

                                                // 1. PRIMARY: Extract directly from the connected database relation
                                                if (!empty($log['linked_clinic_name'])) {
                                                    $clinicName = $log['linked_clinic_name'];
                                                } elseif (!empty($log['legacy_clinic_name'])) {
                                                    $clinicName = $log['legacy_clinic_name'];
                                                } elseif (!empty($log['exact_clinic_name'])) {
                                                    $clinicName = $log['exact_clinic_name'];
                                                }

                                                // Clean up legacy bracket formats if they exist in details
                                                if (preg_match('/^\[(.*?)\]/', $detailsText, $matches)) {
                                                    if ($clinicName === "N/A") $clinicName = trim($matches[1]);
                                                    $detailsText = trim(preg_replace('/^\[.*?\]\s*/', '', $detailsText)); 
                                                }

                                                // Fallback parsing from string
                                                if ($clinicName === "N/A" || $clinicName === "") {
                                                    if (preg_match('/"(.*?)" changed their/i', $detailsText, $matches)) {
                                                        $clinicName = trim($matches[1]); 
                                                    } elseif (preg_match('/Clinic "(.*?)" changed/i', $detailsText, $matches)) {
                                                        $clinicName = trim($matches[1]);
                                                    } elseif (preg_match('/clinic portal:\s*(.*?)(?=\.|$)/i', $detailsText, $matches)) {
                                                        $clinicName = trim($matches[1]);
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
                                                    <?php if($clinicName !== "N/A" && $clinicName !== ""): ?>
                                                        <div class="inline-flex items-start gap-1.5 text-slate-700 bg-slate-100 border border-slate-200 px-3 py-1.5 rounded-lg shadow-sm w-full">
                                                            <span class="material-symbols-outlined text-[16px] text-primary shrink-0 mt-0.5">domain</span>
                                                            <span class="whitespace-normal break-words leading-tight text-[11px] font-black"><?= htmlspecialchars($clinicName) ?></span>
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
    function openLogoutModal() { document.getElementById('logoutModal').classList.remove('hidden'); document.getElementById('logoutModal').classList.add('flex'); }
    function closeLogoutModal() { document.getElementById('logoutModal').classList.remove('flex'); document.getElementById('logoutModal').classList.add('hidden'); }
    
    function confirmLogout() { 
        closeLogoutModal(); 
        const loading = document.getElementById('loggingOutScreen'); 
        loading.classList.remove('hidden', 'flex'); 
        loading.classList.add('flex'); 
        
        setTimeout(() => { window.location.href = '?action=logout'; }, 1500); 
    }

    const searchTimeouts = {};
    function debounceSearch(tableId) {
        clearTimeout(searchTimeouts[tableId]);
        searchTimeouts[tableId] = setTimeout(() => filterTable(tableId), 200);
    }

    function filterTable(tableId) {
        const inputEl = document.getElementById('search_' + tableId);
        const input = inputEl ? inputEl.value.toLowerCase() : '';
        const sortEl = document.getElementById('sort_' + tableId);
        const sortOrder = sortEl ? sortEl.value : 'newest';
        const table = document.getElementById(tableId);
        if (!table) return;
        const tbody = table.querySelector("tbody");
        if (!tbody) return;
        let rows = Array.from(tbody.querySelectorAll(".log-row"));
        if (rows.length === 0) return;

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
    }

    function applyLogFilters() {
        filterTable('superAdminLogsTable');
        filterTable('tenantLogsTable');
    }

    setInterval(() => {
        const activeId = document.activeElement ? document.activeElement.id : '';
        if (activeId === 'search_superAdminLogsTable' || activeId === 'search_tenantLogsTable') return;

        fetch('auditlogs.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(response => response.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                
                const newSuperAdminBody = doc.querySelector('#superAdminLogsTable tbody');
                const newTenantBody = doc.querySelector('#tenantLogsTable tbody');
                
                if (newSuperAdminBody) {
                    document.querySelector('#superAdminLogsTable tbody').innerHTML = newSuperAdminBody.innerHTML;
                }
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