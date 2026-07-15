<?php
date_default_timezone_set('Asia/Manila');
ob_start();
session_start();

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    $clinicCode = $_GET['c'] ?? 'N/A';
    if (!empty($clinicCode) && $clinicCode !== 'N/A') {
        header("Location: tenant_login.php?c=" . urlencode($clinicCode));
    } else {
        header("Location: tenant_login.php");
    }
    exit();
}

// Security Check
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

require_once 'db.php';

// --- SIDEBAR NAME LOGIC ---
$displayName = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'User');
$displayRole = $_SESSION['role'] ?? 'Staff';
$normalizedRole = strtolower(trim((string)$displayRole));
$isStaffRole = ($normalizedRole === 'staff');
$tenant_id = $_SESSION['TenantID'] ?? null;

if (empty($tenant_id)) {
    header("Location: index.php");
    exit();
}

$clinicName = "MaternityHub";
$clinicCode = "N/A";
$clinicLogo = null;
$themeColor = "#15803d";

try {
    $stmtClinic = $pdo->prepare("SELECT clinic_name, clinic_code, clinic_logo, theme_color FROM tenants WHERE TenantID = ?");
    $stmtClinic->execute([$tenant_id]);
    $clinicData = $stmtClinic->fetch(PDO::FETCH_ASSOC);

    if ($clinicData) {
        if (!empty($clinicData['clinic_name'])) $clinicName = $clinicData['clinic_name'];
        if (!empty($clinicData['clinic_code'])) $clinicCode = $clinicData['clinic_code'];
        if (!empty($clinicData['clinic_logo']) && file_exists(__DIR__ . '/uploads/logos/' . $clinicData['clinic_logo'])) {
            $clinicLogo = 'uploads/logos/' . $clinicData['clinic_logo'];
        }
        if (!empty($clinicData['theme_color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$clinicData['theme_color'])) {
            $themeColor = $clinicData['theme_color'];
        }
    }
} catch (PDOException $e) {}

$current_staff_id = (int)($_SESSION['user_id'] ?? 0);
try {
    $stmtPic = $pdo->prepare("SELECT profile_image FROM users WHERE id = ?");
    $stmtPic->execute([$current_staff_id]);
    $dbPic = $stmtPic->fetchColumn();

    if ($dbPic && file_exists(__DIR__ . '/uploads/profiles/' . $dbPic)) {
        $profilePic = "uploads/profiles/" . $dbPic;
    } else {
        $profilePic = "https://ui-avatars.com/api/?name=" . urlencode($displayName) . "&background=" . ltrim($themeColor, '#') . "&color=fff";
    }
} catch (PDOException $e) {
    $profilePic = "https://ui-avatars.com/api/?name=" . urlencode($displayName) . "&background=" . ltrim($themeColor, '#') . "&color=fff";
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Infant Records - <?= htmlspecialchars($clinicName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = { darkMode: "class", theme: { extend: { colors: { "primary": "<?= htmlspecialchars($themeColor) ?>", "primary-dark": "color-mix(in srgb, <?= htmlspecialchars($themeColor) ?> 70%, black)", "primary-light": "color-mix(in srgb, <?= htmlspecialchars($themeColor) ?> 20%, white)", "background-light": "color-mix(in srgb, <?= htmlspecialchars($themeColor) ?> 4%, white)" }, fontFamily: { "display": ["Plus Jakarta Sans", "sans-serif"] } } } }
    </script>
    <style> 
        :root { --theme-primary: <?= htmlspecialchars($themeColor) ?>; }
        html, body { margin: 0; padding: 0; scroll-behavior: smooth; }
        .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        .icon-filled { font-variation-settings: 'FILL' 1; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; overflow: hidden; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body class="bg-background-light text-slate-800 h-screen overflow-hidden flex flex-col relative text-sm antialiased font-display">

<header class="h-20 bg-primary border-b border-primary-dark flex items-center justify-between px-6 md:px-12 sticky top-0 z-50 shrink-0 shadow-soft relative">
    <div class="flex items-center gap-4">
        <div class="size-12 rounded-2xl bg-white/15 border border-white/25 overflow-hidden flex items-center justify-center shrink-0">
            <?php if ($clinicLogo): ?>
                <img src="<?= htmlspecialchars($clinicLogo) ?>" alt="Clinic Logo" class="size-full object-cover">
            <?php else: ?>
                <span class="material-symbols-outlined text-white/90 text-2xl">domain</span>
            <?php endif; ?>
        </div>
        <div class="flex flex-col justify-center text-white">
            <h1 class="text-lg font-bold leading-none tracking-tight"><?= htmlspecialchars($clinicName) ?></h1>
            <div class="flex items-center gap-2 mt-1">
                <p class="text-primary-light text-[10px] font-bold uppercase tracking-widest opacity-90">POWERED BY MATERNITYHUB</p>
                <span class="text-white/50 text-[10px]">|</span>
                <p class="text-white bg-black/20 px-2 py-0.5 rounded text-[10px] font-black tracking-widest flex items-center gap-1">CODE: <?= htmlspecialchars($clinicCode) ?></p>
            </div>
        </div>
    </div>
    
    <div class="flex items-center gap-4 ml-auto">
        <div class="hidden sm:flex flex-col text-right justify-center text-white">
            <p class="text-sm font-bold leading-none"><?= htmlspecialchars($displayName) ?></p>
            <p class="text-[9px] text-primary-light italic opacity-80 mt-1 uppercase tracking-tighter\"><?= htmlspecialchars($displayRole) ?></p>
        </div>
        <button onclick="if(confirm('Logout Account?')) window.location.href='?action=logout&c=<?= urlencode($clinicCode) ?>'" class="flex items-center gap-2 bg-white/15 hover:bg-white/25 text-white border border-white/30 px-4 py-2 rounded-xl text-xs font-bold transition-all active:scale-95">
            <span class="material-symbols-outlined text-sm">logout</span>
            <span class="hidden md:inline">Logout</span>
        </button>
    </div>
</header>

<div class="flex-1 flex overflow-hidden">
    <aside class="w-80 bg-white border-r border-slate-200 hidden md:flex flex-col shrink-0 overflow-y-auto">
        <nav class="space-y-3 flex-1 p-6">
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
            <a href="patientrecords.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all">
                <span class="material-symbols-outlined text-2xl">folder_shared</span> <span>Patients</span>
            </a>
            <a href="infantrecords.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] bg-primary/10 text-primary font-bold shadow-sm transition-all hover:scale-[1.02]">
                <span class="material-symbols-outlined text-2xl icon-filled">face</span> <span>Infants</span>
            </a>
            <?php if (!$isStaffRole): ?>
                <a href="staffmanagement.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all">
                    <span class="material-symbols-outlined text-2xl">badge</span> <span>Clinic Staff</span>
                </a>
            <?php endif; ?>

            <div class="space-y-3 mt-4 mb-4">
                <p class="text-xs font-black text-slate-400 uppercase tracking-widest px-4 mb-2 mt-6">Operations</p>
                <a href="financials.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">payments</span> <span>Financials</span>
                </a>
                <a href="report.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">bar_chart</span> <span>Reports</span>
                </a>
                <?php if (!$isStaffRole): ?>
                <a href="tenantsettings.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">settings</span> <span>Settings</span>
                </a>
                <?php endif; ?>
                <a href="support.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">support_agent</span> <span>Help & Support</span>
                </a>
            </div>
        </nav>
        
        <div class="p-6 border-t border-slate-100">
            <div class="bg-slate-50 rounded-3xl p-4 flex items-center gap-4">
                <div class="size-12 rounded-full bg-cover bg-center border-2 border-white shadow-sm" style="background-image: url('<?= htmlspecialchars($profilePic) ?>')"></div>
                <div class="overflow-hidden">
                    <p class="text-sm font-bold text-slate-900 truncate"><?= htmlspecialchars($displayName) ?></p>
                    <p class="text-[10px] text-slate-500 italic">Online</p>
                </div>
            </div>
        </div>
    </aside>

    <main class="flex-1 overflow-y-auto p-4 md:p-8 bg-background-light">
        <div class="max-w-7xl mx-auto space-y-6">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-800 tracking-tight">Infant Records</h1>
                    <p class="text-slate-500 text-xs mt-1">Registry for newborn health data and birth information.</p>
                </div>
                <div class="flex items-center gap-3">
                    <button class="bg-primary text-white px-5 py-2.5 rounded-xl font-bold text-sm flex items-center gap-2 hover:bg-primary-dark transition-all shadow-lg active:scale-95">
                        <span class="material-symbols-outlined text-lg">baby_changing_station</span> Add New Infant
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex items-center gap-4">
                    <div class="size-12 rounded-xl bg-green-50 flex items-center justify-center text-green-600">
                        <span class="material-symbols-outlined text-3xl">child_care</span>
                    </div>
                    <div>
                        <p class="text-[10px] text-slate-400 font-black uppercase tracking-widest">Total Births (Month)</p>
                        <p class="text-2xl font-black text-slate-800">42</p>
                    </div>
                </div>
                <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex items-center gap-4">
                    <div class="size-12 rounded-xl bg-blue-50 flex items-center justify-center text-blue-600">
                        <span class="material-symbols-outlined text-3xl">male</span>
                    </div>
                    <div>
                        <p class="text-[10px] text-slate-400 font-black uppercase tracking-widest">Male Infants</p>
                        <p class="text-2xl font-black text-slate-800">22</p>
                    </div>
                </div>
                <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex items-center gap-4">
                    <div class="size-12 rounded-xl bg-pink-50 flex items-center justify-center text-pink-600">
                        <span class="material-symbols-outlined text-3xl">female</span>
                    </div>
                    <div>
                        <p class="text-[10px] text-slate-400 font-black uppercase tracking-widest">Female Infants</p>
                        <p class="text-2xl font-black text-slate-800">20</p>
                    </div>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden text-sm flex flex-col h-full max-h-[calc(100vh-250px)]">
                <div class="flex-1 overflow-auto">
                    <table class="w-full text-left border-collapse">
                        <thead class="bg-slate-50 border-b text-[11px] font-bold uppercase text-slate-500 sticky top-0 z-10">
                            <tr>
                                <th class="px-6 py-4 bg-slate-50">Infant Name</th>
                                <th class="px-6 py-4 bg-slate-50 text-center">Sex</th>
                                <th class="px-6 py-4 bg-slate-50">Birth Date & Time</th>
                                <th class="px-6 py-4 bg-slate-50">Mother / Parent</th>
                                <th class="px-6 py-4 bg-slate-50">Weight</th>
                                <th class="px-8 py-4 text-right bg-slate-50">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="size-9 rounded-lg bg-green-100 text-green-700 flex items-center justify-center font-black text-xs uppercase">JA</div>
                                        <span class="font-bold text-slate-800">Alcaraz, Baby Boy 1</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="px-3 py-1 bg-blue-50 text-blue-600 rounded-full text-[10px] font-black uppercase tracking-wider">Male</span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-bold text-slate-700 text-xs">Oct 24, 2023</div>
                                    <div class="text-[10px] text-slate-400 font-medium">08:45 AM</div>
                                </td>
                                <td class="px-6 py-4 font-bold text-slate-600">Maria Clara Alcaraz</td>
                                <td class="px-6 py-4 font-black text-slate-800">3.2 kg</td>
                                <td class="px-8 py-4 text-right flex justify-end gap-2">
                                    <button class="size-8 flex items-center justify-center rounded-lg text-slate-400 hover:bg-green-50 hover:text-green-600 transition-all"><span class="material-symbols-outlined text-xl">visibility</span></button>
                                    <button class="size-8 flex items-center justify-center rounded-lg text-slate-400 hover:bg-blue-50 hover:text-blue-600 transition-all"><span class="material-symbols-outlined text-xl">edit</span></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>

</body>
</html>