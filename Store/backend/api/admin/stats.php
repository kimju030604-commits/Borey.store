<?php
/**
 * Admin Stats API
 * GET /api/admin/stats.php
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: http://localhost:5173');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';

$products_row   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM products"));
$invoices_row   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total, SUM(total_usd) AS revenue FROM invoices"));
$stock_row      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(stock) AS total_stock, SUM(stock = 0) AS out_of_stock FROM products"));
$codes_row      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS active FROM access_codes WHERE is_active = TRUE"));

echo json_encode([
    'status' => 'success',
    'data' => [
        'total_products'  => (int)($products_row['total'] ?? 0),
        'total_invoices'  => (int)($invoices_row['total'] ?? 0),
        'total_revenue'   => (float)($invoices_row['revenue'] ?? 0),
        'total_stock'     => (int)($stock_row['total_stock'] ?? 0),
        'out_of_stock'    => (int)($stock_row['out_of_stock'] ?? 0),
        'active_codes'    => (int)($codes_row['active'] ?? 0),
    ]
]);
