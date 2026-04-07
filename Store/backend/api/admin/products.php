<?php
/**
 * Admin Products API
 * GET                      → list all products
 * POST ?action=add         → add new product (with image upload)
 * POST ?action=update_stock→ update product stock
 * POST ?action=delete      → delete product
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

// ── GET: list products ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    mysqli_query($conn, "ALTER TABLE products ADD COLUMN IF NOT EXISTS stock INT DEFAULT 0");
    $rows = [];
    $q = mysqli_query($conn, "SELECT * FROM products ORDER BY id DESC");
    while ($row = mysqli_fetch_assoc($q)) {
        if (!empty($row['image'])) {
            $row['image'] = preg_replace('#^img/products/#', 'assets/img/products/', $row['image']);
        }
        $rows[] = $row;
    }
    respond('success', 'OK', 200, $rows);
}

// ── POST: mutations ─────────────────────────────────────────────────────────
if ($action === 'update_stock') {
    $id    = intval($_POST['id'] ?? 0);
    $stock = max(0, intval($_POST['stock'] ?? 0));
    if ($id <= 0) { respond('error', 'Invalid id', 400); }
    $stmt = mysqli_prepare($conn, "UPDATE products SET stock = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'ii', $stock, $id);
    if (mysqli_stmt_execute($stmt)) {
        respond('success', 'Stock updated');
    } else {
        respond('error', 'Error updating stock', 500);
    }
}

if ($action === 'delete') {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) { respond('error', 'Invalid id', 400); }

    $res = mysqli_prepare($conn, "SELECT image FROM products WHERE id = ?");
    mysqli_stmt_bind_param($res, 'i', $id);
    mysqli_stmt_execute($res);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($res));
    if ($row && !empty($row['image'])) {
        $imgRelative = preg_replace('#^img/products/#', 'assets/img/products/', $row['image']);
        $path = dirname(__DIR__, 2) . '/frontend/' . $imgRelative;
        if (file_exists($path)) { unlink($path); }
    }

    $del = mysqli_prepare($conn, "DELETE FROM products WHERE id = ?");
    mysqli_stmt_bind_param($del, 'i', $id);
    if (mysqli_stmt_execute($del)) {
        respond('success', 'Product deleted');
    } else {
        respond('error', 'Error deleting product', 500);
    }
}

if ($action === 'add') {
    $name     = trim($_POST['name'] ?? '');
    $nameEn   = trim($_POST['name_en'] ?? '');
    $price    = floatval($_POST['price'] ?? 0);
    $category = trim($_POST['category'] ?? '');
    $rating   = floatval($_POST['rating'] ?? 5);
    $stock    = intval($_POST['stock'] ?? 0);

    if (empty($name) || $price <= 0) { respond('error', 'Name and price are required', 400); }
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        respond('error', 'Image is required', 400);
    }

    $imgDir  = dirname(__DIR__, 2) . '/frontend/assets/img/products';
    if (!is_dir($imgDir)) { mkdir($imgDir, 0755, true); }

    $tmpPath  = $_FILES['image']['tmp_name'];
    $fileType = mime_content_type($tmpPath);
    $fileSize = $_FILES['image']['size'];

    if ($fileSize > 5 * 1024 * 1024) { respond('error', 'Image too large (max 5MB)', 400); }
    if (!in_array($fileType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
        respond('error', 'Invalid image format (JPG, PNG, GIF, WEBP only)', 400);
    }

    $ext      = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
    $filename = uniqid('product_') . '.' . strtolower($ext);
    $fullPath = $imgDir . '/' . $filename;

    if (!move_uploaded_file($tmpPath, $fullPath)) {
        respond('error', 'Error saving image', 500);
    }

    $imagePath = 'assets/img/products/' . $filename;
    $stmt = mysqli_prepare($conn, "INSERT INTO products (name, name_en, price, category, rating, image, stock) VALUES (?, ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'ssdsds i', $name, $nameEn, $price, $category, $rating, $imagePath, $stock);
    // Fix bind param types
    $stmt = mysqli_prepare($conn, "INSERT INTO products (name, name_en, price, category, rating, image, stock) VALUES (?, ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'ssdsdsi', $name, $nameEn, $price, $category, $rating, $imagePath, $stock);

    if (mysqli_stmt_execute($stmt)) {
        respond('success', 'Product added', 200, ['id' => mysqli_insert_id($conn)]);
    } else {
        respond('error', 'Error adding product', 500);
    }
}

respond('error', 'Unknown action', 400);
