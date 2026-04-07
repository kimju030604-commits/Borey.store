<?php
/**
 * Admin Access Codes API
 * GET                       → list all codes
 * POST ?action=generate     → generate new code
 * POST ?action=deactivate   → deactivate a code by id
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: http://localhost:5173');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';

function respond($status, $msg, $code = 200, $data = []) {
    http_response_code($code);
    echo json_encode(['status' => $status, 'message' => $msg, 'data' => $data]);
    exit;
}

$action = trim($_GET['action'] ?? $_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM access_codes ORDER BY created_at DESC"), MYSQLI_ASSOC);
    respond('success', 'OK', 200, $rows);
}

if ($action === 'generate') {
    $code    = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $expires = date('Y-m-d H:i:s', strtotime('+90 days'));
    $creator = 'admin_panel';
    $stmt = mysqli_prepare($conn, "INSERT INTO access_codes (code, expires_at, created_by) VALUES (?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'sss', $code, $expires, $creator);
    if (mysqli_stmt_execute($stmt)) {
        respond('success', 'Code generated', 200, ['code' => $code, 'expires' => $expires]);
    } else {
        respond('error', 'Error generating code', 500);
    }
}

if ($action === 'deactivate') {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) { respond('error', 'Invalid id', 400); }
    $stmt = mysqli_prepare($conn, "UPDATE access_codes SET is_active = FALSE WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    if (mysqli_stmt_execute($stmt)) {
        respond('success', 'Code deactivated');
    } else {
        respond('error', 'Error deactivating code', 500);
    }
}

respond('error', 'Unknown action', 400);
