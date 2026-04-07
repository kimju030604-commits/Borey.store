<?php
/**
 * Admin Authentication API
 * POST ?action=login  — verify access code, start session
 * POST ?action=logout — destroy session
 * GET  ?action=check  — return auth status
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: http://localhost:5173');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../../config/database.php';

function respond($status, $message, $code = 200, $data = []) {
    http_response_code($code);
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
    exit;
}

$action = trim($_REQUEST['action'] ?? '');

if ($action === 'check') {
    $loggedIn = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
    respond($loggedIn ? 'success' : 'error', $loggedIn ? 'Authenticated' : 'Not authenticated', $loggedIn ? 200 : 401, ['logged_in' => $loggedIn]);
}

if ($action === 'logout') {
    session_destroy();
    respond('success', 'Logged out');
}

if ($action === 'login') {
    $ip          = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $attemptsKey = 'login_attempts_' . md5($ip);
    $timestampKey = 'login_ts_' . md5($ip);
    $attempts     = $_SESSION[$attemptsKey] ?? 0;
    $lastAttempt  = $_SESSION[$timestampKey] ?? 0;

    if (time() - $lastAttempt > 900) { $attempts = 0; }

    if ($attempts >= 10) {
        $wait = max(0, 900 - (time() - $lastAttempt));
        respond('error', 'Too many failed attempts. Please wait ' . ceil($wait / 60) . ' minute(s).', 429);
    }

    $code = trim($_POST['access_code'] ?? '');
    if (empty($code)) { respond('error', 'Please enter your access code.', 400); }

    $stmt = mysqli_prepare($conn, "SELECT * FROM access_codes WHERE code = ? AND is_active = TRUE AND (expires_at IS NULL OR expires_at > NOW())");
    mysqli_stmt_bind_param($stmt, 's', $code);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($row = mysqli_fetch_assoc($result)) {
        $upd = mysqli_prepare($conn, "UPDATE access_codes SET used_count = used_count + 1, last_used = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($upd, 'i', $row['id']);
        mysqli_stmt_execute($upd);

        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['access_code_id']  = $row['id'];
        $_SESSION['login_time']       = time();
        unset($_SESSION[$attemptsKey], $_SESSION[$timestampKey]);

        respond('success', 'Authenticated');
    } else {
        $_SESSION[$attemptsKey]  = $attempts + 1;
        $_SESSION[$timestampKey] = time();
        respond('error', 'Invalid or expired access code.', 401);
    }
}

respond('error', 'Unknown action', 400);
