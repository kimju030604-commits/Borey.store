<?php
/**
 * Admin Invoices API
 * GET  ?page=1&date=YYYY-MM-DD → paginated invoice list
 * POST ?action=delete          → delete invoice + PDF
 * POST ?action=regenerate_pdf  → regenerate invoice PDF
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

function respond($status, $msg, $code = 200, $extra = []) {
    http_response_code($code);
    echo json_encode(array_merge(['status' => $status, 'message' => $msg], $extra));
    exit;
}

$action = trim($_GET['action'] ?? $_POST['action'] ?? '');

// ── GET: paginated list ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $filterDate = trim($_GET['date'] ?? '');
    $hasDate    = ($filterDate !== '' && strtotime($filterDate) !== false);
    $safeDate   = $hasDate ? date('Y-m-d', strtotime($filterDate)) : '';
    $where      = $hasDate ? "WHERE DATE(created_at) = '" . mysqli_real_escape_string($conn, $safeDate) . "'" : '';

    $page    = max(1, intval($_GET['page'] ?? 1));
    $perPage = 20;
    $offset  = ($page - 1) * $perPage;

    $totalRow   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total, SUM(total_usd) AS revenue FROM invoices $where"));
    $totalCount = (int)($totalRow['total'] ?? 0);
    $revenue    = (float)($totalRow['revenue'] ?? 0);
    $totalPages = max(1, (int)ceil($totalCount / $perPage));

    $rows = [];
    $q = mysqli_query($conn, "SELECT * FROM invoices $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
    while ($row = mysqli_fetch_assoc($q)) {
        $row['items'] = json_decode($row['items'], true) ?: [];
        $rows[] = $row;
    }

    echo json_encode(['status' => 'success', 'data' => $rows, 'total' => $totalCount, 'revenue' => $revenue, 'total_pages' => $totalPages, 'page' => $page]);
    exit;
}

// ── POST: mutations ─────────────────────────────────────────────────────────
if ($action === 'delete') {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) { respond('error', 'Invalid id', 400); }

    $q   = mysqli_prepare($conn, "SELECT pdf_path FROM invoices WHERE id = ?");
    mysqli_stmt_bind_param($q, 'i', $id);
    mysqli_stmt_execute($q);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($q));

    $del = mysqli_prepare($conn, "DELETE FROM invoices WHERE id = ?");
    mysqli_stmt_bind_param($del, 'i', $id);
    if (mysqli_stmt_execute($del)) {
        if ($row && !empty($row['pdf_path'])) {
            $path = dirname(__DIR__, 3) . '/' . $row['pdf_path'];
            if (file_exists($path)) { unlink($path); }
        }
        respond('success', 'Invoice deleted');
    } else {
        respond('error', 'Error deleting invoice', 500);
    }
}

if ($action === 'regenerate_pdf') {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) { respond('error', 'Invalid id', 400); }

    $q = mysqli_prepare($conn, "SELECT * FROM invoices WHERE id = ?");
    mysqli_stmt_bind_param($q, 'i', $id);
    mysqli_stmt_execute($q);
    $invoice = mysqli_fetch_assoc(mysqli_stmt_get_result($q));

    if (!$invoice) { respond('error', 'Invoice not found', 404); }

    try {
        require_once dirname(__DIR__, 2) . '/lib/InvoicePDF.php';
        $pdfDir  = dirname(__DIR__, 3) . '/storage/invoices/';
        if (!is_dir($pdfDir)) { mkdir($pdfDir, 0755, true); }
        $filename = 'Invoice_' . $invoice['invoice_number'] . '.pdf';
        generateInvoicePDF($invoice, $pdfDir . $filename);
        $pdfPath = 'storage/invoices/' . $filename;
        $upd = mysqli_prepare($conn, "UPDATE invoices SET pdf_path = ? WHERE id = ?");
        mysqli_stmt_bind_param($upd, 'si', $pdfPath, $id);
        mysqli_stmt_execute($upd);
        respond('success', 'PDF regenerated');
    } catch (Exception $e) {
        respond('error', 'Error regenerating PDF: ' . $e->getMessage(), 500);
    }
}

respond('error', 'Unknown action', 400);
