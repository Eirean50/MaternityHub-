<?php
session_start();
require_once 'db.php';

// AUTH: must be logged in clinic admin
if (empty($_SESSION['user_id']) || empty($_SESSION['TenantID'])) {
    header("Location: registration.php");
    exit();
}

// --- LOGOUT HANDLER (no logout.php) ---
if (isset($_GET['logout']) && $_GET['logout'] == '1') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header("Location: registration.php");
    exit();
}

$tenantId   = $_SESSION['TenantID'];
$userId     = $_SESSION['user_id'];
$fullName   = $_SESSION['full_name'] ?? 'Clinic Admin';
$role       = $_SESSION['role'] ?? 'Admin';

// Fetch tenant
$stmt = $pdo->prepare("SELECT TenantID, clinic_name, clinic_code, status, rejection_reason, doh_lto_no FROM tenants WHERE TenantID = ? LIMIT 1");
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tenant) {
    session_destroy();
    header("Location: registration.php");
    exit();
}

$tStatus = $tenant['status'] ?? '';

// If approved (Active) or pending payment -> redirect to dashboard / payment
if ($tStatus === 'Active') {
    $cParam = !empty($tenant['clinic_code']) ? '?c=' . urlencode($tenant['clinic_code']) : '';
    header("Location: ClinicHomepage.php" . $cParam);
    exit();
}
if ($tStatus === 'Pending Payment') {
    // Send them back through registration login so PayMongo redirect kicks in
    session_destroy();
    header("Location: registration.php?msg=approved_pay_now");
    exit();
}

$flash = null; $flashType = 'success';

// Handle resubmission upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['doh_lto'])) {
    if ($tStatus !== 'Rejected') {
        $flash = "Resubmission is only available for rejected clinics.";
        $flashType = 'error';
    } elseif ($_FILES['doh_lto']['error'] !== UPLOAD_ERR_OK) {
        $flash = "Please choose a valid file to upload.";
        $flashType = 'error';
    } else {
        $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
        $ext = strtolower(pathinfo($_FILES['doh_lto']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
            $flash = "Only JPG, PNG, or PDF files are allowed.";
            $flashType = 'error';
        } elseif ($_FILES['doh_lto']['size'] > 5 * 1024 * 1024) {
            $flash = "File too large. Maximum size is 5MB.";
            $flashType = 'error';
        } else {
            $newName = time() . '_' . preg_replace('/[^a-z0-9]/', '', strtolower($tenant['clinic_code'])) . '_doh_lto.' . $ext;
            if (!is_dir('uploads/doh_lto/')) { @mkdir('uploads/doh_lto/', 0777, true); }
            $destPath = 'uploads/doh_lto/' . $newName;
            if (move_uploaded_file($_FILES['doh_lto']['tmp_name'], $destPath)) {
                try {
                    $upd = $pdo->prepare("UPDATE tenants SET doh_lto_no = ?, status = 'Pending Approval', rejection_reason = NULL WHERE TenantID = ?");
                    $upd->execute([$destPath, $tenantId]);
                    // also reset linked user back to Pending so they cannot login until re-approved
                    $updU = $pdo->prepare("UPDATE users SET status = 'Pending' WHERE TenantID = ?");
                    $updU->execute([$tenantId]);

                    if (function_exists('log_audit')) {
                        log_audit($pdo, $fullName, $role, 'DOH-LTO Resubmitted', "Clinic '{$tenant['clinic_name']}' (ID: $tenantId) resubmitted DOH-LTO for re-approval.");
                    }

                    // Log out — they must wait for approval before logging back in
                    session_destroy();
                    header("Location: registration.php?msg=resubmitted");
                    exit();
                } catch (PDOException $e) {
                    $flash = "Database error: " . $e->getMessage();
                    $flashType = 'error';
                }
            } else {
                $flash = "Upload failed. Please try again.";
                $flashType = 'error';
            }
        }
    }
}

$reason = $tenant['rejection_reason'] ?? '';
$clinicName = $tenant['clinic_name'] ?? 'Your Clinic';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Clinic Rejected — MaternityHub</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Symbols+Outlined">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>body{font-family:'Inter',sans-serif;}</style>
</head>
<body class="bg-gradient-to-br from-rose-50 via-white to-slate-100 min-h-screen flex items-center justify-center p-4">

