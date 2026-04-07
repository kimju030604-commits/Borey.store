<?php
/**
 * Public Products API
 * GET /api/products.php → returns all products as JSON
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: http://localhost:5173');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../config/database.php';

$query = mysqli_query($conn, "SELECT id, name, name_en, price, category, rating, image, stock, created_at, updated_at FROM products ORDER BY id DESC");
$products = [];
while ($row = mysqli_fetch_assoc($query)) {
    if (!empty($row['image'])) {
        $row['image'] = preg_replace('#^img/products/#', 'assets/img/products/', $row['image']);
    }
    $products[] = $row;
}

echo json_encode(['status' => 'success', 'data' => $products]);
