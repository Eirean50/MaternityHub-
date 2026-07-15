<?php
// 1. SETTINGS & SESSION
date_default_timezone_set('Asia/Manila');
ob_start();
ini_set('display_errors', 0); // Tinanggal natin ang errors sa UI para iwas crash
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
    } catch (Exception $e) {
        // Silent fail
    }
}
// ==============================================================

// ==============================================================
// --- LOGOUT HANDLER (BULLETPROOF VERSION) ---
// ==============================================================
// TAMA NA ANG TAWAG DITO: Hahanapin niya ang ?logout=1 mula sa button
if (isset($_GET['logout'])) {
    // Kunin agad ang session data o mag-fallback para di ma-skip
    $logoutName = $_SESSION['full_name'] ?? 'Super Admin';
    $logoutRole = $_SESSION['role'] ?? 'SuperAdmin';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $currentTime = date('Y-m-d H:i:s');

    try {
        // Insert diretso sa table natin
        $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, 'Logout', 'Super Admin safely logged out of the platform.', ?, ?)");
        $stmtLog->execute([$logoutName, $logoutRole, $ip, $currentTime]);

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

        // JS Redirect (Para iwas Headers Already Sent error na nagpapa-crash!)
        echo "<script>window.location.href = 'index.php';</script>";
        exit();

    } catch (Exception $e) {
        die("<div style='background:black; color:red; padding:50px; text-align:center; font-size:24px; font-family:sans-serif;'><b>DATABASE ERROR SA LOGOUT:</b><br><br>" . $e->getMessage() . "</div>");
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
// ==============================================================
$hex = ltrim($superThemeColor, '#');
if (strlen($hex) == 3) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; }
$r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
$luminance = ($r * 0.299) + ($g * 0.587) + ($b * 0.114);

$isLightTheme = ($luminance > 150);

$headerText = $isLightTheme ? 'text-slate-900' : 'text-white';
$headerBgOp = $isLightTheme ? 'bg-slate-900/10' : 'bg-white/10';
$headerBorderOp = $isLightTheme ? 'border-slate-900/20' : 'border-white/20';
$headerHoverOp = $isLightTheme ? 'hover:bg-slate-900/20' : 'hover:bg-white/20';
$badgeTheme = $isLightTheme ? 'text-amber-800 bg-amber-400/40 border-amber-500/30' : 'text-amber-300 bg-amber-400/20 border-amber-300/30';

// --- SUPER ADMIN SECURITY CHECK ---
$isSuperAdmin = false;
if (isset($_SESSION['user_id'])) {
    $role = strtolower(trim($_SESSION['role'] ?? ''));
    $fullName = $_SESSION['full_name'] ?? '';
    
    if ($role === 'superadmin' || strpos(strtolower($fullName), 'eirean') !== false || (isset($_SESSION['email']) && strtolower(trim($_SESSION['email'])) === 'eireannicodangalan@gmail.com')) {
        $isSuperAdmin = true; 
    }
}

if (!$isSuperAdmin) {
    echo "<script>window.location.href = 'index.php';</script>";
    exit();
}

$displayName = $_SESSION['full_name'] ?? 'Super Admin';
$currentEmail = strtolower(trim($_SESSION['email'] ?? ''));

$error = null;
$msg = $_GET['msg'] ?? null;

// =========================================================================
// FETCH REAL SALES DATA FROM TENANTS TABLE WITH FILTER LOGIC
// =========================================================================
$filter = $_GET['period'] ?? 'all';
$filterLabel = 'All Time';
$currentYear = date('Y');
$specificMonthFilter = null;

if ($filter === 'daily') {
    $filterLabel = 'Daily (Today)';
} elseif ($filter === 'weekly') {
    $filterLabel = 'Weekly (This Week)';
} elseif ($filter === 'monthly') {
    $filterLabel = 'Monthly (This Month)';
} elseif (in_array($filter, ['01','02','03','04','05','06','07','08','09','10','11','12'])) {
    $monthName = date('F', mktime(0, 0, 0, $filter, 10));
    $filterLabel = $monthName . ' ' . $currentYear;
    $specificMonthFilter = $currentYear . '-' . $filter;
}

$today = date('Y-m-d');
$dayOfWeek = date('N');
$mondayThisWeek = date('Y-m-d', strtotime('-' . ($dayOfWeek - 1) . ' days'));
$sundayThisWeek = date('Y-m-d', strtotime('+' . (7 - $dayOfWeek) . ' days'));
$currentMonth = date('Y-m');

$transactions = [];
$filteredTotalRev = 0;

