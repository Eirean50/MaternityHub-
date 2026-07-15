<?php
// suspended.php — shown when a clinic is suspended; lets the owner submit an appeal
date_default_timezone_set('Asia/Manila');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();
session_start();
require_once 'db.php';

// Force MySQL session to Philippine Time (UTC+8) so NOW() and stored timestamps match Asia/Manila
try { $pdo->exec("SET time_zone = '+08:00'"); } catch (PDOException $e) { /* silent */ }

// AUTO-MIGRATE: ensure suspended_at column exists
try { $pdo->query("SELECT suspended_at FROM tenants LIMIT 1"); }
catch (PDOException $e) { try { $pdo->exec("ALTER TABLE tenants ADD suspended_at DATETIME NULL"); } catch (PDOException $ex) {} }

// AUTO-MIGRATE: appeals table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS suspension_appeals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        TenantID VARCHAR(64) NULL,
        clinic_code VARCHAR(64) NULL,
        clinic_name VARCHAR(255) NULL,
        appellant_name VARCHAR(255) NOT NULL,
        appellant_email VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'Pending Review',
        ip_address VARCHAR(64) NULL,
        created_at DATETIME NOT NULL
    )");
} catch (PDOException $e) { /* silent */ }

// Resolve clinic by ?c=clinic_code
$clinicCode = trim($_GET['c'] ?? '');
$tenant = null;
if ($clinicCode !== '') {
    try {
        $stmt = $pdo->prepare("SELECT TenantID, clinic_name, clinic_code, status, suspension_reason, suspended_at, theme_color, clinic_logo, complete_address, clinic_contact FROM tenants WHERE clinic_code = ? LIMIT 1");
        $stmt->execute([$clinicCode]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { /* silent */ }
}

// If clinic not found OR clinic is not suspended, redirect away
if (!$tenant) {
    header("Location: registration.php?error=invalid_clinic");
    exit();
}
if (strtolower(trim((string)$tenant['status'])) !== 'suspended') {
    header("Location: ClinicHomepage.php?c=" . urlencode($clinicCode));
    exit();
}

$themeColor = !empty($tenant['theme_color']) ? $tenant['theme_color'] : '#15803d';
$logoPath   = (!empty($tenant['clinic_logo']) && file_exists(__DIR__ . '/uploads/logos/' . $tenant['clinic_logo']))
    ? 'uploads/logos/' . $tenant['clinic_logo'] : null;
$reason     = trim((string)($tenant['suspension_reason'] ?? ''));
$clinicName = $tenant['clinic_name'] ?? 'Your Clinic';
$suspendedAt = !empty($tenant['suspended_at']) ? $tenant['suspended_at'] : null;
$suspendedAtFmt = null;
if ($suspendedAt) {
    try {
        $dt = new DateTime($suspendedAt, new DateTimeZone('Asia/Manila'));
        $dt->setTimezone(new DateTimeZone('Asia/Manila'));
        $suspendedAtFmt = $dt->format('F j, Y \a\t g:i A') . ' PHT';
    } catch (Exception $e) {
        $suspendedAtFmt = date('F j, Y \a\t g:i A', strtotime($suspendedAt)) . ' PHT';
    }
}

// MaternityHub Super Admin branding (logo + theme) from settings JSON
$settingsFile = __DIR__ . '/maternityhub_settings.json';
$superLogo = null;
$superThemeColor = '#10b981';
if (file_exists($settingsFile)) {
    $s = json_decode(file_get_contents($settingsFile), true);
    $superLogo = $s['super_logo'] ?? null;
    $superThemeColor = $s['super_theme_color'] ?? '#10b981';
}
$superLogoPath = ($superLogo && file_exists(__DIR__ . '/uploads/logos/' . $superLogo))
    ? 'uploads/logos/' . $superLogo : null;

// HANDLE APPEAL SUBMISSION
$appealMsg = null; $appealOk = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_appeal'])) {
    $name    = trim($_POST['appellant_name'] ?? '');
    $email   = trim($_POST['appellant_email'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($name === '' || $email === '' || $message === '') {
        $appealMsg = "Please fill in all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $appealMsg = "Please provide a valid email address.";
    } elseif (strlen($message) < 20) {
        $appealMsg = "Your appeal message must be at least 20 characters.";
    } else {
        try {
            $ins = $pdo->prepare("INSERT INTO suspension_appeals (TenantID, clinic_code, clinic_name, appellant_name, appellant_email, message, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $ins->execute([
                $tenant['TenantID'] ?? null,
                $tenant['clinic_code'] ?? null,
                $clinicName,
                $name, $email, $message,
                $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
            ]);

            // Email Super Admin
            $sender = 'MaternityHub System <maternityhub@alwaysdata.net>';
            $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: $sender\r\nReply-To: " . $email . "\r\nX-Mailer: PHP/" . phpversion();
            $subject = "Suspension Appeal — " . $clinicName;
            $body = "<h3>New Suspension Appeal</h3>"
                  . "<p><strong>Clinic:</strong> " . htmlspecialchars($clinicName) . " (" . htmlspecialchars((string)$tenant['clinic_code']) . ")</p>"
                  . "<p><strong>From:</strong> " . htmlspecialchars($name) . " &lt;" . htmlspecialchars($email) . "&gt;</p>"
                  . "<p><strong>Original Suspension Reason:</strong><br>" . nl2br(htmlspecialchars($reason)) . "</p>"
                  . "<hr><p><strong>Appeal Message:</strong><br>" . nl2br(htmlspecialchars($message)) . "</p>";
            @mail('maternityhub@alwaysdata.net', $subject, $body, $headers);

            // Confirmation to appellant
            $ackHeaders = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: $sender\r\nReply-To: maternityhub@alwaysdata.net\r\nX-Mailer: PHP/" . phpversion();
            $ackBody = "<p>Hi <strong>" . htmlspecialchars($name) . "</strong>,</p>"
                     . "<p>We have received your appeal regarding the suspension of <strong>" . htmlspecialchars($clinicName) . "</strong>. Our Super Admin team will review your message and respond within 1–3 business days.</p>"
                     . "<p>— MaternityHub Team</p>";
            @mail($email, "We received your appeal — MaternityHub", $ackBody, $ackHeaders);

            $appealOk = true;
            $appealMsg = "Your appeal has been submitted. We'll get back to you via email within 1–3 business days.";
        } catch (PDOException $e) {
            $appealMsg = "We couldn't submit your appeal right now. Please try again later or email us directly.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Clinic Suspended — <?= htmlspecialchars($clinicName) ?> | MaternityHub</title>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
  tailwind.config = { theme: { extend: {
    colors: { primary: "<?= htmlspecialchars($themeColor) ?>", super: "<?= htmlspecialchars($superThemeColor) ?>" },
    fontFamily: { display: ["Plus Jakarta Sans", "sans-serif"] },
    boxShadow: { 'soft': '0 10px 40px -10px rgba(0,0,0,0.08)' }
  } } }
</script>
<style>
  body { font-family: 'Plus Jakarta Sans', sans-serif; }
  .material-symbols-outlined { font-variation-settings: 'FILL' 1, 'wght' 500, 'GRAD' 0, 'opsz' 24; }
  .pattern-dots { background-image: radial-gradient(circle at 20% 20%, rgba(255,255,255,0.4) 1px, transparent 1px), radial-gradient(circle at 80% 70%, rgba(255,255,255,0.3) 1px, transparent 1px); background-size: 28px 28px; }
</style>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col">

<!-- MaternityHub Branding Bar -->
<header class="bg-white border-b border-slate-200 shadow-sm sticky top-0 z-40">
  <div class="max-w-3xl mx-auto px-6 py-4 flex items-center justify-between">
    <div class="flex items-center gap-3">
      <?php if ($superLogoPath): ?>
        <img src="<?= htmlspecialchars($superLogoPath) ?>" alt="MaternityHub" class="h-10 w-10 rounded-xl object-cover border border-slate-100 shadow-sm">
      <?php else: ?>
        <div class="size-10 rounded-xl flex items-center justify-center text-white shadow-sm" style="background: <?= htmlspecialchars($superThemeColor) ?>;">
          <span class="material-symbols-outlined">favorite</span>
        </div>
      <?php endif; ?>
      <div class="leading-tight">
        <p class="text-sm font-black text-slate-900 tracking-tight">MaternityHub</p>
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Clinic Management Platform</p>
      </div>
    </div>
    <a href="ClinicHomepage.php?c=<?= urlencode($clinicCode) ?>" class="hidden sm:inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-slate-800 transition">
      <span class="material-symbols-outlined text-base">arrow_back</span>
      Back to Clinic Page
    </a>
  </div>
</header>

<main class="flex-1 flex items-start justify-center p-4 md:p-8">
<div class="max-w-2xl w-full bg-white rounded-[2rem] shadow-soft border border-slate-100 overflow-hidden">

  <!-- Hero -->
  <div class="relative bg-gradient-to-br from-amber-500 via-orange-500 to-rose-500 p-8 md:p-10 text-white text-center overflow-hidden">
    <div class="absolute inset-0 pattern-dots opacity-40"></div>
    <div class="relative">
      <div class="size-20 mx-auto rounded-3xl bg-white/20 backdrop-blur flex items-center justify-center mb-4 border border-white/30 shadow-lg">
        <span class="material-symbols-outlined text-4xl">block</span>
      </div>
      <span class="inline-block px-3 py-1 rounded-full bg-white/20 text-[10px] font-black uppercase tracking-widest border border-white/30 mb-3">Account Status</span>
      <h1 class="text-3xl md:text-4xl font-black tracking-tight">Clinic Suspended</h1>
      <p class="text-white/90 text-sm md:text-base mt-2 font-medium max-w-md mx-auto">
        Access to this clinic portal has been temporarily revoked by the MaternityHub Super Admin team.
      </p>
    </div>
  </div>

  <div class="p-6 md:p-10 space-y-6">

    <!-- Clinic Identity Card -->
    <div class="flex items-center gap-4 p-4 bg-slate-50 border border-slate-100 rounded-2xl">
      <?php if ($logoPath): ?>
        <img src="<?= htmlspecialchars($logoPath) ?>" alt="<?= htmlspecialchars($clinicName) ?>" class="size-14 rounded-2xl object-cover border border-slate-200 shadow-sm shrink-0">
      <?php else: ?>
        <div class="size-14 rounded-2xl flex items-center justify-center text-white shadow-sm shrink-0" style="background: <?= htmlspecialchars($themeColor) ?>;">
          <span class="material-symbols-outlined text-2xl">local_hospital</span>
        </div>
      <?php endif; ?>
      <div class="min-w-0 flex-1">
        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Clinic</p>
        <p class="text-base font-black text-slate-900 truncate"><?= htmlspecialchars($clinicName) ?></p>
        <?php if (!empty($tenant['clinic_code'])): ?>
          <p class="text-[11px] text-slate-500 font-mono">Code: <?= htmlspecialchars($tenant['clinic_code']) ?></p>
        <?php endif; ?>
      </div>
      <div class="hidden sm:flex flex-col items-end shrink-0">
        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-amber-100 text-amber-800 text-[10px] font-black uppercase tracking-widest">
          <span class="size-1.5 rounded-full bg-amber-500 animate-pulse"></span>
          Suspended
        </span>
        <?php if ($suspendedAtFmt): ?>
          <p class="text-[10px] text-slate-400 font-bold mt-1">Since <?= htmlspecialchars($suspendedAtFmt) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($suspendedAtFmt): ?>
    <!-- Suspended On (mobile-friendly row) -->
    <div class="sm:hidden flex items-center gap-3 px-4 py-3 bg-amber-50 border border-amber-100 rounded-xl">
      <span class="material-symbols-outlined text-amber-500">schedule</span>
      <div>
        <p class="text-[10px] font-black text-amber-700 uppercase tracking-widest">Suspended On</p>
        <p class="text-sm font-bold text-amber-900"><?= htmlspecialchars($suspendedAtFmt) ?></p>
      </div>
    </div>
    <?php endif; ?>

    <!-- Reason -->
    <div>
      <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 flex items-center gap-1.5">
        <span class="material-symbols-outlined text-base text-amber-500">info</span>
        Reason for Suspension
      </p>
      <?php if ($reason !== ''): ?>
        <div class="bg-amber-50 border-l-4 border-amber-500 p-4 rounded-r-xl">
          <p class="text-sm text-amber-900 leading-relaxed"><?= nl2br(htmlspecialchars($reason)) ?></p>
        </div>
      <?php else: ?>
        <div class="bg-slate-50 border-l-4 border-slate-300 p-4 rounded-r-xl">
          <p class="text-sm text-slate-600 italic">No reason was provided. Please contact the MaternityHub Super Admin team for details.</p>
        </div>
      <?php endif; ?>
    </div>

    <!-- Appeal Form -->
    <div class="border-t border-slate-100 pt-6">
      <h2 class="text-xl font-black text-slate-900 tracking-tight mb-1 flex items-center gap-2">
        <span class="material-symbols-outlined text-primary">gavel</span>
        Submit an Appeal
      </h2>
      <p class="text-slate-500 text-sm mb-5">Believe this is a mistake? Send your appeal to the Super Admin team. We typically respond within 1–3 business days.</p>

      <?php if ($appealMsg): ?>
        <div class="mb-5 p-4 rounded-xl text-sm font-bold flex items-center gap-3 <?= $appealOk ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-red-50 text-red-700 border border-red-200' ?>">
          <span class="material-symbols-outlined"><?= $appealOk ? 'check_circle' : 'error' ?></span>
          <?= htmlspecialchars($appealMsg) ?>
        </div>
      <?php endif; ?>

      <?php if (!$appealOk): ?>
      <form method="POST" class="space-y-4">
        <input type="hidden" name="submit_appeal" value="1">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Your Name <span class="text-red-500">*</span></label>
            <input type="text" name="appellant_name" required maxlength="255"
              value="<?= htmlspecialchars($_POST['appellant_name'] ?? '') ?>"
              class="w-full rounded-xl border border-slate-200 p-3 text-sm focus:ring-primary focus:border-primary outline-none">
          </div>
          <div>
            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Your Email <span class="text-red-500">*</span></label>
            <input type="email" name="appellant_email" required maxlength="255"
              value="<?= htmlspecialchars($_POST['appellant_email'] ?? '') ?>"
              class="w-full rounded-xl border border-slate-200 p-3 text-sm focus:ring-primary focus:border-primary outline-none">
          </div>
        </div>

        <div>
          <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Appeal Message <span class="text-red-500">*</span></label>
          <textarea name="message" required rows="6" minlength="20" placeholder="Explain why you believe the suspension should be lifted. Include any supporting context."
            class="w-full rounded-xl border border-slate-200 p-3 text-sm focus:ring-primary focus:border-primary outline-none resize-none"><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
          <p class="text-[10px] text-slate-400 mt-1">Minimum 20 characters.</p>
        </div>

        <div class="flex flex-col sm:flex-row gap-3 pt-2">
          <a href="ClinicHomepage.php?c=<?= urlencode($clinicCode) ?>" class="flex-1 py-3 rounded-xl font-bold text-slate-500 bg-slate-100 hover:bg-slate-200 transition-all text-xs text-center">Back to Clinic Page</a>
          <button type="submit" class="flex-1 py-3 rounded-xl font-bold text-white bg-primary hover:opacity-90 transition-all text-xs shadow-md flex items-center justify-center gap-2">
            <span class="material-symbols-outlined text-base">send</span>
            Submit Appeal
          </button>
        </div>
      </form>
      <?php else: ?>
        <div class="text-center pt-2">
          <a href="ClinicHomepage.php?c=<?= urlencode($clinicCode) ?>" class="inline-block py-3 px-6 rounded-xl font-bold text-white bg-primary hover:opacity-90 transition-all text-xs shadow-md">Back to Clinic Page</a>
        </div>
      <?php endif; ?>
    </div>

  </div>
</div>
</main>

<footer class="py-6 text-center">
  <p class="text-[11px] text-slate-400 font-medium">&copy; <?= date('Y') ?> MaternityHub Platform. All rights reserved.</p>
</footer>

</body>
</html>
