<?php
date_default_timezone_set('Asia/Manila');
ob_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'db.php';

// --- SYSTEM SETTINGS (JSON BASED) ---
$settingsFile = __DIR__ . '/maternityhub_settings.json';
if (!file_exists($settingsFile)) {
    file_put_contents($settingsFile, json_encode([
        'maintenance_mode' => false,
        'super_theme_color' => '#10b981' // Default Emerald Green
    ]));
}
$settings = json_decode(file_get_contents($settingsFile), true);
$maintenanceMode = $settings['maintenance_mode'] ?? false;
$superThemeColor = $settings['super_theme_color'] ?? '#10b981';

// --- SUPER ADMIN SECURITY CHECK ---
$isSuperAdmin = false;
if (isset($_SESSION['user_id'])) {
    $role = strtolower(trim($_SESSION['role'] ?? ''));
    $fullName = $_SESSION['full_name'] ?? '';
    
    // Allow if role is superadmin OR user is the master account
    if ($role === 'superadmin' || strpos(strtolower($fullName), 'eirean') !== false || (isset($_SESSION['email']) && strtolower(trim($_SESSION['email'])) === 'eireannicodangalan@gmail.com')) {
        $isSuperAdmin = true; 
    }
}

if (!$isSuperAdmin) {
    header("Location: index.php");
    exit();
}

$displayName = $_SESSION['full_name'] ?? 'Super Admin';
$currentEmail = strtolower(trim($_SESSION['email'] ?? ''));
$profilePic = "https://ui-avatars.com/api/?name=" . urlencode($displayName) . "&background=0f172a&color=fff";

$error = null;
$msg = $_GET['msg'] ?? null;

// --- ACTIONS: UPDATE UI SETTINGS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_ui_settings'])) {
    $newColor = trim($_POST['theme_color']);
    if (preg_match('/^#[a-f0-9]{6}$/i', $newColor)) {
        $settings['super_theme_color'] = $newColor;
        file_put_contents($settingsFile, json_encode($settings));
        header("Location: systemsettings.php?msg=ui_updated");
        exit();
    } else {
        $error = "Invalid color format.";
    }
}

// --- ACTIONS: TOGGLE MAINTENANCE MODE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_maintenance'])) {
    $maintenanceMode = !$maintenanceMode;
    $settings['maintenance_mode'] = $maintenanceMode;
    file_put_contents($settingsFile, json_encode($settings));
    header("Location: systemsettings.php?msg=" . ($maintenanceMode ? 'maintenance_on' : 'maintenance_off'));
    exit();
}

