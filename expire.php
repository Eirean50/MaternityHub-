<?php
session_start();
require_once 'db.php';

// Central pricing & duration map for the 3 subscription tiers.
if (!function_exists('mh_plan_info')) {
    function mh_plan_info($plan) {
        $p = strtolower(trim((string)$plan));
        if (in_array($p, ['semi-annual','semi annual','semiannual','semi'], true)) {
            return ['key' => 'Semi-Annual', 'label' => 'Semi-Annual', 'price' => 13499, 'days' => 180];
        }
        if (in_array($p, ['annual','yearly','year'], true)) {
            return ['key' => 'Annual',      'label' => 'Annual',      'price' => 24999, 'days' => 360];
        }
        return ['key' => 'Monthly', 'label' => 'Monthly', 'price' => 2499, 'days' => 30];
    }
}

// AUTH: must be logged in clinic admin
if (empty($_SESSION['user_id']) || empty($_SESSION['TenantID'])) {
    header("Location: registration.php");
    exit();
}

// --- LOGOUT HANDLER (in-file) ---
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

$tenantId = $_SESSION['TenantID'];
$userId   = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Clinic Admin';
$role     = $_SESSION['role'] ?? 'Admin';

// Fetch tenant
$stmt = $pdo->prepare("SELECT TenantID, clinic_name, clinic_code, plan, status, expires_at FROM tenants WHERE TenantID = ? LIMIT 1");
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tenant) {
    session_destroy();
    header("Location: registration.php");
    exit();
}

$tStatus = $tenant['status'] ?? '';

// AUTO-EXPIRE: Active but past expires_at -> set Expired (atomic), notify owner, log audit
if ($tStatus === 'Active' && !empty($tenant['expires_at']) && strtotime($tenant['expires_at']) < time()) {
    try {
        $upd = $pdo->prepare("UPDATE tenants SET status = 'Expired' WHERE TenantID = ? AND status = 'Active' AND expires_at IS NOT NULL AND expires_at < NOW()");
        $upd->execute([$tenantId]);

        if ($upd->rowCount() >= 1) {
            // Owner info for email
            $info = $pdo->prepare("SELECT t.clinic_name,
                                          (SELECT email      FROM users WHERE TenantID = t.TenantID ORDER BY id ASC LIMIT 1) AS owner_email,
                                          (SELECT first_name FROM users WHERE TenantID = t.TenantID ORDER BY id ASC LIMIT 1) AS owner_fname
                                   FROM tenants t WHERE t.TenantID = ? LIMIT 1");
            $info->execute([$tenantId]);
            $r = $info->fetch(PDO::FETCH_ASSOC);

            if ($r) {
                // Audit log
                try {
                    $auditStmt = $pdo->prepare("INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, 'System Action', 'System', 'Subscription Expired', ?, 'System Auto-Expire', NOW())");
                    $auditStmt->execute([$tenantId, 'Subscription for "' . $r['clinic_name'] . '" automatically expired. Clinic access paused pending renewal.']);
                } catch (PDOException $eA) {}

                // Notify owner
                if (!empty($r['owner_email'])) {
                    $sender   = 'maternityhub@alwaysdata.net';
                    $subject  = 'Your MaternityHub Subscription Has Expired';
                    $headers  = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: $sender\r\nReply-To: maternityhub@alwaysdata.net\r\nX-Mailer: PHP/" . phpversion();
                    $body     = "<div style='font-family:Arial,sans-serif;'>"
                              . "<h2 style='color:#b91c1c;'>Subscription Expired</h2>"
                              . "<p>Hi " . htmlspecialchars($r['owner_fname'] ?: 'Clinic Owner') . ",</p>"
                              . "<p>Your <strong>MaternityHub</strong> subscription for <strong>" . htmlspecialchars($r['clinic_name']) . "</strong> has expired.</p>"
                              . "<p>Please log in and renew your subscription to restore full clinic access.</p>"
                              . "</div>";
                    @mail($r['owner_email'], $subject, $body, $headers);
                }
            }
        }
    } catch (PDOException $e) {}
    $tStatus = 'Expired';
}

// If Active and not expired -> redirect to dashboard/clinic homepage
if ($tStatus === 'Active') {
    $cParam = !empty($tenant['clinic_code']) ? '?c=' . urlencode($tenant['clinic_code']) : '';
    header("Location: ClinicHomepage.php" . $cParam);
    exit();
}

// PAYMONGO CONFIG (must match registration.php)
$paymongoSecretKey = 'secret';
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";

// --- HANDLE RENEW ACTION (POST) ---
$flash = null; $flashType = 'error';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['renew'])) {
    if ($tStatus !== 'Expired') {
        $flash = "Renewal is only available for expired clinics.";
    } else {
        // Plan selected on this page (defaults to current tenant plan)
        $selectedPlanRaw = $_POST['selected_plan'] ?? ($tenant['plan'] ?? 'Monthly');
        $info          = mh_plan_info($selectedPlanRaw);
        $planName      = $info['label'];
        $planKey       = $info['key'];
        $amountPesos   = (int)$info['price'];
        $amountInCents = $amountPesos * 100;

        // Persist the chosen plan on tenants so registration.php success handler activates the correct duration & price
        try {
            $updPlan = $pdo->prepare("UPDATE tenants SET plan = ? WHERE TenantID = ?");
            $updPlan->execute([$planKey, $tenantId]);
            $tenant['plan'] = $planKey;
        } catch (PDOException $ePlan) {}

        $base = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
        $successUrl = $base . '/registration.php?payment=success&tid=' . urlencode($tenantId) . '&uid=' . urlencode($userId) . '&c=' . urlencode($tenant['clinic_code']);
        $cancelUrl  = $base . '/expire.php?msg=cancel';

        $payload = [
            'data' => [
                'attributes' => [
                    'send_email_receipt' => true,
                    'show_description' => true,
                    'show_line_items' => true,
                    'description' => "MaternityHub $planName Renewal for " . $tenant['clinic_name'],
                    'line_items' => [[
                        'name' => "MaternityHub $planName Renewal (" . $info['days'] . " days)",
                        'amount' => $amountInCents,
                        'currency' => 'PHP',
                        'quantity' => 1
                    ]],
                    'payment_method_types' => ['gcash', 'paymaya', 'card'],
                    'success_url' => $successUrl,
                    'cancel_url'  => $cancelUrl,
                ]
            ]
        ];

        $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($paymongoSecretKey . ':')
        ]);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            $flash = "Unable to start renewal payment: $err";
        } else {
            $rd = json_decode($response, true);
            if (!empty($rd['data']['attributes']['checkout_url'])) {
                header("Location: " . $rd['data']['attributes']['checkout_url']);
                exit();
            }
            $flash = "PayMongo error: " . ($rd['errors'][0]['detail'] ?? 'Unknown error.');
        }
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'cancel') {
    $flash = "Renewal payment was cancelled. You may try again anytime.";
}