$metrics = [
    'daily' => 0, 
    'weekly' => 0, 
    'monthly' => 0, 
    'total' => 0,
    'active_plans' => 0,
    'success_rate' => 100,
    'txn_total' => 0,
    'txn_success' => 0,
    'txn_failed_pending' => 0
]; 

$chartData = ['labels' => [], 'values' => []];
$dbError = null;

// AUTO-FIX: subscription_payments ledger (one row per paid transaction \u2014 initial signup AND every renewal)
try { $pdo->query("SELECT 1 FROM subscription_payments LIMIT 1"); }
catch (PDOException $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS subscription_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            TenantID VARCHAR(50) NOT NULL,
            user_id INT NULL,
            payer_name VARCHAR(150) NULL,
            plan VARCHAR(50) DEFAULT 'Standard',
            amount DECIMAL(10,2) NOT NULL,
            payment_type VARCHAR(20) NOT NULL DEFAULT 'initial',
            paid_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tenant (TenantID),
            INDEX idx_paid_at (paid_at)
        )");
    } catch (PDOException $ex) {}
}

// BACKFILL: ensure every previously-paid tenant has at least one 'initial' ledger entry
// so legacy data still appears in the sales report alongside renewals going forward.
try {
    $stmtBackfill = $pdo->query("
        SELECT t.TenantID, t.plan, t.created_at
        FROM tenants t
        LEFT JOIN subscription_payments sp ON sp.TenantID = t.TenantID
        WHERE t.status IN ('Active', 'Suspended', 'Rejected', 'Expired', 'Archived')
          AND sp.id IS NULL
    ");
    if ($stmtBackfill) {
        $missing = $stmtBackfill->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($missing)) {
            $insBackfill = $pdo->prepare("INSERT INTO subscription_payments (TenantID, user_id, payer_name, plan, amount, payment_type, paid_at) VALUES (?, NULL, NULL, ?, ?, 'initial', ?)");
            // Resolve amount via the same plan map used by registration.php / expire.php
            $planMapBF = function($plan) {
                $p = strtolower(trim((string)$plan));
                if (in_array($p, ['semi-annual','semi annual','semiannual','semi'], true)) return ['Semi-Annual', 13499];
                if (in_array($p, ['annual','yearly','year'], true))                       return ['Annual', 24999];
                return ['Monthly', 2499];
            };
            foreach ($missing as $mt) {
                [$bPlanLabel, $bAmount] = $planMapBF($mt['plan'] ?? '');
                try { $insBackfill->execute([$mt['TenantID'], $bPlanLabel, $bAmount, $mt['created_at']]); } catch (PDOException $e) {}
            }
        }
    }
} catch (PDOException $e) { /* silent */ }

