<?php
// 1. SETTINGS & SESSION
ob_start();
date_default_timezone_set('Asia/Manila');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'db.php';

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $c = $_GET['c'] ?? '';

    if (isset($_SESSION['full_name'])) {
        try {
            $logoutName = $_SESSION['full_name'];
            $logoutRole = $_SESSION['role'] ?? 'User';
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $currentTime = date('Y-m-d H:i:s');
            $logoutTenant = $_SESSION['TenantID'] ?? null;

            $stmtLog = $pdo->prepare("INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, 'Logout', 'User securely logged out of their clinic portal.', ?, ?)");
            $stmtLog->execute([$logoutTenant, $logoutName, $logoutRole, $ip, $currentTime]);
        } catch (Exception $e) {
            // Silent fail
        }
    }

    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();

    if (!empty($c) && $c !== 'N/A') {
        header('Location: tenant_login.php?c=' . urlencode($c));
    } else {
        header('Location: tenant_login.php');
    }
    exit();
}

if (!isset($_SESSION['user_id']) || strtolower(trim((string)($_SESSION['role'] ?? ''))) === 'patient') {
    header('Location: tenant_login.php');
    exit();
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$tenant_id = $_SESSION['TenantID'] ?? null;
$userRole = $_SESSION['role'] ?? 'Staff';
$roleLower = strtolower(trim((string)$userRole));
$isStaffRole = ($roleLower === 'staff');
$isAdminRole = in_array($roleLower, ['admin', 'administrator', 'owner', 'owner/midwife'], true);

// Keep clinic settings page for admin roles.
if ($isAdminRole) {
    header('Location: tenantsettings.php');
    exit();
}

if (!$tenant_id || $userId <= 0) {
    header('Location: tenant_login.php');
    exit();
}

$displayName = $_SESSION['full_name'] ?? 'Staff User';
$msgError = null;

$clinicName = 'MaternityHub';
$clinicCode = 'N/A';
$clinicLogo = null;
$themeColor = '#16a34a';

try {
    $stmtClinic = $pdo->prepare('SELECT clinic_name, clinic_code, clinic_logo, theme_color FROM tenants WHERE TenantID = ? LIMIT 1');
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
} catch (PDOException $e) {
    // Silent fail
}

$hex = ltrim($themeColor, '#');
if (strlen($hex) == 3) {
    $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
}
$r = hexdec(substr($hex, 0, 2));
$g = hexdec(substr($hex, 2, 2));
$b = hexdec(substr($hex, 4, 2));
$luminance = ($r * 0.299) + ($g * 0.587) + ($b * 0.114);
$isLightTheme = ($luminance > 150);

$headerTextPrimary = $isLightTheme ? 'text-slate-900' : 'text-white';
$headerTextSecondary = $isLightTheme ? 'text-slate-700' : 'text-primary-light';
$headerTextMuted = $isLightTheme ? 'text-slate-400' : 'text-white/50';
$headerBadgeBg = $isLightTheme ? 'bg-slate-200 text-slate-800' : 'bg-black/20 text-white';
$headerIconBox = $isLightTheme ? 'bg-white border border-slate-200' : 'bg-white/15 border border-white/25';
$headerIconColor = $isLightTheme ? 'text-slate-700' : 'text-white/90';
$headerBtn = $isLightTheme ? 'bg-white hover:bg-slate-50 text-slate-800 border-slate-200 shadow-sm' : 'bg-white/15 hover:bg-white/25 text-white border-white/30';
$sidebarActive = $isLightTheme ? 'bg-slate-800 text-white shadow-md' : 'bg-primary/10 text-primary';
$mainBtn = $isLightTheme ? 'bg-slate-800 hover:bg-slate-900 text-white' : 'bg-primary hover:bg-primary-dark text-white';

// --- OWNER / STAFF ADMIN PERMISSION SYSTEM ---
$currentUserIsOwner = in_array($roleLower, ['admin', 'administrator', 'owner', 'owner/midwife'], true);
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

function log_staff_settings_audit($pdo, $tenant_id, $userName, $role, $actionType, $details) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $currentTime = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare('INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$tenant_id, $userName, $role, $actionType, $details, $ip, $currentTime]);
    } catch (Exception $e) {
        // Silent fail
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'update_profile_pic') {
        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === 0) {
            $file = $_FILES['profile_pic'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];

            if (!in_array($ext, $allowed, true)) {
                $msgError = 'Invalid image format. Allowed: JPG, JPEG, PNG, GIF.';
            } elseif ($file['size'] > 5000000) {
                $msgError = 'Image is too large. Maximum size is 5MB.';
            } else {
                $uploadDir = __DIR__ . '/uploads/profiles/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $filename = 'profile_' . $userId . '_' . time() . '.' . $ext;
                $target = $uploadDir . $filename;
                if (move_uploaded_file($file['tmp_name'], $target)) {
                    try {
                        $stmtEmail = $pdo->prepare('SELECT email FROM users WHERE id = ? AND TenantID = ? LIMIT 1');
                        $stmtEmail->execute([$userId, $tenant_id]);
                        $currentEmail = trim((string)$stmtEmail->fetchColumn());

                        $pdo->beginTransaction();

                        $stmt = $pdo->prepare('UPDATE users SET profile_image = ? WHERE id = ? AND TenantID = ?');
                        $stmt->execute([$filename, $userId, $tenant_id]);

                        if ($currentEmail !== '') {
                            $stmtClinicStaff = $pdo->prepare('UPDATE clinic_staff SET profile_image = ? WHERE TenantID = ? AND LOWER(TRIM(COALESCE(email_address, ""))) = LOWER(TRIM(?))');
                            $stmtClinicStaff->execute([$filename, $tenant_id, $currentEmail]);
                        }

                        $pdo->commit();

                        log_staff_settings_audit($pdo, $tenant_id, $_SESSION['full_name'] ?? $displayName, $userRole, 'Profile Update', 'Updated profile picture.');
                        header('Location: staffsettings.php?msg=profile_updated');
                        exit();
                    } catch (PDOException $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $msgError = 'Failed to save profile picture.';
                    }
                } else {
                    $msgError = 'Failed to upload image file.';
                }
            }
        } else {
            $msgError = 'No profile picture selected.';
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'update_username') {
        $newFirstName = trim($_POST['first_name'] ?? '');
        $newMiddleName = trim($_POST['middle_name'] ?? '');
        $newLastName = trim($_POST['last_name'] ?? '');

        if ($newFirstName === '' || $newLastName === '') {
            $msgError = 'First name and last name are required.';
        } else {
            try {
                // Get old name and email for audit log and clinic_staff sync
                $stmtOld = $pdo->prepare('SELECT first_name, middle_name, last_name, email FROM users WHERE id = ? AND TenantID = ? LIMIT 1');
                $stmtOld->execute([$userId, $tenant_id]);
                $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);
                $oldFullName = trim(($oldData['first_name'] ?? '') . ' ' . ($oldData['middle_name'] ?? '') . ' ' . ($oldData['last_name'] ?? ''));
                $oldFullName = preg_replace('/\s+/', ' ', $oldFullName);
                $oldEmail = trim((string)($oldData['email'] ?? ''));

                $pdo->beginTransaction();

                // Update users table
                $stmt = $pdo->prepare('UPDATE users SET first_name = ?, middle_name = ?, last_name = ? WHERE id = ? AND TenantID = ?');
                $stmt->execute([$newFirstName, $newMiddleName, $newLastName, $userId, $tenant_id]);

                // Sync to clinic_staff table so staffmanagement.php reflects the change
                if ($oldEmail !== '') {
                    $stmtSyncStaff = $pdo->prepare('UPDATE clinic_staff SET first_name = ?, middle_name = ?, last_name = ? WHERE TenantID = ? AND LOWER(TRIM(COALESCE(email_address, ""))) = LOWER(TRIM(?))');
                    $stmtSyncStaff->execute([$newFirstName, $newMiddleName, $newLastName, $tenant_id, $oldEmail]);
                }

                $pdo->commit();

                $updatedFullName = trim($newFirstName . ' ' . $newMiddleName . ' ' . $newLastName);
                $updatedFullName = preg_replace('/\s+/', ' ', $updatedFullName);
                $_SESSION['full_name'] = $updatedFullName;

                // Detailed audit log: old name → new name
                $auditDetails = 'Changed display name from "' . $oldFullName . '" to "' . $updatedFullName . '".';
                log_staff_settings_audit($pdo, $tenant_id, $updatedFullName, $userRole, 'Username Change', $auditDetails);

                header('Location: staffsettings.php?msg=username_updated');
                exit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $msgError = 'Failed to update username.';
            }
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'update_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $msgError = 'Please fill in all password fields.';
        } elseif ($newPassword !== $confirmPassword) {
            $msgError = 'New password and confirmation do not match.';
        } elseif (strlen($newPassword) < 8) {
            $msgError = 'New password must be at least 8 characters.';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ? AND TenantID = ? LIMIT 1');
                $stmt->execute([$userId, $tenant_id]);
                $currentHash = $stmt->fetchColumn();

                if (!$currentHash || !password_verify($currentPassword, $currentHash)) {
                    $msgError = 'Current password is incorrect.';
                } else {
                    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmtUpdate = $pdo->prepare('UPDATE users SET password = ? WHERE id = ? AND TenantID = ?');
                    $stmtUpdate->execute([$newHash, $userId, $tenant_id]);

                    log_staff_settings_audit($pdo, $tenant_id, $_SESSION['full_name'] ?? $displayName, $userRole, 'Password Update', 'Updated account password.');
                    header('Location: staffsettings.php?msg=password_updated');
                    exit();
                }
            } catch (PDOException $e) {
                $msgError = 'Failed to update password.';
            }
        }
    }
}