$clinicName = $tenant['clinic_name'] ?? 'Your Clinic';
$expiresAt  = $tenant['expires_at'] ?? null;
$daysExpired = 0;
if ($expiresAt) {
    $diffSec = time() - strtotime($expiresAt);
    if ($diffSec > 0) { $daysExpired = (int) floor($diffSec / 86400); }
}
$expiredLabel = $expiresAt ? date('F d, Y', strtotime($expiresAt)) : '—';

// Plan-aware display info (must match PayMongo charge above and registration.php pricing)
$displayInfo      = mh_plan_info($tenant['plan'] ?? '');
$displayPlanLabel = $displayInfo['label'];
$displayPlanPrice = (int)$displayInfo['price'];
$displayPlanDays  = (int)$displayInfo['days'];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Subscription Expired — MaternityHub</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Symbols+Outlined">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>body{font-family:'Inter',sans-serif;}</style>
</head>
<body class="bg-gradient-to-br from-amber-50 via-white to-slate-100 min-h-screen flex items-center justify-center p-4">

<div class="max-w-2xl w-full bg-white rounded-[2rem] shadow-2xl border border-slate-100 overflow-hidden">

    <!-- HEADER -->
    <div class="bg-gradient-to-r from-amber-500 to-orange-600 p-8 text-white relative">
        <div class="flex items-center gap-4">
            <div class="size-16 rounded-3xl bg-white/20 flex items-center justify-center backdrop-blur-sm">
                <span class="material-symbols-outlined text-4xl">schedule</span>
            </div>
            <div>
                <p class="text-[11px] font-black uppercase tracking-widest opacity-80">Status</p>
                <h1 class="text-3xl font-black leading-tight">Subscription Expired</h1>
            </div>
        </div>
        <button type="button" onclick="openLogoutConfirm()" class="absolute top-6 right-6 inline-flex items-center gap-1.5 bg-white/15 hover:bg-white/25 px-3 py-1.5 rounded-full text-xs font-bold backdrop-blur-sm transition-all">
            <span class="material-symbols-outlined text-base">logout</span> Logout
        </button>
    </div>

    <!-- BODY -->
    <div class="p-8">

        <?php if ($flash): ?>
            <div class="mb-6 p-4 rounded-xl border bg-red-50 border-red-200 text-red-700 flex items-start gap-3">
                <span class="material-symbols-outlined">error</span>
                <p class="text-sm font-semibold"><?= htmlspecialchars($flash) ?></p>
            </div>
        <?php endif; ?>

        <p class="text-slate-700 text-base leading-relaxed mb-5">
            Hi <strong><?= htmlspecialchars($fullName) ?></strong>, your <strong>MaternityHub <?= htmlspecialchars($displayPlanLabel) ?></strong> subscription
            for <strong class="text-amber-600"><?= htmlspecialchars($clinicName) ?></strong> has
            <strong class="text-amber-600">expired</strong>.
            Renew now to regain full access to your clinic dashboard.
        </p>

        <!-- INFO PANEL -->
        <div class="grid grid-cols-2 gap-4 mb-6">
            <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Expired On</p>
                <p class="text-sm font-bold text-slate-800"><?= htmlspecialchars($expiredLabel) ?></p>
            </div>
            <div class="bg-amber-50 rounded-xl p-4 border border-amber-100">
                <p class="text-[10px] font-black text-amber-700 uppercase tracking-widest mb-1">Days Expired</p>
                <p class="text-sm font-black text-amber-700"><?= $daysExpired ?> day<?= $daysExpired === 1 ? '' : 's' ?></p>
            </div>
        </div>

        <!-- RENEW PANEL -->
        <div class="bg-gradient-to-br from-emerald-50 to-emerald-100/50 border border-emerald-200 rounded-2xl p-6 mb-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <p class="text-[10px] font-black text-emerald-700 uppercase tracking-widest mb-1">Choose Your Plan</p>
                    <h3 class="text-lg font-black text-slate-900">Renew or upgrade your subscription</h3>
                </div>
                <span class="material-symbols-outlined text-5xl text-emerald-600 opacity-40">payments</span>
            </div>

            <form method="POST" id="renewForm">
                <input type="hidden" name="renew" value="1">
                <input type="hidden" name="selected_plan" id="selected_plan_field" value="<?= htmlspecialchars($displayInfo['key']) ?>">

                <?php
                    $planOptions = [
                        ['key' => 'Monthly',     'label' => 'Monthly',     'price' => 2499,  'days' => 30,  'suffix' => '/month',    'badge' => null],
                        ['key' => 'Semi-Annual', 'label' => 'Semi-Annual', 'price' => 13499, 'days' => 180, 'suffix' => '/6 months', 'badge' => 'Save more'],
                        ['key' => 'Annual',      'label' => 'Annual',      'price' => 24999, 'days' => 360, 'suffix' => '/year',     'badge' => 'Best Value'],
                    ];
                    $currentKey = $displayInfo['key'];
                ?>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">
                    <?php foreach ($planOptions as $po): $isActive = ($po['key'] === $currentKey); ?>
                        <button type="button"
                                data-plan="<?= htmlspecialchars($po['key']) ?>"
                                class="plan-option text-left p-4 rounded-xl border-2 transition-all relative <?= $isActive ? 'border-emerald-500 bg-white shadow-md' : 'border-slate-200 bg-white/60 hover:border-emerald-300' ?>">
                            <?php if (!empty($po['badge'])): ?>
                                <span class="absolute -top-2 right-3 bg-amber-400 text-slate-900 text-[9px] font-black uppercase tracking-widest px-2 py-0.5 rounded-full shadow"><?= htmlspecialchars($po['badge']) ?></span>
                            <?php endif; ?>
                            <p class="text-[10px] font-black uppercase tracking-widest text-slate-500 mb-1"><?= $po['days'] ?> Days</p>
                            <p class="text-base font-black text-slate-900 leading-tight"><?= htmlspecialchars($po['label']) ?></p>
                            <p class="text-lg font-black text-emerald-700 mt-1">₱<?= number_format($po['price']) ?><span class="text-[10px] text-slate-500 font-bold"><?= $po['suffix'] ?></span></p>
                            <?php if ($isActive): ?>
                                <span class="absolute top-2 right-2 material-symbols-outlined text-emerald-600 text-base check-icon">check_circle</span>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <ul class="text-xs text-slate-600 space-y-1 mb-5">
                    <li class="flex items-center gap-2"><span class="material-symbols-outlined text-emerald-600 text-base">check_circle</span> Full access to clinic portal for the selected duration</li>
                    <li class="flex items-center gap-2"><span class="material-symbols-outlined text-emerald-600 text-base">check_circle</span> Unlimited patients, appointments &amp; staff</li>
                    <li class="flex items-center gap-2"><span class="material-symbols-outlined text-emerald-600 text-base">check_circle</span> GCash, PayMaya, or Card payment</li>
                </ul>

                <button type="submit" class="w-full py-4 bg-gradient-to-r from-emerald-500 to-emerald-700 text-white rounded-2xl font-black text-sm uppercase tracking-widest hover:shadow-xl hover:shadow-emerald-500/30 transition-all flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined">credit_card</span> Renew &amp; Pay Now
                </button>
            </form>
        </div>

        <p class="text-[11px] text-slate-400 text-center leading-relaxed">
            After successful payment, your subscription will be extended based on the plan you selected and you will regain access to your clinic dashboard.
        </p>
    </div>
