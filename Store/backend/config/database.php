<?php
// ── Auto-detect environment ───────────────────────────────────────────────────
$is_local = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true);

if ($is_local) {
    // XAMPP local development
    $db_host = 'localhost';
    $db_port = 3306;
    $db_user = 'root';
    $db_pass = '';
    $db_name = 'store';
} else {
    // Production server (Daun Penh Data Center)
    $db_host = 'localhost';
    $db_port = 3306;
    $db_user = 'dpdc523_borey-store';
    $db_pass = 'borey-store_123';
    $db_name = 'dpdc523_borey-store';
}

$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name, $db_port);

if (!$conn) {
    // Do not expose connection details to end users
    http_response_code(503);
    die('Service temporarily unavailable.');
}

// ── CSRF helpers (require session_start() to have been called first) ─────────

/**
 * Return the CSRF token for the current session, generating one if needed.
 */
function csrf_token(): string {
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    return '';
}

/**
 * Verify the CSRF token submitted in a POST request.
 * Terminates with HTTP 403 if the token is missing or invalid.
 */
function csrf_verify(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return; // Can't verify without a session (e.g. pure-API endpoints)
    }
    $submitted = trim($_POST['csrf_token'] ?? '');
    $expected  = $_SESSION['csrf_token'] ?? '';
    if ($submitted === '' || $expected === '' || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        die('Security check failed. Please go back and try again.');
    }
}
?>