try {
    // Pull every payment (joined with tenant for clinic name + status)
    $stmtPayments = $pdo->query("
        SELECT sp.id AS payment_id, sp.TenantID, sp.amount, sp.plan, sp.payment_type, sp.paid_at, sp.payer_name,
               t.clinic_name, t.status AS tenant_status
        FROM subscription_payments sp
        LEFT JOIN tenants t ON t.TenantID = sp.TenantID
        ORDER BY sp.paid_at DESC
    ");
    $allPayments = $stmtPayments->fetchAll(PDO::FETCH_ASSOC);

    // Active plan count (one per tenant, current state)
    try { $metrics['active_plans'] = (int) $pdo->query("SELECT COUNT(*) FROM tenants WHERE status = 'Active'")->fetchColumn(); } catch (PDOException $e) {}

    $overallTxnTotal = count($allPayments);
    $overallSuccess  = $overallTxnTotal; // every ledger row is a successful payment

    foreach ($allPayments as $p) {
        $plan        = $p['plan'] ?? 'Standard';
        $amount      = (float) $p['amount'];
        $txnDateStr  = $p['paid_at'];
        $txnDateOnly = date('Y-m-d', strtotime($txnDateStr));
        $txnMonthOnly= date('Y-m', strtotime($txnDateStr));

        $statusBadge = 'Paid';
        $metrics['total'] += $amount;
        if ($txnDateOnly === $today)                                                $metrics['daily']   += $amount;
        if ($txnDateOnly >= $mondayThisWeek && $txnDateOnly <= $sundayThisWeek)     $metrics['weekly']  += $amount;
        if ($txnMonthOnly === $currentMonth)                                        $metrics['monthly'] += $amount;

        $includeRecord = false;
        if ($filter === 'daily' && $txnDateOnly === $today) $includeRecord = true;
        elseif ($filter === 'weekly' && $txnDateOnly >= $mondayThisWeek && $txnDateOnly <= $sundayThisWeek) $includeRecord = true;
        elseif ($filter === 'monthly' && $txnMonthOnly === $currentMonth) $includeRecord = true;
        elseif ($specificMonthFilter !== null && $txnMonthOnly === $specificMonthFilter) $includeRecord = true;
        elseif ($filter === 'all') $includeRecord = true;

        if ($includeRecord) {
            $metrics['txn_total']++;
            $metrics['txn_success']++;
            $filteredTotalRev += $amount;

            $isRenewal = (strtolower((string)($p['payment_type'] ?? 'initial')) === 'renewal');
            $txnId = ($isRenewal ? 'REN-' : 'SUB-') . $p['TenantID'] . '-' . $p['payment_id'];

            $transactions[] = [
                'id' => $txnId,
                'clinic' => $p['clinic_name'] ?? ('Tenant ' . $p['TenantID']),
                'plan' => ucfirst($plan) . ($isRenewal ? ' (Renewal)' : ''),
                'amount' => $amount,
                'date' => $txnDateStr,
                'status' => $statusBadge
            ];
        }
    }

    $metrics['success_rate'] = $overallTxnTotal > 0 ? ($overallSuccess / $overallTxnTotal) * 100 : 100;

    // 6-month cumulative revenue chart based on ledger
    $cumulativeRev = 0;
    try {
        $stmtPast = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM subscription_payments WHERE paid_at < ?");
        $stmtPast->execute([date('Y-m-01', strtotime("-5 months"))]);
        $cumulativeRev = (float) $stmtPast->fetchColumn();
    } catch (PDOException $e) {}

    for ($i = 5; $i >= 0; $i--) {
        $m = (int)date('m', strtotime("-$i months"));
        $y = (int)date('Y', strtotime("-$i months"));
        $monthName = date('M', strtotime("-$i months"));

        $revThisMonth = 0;
        try {
            $stmtRev = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM subscription_payments WHERE MONTH(paid_at) = ? AND YEAR(paid_at) = ?");
            $stmtRev->execute([$m, $y]);
            $revThisMonth = (float) $stmtRev->fetchColumn();
        } catch (PDOException $e) {}

        $cumulativeRev += $revThisMonth;
        $chartData['labels'][] = $monthName;
        $chartData['values'][] = $cumulativeRev;
    }

} catch (PDOException $e) {
    $dbError = "Database Error: " . $e->getMessage();
}

function getStatusBadge($status) {
    if ($status === 'Paid') return 'text-emerald-700 font-bold';
    if ($status === 'Failed' || $status === 'Cancelled') return 'text-red-700 font-bold';
    return 'text-amber-600 font-bold'; // Pending
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Sales Report - MaternityHub</title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
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
        
        /* Print optimizations */
        @media print { 
            aside, header, .no-print, #logoutModal, #loggingOutScreen { display: none !important; } 
            main { padding: 0 !important; margin: 0 !important; background: white !important; overflow: visible !important; } 
            .print-container { width: 100% !important; border: none !important; box-shadow: none !important; max-width: 100% !important; } 
            .scrollable-box { max-height: none !important; overflow: visible !important; } 
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
            <a href="systemreports.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                <span class="material-symbols-outlined text-2xl">bar_chart</span> <span class="text-base">System Reports</span>
            </a>
            <a href="salesreport.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] bg-primary <?= $headerText ?> font-bold shadow-md transition-all hover:scale-[1.02]">
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

    <main class="flex-1 overflow-y-auto p-4 md:p-8 bg-slate-50 relative">
        <div id="printableReportArea" class="max-w-7xl mx-auto space-y-6 print-container pb-20 bg-slate-50 p-2">
            
            <?php if($dbError): ?>
                <div class="p-4 rounded-xl text-sm font-bold flex items-center gap-3 bg-amber-100 text-amber-800 border border-amber-200 no-print">
                    <span class="material-symbols-outlined text-xl">info</span> <?= htmlspecialchars($dbError) ?>
                </div>
            <?php endif; ?>

            <div class="flex flex-col md:flex-row md:items-start justify-between gap-4 border-b border-slate-200 pb-6">
                <div class="flex flex-col">
                    <h2 class="text-[26px] font-bold text-[#2b5797] tracking-tight mb-2">Platform Sales & Revenue</h2>
                    <p class="text-[15px] text-slate-800" style="font-family: 'Georgia', serif;">Monitor subscription earnings, active clinic plans, and transaction history.</p>
                </div>
                <div class="no-print mt-4 md:mt-0 flex gap-3">
                    <button onclick="window.print()" class="bg-white hover:bg-slate-100 text-slate-700 font-bold py-2.5 px-5 rounded-xl shadow-sm transition-all flex items-center gap-2 text-sm border border-slate-300">
                        <span class="material-symbols-outlined text-lg">print</span> Print
                    </button>
                    <button onclick="generatePDF()" class="bg-primary hover:bg-primary-dark text-white font-bold py-2.5 px-5 rounded-xl shadow-md transition-all flex items-center gap-2 text-sm border border-primary-dark/20">
                        <span class="material-symbols-outlined text-lg">picture_as_pdf</span> Save as PDF
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mt-2">
                <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm flex flex-col justify-between">
                    <div class="flex items-center justify-between mb-4">
                        <div class="size-12 rounded-xl bg-blue-50 text-blue-500 flex items-center justify-center"><span class="material-symbols-outlined">today</span></div>
                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Today</span>
                    </div>
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-widest mb-1">Daily Sales</p>
                    <h3 class="text-3xl font-black text-slate-800 tracking-tighter leading-none">₱<?= number_format($metrics['daily'], 2) ?></h3>
                </div>

                <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm flex flex-col justify-between">
                    <div class="flex items-center justify-between mb-4">
                        <div class="size-12 rounded-xl bg-amber-50 text-amber-500 flex items-center justify-center"><span class="material-symbols-outlined">date_range</span></div>
                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">This Week</span>
                    </div>
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-widest mb-1">Weekly Sales</p>
                    <h3 class="text-3xl font-black text-slate-800 tracking-tighter leading-none">₱<?= number_format($metrics['weekly'], 2) ?></h3>
                </div>

                <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm flex flex-col justify-between">
                    <div class="flex items-center justify-between mb-4">
                        <div class="size-12 rounded-xl bg-purple-50 text-purple-500 flex items-center justify-center"><span class="material-symbols-outlined">calendar_month</span></div>
                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">This Month</span>
                    </div>
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-widest mb-1">Monthly Sales</p>
                    <h3 class="text-3xl font-black text-slate-800 tracking-tighter leading-none">₱<?= number_format($metrics['monthly'], 2) ?></h3>
                </div>

                <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm flex flex-col justify-between relative overflow-hidden">
                    <div class="absolute inset-0 bg-primary/5"></div>
                    <div class="relative z-10 flex items-center justify-between mb-4">
                        <div class="size-12 rounded-xl bg-emerald-50 text-emerald-500 flex items-center justify-center"><span class="material-symbols-outlined">payments</span></div>
                        <span class="text-[10px] font-black text-emerald-600 uppercase tracking-widest bg-emerald-100 px-2 py-1 rounded-md">Lifetime</span>
                    </div>
                    <p class="relative z-10 text-slate-500 text-[10px] font-black uppercase tracking-widest mb-1">Total Earnings</p>
                    <h3 class="relative z-10 text-3xl font-black text-emerald-600 tracking-tighter leading-none">₱<?= number_format($metrics['total'], 2) ?></h3>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-6">
                <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm col-span-1 lg:col-span-3">
                    <div class="flex justify-between items-center mb-6">
                        <div class="flex items-center gap-2">
                            <div class="size-8 bg-primary/10 text-primary rounded-lg flex items-center justify-center"><span class="material-symbols-outlined text-sm">trending_up</span></div>
                            <h3 class="text-base font-black text-slate-800 uppercase tracking-widest">Revenue Growth</h3>
                        </div>
                        <span class="text-[10px] font-bold text-slate-400 bg-slate-100 px-3 py-1.5 rounded-md">Last 6 Months (Cumulative)</span>
                    </div>
                    <div class="relative h-[300px] w-full"><canvas id="revenueChart"></canvas></div>
                </div>

                <div class="bg-white rounded-[1.5rem] border border-slate-200 shadow-sm overflow-hidden flex flex-col col-span-1 lg:col-span-3 mb-10">
                    
                    <div class="p-6 border-b border-slate-300 bg-slate-50 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div>
                            <h3 class="text-xl font-black text-slate-800 flex items-center gap-2">
                                <span class="material-symbols-outlined text-slate-500">receipt_long</span> Transaction Ledger
                            </h3>
                            <p class="text-xs text-slate-500 mt-1 font-medium">Detailed financial record of all clinic subscriptions.</p>
                        </div>
                        
                        <div class="flex items-center gap-3 w-full sm:w-auto no-print">
                            <form method="GET" action="salesreport.php" class="w-full sm:w-auto">
                                <select name="period" onchange="this.form.submit()" class="w-full rounded-md border border-slate-300 text-xs focus:ring-primary focus:border-primary outline-none bg-white font-bold text-slate-600 cursor-pointer py-2 pl-3 pr-8 shadow-sm">
                                    <optgroup label="Quick Filters">
                                        <option value="all" <?= $filter == 'all' ? 'selected' : '' ?>>All Time</option>
                                        <option value="daily" <?= $filter == 'daily' ? 'selected' : '' ?>>Today (Daily)</option>
                                        <option value="weekly" <?= $filter == 'weekly' ? 'selected' : '' ?>>This Week (Weekly)</option>
                                        <option value="monthly" <?= $filter == 'monthly' ? 'selected' : '' ?>>This Month</option>
                                    </optgroup>
                                    <optgroup label="Filter by Month (<?= $currentYear ?>)">
                                        <option value="01" <?= $filter == '01' ? 'selected' : '' ?>>January</option>
                                        <option value="02" <?= $filter == '02' ? 'selected' : '' ?>>February</option>
                                        <option value="03" <?= $filter == '03' ? 'selected' : '' ?>>March</option>
                                        <option value="04" <?= $filter == '04' ? 'selected' : '' ?>>April</option>
                                        <option value="05" <?= $filter == '05' ? 'selected' : '' ?>>May</option>
                                        <option value="06" <?= $filter == '06' ? 'selected' : '' ?>>June</option>
                                        <option value="07" <?= $filter == '07' ? 'selected' : '' ?>>July</option>
                                        <option value="08" <?= $filter == '08' ? 'selected' : '' ?>>August</option>
                                        <option value="09" <?= $filter == '09' ? 'selected' : '' ?>>September</option>
                                        <option value="10" <?= $filter == '10' ? 'selected' : '' ?>>October</option>
                                        <option value="11" <?= $filter == '11' ? 'selected' : '' ?>>November</option>
                                        <option value="12" <?= $filter == '12' ? 'selected' : '' ?>>December</option>
                                    </optgroup>
                                </select>
                            </form>
                            
                            <div class="relative w-full sm:max-w-xs">
                                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">search</span>
                                <input type="text" id="txnSearch" onkeyup="filterTransactions()" placeholder="Search record..." class="w-full pl-9 pr-4 py-2 rounded-md border border-slate-300 text-xs focus:ring-primary focus:border-primary outline-none bg-white shadow-inner">
                            </div>
                        </div>
                    </div>

                    <div class="border-b border-slate-300 bg-white">
                        <div class="p-4 text-center">
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Total Transactions</p>
                            <p class="text-2xl font-black text-slate-800 leading-none"><?= number_format($metrics['txn_total']) ?></p>
                        </div>
                    </div>
                    
                    <div class="overflow-x-auto overflow-y-auto max-h-[600px] scrollable-box w-full">
                        <table class="w-full text-left border-collapse bg-white" id="txnTable" style="min-width: 800px;">
                            <thead class="sticky top-0 z-10 bg-slate-200">
                                <tr>
                                    <th class="border border-slate-300 p-3 text-xs font-black text-slate-700 uppercase tracking-wider whitespace-nowrap">Transaction ID</th>
                                    <th class="border border-slate-300 p-3 text-xs font-black text-slate-700 uppercase tracking-wider whitespace-nowrap">Date / Time</th>
                                    <th class="border border-slate-300 p-3 text-xs font-black text-slate-700 uppercase tracking-wider">Clinic Name</th>
                                    <th class="border border-slate-300 p-3 text-xs font-black text-slate-700 uppercase tracking-wider whitespace-nowrap">Plan / Type</th>
                                    <th class="border border-slate-300 p-3 text-xs font-black text-slate-700 uppercase tracking-wider text-right">Amount (₱)</th>
                                    <th class="border border-slate-300 p-3 text-xs font-black text-slate-700 uppercase tracking-wider text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="text-sm text-slate-800">
                                <?php if(empty($transactions)): ?>
                                    <tr><td colspan="6" class="p-8 text-center text-slate-500 italic border border-slate-300">No financial transactions found for this period.</td></tr>
                                <?php else: ?>
                                    <?php foreach($transactions as $txn): ?>
                                        <tr class="hover:bg-blue-50/50 even:bg-slate-50/70 transition-colors txn-row">
                                            <td class="border border-slate-300 p-2 font-mono text-[12px] font-bold text-slate-700 whitespace-nowrap">
                                                <?= htmlspecialchars($txn['id']) ?>
                                            </td>
                                            <td class="border border-slate-300 p-2 text-[12px] text-slate-600 whitespace-nowrap">
                                                <?= date('m/d/Y - h:i A', strtotime($txn['date'])) ?>
                                            </td>
                                            <td class="border border-slate-300 p-2 text-[13px] font-bold text-slate-900">
                                                <?= htmlspecialchars($txn['clinic']) ?>
                                            </td>
                                            <td class="border border-slate-300 p-2 text-[11px] whitespace-nowrap">
                                                <?php $isRenewalRow = (stripos($txn['plan'], 'renewal') !== false); ?>
                                                <span class="inline-block px-2 py-1 rounded-md font-black uppercase tracking-widest <?= $isRenewalRow ? 'bg-emerald-100 text-emerald-700 border border-emerald-200' : 'bg-blue-100 text-blue-700 border border-blue-200' ?>"><?= htmlspecialchars($txn['plan']) ?></span>
                                            </td>
                                            <td class="border border-slate-300 p-2 text-[14px] font-black text-slate-900 text-right tracking-tight">
                                                <?= number_format($txn['amount'], 2) ?>
                                            </td>
                                            <td class="border border-slate-300 p-2 text-[11px] text-center uppercase tracking-widest <?= getStatusBadge($txn['status']) ?>">
                                                <?= htmlspecialchars($txn['status']) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="bg-slate-200 sticky bottom-0 z-10">
                                <tr>
                                    <td colspan="4" class="border border-slate-300 p-4 text-right font-black text-slate-700 text-[11px] uppercase tracking-widest">
                                        Total Processed Revenue <?= $filter !== 'all' ? '(' . $filterLabel . ')' : '' ?>:
                                    </td>
                                    <td class="border border-slate-300 p-4 text-right font-black text-primary-dark text-lg tracking-tight">
                                        ₱<?= number_format($filteredTotalRev, 2) ?>
                                    </td>
                                    <td class="border border-slate-300 bg-slate-200"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

            </div>

        </div>
    </main>
</div>

<div id="pdfExportContainer" style="position: absolute; top: -9999px; left: -9999px; width: 1050px; background: white; z-index: -1;">
    <div id="pdfExportContent" style="padding: 40px; background: white; color: black; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;">
        <div style="border-bottom: 3px solid #0f172a; padding-bottom: 15px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: flex-end;">
            <div>
                <h1 style="margin: 0; font-size: 28px; text-transform: uppercase; font-weight: 900; color: #0f172a; letter-spacing: 1px;">MaternityHub</h1>
                <p style="margin: 5px 0 0 0; font-size: 14px; font-weight: bold; color: #475569; text-transform: uppercase; letter-spacing: 2px;">Sales & Financial Ledger Report - <?= htmlspecialchars($filterLabel) ?></p>
            </div>
            <div style="text-align: right; font-size: 11px; color: #333;">
                <p style="margin: 0;"><strong>Date Generated:</strong> <?= date('F d, Y h:i A') ?></p>
                <p style="margin: 4px 0 0 0;"><strong>Generated By:</strong> <?= htmlspecialchars($displayName) ?></p>
            </div>
        </div>

        <h3 style="font-size: 16px; margin-bottom: 10px; color: #0f172a; text-transform: uppercase; font-weight: 900;">1. Revenue Breakdown</h3>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px; border: 1px solid #cbd5e1;">
            <tr>
                <td style="border: 1px solid #cbd5e1; padding: 15px; background: #f8fafc; width: 25%;">
                    <div style="font-size: 10px; text-transform: uppercase; font-weight: bold; color: #64748b; margin-bottom: 5px;">Daily Sales (Today)</div>
                    <div style="font-size: 20px; font-weight: 900; color: #0f172a;">₱<?= number_format($metrics['daily'], 2) ?></div>
                </td>
                <td style="border: 1px solid #cbd5e1; padding: 15px; background: #f8fafc; width: 25%;">
                    <div style="font-size: 10px; text-transform: uppercase; font-weight: bold; color: #64748b; margin-bottom: 5px;">Weekly Sales</div>
                    <div style="font-size: 20px; font-weight: 900; color: #0f172a;">₱<?= number_format($metrics['weekly'], 2) ?></div>
                </td>
                <td style="border: 1px solid #cbd5e1; padding: 15px; background: #f8fafc; width: 25%;">
                    <div style="font-size: 10px; text-transform: uppercase; font-weight: bold; color: #64748b; margin-bottom: 5px;">Monthly Sales</div>
                    <div style="font-size: 20px; font-weight: 900; color: #0f172a;">₱<?= number_format($metrics['monthly'], 2) ?></div>
                </td>
                <td style="border: 1px solid #cbd5e1; padding: 15px; background: #ecfdf5; width: 25%;">
                    <div style="font-size: 10px; text-transform: uppercase; font-weight: bold; color: #047857; margin-bottom: 5px;">Total Lifetime Earnings</div>
                    <div style="font-size: 20px; font-weight: 900; color: #047857;">₱<?= number_format($metrics['total'], 2) ?></div>
                </td>
            </tr>
        </table>

        <h3 style="font-size: 16px; margin-bottom: 10px; color: #0f172a; text-transform: uppercase; font-weight: 900;">2. Transaction Summary (<?= htmlspecialchars($filterLabel) ?>)</h3>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px; border: 1px solid #cbd5e1;">
            <tr>
                <td style="border: 1px solid #cbd5e1; padding: 15px; background: #f8fafc; width: 33%;">
                    <div style="font-size: 10px; text-transform: uppercase; font-weight: bold; color: #64748b; margin-bottom: 5px;">Total Transactions</div>
                    <div style="font-size: 20px; font-weight: 900; color: #0f172a;"><?= number_format($metrics['txn_total']) ?></div>
                </td>
                <td style="border: 1px solid #cbd5e1; padding: 15px; background: #ecfdf5; width: 33%;">
                    <div style="font-size: 10px; text-transform: uppercase; font-weight: bold; color: #047857; margin-bottom: 5px;">Successful Payments</div>
                    <div style="font-size: 20px; font-weight: 900; color: #047857;"><?= number_format($metrics['txn_success']) ?></div>
                </td>
                <td style="border: 1px solid #cbd5e1; padding: 15px; background: #fffbeb; width: 34%;">
                    <div style="font-size: 10px; text-transform: uppercase; font-weight: bold; color: #b45309; margin-bottom: 5px;">Pending / Failed</div>
                    <div style="font-size: 20px; font-weight: 900; color: #b45309;"><?= number_format($metrics['txn_failed_pending']) ?></div>
                </td>
            </tr>
        </table>

        <h3 style="font-size: 16px; margin-bottom: 10px; color: #0f172a; text-transform: uppercase; font-weight: 900;">3. Detailed Ledger (<?= htmlspecialchars($filterLabel) ?>)</h3>
        <table style="width: 100%; border-collapse: collapse; font-size: 11px; text-align: left;">
            <thead>
                <tr>
                    <th style="padding: 10px; border: 1px solid #94a3b8; background-color: #e2e8f0; font-weight: bold; text-transform: uppercase; color: #0f172a;">TXN ID</th>
                    <th style="padding: 10px; border: 1px solid #94a3b8; background-color: #e2e8f0; font-weight: bold; text-transform: uppercase; color: #0f172a;">Date & Time</th>
                    <th style="padding: 10px; border: 1px solid #94a3b8; background-color: #e2e8f0; font-weight: bold; text-transform: uppercase; color: #0f172a;">Clinic Name</th>
                    <th style="padding: 10px; border: 1px solid #94a3b8; background-color: #e2e8f0; font-weight: bold; text-transform: uppercase; color: #0f172a; text-align: center;">Plan</th>
                    <th style="padding: 10px; border: 1px solid #94a3b8; background-color: #e2e8f0; font-weight: bold; text-transform: uppercase; color: #0f172a; text-align: right;">Amount</th>
                    <th style="padding: 10px; border: 1px solid #94a3b8; background-color: #e2e8f0; font-weight: bold; text-transform: uppercase; color: #0f172a; text-align: center;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($transactions)): ?>
                    <tr><td colspan="6" style="padding: 15px; border: 1px solid #cbd5e1; text-align: center; color: #64748b; font-style: italic;">No records found for this period.</td></tr>
                <?php else: ?>
                    <?php 
                    $rowNum = 0;
                    foreach($transactions as $txn): 
                        $rowNum++;
                        $bgColor = ($rowNum % 2 == 0) ? '#f8fafc' : '#ffffff';
                    ?>
                    <tr style="background-color: <?= $bgColor ?>;">
                        <td style="padding: 8px 10px; border: 1px solid #cbd5e1; font-family: monospace; font-weight: bold;"><?= htmlspecialchars($txn['id']) ?></td>
                        <td style="padding: 8px 10px; border: 1px solid #cbd5e1;"><?= date('m/d/Y - h:i A', strtotime($txn['date'])) ?></td>
                        <td style="padding: 8px 10px; border: 1px solid #cbd5e1; font-weight: bold;"><?= htmlspecialchars($txn['clinic']) ?></td>
                        <td style="padding: 8px 10px; border: 1px solid #cbd5e1; text-align: center;"><?= htmlspecialchars($txn['plan']) ?></td>
                        <td style="padding: 8px 10px; border: 1px solid #cbd5e1; text-align: right; font-weight: bold;">₱<?= number_format($txn['amount'], 2) ?></td>
                        <td style="padding: 8px 10px; border: 1px solid #cbd5e1; text-align: center; font-weight: bold; text-transform: uppercase;"><?= htmlspecialchars($txn['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" style="padding: 12px 10px; border: 1px solid #94a3b8; background-color: #e2e8f0; text-align: right; font-weight: 900; font-size: 11px; text-transform: uppercase; color: #0f172a;">TOTAL PROCESSED REVENUE (<?= htmlspecialchars($filterLabel) ?>):</td>
                    <td style="padding: 12px 10px; border: 1px solid #94a3b8; background-color: #e2e8f0; text-align: right; font-weight: 900; font-size: 14px; color: #0f172a;">₱<?= number_format($filteredTotalRev, 2) ?></td>
                    <td style="border: 1px solid #94a3b8; background-color: #e2e8f0;"></td>
                </tr>
            </tfoot>
        </table>
        
        <div style="margin-top: 50px; text-align: center; font-size: 10px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 10px;">
            <p style="margin: 0;">This is a system-generated financial report. No signature is required.</p>
            <p style="margin: 3px 0 0 0;">MaternityHub Platform &copy; <?= date('Y') ?></p>
        </div>
    </div>
</div>

<script>
    function generatePDF() {
        const element = document.getElementById('pdfExportContent');
        const dateStr = new Date().toISOString().slice(0, 10);
        
        const opt = {
            margin:       0.5, 
            filename:     'MaternityHub_Sales_Ledger_' + dateStr + '.pdf',
            image:        { type: 'jpeg', quality: 0.98 },
            html2canvas:  { scale: 2, useCORS: true },
            jsPDF:        { unit: 'in', format: 'letter', orientation: 'landscape' }
        };

        html2pdf().set(opt).from(element).save();
    }

    document.addEventListener("DOMContentLoaded", function() {
        const ctx = document.getElementById('revenueChart').getContext('2d');
        let gradient = ctx.createLinearGradient(0, 0, 0, 300);
        gradient.addColorStop(0, 'rgba(16, 185, 129, 0.5)'); 
        gradient.addColorStop(1, 'rgba(16, 185, 129, 0)');

        const labels = <?= json_encode($chartData['labels']) ?>;
        const dataValues = <?= json_encode($chartData['values']) ?>;

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Cumulative Revenue (₱)',
                    data: dataValues, 
                    borderColor: '#10b981',
                    backgroundColor: gradient,
                    borderWidth: 3,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#10b981',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                animation: false, 
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(c) { return '₱' + c.parsed.y.toLocaleString(); } } } },
                scales: {
                    y: { beginAtZero: true, ticks: { color: '#94a3b8', font: { size: 11 }, callback: function(v) { return '₱' + v.toLocaleString(); } }, grid: { color: '#f1f5f9', drawBorder: false } },
                    x: { ticks: { color: '#94a3b8', font: { size: 11, weight: 'bold' } }, grid: { display: false, drawBorder: false } }
                }
            }
        });
    });

    function filterTransactions() {
        const input = document.getElementById("txnSearch").value.toLowerCase();
        const rows = document.querySelectorAll(".txn-row");
        rows.forEach(row => {
            const text = row.innerText.toLowerCase();
            row.style.display = text.includes(input) ? "" : "none";
        });
    }

    function openLogoutModal() { document.getElementById('logoutModal').classList.remove('hidden'); document.getElementById('logoutModal').classList.add('flex'); }
    function closeLogoutModal() { document.getElementById('logoutModal').classList.remove('flex'); document.getElementById('logoutModal').classList.add('hidden'); }
    
    function confirmLogout() { 
        closeLogoutModal(); 
        const loading = document.getElementById('loggingOutScreen'); 
        loading.classList.remove('hidden', 'flex'); 
        loading.classList.add('flex'); 
        setTimeout(() => { window.location.href = '?logout=1'; }, 1500); 
    }
</script>
</body>
</html>