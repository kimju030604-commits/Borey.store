<?php
session_start();

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// If already logged in, redirect to dashboard
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: dashboard.php');
    exit;
}

require_once '../../backend/config/database.php';

$error = '';
$success = '';

// ── Brute-force protection ─────────────────────────────────────────────────
// Allow max 10 attempts per 15-minute window, keyed on visitor IP.
$ip          = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$attemptsKey = 'login_attempts_' . md5($ip);
$timestampKey = 'login_ts_' . md5($ip);

$attempts  = $_SESSION[$attemptsKey] ?? 0;
$lastAttempt = $_SESSION[$timestampKey] ?? 0;

// Reset window after 15 minutes
if (time() - $lastAttempt > 900) {
    $attempts = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check first
    csrf_verify();

    if ($attempts >= 10) {
        $remainSecs = max(0, 900 - (time() - $lastAttempt));
        $error = 'Too many failed attempts. Please wait ' . ceil($remainSecs / 60) . ' minute(s).';
    } else {
        $access_code = trim($_POST['access_code'] ?? '');

        if (!empty($access_code)) {
            // Use a prepared statement to prevent SQL injection
            $stmt = mysqli_prepare($conn, "SELECT * FROM access_codes WHERE code = ? AND is_active = TRUE AND (expires_at IS NULL OR expires_at > NOW())");
            mysqli_stmt_bind_param($stmt, 's', $access_code);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);

            if (mysqli_num_rows($result) > 0) {
                $code_row = mysqli_fetch_assoc($result);

                // Log the successful use
                $upd = mysqli_prepare($conn, "UPDATE access_codes SET used_count = used_count + 1, last_used = NOW() WHERE id = ?");
                mysqli_stmt_bind_param($upd, 'i', $code_row['id']);
                mysqli_stmt_execute($upd);

                // Start a clean new session (destroy old to prevent fixation)
                session_regenerate_id(true);
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['access_code_id']  = $code_row['id'];
                $_SESSION['login_time']       = time();
                // Reset brute-force counters on success
                unset($_SESSION[$attemptsKey], $_SESSION[$timestampKey]);

                header('Location: dashboard.php');
                exit;
            } else {
                // Increment brute-force counter
                $_SESSION[$attemptsKey]  = $attempts + 1;
                $_SESSION[$timestampKey] = time();
                $error = 'Invalid or expired access code.';
            }
        } else {
            $error = 'Please enter your access code.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | Borey.store</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 flex items-center justify-center min-h-screen p-4">

    <div class="w-full max-w-sm p-8 space-y-6 bg-white rounded-[2rem] shadow-xl shadow-slate-200/50">
        <div class="text-center">
            <h1 class="text-2xl md:text-3xl font-black tracking-tighter">ADMIN PANEL</h1>
            <p class="mt-1 text-sm text-slate-500 font-bold">Borey<span class="text-blue-600">.store</span> Access</p>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg" role="alert">
                <p class="font-bold"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        <?php endif; ?>

        <form class="space-y-6" action="login.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="space-y-1.5">
                <label for="access_code" class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Access Code</label>
                <input id="access_code" name="access_code" type="password" required maxlength="20" class="w-full p-4 bg-slate-100 border border-slate-200 rounded-2xl focus:ring-4 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all text-center text-2xl tracking-widest font-black" placeholder="XXXXXXXX" autofocus>
                <p class="text-[10px] text-slate-400 mt-2">Enter your 8-character access code to proceed</p>
            </div>

            <div>
                <button type="submit" class="w-full bg-slate-900 text-white py-4 rounded-2xl font-black text-base transition-all active:scale-[0.98] shadow-lg shadow-slate-900/20 hover:bg-slate-800">
                    Unlock Admin Panel
                </button>
            </div>
        </form>
    </div>

</body>
</html>