</div>

<!-- LOGOUT CONFIRM MODAL -->
<div id="logoutConfirmModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
    <div class="bg-white rounded-[2rem] p-8 max-w-sm w-full shadow-2xl border border-slate-100 text-center">
        <div class="size-16 rounded-3xl flex items-center justify-center mx-auto mb-4 bg-amber-50 text-amber-500 shadow-inner">
            <span class="material-symbols-outlined text-3xl">logout</span>
        </div>
        <h3 class="text-xl font-black text-slate-900 mb-2 tracking-tight">Confirm Logout</h3>
        <p class="text-slate-500 text-sm mb-6">Are you sure you want to log out? You will be returned to the login page.</p>
        <div class="flex gap-3">
            <button type="button" onclick="closeLogoutConfirm()" class="flex-1 py-3 rounded-xl font-bold text-slate-500 bg-slate-100 hover:bg-slate-200 transition-all text-xs">Cancel</button>
            <a href="expire.php?logout=1" class="flex-1 py-3 rounded-xl font-bold text-white bg-amber-500 hover:bg-amber-600 transition-all text-xs shadow-md shadow-amber-500/30 flex items-center justify-center">Yes, Log Out</a>
        </div>
    </div>
</div>

<script>
    function openLogoutConfirm() {
        const m = document.getElementById('logoutConfirmModal');
        m.classList.remove('hidden'); m.classList.add('flex');
    }
    function closeLogoutConfirm() {
        const m = document.getElementById('logoutConfirmModal');
        m.classList.remove('flex'); m.classList.add('hidden');
    }
    document.getElementById('logoutConfirmModal').addEventListener('click', (e) => {
        if (e.target.id === 'logoutConfirmModal') closeLogoutConfirm();
    });

    // Plan picker on renewal panel
    document.querySelectorAll('.plan-option').forEach(btn => {
        btn.addEventListener('click', () => {
            const plan = btn.getAttribute('data-plan');
            document.getElementById('selected_plan_field').value = plan;
            document.querySelectorAll('.plan-option').forEach(b => {
                b.classList.remove('border-emerald-500', 'bg-white', 'shadow-md');
                b.classList.add('border-slate-200', 'bg-white/60', 'hover:border-emerald-300');
                const ic = b.querySelector('.check-icon'); if (ic) ic.remove();
            });
            btn.classList.remove('border-slate-200', 'bg-white/60', 'hover:border-emerald-300');
            btn.classList.add('border-emerald-500', 'bg-white', 'shadow-md');
            if (!btn.querySelector('.check-icon')) {
                const ic = document.createElement('span');
                ic.className = 'absolute top-2 right-2 material-symbols-outlined text-emerald-600 text-base check-icon';
                ic.textContent = 'check_circle';
                btn.appendChild(ic);
            }
        });
    });
</script>

</body>
</html>