<div class="max-w-2xl w-full bg-white rounded-[2rem] shadow-2xl border border-slate-100 overflow-hidden">

    <!-- HEADER -->
    <div class="bg-gradient-to-r from-rose-500 to-red-600 p-8 text-white relative">
        <div class="flex items-center gap-4">
            <div class="size-16 rounded-3xl bg-white/20 flex items-center justify-center backdrop-blur-sm">
                <span class="material-symbols-outlined text-4xl">block</span>
            </div>
            <div>
                <p class="text-[11px] font-black uppercase tracking-widest opacity-80">Status</p>
                <h1 class="text-3xl font-black leading-tight">Clinic Registration Rejected</h1>
            </div>
        </div>
        <button type="button" onclick="openLogoutConfirm()" class="absolute top-6 right-6 inline-flex items-center gap-1.5 bg-white/15 hover:bg-white/25 px-3 py-1.5 rounded-full text-xs font-bold backdrop-blur-sm transition-all">
            <span class="material-symbols-outlined text-base">logout</span> Logout
        </button>
    </div>

    <!-- BODY -->
    <div class="p-8">

        <?php if ($flash): ?>
            <div class="mb-6 p-4 rounded-xl border <?= $flashType === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-emerald-50 border-emerald-200 text-emerald-700' ?> flex items-start gap-3">
                <span class="material-symbols-outlined"><?= $flashType === 'error' ? 'error' : 'check_circle' ?></span>
                <p class="text-sm font-semibold"><?= htmlspecialchars($flash) ?></p>
            </div>
        <?php endif; ?>

        <p class="text-slate-700 text-base leading-relaxed mb-5">
            Hi <strong><?= htmlspecialchars($fullName) ?></strong>, your maternity clinic
            <strong class="text-rose-600"><?= htmlspecialchars($clinicName) ?></strong> has been
            <strong class="text-rose-600">rejected</strong> by the MaternityHub Admins. You may submit
            the photo of your DOH-LTO again for approval.
        </p>

        <?php if (!empty($reason)): ?>
            <div class="bg-rose-50 border-l-4 border-rose-500 rounded-r-xl p-5 mb-6">
                <p class="text-[10px] font-black text-rose-700 uppercase tracking-widest mb-1">Reason for Rejection</p>
                <p class="text-sm text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($reason)) ?></p>
            </div>
        <?php endif; ?>

        <!-- RESUBMIT FORM -->
        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <label class="block">
                <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Resubmit DOH-LTO Photo / Document</span>
                <div class="mt-2 border-2 border-dashed border-slate-300 rounded-2xl p-6 text-center hover:border-rose-400 hover:bg-rose-50/40 transition-all cursor-pointer relative">
                    <input type="file" name="doh_lto" accept=".jpg,.jpeg,.png,.pdf" required class="absolute inset-0 opacity-0 cursor-pointer" onchange="document.getElementById('fname').textContent = this.files[0]?.name || 'No file chosen'">
                    <span class="material-symbols-outlined text-4xl text-slate-400">cloud_upload</span>
                    <p class="text-sm font-bold text-slate-700 mt-2">Click or drag a file here</p>
                    <p id="fname" class="text-xs text-slate-500 mt-1">JPG, PNG, or PDF — max 5MB</p>
                </div>
            </label>

            <button type="submit" class="w-full py-4 bg-gradient-to-r from-rose-500 to-red-600 text-white rounded-2xl font-black text-sm uppercase tracking-widest hover:shadow-xl hover:shadow-rose-500/30 transition-all flex items-center justify-center gap-2">
                <span class="material-symbols-outlined">send</span> Resubmit for Approval
            </button>
        </form>

        <p class="text-[11px] text-slate-400 text-center mt-6 leading-relaxed">
            After resubmission, you will be logged out. You'll receive an email once the Super Admin reviews your new submission.
        </p>
    </div>
</div>

<!-- LOGOUT CONFIRM MODAL -->
<div id="logoutConfirmModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
    <div class="bg-white rounded-[2rem] p-8 max-w-sm w-full shadow-2xl border border-slate-100 text-center">
        <div class="size-16 rounded-3xl flex items-center justify-center mx-auto mb-4 bg-rose-50 text-rose-500 shadow-inner">
            <span class="material-symbols-outlined text-3xl">logout</span>
        </div>
        <h3 class="text-xl font-black text-slate-900 mb-2 tracking-tight">Confirm Logout</h3>
        <p class="text-slate-500 text-sm mb-6">Are you sure you want to log out? You will be returned to the login page.</p>
        <div class="flex gap-3">
            <button type="button" onclick="closeLogoutConfirm()" class="flex-1 py-3 rounded-xl font-bold text-slate-500 bg-slate-100 hover:bg-slate-200 transition-all text-xs">Cancel</button>
            <a href="rejected.php?logout=1" class="flex-1 py-3 rounded-xl font-bold text-white bg-rose-500 hover:bg-rose-600 transition-all text-xs shadow-md shadow-rose-500/30 flex items-center justify-center">Yes, Log Out</a>
        </div>
    </div>
</div>

<script>
    function openLogoutConfirm() {
        const m = document.getElementById('logoutConfirmModal');
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
    function closeLogoutConfirm() {
        const m = document.getElementById('logoutConfirmModal');
        m.classList.remove('flex');
        m.classList.add('hidden');
    }
    document.getElementById('logoutConfirmModal').addEventListener('click', (e) => {
        if (e.target.id === 'logoutConfirmModal') closeLogoutConfirm();
    });
</script>

</body>
</html>
