<?php
header('Content-Type: application/json; charset=utf-8');

function respond($status, $message, $code = 200, $data = []) {
    http_response_code($code);
    echo json_encode([
        'status' => $status,
        'message' => $message,
        'data' => $data,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond('error', 'POST required', 400);
}

$action = trim((string)($_POST['action'] ?? ''));
if ($action === '') {
    respond('error', 'Missing action', 400);
}

// Backward-compatible no-op endpoint if old frontend calls this.
if ($action === 'store_transaction') {
    respond('success', 'Transaction stored (no-op in proxy)', 200, ['proxied' => false]);
}

// Handle bank transaction receipt upload
if ($action === 'upload_receipt') {
    $orderId = trim((string)($_POST['order_id'] ?? ''));
    if ($orderId === '') {
        respond('error', 'Missing order_id', 400);
    }
    
    if (!isset($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
        $uploadError = $_FILES['receipt']['error'] ?? 'No file uploaded';
        respond('error', 'File upload failed: ' . $uploadError, 400);
    }
    
    $file = $_FILES['receipt'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $maxSize = 5 * 1024 * 1024; // 5MB
    
    // Validate allowed file extensions (defence-in-depth alongside MIME check)
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExtensions, true)) {
        respond('error', 'Invalid file extension. Please upload JPG, PNG, WebP, or GIF', 400);
    }
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedTypes)) {
        respond('error', 'Invalid file type. Please upload JPG, PNG, WebP, or GIF', 400);
    }
    
    // Validate file size
    if ($file['size'] > $maxSize) {
        respond('error', 'File too large. Maximum 5MB allowed', 400);
    }
    
    // Generate safe filename
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $safeOrderId = preg_replace('/[^a-zA-Z0-9\-_]/', '', $orderId);
    $timestamp = date('Ymd_His');
    $filename = "receipt_{$safeOrderId}_{$timestamp}.{$ext}";
    
    $uploadDir = __DIR__ . '/../../storage/uploads/receipts/';
    $uploadPath = $uploadDir . $filename;
    
    // Ensure directory exists
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
        // Store receipt info in a JSON file for tracking
        $receiptLog = $uploadDir . 'receipts_log.json';
        $logs = [];
        if (file_exists($receiptLog)) {
            $logs = json_decode(file_get_contents($receiptLog), true) ?: [];
        }
        $logs[] = [
            'order_id' => $orderId,
            'filename' => $filename,
            'original_name' => $file['name'],
            'uploaded_at' => date('Y-m-d H:i:s'),
            'size' => $file['size'],
            'mime_type' => $mimeType,
        ];
        file_put_contents($receiptLog, json_encode($logs, JSON_PRETTY_PRINT));
        
        // Update invoice with receipt path and regenerate PDF
        require_once __DIR__ . '/../config/database.php';
        require_once __DIR__ . '/../lib/OCRHelper.php';
        $receiptRelPath = 'storage/uploads/receipts/' . $filename;
        $paymentTime = date('Y-m-d H:i:s');
        
        // Extract sender name from receipt using OCR
        $ocrResult = processReceiptForSenderName($uploadPath);
        $verifiedSender = $ocrResult['sender_name'];
        
        // Update invoice record with receipt path, verified sender, and payment time
        if ($verifiedSender) {
            $updateStmt = mysqli_prepare($conn, "UPDATE invoices SET receipt_path = ?, verified_sender = ?, payment_time = ? WHERE order_id = ?");
            mysqli_stmt_bind_param($updateStmt, 'ssss', $receiptRelPath, $verifiedSender, $paymentTime, $orderId);
        } else {
            $updateStmt = mysqli_prepare($conn, "UPDATE invoices SET receipt_path = ?, payment_time = ? WHERE order_id = ?");
            mysqli_stmt_bind_param($updateStmt, 'sss', $receiptRelPath, $paymentTime, $orderId);
        }
        mysqli_stmt_execute($updateStmt);
        
        // Regenerate PDF with receipt image included
        $invoiceQuery = mysqli_prepare($conn, "SELECT * FROM invoices WHERE order_id = ?");
        mysqli_stmt_bind_param($invoiceQuery, 's', $orderId);
        mysqli_stmt_execute($invoiceQuery);
        $invoiceResult = mysqli_stmt_get_result($invoiceQuery);
        
        if ($invoice = mysqli_fetch_assoc($invoiceResult)) {
            try {
                require_once __DIR__ . '/../lib/InvoicePDF.php';
                
                // Add receipt path to invoice data
                $invoice['receipt_path'] = $receiptRelPath;
                
                $pdfDir = __DIR__ . '/../../storage/invoices/';
                if (!is_dir($pdfDir)) {
                    mkdir($pdfDir, 0755, true);
                }
                
                $pdfFilename = 'Invoice_' . $invoice['invoice_number'] . '.pdf';
                $pdfFullPath = $pdfDir . $pdfFilename;
                
                generateInvoicePDF($invoice, $pdfFullPath);
                $pdfPath = 'storage/invoices/' . $pdfFilename;
                
                // Update PDF path using a prepared statement
                $updPdf = mysqli_prepare($conn, "UPDATE invoices SET pdf_path = ? WHERE order_id = ?");
                mysqli_stmt_bind_param($updPdf, 'ss', $pdfPath, $orderId);
                mysqli_stmt_execute($updPdf);
            } catch (Exception $e) {
                error_log('PDF regeneration with receipt failed: ' . $e->getMessage());
            }
        }
        
        respond('success', 'Receipt uploaded successfully', 200, [
            'filename' => $filename,
            'order_id' => $orderId,
            'verified_sender' => $verifiedSender,
            'ocr_success' => $ocrResult['success'],
        ]);
    } else {
        respond('error', 'Failed to save file', 500);
    }
}