$userFirstName = '';
$userMiddleName = '';
$userLastName = '';
$userEmail = '';
$profilePic = 'https://ui-avatars.com/api/?name=' . urlencode($displayName) . '&background=' . ltrim($themeColor, '#') . '&color=fff';

try {
    $stmtUser = $pdo->prepare('SELECT u.first_name, u.middle_name, u.last_name, u.email, COALESCE(u.profile_image, cs.profile_image) AS profile_image FROM users u LEFT JOIN clinic_staff cs ON cs.TenantID = u.TenantID AND LOWER(TRIM(COALESCE(cs.email_address, ""))) = LOWER(TRIM(COALESCE(u.email, ""))) WHERE u.id = ? AND u.TenantID = ? LIMIT 1');
    $stmtUser->execute([$userId, $tenant_id]);
    $userData = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if ($userData) {
        $userFirstName = $userData['first_name'] ?? '';
        $userMiddleName = $userData['middle_name'] ?? '';
        $userLastName = $userData['last_name'] ?? '';
        $userEmail = $userData['email'] ?? '';
        $displayName = preg_replace('/\s+/', ' ', trim($userFirstName . ' ' . $userMiddleName . ' ' . $userLastName));

        if (!empty($userData['profile_image'])) {
            $profileImageValue = trim((string)$userData['profile_image']);
            if (preg_match('~^https?://~i', $profileImageValue) || str_starts_with($profileImageValue, 'uploads/')) {
                $profilePic = $profileImageValue;
            } else {
                $profilePic = 'uploads/profiles/' . $profileImageValue;
            }
        }
    }
} catch (PDOException $e) {
    // Silent fail
}

