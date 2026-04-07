<?php
/**
 * Invoice API - Generate, Save, and Retrieve Invoices
 */
ob_start();
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';

// ── Telegram alert helper ──────────────────────────────────────────────────
function sendTelegramAlert(string $text): void {
    $token   = '8248731619:AAEeo_yJJlArDBVtN_tEDILVh0rmSGMu-nU';
    $chatId  = '-1003800950994';
    $url     = "https://api.telegram.org/bot{$token}/sendMessage";
    $payload = json_encode([
        'chat_id'                  => $chatId,
        'text'                     => $text,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
// ──────────────────────────────────────────────────────────────────────────

function respond($status, $message, $code = 200, $data = []) {
    http_response_code($code);
    echo json_encode([
        'status' => $status,
        'message' => $message,
        'data' => $data,
    ]);
    exit;
}

$action = $_REQUEST['action'] ?? '';

// Create invoices table if not exists
$createTable = "CREATE TABLE IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(50) NOT NULL UNIQUE,
    order_id VARCHAR(50) NOT NULL,
    order_number VARCHAR(50) DEFAULT NULL,
    customer_name VARCHAR(255) NOT NULL,
    customer_phone VARCHAR(50) NOT NULL,
    customer_location TEXT NOT NULL,
    items JSON NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    delivery_fee DECIMAL(10,2) DEFAULT 0,
    total_usd DECIMAL(10,2) NOT NULL,
    total_khr INT NOT NULL,
    payment_method VARCHAR(50) DEFAULT 'KHQR',
    payment_bank VARCHAR(100) DEFAULT NULL,
    payer_name VARCHAR(255) DEFAULT NULL,
    payer_account_id VARCHAR(100) DEFAULT NULL,
    payment_status VARCHAR(20) DEFAULT 'paid',
    pdf_path VARCHAR(255) DEFAULT NULL,
    receipt_path VARCHAR(255) DEFAULT NULL,
    verified_sender VARCHAR(255) DEFAULT NULL,
    payment_time DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
mysqli_query($conn, $createTable);

// Add columns if they don't exist (for existing tables)
mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN IF NOT EXISTS pdf_path VARCHAR(255) DEFAULT NULL");
mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN IF NOT EXISTS receipt_path VARCHAR(255) DEFAULT NULL");
mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN IF NOT EXISTS verified_sender VARCHAR(255) DEFAULT NULL");
mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN IF NOT EXISTS payment_time DATETIME DEFAULT NULL");
mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN IF NOT EXISTS order_number VARCHAR(50) DEFAULT NULL");
mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN IF NOT EXISTS payment_bank VARCHAR(100) DEFAULT NULL");
mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN IF NOT EXISTS payer_name VARCHAR(255) DEFAULT NULL");
mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN IF NOT EXISTS payer_account_id VARCHAR(100) DEFAULT NULL");
mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN IF NOT EXISTS bakong_hash VARCHAR(255) DEFAULT NULL");

// ── GET action: return invoice data as JSON (for React frontend) ──────────────
if ($action === 'get') {
    $invoiceNumber = trim($_GET['id'] ?? '');
    $orderId       = trim($_GET['order'] ?? '');

    if (!empty($invoiceNumber)) {
        $stmt = mysqli_prepare($conn, "SELECT * FROM invoices WHERE invoice_number = ?");
        mysqli_stmt_bind_param($stmt, 's', $invoiceNumber);
    } elseif (!empty($orderId)) {
        $stmt = mysqli_prepare($conn, "SELECT * FROM invoices WHERE order_id = ?");
        mysqli_stmt_bind_param($stmt, 's', $orderId);
    } else {
        respond('error', 'Missing id or order parameter', 400);
    }

    mysqli_stmt_execute($stmt);
    $result  = mysqli_stmt_get_result($stmt);
    $invoice = mysqli_fetch_assoc($result);

    if (!$invoice) {
        respond('error', 'Invoice not found', 404);
    }

    // Decode items JSON and set items as array
    $invoice['items'] = json_decode($invoice['items'], true) ?: [];

    respond('success', 'OK', 200, $invoice);
}

if ($action === 'create') {
    // Create new invoice
    $orderId = trim($_POST['order_id'] ?? '');
    $customerName = trim($_POST['customer_name'] ?? '');
    $customerPhone = trim($_POST['customer_phone'] ?? '');
    $customerLocation = trim($_POST['customer_location'] ?? '');
    $items = $_POST['items'] ?? '[]';
    $subtotal = floatval($_POST['subtotal'] ?? 0);
    $deliveryFee = floatval($_POST['delivery_fee'] ?? 0);
    $totalUsd = floatval($_POST['total_usd'] ?? 0);
    $totalKhr = intval($_POST['total_khr'] ?? 0);
    $paymentMethod = trim($_POST['payment_method'] ?? 'KHQR');
    $paymentBank = trim($_POST['payment_bank'] ?? 'Bakong');
    $payerName = trim($_POST['payer_name'] ?? $customerName);
    $payerAccountId = trim($_POST['payer_account_id'] ?? '');
    $bakongHash = trim($_POST['bakong_hash'] ?? '');
    $paymentTimeInput = trim($_POST['payment_time'] ?? '');
    $paymentTime = $paymentTimeInput !== '' ? $paymentTimeInput : date('Y-m-d H:i:s');
    
    if (empty($orderId) || empty($customerName) || empty($customerPhone)) {
        respond('error', 'Missing required fields', 400);
    }

    // Normalize & validate phone (keep leading 0, allow spaces/dashes/+855)
    $digits = preg_replace('/\D+/', '', $customerPhone);
    if (strpos($digits, '855') === 0) {
        $digits = '0' . substr($digits, 3);
    }
    $customerPhone = $digits;
    if (!preg_match('/^0\d{8,9}$/', $customerPhone)) {
        respond('error', 'Invalid phone number. Use format 0XXXXXXXXX (e.g., 0967900198).', 400);
    }
    
    // Check if invoice already exists for this order
    $checkQuery = mysqli_prepare($conn, "SELECT invoice_number, bakong_hash FROM invoices WHERE order_id = ?");
    mysqli_stmt_bind_param($checkQuery, 's', $orderId);
    mysqli_stmt_execute($checkQuery);
    $result = mysqli_stmt_get_result($checkQuery);
    
    if ($row = mysqli_fetch_assoc($result)) {
        // If bakong_hash is now available but wasn't saved before, update it
        if (!empty($bakongHash) && empty($row['bakong_hash'])) {
            $updHash = mysqli_prepare($conn, "UPDATE invoices SET bakong_hash = ? WHERE order_id = ?");
            mysqli_stmt_bind_param($updHash, 'ss', $bakongHash, $orderId);
            mysqli_stmt_execute($updHash);
        }
        // Return existing invoice
        respond('success', 'Invoice already exists', 200, [
            'invoice_number' => $row['invoice_number'],
            'order_id' => $orderId
        ]);
    }
    
    // Parse items for stock checks
    $itemsArray = json_decode($items, true) ?: [];

    // Begin transaction to ensure stock consistency
    mysqli_begin_transaction($conn);

    // Reserve stock (fail fast if insufficient)
    foreach ($itemsArray as $item) {
        $pid = intval($item['id'] ?? 0);
        $qty = max(1, intval($item['qty'] ?? $item['quantity'] ?? 1));
        if ($pid <= 0) {
            continue; // Skip items without a valid product id
        }

        $stockStmt = mysqli_prepare($conn, "SELECT stock, name FROM products WHERE id = ? FOR UPDATE");
        mysqli_stmt_bind_param($stockStmt, 'i', $pid);
        mysqli_stmt_execute($stockStmt);
        $stockRes = mysqli_stmt_get_result($stockStmt);
        if ($row = mysqli_fetch_assoc($stockRes)) {
            if ($row['stock'] < $qty) {
                mysqli_rollback($conn);
                respond('error', 'Insufficient stock for ' . ($row['name'] ?? 'item'), 409);
            }
            $newStock = max(0, $row['stock'] - $qty);
            $updStock = mysqli_prepare($conn, "UPDATE products SET stock = ? WHERE id = ?");
            mysqli_stmt_bind_param($updStock, 'ii', $newStock, $pid);
            mysqli_stmt_execute($updStock);
        }
    }

    // Generate invoice number based on total successful purchases (no daily reset)
    $maxRes = mysqli_query($conn, "SELECT MAX(CAST(SUBSTRING_INDEX(invoice_number, '.', -1) AS UNSIGNED)) AS max_num FROM invoices");
    $maxRow = mysqli_fetch_assoc($maxRes);
    $nextNumber = ($maxRow && !is_null($maxRow['max_num'])) ? intval($maxRow['max_num']) + 1 : 1;
    $invoiceNumber = 'Borey.' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    $orderNumber = 'BRY-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    
    // Insert new invoice
    $insertQuery = mysqli_prepare($conn, 
        "INSERT INTO invoices (invoice_number, order_id, order_number, customer_name, customer_phone, customer_location, items, subtotal, delivery_fee, total_usd, total_khr, payment_method, payment_bank, payer_name, payer_account_id, payment_time, bakong_hash) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    
    mysqli_stmt_bind_param($insertQuery, 'sssssssdddissssss', 
        $invoiceNumber, $orderId, $orderNumber, $customerName, $customerPhone, $customerLocation, 
        $items, $subtotal, $deliveryFee, $totalUsd, $totalKhr, $paymentMethod, $paymentBank, $payerName, $payerAccountId, $paymentTime, $bakongHash
    );
    
    if (mysqli_stmt_execute($insertQuery)) {
        mysqli_commit($conn);
        // Generate PDF file
        $pdfPath = null;
        try {
            require_once __DIR__ . '/../lib/InvoicePDF.php';
            
            $invoiceData = [
                'invoice_number' => $invoiceNumber,
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'customer_location' => $customerLocation,
                'items' => $items,
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'total_usd' => $totalUsd,
                'total_khr' => $totalKhr,
                'payment_method' => $paymentMethod,
                'payment_bank' => $paymentBank,
                'payer_name' => $payerName,
                'payer_account_id' => $payerAccountId,
                'bakong_hash' => $bakongHash,
                'payment_status' => 'paid',
                'payment_time' => $paymentTime,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $pdfDir = __DIR__ . '/../../storage/invoices/';
            if (!is_dir($pdfDir)) {
                mkdir($pdfDir, 0755, true);
            }
            
            $pdfFilename = 'Invoice_' . $invoiceNumber . '.pdf';
            $pdfFullPath = $pdfDir . $pdfFilename;
            
            generateInvoicePDF($invoiceData, $pdfFullPath);
            $pdfPath = 'storage/invoices/' . $pdfFilename;
            
            // Update invoice record with PDF path
            $updatePdf = mysqli_prepare($conn, "UPDATE invoices SET pdf_path = ? WHERE invoice_number = ?");
            mysqli_stmt_bind_param($updatePdf, 'ss', $pdfPath, $invoiceNumber);
            mysqli_stmt_execute($updatePdf);
        } catch (Exception $e) {
            // PDF generation failed, but invoice was created
            error_log('PDF generation failed: ' . $e->getMessage());
        }
        
        // ── Send Telegram notification ──────────────────────────────────
        try {
            $itemsList = [];
            $decodedItems = json_decode($items, true) ?: [];
            foreach ($decodedItems as $item) {
                $name      = htmlspecialchars($item['name'] ?? 'Item', ENT_XML1);
                $qty       = intval($item['qty']  ?? $item['quantity'] ?? 1);
                $priceKhr  = isset($item['price']) ? number_format($item['price'] * 4000 * $qty, 0) : '—';
                $itemsList[] = "  • {$name} ×{$qty} = {$priceKhr} ៛";
            }
            $itemsText  = implode("\n", $itemsList);
            $totalFmt   = number_format($totalKhr, 0);
            $payerDisplay = $payerName ?: $customerName;

            $msg  = "🧾 <b>New Order — Invoice #{$invoiceNumber}</b>\n";
            $msg .= "\n";
            $msg .= "👤 <b>Customer:</b> {$customerName}\n";
            if ($customerPhone) $msg .= "📞 <b>Phone:</b> {$customerPhone}\n";
            if ($customerLocation) $msg .= "📍 <b>Location:</b> {$customerLocation}\n";
            $msg .= "\n";
            $msg .= "🛒 <b>Items:</b>\n{$itemsText}\n";
            $msg .= "\n";
            $msg .= "💰 <b>Total:</b> {$totalFmt} ៛";
            if ($totalUsd > 0) $msg .= " (~\${$totalUsd})";
            $msg .= "\n";
            $msg .= "💳 <b>Payment:</b> {$paymentBank}\n";
            if ($payerDisplay && $payerDisplay !== $customerName) {
                $msg .= "✅ <b>Paid by:</b> {$payerDisplay}\n";
            } else {
                $msg .= "✅ <b>Payment:</b> Confirmed\n";
            }
            if (!empty($bakongHash)) {
                $hashShort = substr($bakongHash, 0, 8);
                $msg .= "🔗 <b>Bakong Hash:</b> {$hashShort}\n";
            }
            sendTelegramAlert($msg);
        } catch (Exception $e) {
            error_log('Telegram notification failed: ' . $e->getMessage());
        }
        // ───────────────────────────────────────────────────────────────────

        respond('success', 'Invoice created', 200, [
            'invoice_number' => $invoiceNumber,
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'pdf_path' => $pdfPath
        ]);
    } else {
        mysqli_rollback($conn);
        respond('error', 'Failed to create invoice: ' . mysqli_error($conn), 500);
    }
    
} elseif ($action === 'get') {
    // Get invoice by invoice_number or order_id
    $invoiceNumber = trim($_GET['invoice_number'] ?? '');
    $orderId = trim($_GET['order_id'] ?? '');
    
    if (empty($invoiceNumber) && empty($orderId)) {
        respond('error', 'Missing invoice_number or order_id', 400);
    }
    
    if (!empty($invoiceNumber)) {
        $query = mysqli_prepare($conn, "SELECT * FROM invoices WHERE invoice_number = ?");
        mysqli_stmt_bind_param($query, 's', $invoiceNumber);
    } else {
        $query = mysqli_prepare($conn, "SELECT * FROM invoices WHERE order_id = ?");
        mysqli_stmt_bind_param($query, 's', $orderId);
    }
    
    mysqli_stmt_execute($query);
    $result = mysqli_stmt_get_result($query);
    
    if ($invoice = mysqli_fetch_assoc($result)) {
        $invoice['items'] = json_decode($invoice['items'], true);
        respond('success', 'Invoice found', 200, $invoice);
    } else {
        respond('error', 'Invoice not found', 404);
    }
    
} elseif ($action === 'list') {
    // List all invoices — admin only
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        respond('error', 'Unauthorized', 401);
    }

    $limit  = intval($_GET['limit']  ?? 50);
    $offset = intval($_GET['offset'] ?? 0);
    
    $query = mysqli_query($conn, "SELECT * FROM invoices ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $invoices = [];
    while ($row = mysqli_fetch_assoc($query)) {
        $row['items'] = json_decode($row['items'], true);
        $invoices[] = $row;
    }
    
    // Get total count
    $countQuery = mysqli_query($conn, "SELECT COUNT(*) as total FROM invoices");
    $total = mysqli_fetch_assoc($countQuery)['total'];
    
    respond('success', 'Invoices retrieved', 200, [
        'invoices' => $invoices,
        'total' => intval($total),
        'limit' => $limit,
        'offset' => $offset
    ]);
    
} elseif ($action === 'update_sender') {
    // Admin action: Update verified sender name — admin only
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        respond('error', 'Unauthorized', 401);
    }
    $verifiedSender = trim($_POST['verified_sender'] ?? '');
    
    if (empty($invoiceNumber)) {
        respond('error', 'Missing invoice_number', 400);
    }
    
    $updateStmt = mysqli_prepare($conn, "UPDATE invoices SET verified_sender = ? WHERE invoice_number = ?");
    mysqli_stmt_bind_param($updateStmt, 'ss', $verifiedSender, $invoiceNumber);
    
    if (mysqli_stmt_execute($updateStmt) && mysqli_affected_rows($conn) > 0) {
        // Regenerate PDF with updated sender
        $query = mysqli_prepare($conn, "SELECT * FROM invoices WHERE invoice_number = ?");
        mysqli_stmt_bind_param($query, 's', $invoiceNumber);
        mysqli_stmt_execute($query);
        $result = mysqli_stmt_get_result($query);
        
        if ($invoice = mysqli_fetch_assoc($result)) {
            try {
                require_once __DIR__ . '/../lib/InvoicePDF.php';
                
                $pdfDir = __DIR__ . '/../../storage/invoices/';
                if (!is_dir($pdfDir)) {
                    mkdir($pdfDir, 0755, true);
                }
                
                $pdfFilename = 'Invoice_' . $invoice['invoice_number'] . '.pdf';
                $pdfFullPath = $pdfDir . $pdfFilename;
                
                generateInvoicePDF($invoice, $pdfFullPath);
            } catch (Exception $e) {
                error_log('PDF regeneration failed: ' . $e->getMessage());
            }
        }
        
        respond('success', 'Verified sender updated', 200, [
            'invoice_number' => $invoiceNumber,
            'verified_sender' => $verifiedSender
        ]);
    } else {
        respond('error', 'Invoice not found or no change', 404);
    }

} elseif ($action === 'download_pdf') {
    // Download PDF file
    header('Content-Type: application/pdf');
    
    $invoiceNumber = trim($_GET['invoice_number'] ?? '');
    $orderId = trim($_GET['order_id'] ?? '');
    
    if (empty($invoiceNumber) && empty($orderId)) {
        header('Content-Type: application/json');
        respond('error', 'Missing invoice_number or order_id', 400);
    }
    
    if (!empty($invoiceNumber)) {
        $query = mysqli_prepare($conn, "SELECT * FROM invoices WHERE invoice_number = ?");
        mysqli_stmt_bind_param($query, 's', $invoiceNumber);
    } else {
        $query = mysqli_prepare($conn, "SELECT * FROM invoices WHERE order_id = ?");
        mysqli_stmt_bind_param($query, 's', $orderId);
    }
    
    mysqli_stmt_execute($query);
    $result = mysqli_stmt_get_result($query);
    
    if ($invoice = mysqli_fetch_assoc($result)) {
        // Check if PDF exists
        if (!empty($invoice['pdf_path']) && file_exists(__DIR__ . '/../../' . $invoice['pdf_path'])) {
            $pdfPath = __DIR__ . '/../../' . $invoice['pdf_path'];
            header('Content-Disposition: attachment; filename="Invoice_' . $invoice['invoice_number'] . '.pdf"');
            header('Content-Length: ' . filesize($pdfPath));
            readfile($pdfPath);
            exit;
        } else {
            // Generate PDF on the fly
            require_once __DIR__ . '/../lib/InvoicePDF.php';
            downloadInvoicePDF($invoice);
        }
    } else {
        header('Content-Type: application/json');
        respond('error', 'Invoice not found', 404);
    }
    
} elseif ($action === 'download_day_zip') {
    // ── Download all PDFs for a given date as a ZIP ────────────────────
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        respond('error', 'Unauthorized', 401);
    }

    $inputDate = trim($_GET['date'] ?? '');
    if ($inputDate === '' || strtotime($inputDate) === false) {
        respond('error', 'Invalid or missing date', 400);
    }
    $safeDate  = date('Y-m-d', strtotime($inputDate));
    $dateLabel = date('Y-m-d', strtotime($safeDate));

    // Fetch all invoices for that day
    $q = mysqli_query($conn, "SELECT * FROM invoices WHERE DATE(created_at) = '$safeDate' ORDER BY created_at ASC");
    $rows = mysqli_fetch_all($q, MYSQLI_ASSOC);

    if (empty($rows)) {
        respond('error', 'No invoices found for that date', 404);
    }

    // Make sure every invoice has a PDF (regenerate if missing)
    require_once __DIR__ . '/../lib/InvoicePDF.php';
    $pdfDir = __DIR__ . '/../../storage/invoices/';
    if (!is_dir($pdfDir)) {
        mkdir($pdfDir, 0755, true);
    }

    foreach ($rows as &$inv) {
        $pdfFile = $pdfDir . 'Invoice_' . $inv['invoice_number'] . '.pdf';
        if (empty($inv['pdf_path']) || !file_exists(__DIR__ . '/../../' . $inv['pdf_path'])) {
            try {
                generateInvoicePDF($inv, $pdfFile);
                $rel    = 'storage/invoices/Invoice_' . $inv['invoice_number'] . '.pdf';
                $invId  = intval($inv['id']);
                $updRel = mysqli_prepare($conn, "UPDATE invoices SET pdf_path = ? WHERE id = ?");
                mysqli_stmt_bind_param($updRel, 'si', $rel, $invId);
                mysqli_stmt_execute($updRel);
                $inv['pdf_path'] = $rel;
            } catch (Exception $e) {
                // skip if generation fails
            }
        }
    }
    unset($inv);

    // Build ZIP in memory
    if (!class_exists('ZipArchive')) {
        respond('error', 'ZipArchive extension not available', 500);
    }

    $tmpZip = tempnam(sys_get_temp_dir(), 'invoices_') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        respond('error', 'Could not create ZIP file', 500);
    }

    $added = 0;
    foreach ($rows as $inv) {
        if (!empty($inv['pdf_path'])) {
            $fullPath = __DIR__ . '/../../' . $inv['pdf_path'];
            if (file_exists($fullPath)) {
                $zip->addFile($fullPath, 'Invoice_' . $inv['invoice_number'] . '.pdf');
                $added++;
            }
        }
    }
    $zip->close();

    if ($added === 0) {
        @unlink($tmpZip);
        respond('error', 'No PDF files could be generated', 500);
    }

    // Stream ZIP to browser — discard any buffered output first to avoid corrupting binary
    ob_end_clean();
    $zipName = 'Invoices_' . $dateLabel . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . filesize($tmpZip));
    header('Pragma: no-cache');
    header('Cache-Control: must-revalidate');
    readfile($tmpZip);
    @unlink($tmpZip);
    exit;

} else {
    respond('error', 'Invalid action', 400);
}
