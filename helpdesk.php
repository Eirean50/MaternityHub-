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
if (isset($_GET['logout']) && $_GET['logout'] == '1') {
    $currentTime = date('Y-m-d H:i:s');
    if (isset($_SESSION['full_name'])) {
        try {
            $logoutName = $_SESSION['full_name'];
            $logoutRole = $_SESSION['role'] ?? 'SuperAdmin';
            $auditRole = 'superadmin';
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, 'Logout', 'Super Admin safely logged out of the platform.', ?, ?)");
            $stmtLog->execute([$logoutName, $auditRole, $ip, $currentTime]);
        } catch (Throwable $e) {
            error_log('Logout audit logging failed in helpdesk.php: ' . $e->getMessage());
        }
    }
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
    echo "<script>window.location.href = 'index.php';</script>";
    exit();
}

// --- SYSTEM SETTINGS (JSON BASED) ---
$settingsFile = __DIR__ . '/maternityhub_settings.json';
if (!file_exists($settingsFile)) {
    file_put_contents($settingsFile, json_encode(['maintenance_mode' => false, 'super_theme_color' => '#10b981']));
}
$settings = json_decode(file_get_contents($settingsFile), true);
$superThemeColor = $settings['super_theme_color'] ?? '#10b981';

// DYNAMIC TEXT CONTRAST
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
    if ($role === 'superadmin' || strpos(strtolower($fullName), 'eirean') !== false || $role === 'admin') {
        $isSuperAdmin = true; 
    }
}
if (!$isSuperAdmin) {
    echo "<script>window.location.href = 'index.php';</script>";
    exit();
}
$displayName = $_SESSION['full_name'] ?? 'Super Admin';