$settingsHref = $isAdminRole ? 'tenantsettings.php' : 'staffsettings.php';
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Staff Settings - <?= htmlspecialchars($clinicName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet"/>
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
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        @view-transition { navigation: auto; }
        header { view-transition-name: header; }
        aside { view-transition-name: sidebar; }
        ::view-transition-old(sidebar), ::view-transition-new(sidebar),
        ::view-transition-old(header), ::view-transition-new(header) { animation: none; }
    </style>
</head>
<body class="bg-background-light text-slate-800 h-screen flex flex-col relative text-sm antialiased font-display">

<?php if (isset($_GET['msg']) || $msgError): ?>
<div id="alertMsg" class="fixed top-24 left-1/2 -translate-x-1/2 z-[120] bg-white border-l-4 <?= $msgError ? 'border-red-500' : 'border-primary' ?> p-4 rounded-2xl shadow-2xl flex items-center gap-3">
    <span class="material-symbols-outlined <?= $msgError ? 'text-red-500' : 'text-primary' ?>"><?= $msgError ? 'error' : 'check_circle' ?></span>
    <p class="text-xs font-black text-slate-800">
        <?php
            if ($msgError) {
                echo htmlspecialchars($msgError);
            } else {
                $msg = $_GET['msg'] ?? '';
                if ($msg === 'profile_updated') echo 'Profile picture updated successfully.';
                elseif ($msg === 'username_updated') echo 'Username updated successfully.';
                elseif ($msg === 'password_updated') echo 'Password updated successfully.';
                else echo 'Settings updated.';
            }
        ?>
    </p>
</div>
<script>setTimeout(() => { document.getElementById('alertMsg')?.remove(); }, 3000);</script>
<?php endif; ?>

<div id="loggingOutScreen" class="fixed inset-0 z-[110] hidden bg-white flex-col items-center justify-center">
    <div class="size-12 border-4 border-slate-200 border-t-primary rounded-full animate-spin mb-4"></div>
    <p class="font-bold text-slate-800 animate-pulse text-xs">Logging out safely...</p>
</div>

<div id="logoutModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm">
    <div class="bg-white rounded-[2rem] p-6 max-w-xs w-full shadow-2xl text-center border border-slate-100">
        <div class="size-12 rounded-2xl bg-red-50 text-red-500 flex items-center justify-center mx-auto mb-4">
            <span class="material-symbols-outlined text-2xl">logout</span>
        </div>
        <h3 class="text-base font-black text-slate-900 mb-1">Logout Account?</h3>
        <p class="text-slate-500 text-[11px] mb-6">Sigurado ka bang gusto mong lumabas?</p>
        <div class="flex gap-2">
            <button onclick="closeLogoutModal()" class="flex-1 py-2 rounded-xl font-bold text-slate-400 hover:bg-slate-100 text-[11px]">Cancel</button>
            <button onclick="confirmLogout()" class="flex-1 py-2 rounded-xl font-bold bg-red-500 text-white hover:bg-red-600 text-[11px] shadow-lg shadow-red-100">Logout</button>
        </div>
    </div>
</div>

<div class="min-h-screen flex flex-col">
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
                <p class="<?= $headerBadgeBg ?> px-2 py-0.5 rounded text-[10px] font-black tracking-widest">CODE: <?= htmlspecialchars($clinicCode) ?></p>
            </div>
        </div>
    </div>

    <div class="flex items-center gap-4 ml-auto">
        <div class="hidden sm:flex flex-col text-right justify-center <?= $headerTextPrimary ?>">
            <p class="text-sm font-bold leading-none"><?= htmlspecialchars($displayName) ?></p>
            <p class="<?= $headerTextSecondary ?> text-[9px] italic opacity-80 mt-1 uppercase tracking-tighter"><?= htmlspecialchars($userRole) ?></p>
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
                <a href="support.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">support_agent</span> <span>Help &amp; Support</span>
                </a>
                <?php endif; ?>
                <?php if ($currentUserIsOwner || $currentUserIsStaffAdmin || in_array('feedback', $currentUserGrantedFeatures)): ?>
                <a href="feedback.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">feedback</span> <span>Feedback</span>
                </a>
                <?php endif; ?>
                <?php if ($currentUserIsOwner): ?>
                <a href="tenantauditlogs.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">history</span> <span>Audit Logs</span>
                </a>
                <?php endif; ?>
                <a href="<?= $currentUserIsOwner ? 'tenantsettings.php' : 'staffsettings.php' ?>" onclick="event.preventDefault(); return false;" aria-current="page" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] <?= $sidebarActive ?> font-bold shadow-sm transition-all hover:scale-[1.02]">
                    <span class="material-symbols-outlined text-2xl icon-filled">settings</span> <span>Settings</span>
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

    <main class="flex-1 overflow-y-auto p-8 relative z-10">
        <div class="max-w-6xl mx-auto space-y-8">
            <section class="rounded-[2rem] bg-white border border-slate-200 shadow-soft p-6 md:p-8">
                <h2 class="text-lg md:text-xl font-black text-slate-900">Staff Account Settings</h2>
                <p class="text-xs text-slate-500 mt-1">Update your profile picture, username, and password.</p>
            </section>

            <section class="rounded-[2rem] bg-white border border-slate-200 shadow-soft p-6 md:p-8">
                <h3 class="text-sm font-black uppercase tracking-widest text-slate-500 mb-4">Profile Picture</h3>
                <div class="flex flex-col md:flex-row items-start md:items-center gap-6">
                    <div class="size-20 rounded-full bg-cover bg-center border-4 border-white shadow" style="background-image: url('<?= htmlspecialchars($profilePic) ?>');"></div>
                    <form method="POST" enctype="multipart/form-data" class="w-full md:w-auto space-y-3">
                        <input type="hidden" name="action" value="update_profile_pic">
                        <input type="file" name="profile_pic" accept=".jpg,.jpeg,.png,.gif" class="block text-xs text-slate-600 file:mr-3 file:rounded-xl file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-xs file:font-bold file:text-slate-700 hover:file:bg-slate-200" required>
                        <button type="submit" class="px-4 py-2 rounded-xl <?= $mainBtn ?> text-xs font-black">Save Profile Picture</button>
                    </form>
                </div>
            </section>

            <section class="rounded-[2rem] bg-white border border-slate-200 shadow-soft p-6 md:p-8">
                <h3 class="text-sm font-black uppercase tracking-widest text-slate-500 mb-4">Personal Information</h3>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="update_username">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">First Name</label>
                            <input type="text" name="first_name" value="<?= htmlspecialchars($userFirstName) ?>" class="mt-2 w-full rounded-2xl border-slate-200 px-4 py-3 text-sm focus:border-primary focus:ring-primary" required>
                        </div>
                        <div>
                            <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Middle Name</label>
                            <input type="text" name="middle_name" value="<?= htmlspecialchars($userMiddleName) ?>" class="mt-2 w-full rounded-2xl border-slate-200 px-4 py-3 text-sm focus:border-primary focus:ring-primary" placeholder="Optional">
                        </div>
                        <div>
                            <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Last Name</label>
                            <input type="text" name="last_name" value="<?= htmlspecialchars($userLastName) ?>" class="mt-2 w-full rounded-2xl border-slate-200 px-4 py-3 text-sm focus:border-primary focus:ring-primary" required>
                        </div>
                    </div>
                    <div>
                        <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Email (Read Only)</label>
                        <input type="email" value="<?= htmlspecialchars($userEmail) ?>" class="mt-2 w-full rounded-2xl border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-500" readonly>
                    </div>
                    <button type="submit" class="px-4 py-2 rounded-xl <?= $mainBtn ?> text-xs font-black">Save Changes</button>
                </form>
            </section>

            <section class="rounded-[2rem] bg-white border border-slate-200 shadow-soft p-6 md:p-8">
                <h3 class="text-sm font-black uppercase tracking-widest text-slate-500 mb-4">Password</h3>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="update_password">
                    <div>
                        <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Current Password</label>
                        <input type="password" name="current_password" class="mt-2 w-full rounded-2xl border-slate-200 px-4 py-3 text-sm focus:border-primary focus:ring-primary" required>
                    </div>
                    <div>
                        <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">New Password</label>
                        <input type="password" name="new_password" minlength="8" class="mt-2 w-full rounded-2xl border-slate-200 px-4 py-3 text-sm focus:border-primary focus:ring-primary" required>
                    </div>
                    <div>
                        <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Confirm New Password</label>
                        <input type="password" name="confirm_password" minlength="8" class="mt-2 w-full rounded-2xl border-slate-200 px-4 py-3 text-sm focus:border-primary focus:ring-primary" required>
                    </div>
                    <button type="submit" class="px-4 py-2 rounded-xl <?= $mainBtn ?> text-xs font-black">Save Password</button>
                </form>
            </section>
        </div>
    </main>
</div>
</div>

<script>
function openLogoutModal(){
    const modal = document.getElementById('logoutModal');
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeLogoutModal(){
    const modal = document.getElementById('logoutModal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function confirmLogout(){
    closeLogoutModal();
    const loading = document.getElementById('loggingOutScreen');
    if (loading) {
        loading.classList.remove('hidden');
        loading.classList.add('flex');
    }
    setTimeout(() => {
        window.location.href = '?action=logout&c=<?= urlencode($clinicCode) ?>';
    }, 1400);
}
</script>

</body>
</html>