$gatewayUrl = getenv('PY_PAYMENT_GATEWAY_URL');
if (!$gatewayUrl) {
    // Default to local Python gateway (run: python app.py inside backend/services/bakong_gateway)
    $gatewayUrl = 'http://127.0.0.1:8000/api/payment';
}

// Route to specific endpoints based on action
if ($action === 'mark_paid') {
    $gatewayUrl = str_replace('/api/payment', '/api/payment/mark-paid', $gatewayUrl);
} elseif ($action === 'generate_deeplink') {
    $gatewayUrl = str_replace('/api/payment', '/api/generate_deeplink', $gatewayUrl);
} elseif ($action === 'check_account') {
    $gatewayUrl = str_replace('/api/payment', '/api/check_account', $gatewayUrl);
} elseif ($action === 'check_transaction') {
    $gatewayUrl = str_replace('/api/payment', '/api/check_transaction', $gatewayUrl);
}

if (!function_exists('curl_init')) {
    respond('error', 'cURL extension is required', 500);
}

$forwardPayload = $_POST;
if ($action === 'generate_khqr') {
    $forwardPayload['currency'] = 'KHR';
}

$payload = http_build_query($forwardPayload);

$ch = curl_init($gatewayUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded',
]);

$responseBody = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    respond('error', 'Gateway connection failed: ' . $curlError, 502, ['gateway_url' => $gatewayUrl]);
}

if (!is_string($responseBody) || $responseBody === '') {
    respond('error', 'Gateway returned empty response', 502, ['gateway_url' => $gatewayUrl]);
}

$decoded = json_decode($responseBody, true);
if (!is_array($decoded)) {
    $snippet = substr(trim($responseBody), 0, 200);
    respond('error', 'Gateway returned non-JSON response', 502, [
        'gateway_url' => $gatewayUrl,
        'http_code' => $httpCode,
        'response_snippet' => $snippet,
    ]);
}

if ($httpCode >= 400) {
    http_response_code($httpCode);
}

echo json_encode($decoded);
exit;