// --- ACTIONS: ADD NEW SUPER ADMIN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_superadmin'])) {
    $fname = trim($_POST['first_name']);
    $lname = trim($_POST['last_name']);
    $email = strtolower(trim($_POST['email']));
    $pass = $_POST['password'];
    $cpass = $_POST['confirm_password'];

    if (empty($fname) || empty($lname) || empty($email) || empty($pass)) {
        $error = "All fields are required.";
    } elseif ($pass !== $cpass) {
        $error = "Passwords do not match.";
    } elseif (strlen($pass) < 8) {
        $error = "Password must be at least 8 characters long.";
    } else {
        try {
            $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmtCheck->execute([$email]);
            
            if ($stmtCheck->fetch()) {
                $error = "Email address is already used by another account.";
            } else {
                $hashed = password_hash($pass, PASSWORD_DEFAULT);
                $stmtInsert = $pdo->prepare("INSERT INTO users (first_name, last_name, email, password, role, status, TenantID) VALUES (?, ?, ?, ?, 'SuperAdmin', 'Active', NULL)");
                $stmtInsert->execute([$fname, $lname, $email, $hashed]);
                
                header("Location: systemsettings.php?msg=admin_added");
                exit();
            }
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}

// --- ACTIONS: CHANGE SUPER ADMIN PASSWORD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_admin_password'])) {
    $targetAdminId = trim($_POST['target_admin_id'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    $confirmNewPassword = $_POST['confirm_new_password'] ?? '';

    if ($targetAdminId === '' || $newPassword === '' || $confirmNewPassword === '') {
        $error = "All password fields are required.";
    } elseif ($newPassword !== $confirmNewPassword) {
        $error = "New password and confirmation do not match.";
    } elseif (strlen($newPassword) < 8) {
        $error = "New password must be at least 8 characters long.";
    } else {
        try {
            $stmtTargetAdmin = $pdo->prepare("SELECT id, email FROM users WHERE id = ? AND LOWER(role) = 'superadmin'");
            $stmtTargetAdmin->execute([$targetAdminId]);
            $targetAdmin = $stmtTargetAdmin->fetch(PDO::FETCH_ASSOC);

            if (!$targetAdmin) {
                $error = "Selected administrator account was not found.";
            } elseif (strtolower($targetAdmin['email']) === 'eireannicodangalan@gmail.com') {
                $error = "Master Platform Owner password cannot be changed here.";
            } else {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmtPasswordUpdate = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmtPasswordUpdate->execute([$hashedPassword, $targetAdminId]);
                header("Location: systemsettings.php?msg=admin_password_updated");
                exit();
            }
        } catch (PDOException $e) {
            $error = "Password Update Error: " . $e->getMessage();
        }
    }
}

// --- ACTIONS: DELETE SUPER ADMIN ---
if (isset($_GET['delete_admin'])) {
    $del_id = $_GET['delete_admin'];
    
    try {
        $stmtDelCheck = $pdo->prepare("SELECT email FROM users WHERE id = ?");
        $stmtDelCheck->execute([$del_id]);
        $delUser = $stmtDelCheck->fetch(PDO::FETCH_ASSOC);
        
        if ($delUser) {
            if (strtolower($delUser['email']) === 'eireannicodangalan@gmail.com') {
                $error = "ACCESS DENIED: You cannot delete the Master Platform Owner account.";
            } elseif (strtolower($delUser['email']) === $currentEmail) {
                $error = "You cannot delete your own account while logged in.";
            } else {
                $stmtDelete = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmtDelete->execute([$del_id]);
                header("Location: systemsettings.php?msg=admin_deleted");
                exit();
            }
        }
    } catch (PDOException $e) {
        $error = "Delete Error: " . $e->getMessage();
    }
}

// --- FETCH ALL SUPER ADMINS ---
try {
    $stmtAdmins = $pdo->query("SELECT id, first_name, last_name, email, created_at, role, status FROM users WHERE LOWER(role) = 'superadmin' ORDER BY id ASC");
    $superAdmins = $stmtAdmins->fetchAll(PDO::FETCH_ASSOC);

    $masterInDB = false;
    foreach ($superAdmins as $admin) {
        if (strtolower($admin['email']) === 'eireannicodangalan@gmail.com') {
            $masterInDB = true;
            break;
        }
    }

    if (!$masterInDB) {
        $masterAccount = [
            'id' => 'MASTER',
            'first_name' => 'Eirean Nico',
            'last_name' => 'Dangalan',
            'email' => 'eireannicodangalan@gmail.com',
            'created_at' => date('Y-m-d H:i:s'), 
            'role' => 'SuperAdmin',
            'status' => 'Active'
        ];
        array_unshift($superAdmins, $masterAccount); 
    }

} catch (PDOException $e) {
    die("Query failed: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>System Settings - MaternityHub</title>
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
                    fontFamily: { "display": ["Plus Jakarta Sans", "sans-serif"] }
                } 
            } 
        }
    </script>
    <style>
        html, body { margin: 0; padding: 0; scroll-behavior: smooth; }
        .material-symbols-outlined { font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; overflow: hidden; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    </style>
</head>
<body class="bg-background-light text-slate-800 h-screen overflow-hidden flex flex-col relative text-sm antialiased font-display">

<header class="h-20 bg-super border-b border-slate-800 flex items-center justify-between px-6 md:px-12 sticky top-0 z-50 shrink-0">
    <div class="flex items-center gap-4">
        <div class="size-12 rounded-2xl bg-white/10 flex items-center justify-center shrink-0 border border-white/20">
            <span class="material-symbols-outlined text-primary-light text-2xl">settings</span>
        </div>
        <div class="flex flex-col justify-center text-white">
            <h1 class="text-lg font-bold leading-none tracking-tight">MaternityHub Platform</h1>
            <p class="text-primary-light text-[10px] font-bold uppercase tracking-widest mt-1">SYSTEM SETTINGS</p>
        </div>
    </div>
    
    <div class="flex items-center gap-4 ml-auto">
        <div class="hidden sm:flex flex-col text-right justify-center text-white">
            <p class="text-sm font-bold leading-none"><?= htmlspecialchars($displayName) ?></p>
            <div class="mt-2 inline-flex items-center justify-end gap-1.5">
                <span class="inline-flex items-center gap-1 rounded-full border border-amber-300/30 bg-amber-400/10 px-2.5 py-1 text-[9px] font-black uppercase tracking-[0.18em] text-amber-300">
                    <span class="material-symbols-outlined text-[12px]">workspace_premium</span>
                    Platform Owner
                </span>
            </div>
        </div>
        <a href="index.php?logout=1" class="flex items-center gap-2 bg-white/10 hover:bg-red-500 hover:text-white text-slate-300 border border-white/10 px-4 py-2 rounded-xl text-xs font-bold transition-all">
            <span class="material-symbols-outlined text-sm">logout</span><span class="hidden md:inline">Logout</span>
        </a>
    </div>
</header>

<div class="flex-1 flex overflow-hidden">
    <aside class="w-72 bg-white border-r border-slate-200 hidden md:flex flex-col shrink-0 z-10">
        <nav class="flex-1 p-6 h-full flex flex-col gap-2">
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
            <a href="#" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                <span class="material-symbols-outlined text-2xl">support_agent</span> <span class="text-base">Helpdesk Tickets</span>
            </a>
            <a href="systemsettings.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] bg-slate-100 text-primary font-black shadow-sm transition-all hover:scale-[1.02]">
                <span class="material-symbols-outlined text-2xl">settings</span> <span class="text-base">System Settings</span>
            </a>
        </nav>
    </aside>

    <main class="flex-1 overflow-y-auto p-6 md:p-10 bg-slate-50">
        <div class="max-w-5xl mx-auto">
            
            <div class="mb-10 pb-6 border-b border-slate-200">
                <h2 class="text-3xl font-black text-slate-900 tracking-tight">System Settings</h2>
                <p class="text-slate-500 mt-2">Manage platform functionality, global branding, and administrator access.</p>
            </div>

            <?php if($msg === 'admin_added'): ?>
                <div class="mb-6 p-4 rounded-xl text-sm font-bold flex items-center gap-3 bg-emerald-50 text-emerald-700 border border-emerald-200">
                    <span class="material-symbols-outlined">check_circle</span> New Super Admin account successfully created!
                </div>
            <?php endif; ?>
            <?php if($msg === 'ui_updated'): ?>
                <div class="mb-6 p-4 rounded-xl text-sm font-bold flex items-center gap-3 bg-emerald-50 text-emerald-700 border border-emerald-200">
                    <span class="material-symbols-outlined">palette</span> Platform UI color has been successfully updated!
                </div>
            <?php endif; ?>
            <?php if($msg === 'maintenance_on'): ?>
                <div class="mb-6 p-4 rounded-xl text-sm font-bold flex items-center gap-3 bg-amber-50 text-amber-700 border border-amber-200">
                    <span class="material-symbols-outlined">construction</span> Maintenance Mode is ON. All clinic logins are now blocked.
                </div>
            <?php endif; ?>
            <?php if($msg === 'maintenance_off'): ?>
                <div class="mb-6 p-4 rounded-xl text-sm font-bold flex items-center gap-3 bg-emerald-50 text-emerald-700 border border-emerald-200">
                    <span class="material-symbols-outlined">check_circle</span> Maintenance Mode is OFF. Clinics can now log in.
                </div>
            <?php endif; ?>
            <?php if($error): ?>
                <div class="mb-6 p-4 rounded-xl text-sm font-bold flex items-center gap-3 bg-red-50 text-red-700 border border-red-200">
                    <span class="material-symbols-outlined">error</span> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <div class="divide-y divide-slate-200">
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 py-8">
                    <div class="md:col-span-1">
                        <h3 class="text-base font-bold text-slate-900">System Maintenance</h3>
                        <p class="text-xs text-slate-500 mt-2 leading-relaxed">Temporarily disable platform access for all tenants. Use this when performing core system updates or emergency bug fixes.</p>
                    </div>
                    <div class="md:col-span-2">
                        <div class="bg-white rounded-2xl border border-slate-200 p-6 flex items-center justify-between shadow-sm">
                            <div class="flex items-center gap-4">
                                <div class="size-12 rounded-full <?= $maintenanceMode ? 'bg-red-50 text-red-500' : 'bg-slate-100 text-slate-400' ?> flex items-center justify-center transition-colors">
                                    <span class="material-symbols-outlined text-2xl">power_settings_new</span>
                                </div>
                                <div>
                                    <p class="text-sm font-black text-slate-900">Platform Kill Switch</p>
                                    <p class="text-[10px] font-bold <?= $maintenanceMode ? 'text-red-500 animate-pulse' : 'text-slate-400' ?> uppercase tracking-widest mt-0.5">
                                        Status: <?= $maintenanceMode ? 'Offline / Maintenance Mode' : 'Online / Live' ?>
                                    </p>
                                </div>
                            </div>
                            <form method="POST" action="systemsettings.php">
                                <input type="hidden" name="toggle_maintenance" value="1">
                                <button type="submit" class="relative inline-flex h-8 w-14 items-center rounded-full transition-colors focus:outline-none <?= $maintenanceMode ? 'bg-red-500' : 'bg-slate-300 hover:bg-slate-400' ?>">
                                    <span class="inline-block size-6 transform rounded-full bg-white transition-transform <?= $maintenanceMode ? 'translate-x-7' : 'translate-x-1' ?> shadow-sm"></span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 py-8">
                    <div class="md:col-span-1">
                        <h3 class="text-base font-bold text-slate-900">Platform Branding</h3>
                        <p class="text-xs text-slate-500 mt-2 leading-relaxed">Customize the primary accent color used across the Super Admin interface.</p>
                    </div>
                    <div class="md:col-span-2">
                        <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm">
                            <form method="POST" action="systemsettings.php" class="space-y-4">
                                <input type="hidden" name="update_ui_settings" value="1">
                                <div>
                                    <label class="text-[11px] font-black text-slate-500 uppercase tracking-widest block mb-2">Global Theme Color</label>
                                    <div class="flex items-center gap-4">
                                        <div class="p-1 rounded-lg border border-slate-200 bg-slate-50 inline-block">
                                            <input type="color" name="theme_color" value="<?= htmlspecialchars($superThemeColor) ?>" class="size-10 rounded cursor-pointer border-0 p-0 block bg-transparent">
                                        </div>
                                        <p class="text-xs text-slate-500 font-medium">Select a HEX color code to apply globally.</p>
                                    </div>
                                </div>
                                <div class="pt-2">
                                    <button type="submit" class="bg-primary text-white font-bold py-2.5 px-6 rounded-xl text-xs hover:bg-primary-dark transition-colors shadow-sm">
                                        Save Brand Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 py-8">
                    <div class="md:col-span-1">
                        <h3 class="text-base font-bold text-slate-900">Administrator Accounts</h3>
                        <p class="text-xs text-slate-500 mt-2 leading-relaxed">Manage personnel who have unrestricted access to tenant data and platform configuration.</p>
                        
                        <button onclick="document.getElementById('addAdminForm').classList.toggle('hidden')" class="mt-6 flex items-center gap-2 text-primary font-bold text-sm hover:underline">
                            <span class="material-symbols-outlined text-base">add_circle</span> Add New Admin
                        </button>
                    </div>
                    
                    <div class="md:col-span-2 space-y-6">
                        
                        <div id="addAdminForm" class="hidden bg-slate-50 rounded-2xl border border-slate-200 p-6 shadow-inner">
                            <h4 class="text-sm font-black text-slate-800 mb-4 border-b border-slate-200 pb-2">Create Global Account</h4>
                            <form method="POST" action="systemsettings.php" class="space-y-4">
                                <input type="hidden" name="add_superadmin" value="1">
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">First Name</label>
                                        <input type="text" name="first_name" required class="w-full rounded-xl border-slate-300 text-sm p-2.5 focus:ring-primary focus:border-primary bg-white">
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">Last Name</label>
                                        <input type="text" name="last_name" required class="w-full rounded-xl border-slate-300 text-sm p-2.5 focus:ring-primary focus:border-primary bg-white">
                                    </div>
                                </div>
                                <div>
                                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">Email Address</label>
                                    <input type="email" name="email" required class="w-full rounded-xl border-slate-300 text-sm p-2.5 focus:ring-primary focus:border-primary bg-white">
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">Password</label>
                                        <input type="password" name="password" required minlength="8" class="w-full rounded-xl border-slate-300 text-sm p-2.5 focus:ring-primary focus:border-primary bg-white">
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">Confirm</label>
                                        <input type="password" name="confirm_password" required minlength="8" class="w-full rounded-xl border-slate-300 text-sm p-2.5 focus:ring-primary focus:border-primary bg-white">
                                    </div>
                                </div>
                                <div class="pt-2 flex gap-3">
                                    <button type="submit" class="bg-super text-white font-bold py-2.5 px-6 rounded-xl text-xs hover:bg-slate-800 transition-colors shadow-sm">
                                        Create Account
                                    </button>
                                    <button type="button" onclick="document.getElementById('addAdminForm').classList.add('hidden')" class="bg-white border border-slate-300 text-slate-600 font-bold py-2.5 px-6 rounded-xl text-xs hover:bg-slate-100 transition-colors">
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>

                        <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-500 text-[10px] uppercase tracking-widest">
                                        <th class="p-4 font-black">User Details</th>
                                        <th class="p-4 font-black text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php foreach ($superAdmins as $admin): ?>
                                        <?php 
                                            $isMasterAccount = strtolower($admin['email']) === 'eireannicodangalan@gmail.com';
                                            $adminName = trim($admin['first_name'] . ' ' . $admin['last_name']);
                                            $adminInitials = strtoupper(substr($admin['first_name'], 0, 1) . substr($admin['last_name'], 0, 1));
                                            if(empty($adminName)) { $adminName = "Platform Master"; $adminInitials = "MH"; }
                                        ?>
                                        <tr class="hover:bg-slate-50 transition-colors">
                                            <td class="p-4">
                                                <div class="flex items-center gap-3">
                                                    <div class="size-10 rounded-full <?= $isMasterAccount ? 'bg-amber-100 text-amber-700 ring-1 ring-amber-300' : 'bg-slate-100 text-slate-600' ?> flex items-center justify-center font-black text-xs shrink-0">
                                                        <?= $adminInitials ?>
                                                    </div>
                                                    <div>
                                                        <div class="flex items-center gap-2">
                                                            <p class="font-bold text-slate-900 text-sm"><?= htmlspecialchars($adminName) ?></p>
                                                            <?php if($isMasterAccount): ?>
                                                                <span class="bg-amber-100 text-amber-700 px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-widest border border-amber-200">Owner</span>
                                                            <?php elseif(strtolower($admin['email']) === $currentEmail): ?>
                                                                <span class="bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-widest border border-emerald-200">You</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <p class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars($admin['email']) ?></p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="p-4 text-right">
                                                <?php if($isMasterAccount): ?>
                                                    <span class="text-[10px] font-bold text-slate-400 italic">Protected</span>
                                                <?php else: ?>
                                                    <div class="flex items-center justify-end gap-2">
                                                        <button type="button" onclick="openPasswordModal('<?= htmlspecialchars((string)$admin['id'], ENT_QUOTES, 'UTF-8') ?>', <?= htmlspecialchars(json_encode($adminName), ENT_QUOTES, 'UTF-8') ?>)" class="text-slate-400 hover:text-blue-600 transition-colors p-1" title="Change Password">
                                                            <span class="material-symbols-outlined text-lg">key</span>
                                                        </button>
                                                        <?php if(strtolower($admin['email']) !== $currentEmail): ?>
                                                        <button type="button" onclick="openRevokeModal('systemsettings.php?delete_admin=<?= $admin['id'] ?>', <?= htmlspecialchars(json_encode($adminName), ENT_QUOTES, 'UTF-8') ?>)" class="text-slate-400 hover:text-red-600 transition-colors p-1" title="Revoke Access">
                                                            <span class="material-symbols-outlined text-lg">delete</span>
                                                        </button>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>
</div>

<div id="revokeModal" class="fixed inset-0 z-[200] hidden items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm">
    <div class="bg-white rounded-2xl p-6 max-w-sm w-full shadow-2xl border border-slate-100 text-center">
        <div class="size-12 rounded-full bg-red-50 text-red-500 flex items-center justify-center mx-auto mb-4">
            <span class="material-symbols-outlined text-2xl">warning</span>
        </div>
        <h3 class="text-base font-black text-slate-900 mb-1">Revoke Access</h3>
        <p class="text-slate-500 text-xs mb-4">Remove global administrative access for <br><span id="revokeTargetName" class="font-bold text-slate-800"></span>?</p>
        <div class="flex gap-2">
            <button onclick="closeRevokeModal()" class="flex-1 py-2 rounded-xl font-bold text-slate-600 border border-slate-200 hover:bg-slate-50 text-xs">Cancel</button>
            <button id="revokeConfirmBtn" onclick="confirmRevoke()" class="flex-1 py-2 rounded-xl font-bold bg-red-500 text-white hover:bg-red-600 text-xs shadow-sm">Confirm Delete</button>
        </div>
    </div>
</div>

<div id="passwordModal" class="fixed inset-0 z-[210] hidden items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm">
    <div class="bg-white rounded-2xl p-6 max-w-sm w-full shadow-2xl border border-slate-100">
        <h3 class="text-base font-black text-slate-900 mb-1">Change Password</h3>
        <p class="text-slate-500 text-xs mb-4">Setting new password for <span id="passwordTargetName" class="font-bold text-slate-800"></span>.</p>
        
        <form method="POST" action="systemsettings.php" class="space-y-4" onsubmit="return submitPasswordChange()">
            <input type="hidden" name="change_admin_password" value="1">
            <input type="hidden" name="target_admin_id" id="passwordTargetId" value="">
            <div>
                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">New Password</label>
                <input type="password" name="new_password" id="newPasswordField" required minlength="8" class="w-full rounded-xl border-slate-300 text-sm p-2.5 focus:ring-primary focus:border-primary">
            </div>
            <div>
                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">Confirm Password</label>
                <input type="password" name="confirm_new_password" id="confirmNewPasswordField" required minlength="8" class="w-full rounded-xl border-slate-300 text-sm p-2.5 focus:ring-primary focus:border-primary">
            </div>
            <p id="passwordModalError" class="hidden text-xs font-bold text-red-500"></p>
            <div class="flex gap-2 pt-2">
                <button type="button" onclick="closePasswordModal()" class="flex-1 py-2 rounded-xl font-bold text-slate-600 border border-slate-200 hover:bg-slate-50 text-xs">Cancel</button>
                <button type="submit" id="passwordSubmitBtn" class="flex-1 py-2 rounded-xl font-bold bg-blue-600 text-white hover:bg-blue-700 text-xs shadow-sm">Update</button>
            </div>
        </form>
    </div>
</div>

<script>
    let revokeTargetUrl = '';

    function openRevokeModal(targetUrl, adminName) {
        revokeTargetUrl = targetUrl;
        document.getElementById('revokeTargetName').textContent = adminName || '';
        document.getElementById('revokeConfirmBtn').disabled = false;
        document.getElementById('revokeModal').classList.remove('hidden');
        document.getElementById('revokeModal').classList.add('flex');
    }

    function closeRevokeModal() {
        document.getElementById('revokeModal').classList.remove('flex');
        document.getElementById('revokeModal').classList.add('hidden');
    }

    function confirmRevoke() {
        if (!revokeTargetUrl) return;
        document.getElementById('revokeConfirmBtn').disabled = true;
        window.location.href = revokeTargetUrl;
    }

    function openPasswordModal(adminId, adminName) {
        document.getElementById('passwordTargetId').value = adminId || '';
        document.getElementById('passwordTargetName').textContent = adminName || '';
        document.getElementById('newPasswordField').value = '';
        document.getElementById('confirmNewPasswordField').value = '';
        document.getElementById('passwordModalError').classList.add('hidden');
        document.getElementById('passwordSubmitBtn').disabled = false;
        document.getElementById('passwordModal').classList.remove('hidden');
        document.getElementById('passwordModal').classList.add('flex');
    }

    function closePasswordModal() {
        document.getElementById('passwordModal').classList.remove('flex');
        document.getElementById('passwordModal').classList.add('hidden');
    }

    function submitPasswordChange() {
        const newPassword = document.getElementById('newPasswordField').value;
        const confirmPassword = document.getElementById('confirmNewPasswordField').value;
        const errorBox = document.getElementById('passwordModalError');

        if (newPassword.length < 8) {
            errorBox.textContent = 'Password must be at least 8 characters.';
            errorBox.classList.remove('hidden');
            return false;
        }

        if (newPassword !== confirmPassword) {
            errorBox.textContent = 'Passwords do not match.';
            errorBox.classList.remove('hidden');
            return false;
        }

        errorBox.classList.add('hidden');
        document.getElementById('passwordSubmitBtn').disabled = true;
        return true;
    }
</script>

</body>
</html>