// --- HANDLE TICKET ACTIONS (UPDATE STATUS + OPTIONAL EMAIL REPLY) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_ticket'])) {
    $ticket_id    = (int)$_POST['ticket_id'];
    $new_status   = $_POST['ticket_status'];
    $reply_body   = trim($_POST['reply_message'] ?? '');
    $sender_email = filter_var(trim($_POST['sender_email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $sender_name  = strip_tags(trim($_POST['sender_name'] ?? 'Clinic Owner'));
    $ticket_subj  = strip_tags(trim($_POST['ticket_subject'] ?? 'Support Ticket'));

    try {
        $stmt = $pdo->prepare("UPDATE support_tickets SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $ticket_id]);
    } catch (PDOException $e) {
        die("Error updating ticket: " . $e->getMessage());
    }

    $email_result = 'updated';
    if ($reply_body !== '' && $sender_email) {
        $system_email = $settings['system_email'] ?? 'support@maternityhub.com';
        $to           = $sender_email;
        $mail_subject = '=?UTF-8?B?' . base64_encode('RE: ' . $ticket_subj) . '?=';
        $mail_body    = "Hello " . $sender_name . ",\r\n\r\n" . $reply_body
                      . "\r\n\r\n---\r\nMaternityHub Support Team\r\n" . $system_email;
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: 8bit\r\n";
        $headers .= "From: MaternityHub Support <" . $system_email . ">\r\n";
        $headers .= "Reply-To: " . $system_email . "\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $sent = mail($to, $mail_subject, $mail_body, $headers);
        $email_result = $sent ? 'replied' : 'reply_failed';
    }

    header("Location: helpdesk.php?msg=" . $email_result);
    exit();
}

// --- FETCH ALL TICKETS (WITH CLINIC NAME via JOIN) ---
$tickets = [];
$open_count = 0;
$resolved_count = 0;
try {
    $stmt = $pdo->query("
        SELECT t.*, c.clinic_name, c.clinic_code,
               COALESCE(
                   NULLIF(t.email_address, ''),
                   (SELECT u.email FROM users u WHERE u.TenantID = t.TenantID AND LOWER(u.role) IN ('owner','admin','owner/midwife') ORDER BY u.id ASC LIMIT 1)
               ) AS resolved_email
        FROM support_tickets t 
        LEFT JOIN tenants c ON t.TenantID = c.TenantID 
        ORDER BY CASE t.status WHEN 'Open' THEN 1 WHEN 'In Progress' THEN 2 WHEN 'Resolved' THEN 3 END, t.created_at DESC
    ");
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tickets as $t) {
        if ($t['status'] === 'Open' || $t['status'] === 'In Progress') {
            $open_count++;
        } else {
            $resolved_count++;
        }
    }
} catch (PDOException $e) {
    $dbError = "Database Error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Helpdesk Tickets - MaternityHub</title>
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
        .modal-active { display: flex; }
        .modal-inactive { display: none; }
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

            <a href="auditlogs.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                <span class="material-symbols-outlined text-2xl">history</span> <span class="text-base">Audit Logs</span>
            </a>
            
            <a href="helpdesk.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] bg-primary <?= $headerText ?> font-bold shadow-md transition-all hover:scale-[1.02]">
                <span class="material-symbols-outlined text-2xl">support_agent</span> <span class="text-base">Helpdesk Tickets</span>
            </a>
            
            <a href="systemsettings.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                <span class="material-symbols-outlined text-2xl">settings</span> <span class="text-base">System Settings</span>
            </a>
        </nav>
    </aside>

    <main class="flex-1 overflow-y-auto p-4 md:p-8 bg-slate-50">
        <div class="max-w-7xl mx-auto space-y-8">
            
            <?php if(isset($dbError)): ?>
                <div class="p-4 rounded-xl text-sm font-bold flex items-center gap-3 bg-red-100 text-red-700 border border-red-200 mb-6">
                    <span class="material-symbols-outlined text-xl">error</span> <?= htmlspecialchars($dbError) ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['msg'])): 
                $msgConfig = match($_GET['msg']) {
                    'replied'      => ['bg-emerald-50 text-emerald-700 border-emerald-200', 'check_circle', 'Reply sent successfully and ticket status updated.'],
                    'reply_failed' => ['bg-amber-50 text-amber-700 border-amber-200',  'warning',      'Status saved, but email could not be sent. Check server mail settings.'],
                    'updated'      => ['bg-blue-50 text-blue-700 border-blue-200',     'info',         'Ticket status updated successfully.'],
                    'deleted'      => ['bg-red-50 text-red-700 border-red-200',        'delete',       'Ticket deleted successfully.'],
                    default        => null
                };
                if ($msgConfig): [$msgClass, $msgIcon, $msgText] = $msgConfig;
            ?>
                <div class="p-4 rounded-2xl text-sm font-bold flex items-center gap-3 border <?= $msgClass ?>">
                    <span class="material-symbols-outlined text-xl"><?= $msgIcon ?></span> <?= $msgText ?>
                </div>
            <?php endif; endif; ?>

            <div class="flex flex-col gap-6">
                <div>
                    <h1 class="text-3xl font-black text-slate-800 tracking-tighter uppercase leading-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-4xl text-primary">support_agent</span> Helpdesk Tickets
                    </h1>
                    <p class="text-slate-500 text-sm font-medium tracking-tight mt-1">Resolve issues and support requests from clinic tenants.</p>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-1">Total Tickets</p>
                            <p class="text-3xl font-black text-slate-800"><?= count($tickets) ?></p>
                        </div>
                        <div class="size-12 bg-slate-50 rounded-full flex items-center justify-center text-slate-400"><span class="material-symbols-outlined text-2xl">confirmation_number</span></div>
                    </div>
                    <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-black uppercase text-red-500 tracking-widest mb-1">Action Required</p>
                            <p class="text-3xl font-black text-slate-800"><?= $open_count ?></p>
                        </div>
                        <div class="size-12 bg-red-50 rounded-full flex items-center justify-center text-red-500"><span class="material-symbols-outlined text-2xl">warning</span></div>
                    </div>
                    <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-black uppercase text-emerald-500 tracking-widest mb-1">Resolved</p>
                            <p class="text-3xl font-black text-slate-800"><?= $resolved_count ?></p>
                        </div>
                        <div class="size-12 bg-emerald-50 rounded-full flex items-center justify-center text-emerald-500"><span class="material-symbols-outlined text-2xl">task_alt</span></div>
                    </div>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-[2rem] shadow-sm overflow-hidden">
                <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-white">
                    <h3 class="font-black text-slate-800">Support Inbox</h3>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-lg">search</span>
                        <input type="text" id="ticketSearch" onkeyup="filterTickets()" placeholder="Search clinic or subject..." class="pl-10 pr-4 py-2 rounded-xl border-slate-200 text-xs w-64 focus:ring-primary focus:border-primary shadow-sm bg-slate-50">
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full text-left" id="ticketTable">
                        <thead class="bg-slate-50 text-[10px] font-black uppercase tracking-widest text-slate-400">
                            <tr>
                                <th class="px-6 py-4">Clinic / Tenant</th>
                                <th class="px-6 py-4">Subject</th>
                                <th class="px-6 py-4">Status</th>
                                <th class="px-6 py-4">Date Submitted</th>
                                <th class="px-6 py-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium text-slate-600">
                            <?php if (empty($tickets)): ?>
                                <tr><td colspan="5" class="px-6 py-12 text-center text-slate-400 italic">No support tickets found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($tickets as $t): 
                                    $badgeColor = match($t['status']) {
                                        'Open' => 'bg-red-100 text-red-700 border-red-200',
                                        'In Progress' => 'bg-amber-100 text-amber-700 border-amber-200',
                                        'Resolved' => 'bg-emerald-100 text-emerald-700 border-emerald-200',
                                        default => 'bg-slate-100 text-slate-700 border-slate-200'
                                    };
                                    $t['sender_email'] = $t['resolved_email'] ?? '';
                                    $t_json = htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8');
                                ?>
                                <tr class="ticket-row hover:bg-slate-50 transition-colors">
                                    <td class="px-6 py-4">
                                        <div class="font-black text-slate-800 text-sm"><?= htmlspecialchars($t['clinic_name'] ?? 'Unknown Clinic') ?></div>
                                        <div class="text-[10px] text-slate-400 mt-1">ID: <?= htmlspecialchars($t['TenantID']) ?> | Sender: <?= htmlspecialchars($t['sender_name']) ?></div>
                                    </td>
                                    <td class="px-6 py-4 text-sm font-bold text-slate-700"><?= htmlspecialchars($t['subject']) ?></td>
                                    <td class="px-6 py-4">
                                        <span class="px-3 py-1 rounded-md text-[9px] font-black uppercase tracking-widest border <?= $badgeColor ?>"><?= $t['status'] ?></span>
                                    </td>
                                    <td class="px-6 py-4 text-xs font-bold text-slate-500">
                                        <?= date('M d, Y - h:i A', strtotime($t['created_at'])) ?>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <button onclick='openViewModal(<?= $t_json ?>)' class="px-4 py-2 bg-white border border-slate-200 text-blue-600 rounded-xl font-black text-xs hover:bg-blue-50 transition-all shadow-sm">
                                            View
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>

<div id="viewTicketModal" class="fixed inset-0 z-[100] modal-inactive transition-all duration-300 p-4 items-center justify-center">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeViewModal()"></div>
    <div class="bg-white w-full max-w-2xl rounded-[32px] shadow-2xl relative z-10 overflow-hidden border border-slate-100 flex flex-col max-h-[90vh]">
        <div class="p-6 border-b border-slate-100 flex items-center justify-between bg-slate-50">
            <div>
                <h3 class="text-lg font-black tracking-tight flex items-center gap-2 text-slate-800">
                    <span class="material-symbols-outlined text-primary">forum</span> Ticket Details
                </h3>
            </div>
            <button type="button" onclick="closeViewModal()" class="size-8 flex items-center justify-center rounded-full hover:bg-slate-200 transition-all">
                <span class="material-symbols-outlined text-lg">close</span>
            </button>
        </div>
        
        <div class="p-8 overflow-y-auto bg-white flex-1 space-y-6">
            <div class="grid grid-cols-2 gap-4 bg-slate-50 p-5 rounded-2xl border border-slate-200 shadow-sm">
                <div>
                    <p class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-1">Clinic Name</p>
                    <p class="font-bold text-slate-800 text-sm" id="vt_clinic"></p>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-1">Tenant ID</p>
                    <p class="font-bold text-slate-800 text-sm" id="vt_tenant"></p>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-1">Sender</p>
                    <p class="font-bold text-slate-800 text-sm" id="vt_sender"></p>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-1">Email / Contact</p>
                    <p class="font-bold text-primary text-sm underline cursor-pointer" id="vt_email"></p>
                </div>
            </div>

            <div>
                <p class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-2 px-1">Subject</p>
                <div class="bg-slate-50 p-4 rounded-xl border border-slate-200 shadow-sm font-black text-slate-800 text-lg" id="vt_subject"></div>
            </div>

            <div>
                <p class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-2 px-1">Message / Concern</p>
                <div class="bg-slate-50 p-6 rounded-2xl border border-slate-200 shadow-sm text-slate-600 leading-relaxed whitespace-pre-line" id="vt_message"></div>
            </div>
        </div>

        <form method="POST" class="p-6 border-t border-slate-200 bg-slate-50 space-y-4">
            <input type="hidden" name="update_ticket" value="1">
            <input type="hidden" name="ticket_id" id="vt_id">
            <input type="hidden" name="sender_email" id="vt_sender_email_hidden">
            <input type="hidden" name="sender_name" id="vt_sender_name_hidden">
            <input type="hidden" name="ticket_subject" id="vt_subject_hidden">

            <div>
                <label class="text-[10px] font-black uppercase tracking-widest text-slate-400 block mb-1.5">Reply to Sender <span class="text-slate-300 font-medium normal-case tracking-normal">(optional — will be emailed)</span></label>
                <textarea name="reply_message" id="vt_reply_message" rows="4" placeholder="Type your reply here..." class="w-full rounded-2xl border border-slate-200 text-sm p-4 focus:ring-primary focus:border-primary outline-none bg-white resize-none shadow-sm placeholder:text-slate-300"></textarea>
            </div>

            <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="flex items-center gap-3 w-full sm:w-auto">
                    <label class="text-[10px] font-black uppercase tracking-widest text-slate-400 whitespace-nowrap">Update Status:</label>
                    <select name="ticket_status" id="vt_status" class="w-full sm:w-40 rounded-xl border-slate-300 text-sm font-bold text-slate-700 focus:ring-primary focus:border-primary">
                        <option value="Open">Open</option>
                        <option value="In Progress">In Progress</option>
                        <option value="Resolved">Resolved</option>
                    </select>
                </div>
                <div class="flex gap-2 w-full sm:w-auto">
                    <button type="button" onclick="closeViewModal()" class="flex-1 sm:flex-none px-6 py-3 rounded-xl bg-slate-200 text-slate-600 font-black text-[10px] uppercase tracking-widest hover:bg-slate-300 transition-all">Close</button>
                    <button type="submit" class="flex-1 sm:flex-none px-6 py-3 rounded-xl bg-primary text-white font-black text-[10px] uppercase tracking-widest hover:opacity-90 transition-all shadow-lg flex items-center gap-2">
                        <span class="material-symbols-outlined text-sm">send</span> Send & Save
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    function filterTickets() {
        let input = document.getElementById("ticketSearch").value.toLowerCase();
        let rows = document.querySelectorAll(".ticket-row");
        rows.forEach(row => {
            row.style.display = row.innerText.toLowerCase().includes(input) ? "" : "none";
        });
    }

    function openViewModal(data) {
        document.getElementById('vt_id').value = data.id;
        document.getElementById('vt_clinic').innerText = data.clinic_name || 'N/A';
        document.getElementById('vt_tenant').innerText = data.TenantID;
        document.getElementById('vt_sender').innerText = data.sender_name;
        document.getElementById('vt_email').innerText = data.sender_email || 'No email on record';
        document.getElementById('vt_subject').innerText = data.subject;
        document.getElementById('vt_message').innerText = data.message;
        document.getElementById('vt_status').value = data.status;
        document.getElementById('vt_sender_email_hidden').value = data.sender_email || '';
        document.getElementById('vt_sender_name_hidden').value = data.sender_name || '';
        document.getElementById('vt_subject_hidden').value = data.subject || '';
        document.getElementById('vt_reply_message').value = '';

        const modal = document.getElementById('viewTicketModal');
        modal.classList.remove('modal-inactive');
        modal.classList.add('modal-active');
    }

    function closeViewModal() {
        const modal = document.getElementById('viewTicketModal');
        modal.classList.remove('modal-active');
        modal.classList.add('modal-inactive');
    }

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
        setTimeout(() => { window.location.href = '?logout=1'; }, 1500); 
    }
</script>
</body>
</html>