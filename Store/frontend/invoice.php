<?php
/**
 * Invoice View - Display and Print Invoice
 */
require_once __DIR__ . '/../backend/config/database.php';

// When served via .htaccess rewrite the browser URL is /Store/invoice.php (no
// 'frontend/' segment), so relative asset paths need a 'frontend/' prefix.
// REQUEST_URI holds the original URL the browser requested — use that.
$assetBase = (strpos($_SERVER['REQUEST_URI'] ?? '', '/frontend/') === false) ? 'frontend/' : '';

$invoiceNumber = trim($_GET['id'] ?? '');
$orderId = trim($_GET['order'] ?? '');

if (empty($invoiceNumber) && empty($orderId)) {
    header('Location: index.php');
    exit;
}

// Fetch invoice
if (!empty($invoiceNumber)) {
    $query = mysqli_prepare($conn, "SELECT * FROM invoices WHERE invoice_number = ?");
    mysqli_stmt_bind_param($query, 's', $invoiceNumber);
} else {
    $query = mysqli_prepare($conn, "SELECT * FROM invoices WHERE order_id = ?");
    mysqli_stmt_bind_param($query, 's', $orderId);
}

mysqli_stmt_execute($query);
$result = mysqli_stmt_get_result($query);
$invoice = mysqli_fetch_assoc($result);

if (!$invoice) {
    echo "<h1>Invoice not found</h1>";
    exit;
}

$items = json_decode($invoice['items'], true) ?: [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?php echo htmlspecialchars($invoice['invoice_number']); ?> | Borey.Store</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
            .invoice-container { 
                box-shadow: none !important; 
                border: none !important;
                margin: 0 !important;
                max-width: 100% !important;
            }
        }
    </style>
</head>
<body class="bg-slate-100 min-h-screen py-8 px-4">
    
    <!-- Action Buttons (no print) -->
    <div class="no-print max-w-3xl mx-auto mb-6 flex gap-4 justify-end flex-wrap">
        <a href="<?php echo $assetBase ? 'backend/api/invoice.php' : '../backend/api/invoice.php'; ?>?action=download_pdf&invoice_number=<?php echo urlencode($invoice['invoice_number']); ?>" class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-xl font-bold flex items-center gap-2 transition-colors shadow-lg">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
            Download PDF
        </a>
        <button onclick="window.print()" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-xl font-bold flex items-center gap-2 transition-colors shadow-lg">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
            Print Invoice
        </button>
        <a href="<?php echo $assetBase; ?>index.php" class="bg-slate-200 hover:bg-slate-300 text-slate-700 px-6 py-3 rounded-xl font-bold flex items-center gap-2 transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
            Back to Store
        </a>
    </div>

    <!-- Invoice Container -->
    <div class="invoice-container max-w-3xl mx-auto bg-white rounded-3xl shadow-xl border border-slate-200 overflow-hidden">
        
        <!-- Header -->
        <div class="bg-gradient-to-r from-slate-900 to-blue-900 text-white p-8">
            <div class="flex justify-between items-start">
                <div>
                    <h1 class="text-3xl font-black tracking-tight mb-2">BOREY<span class="text-blue-400">.STORE</span></h1>
                    <p class="text-slate-300 text-sm">Premium Local Marketplace</p>
                </div>
                <div class="text-right">
                    <div class="inline-block bg-green-500 text-white px-4 py-2 rounded-xl text-sm font-bold mb-3">
                        <?php echo strtoupper($invoice['payment_status']); ?>
                    </div>
                    <p class="text-2xl font-black">INVOICE</p>
                </div>
            </div>
        </div>
        
        <!-- Invoice Details -->
        <div class="p-8">
            <div class="grid grid-cols-2 gap-8 mb-8">
                <div>
                    <h3 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-3">Invoice To</h3>
                    <p class="font-bold text-lg text-slate-900"><?php echo htmlspecialchars($invoice['customer_name']); ?></p>
                    <p class="text-slate-600"><?php echo htmlspecialchars($invoice['customer_phone']); ?></p>
                    <p class="text-slate-600"><?php echo htmlspecialchars($invoice['customer_location']); ?></p>
                </div>
                <div>
                    <h3 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-3 text-center">Invoice Details</h3>
                    <p class="text-slate-600"><span class="font-bold text-slate-900">Invoice #:</span> <?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
                    <p class="text-slate-600"><span class="font-bold text-slate-900">Order #:</span> <?php echo htmlspecialchars($invoice['order_number'] ?? $invoice['order_id']); ?></p>
                    <p class="text-slate-600"><span class="font-bold text-slate-900">Date:</span> <?php echo date('M d, Y', strtotime($invoice['created_at'])); ?></p>
                    <p class="text-slate-600"><span class="font-bold text-slate-900">Payment:</span> <?php echo htmlspecialchars($invoice['payment_bank'] ?? $invoice['payment_method']); ?></p>
                    <?php if (!empty($invoice['bakong_hash'])): ?>
                    <p class="text-slate-600"><span class="font-bold text-slate-900">Bakong Hash:</span> <span class="font-mono text-slate-600"><?php echo htmlspecialchars(substr($invoice['bakong_hash'], 0, 8)); ?></span></p>
                    <?php endif; ?>
                    <?php if (!empty($invoice['payment_time'])): ?>
                    <p class="text-slate-600"><span class="font-bold text-green-600">Paid:</span> <?php echo date('M d, Y g:i A', strtotime($invoice['payment_time'])); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Items Table -->
            <div class="border border-slate-200 rounded-2xl overflow-hidden mb-8">
                <table class="w-full">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="text-left py-4 px-6 text-xs font-bold text-slate-500 uppercase tracking-widest">Item</th>
                            <th class="text-center py-4 px-4 text-xs font-bold text-slate-500 uppercase tracking-widest">Qty</th>
                            <th class="text-right py-4 px-6 text-xs font-bold text-slate-500 uppercase tracking-widest">Price</th>
                            <th class="text-right py-4 px-6 text-xs font-bold text-slate-500 uppercase tracking-widest">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td class="py-4 px-6">
                                <div class="flex items-center gap-3">
                                    <?php if (!empty($item['image'])): ?>
                                    <img src="<?php echo htmlspecialchars($assetBase . $item['image']); ?>" class="w-12 h-12 rounded-lg object-cover">
                                    <?php endif; ?>
                                    <div>
                                        <p class="font-bold text-slate-900"><?php echo htmlspecialchars($item['name']); ?></p>
                                        <?php if (!empty($item['category'])): ?>
                                        <p class="text-xs text-slate-400"><?php echo htmlspecialchars($item['category']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="py-4 px-4 text-center font-bold text-slate-700"><?php echo intval($item['qty']); ?></td>
                            <td class="py-4 px-6 text-right text-slate-700">$<?php echo number_format(floatval($item['price']), 2); ?></td>
                            <td class="py-4 px-6 text-right font-bold text-slate-900">$<?php echo number_format(floatval($item['price']) * intval($item['qty']), 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Totals -->
            <div class="flex justify-end">
                <div class="w-72 space-y-3">
                    <div class="flex justify-between text-slate-600">
                        <span>Subtotal</span>
                        <span>$<?php echo number_format($invoice['subtotal'], 2); ?></span>
                    </div>
                    <div class="flex justify-between text-slate-600">
                        <span>Delivery</span>
                        <span class="text-green-600 font-bold"><?php echo $invoice['delivery_fee'] > 0 ? '$' . number_format($invoice['delivery_fee'], 2) : 'FREE'; ?></span>
                    </div>
                    <div class="border-t border-slate-200 pt-3 flex justify-between text-xl font-black">
                        <span>Total (USD)</span>
                        <span class="text-blue-600">$<?php echo number_format($invoice['total_usd'], 2); ?></span>
                    </div>
                    <div class="flex justify-between text-lg font-bold text-slate-500">
                        <span>Total (KHR)</span>
                        <span><?php echo number_format($invoice['total_khr']); ?> ៛</span>
                    </div>
                </div>
            </div>
            
            <!-- Footer Notes -->
            <div class="mt-12 pt-8 border-t border-slate-100">
                <div class="grid grid-cols-2 gap-8">
                    <div>
                        <h4 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-3">Payment Information</h4>
                        <p class="text-sm text-slate-600">Paid via <?php echo htmlspecialchars($invoice['payment_bank'] ?? $invoice['payment_method']); ?></p>
                        <p class="text-sm text-slate-600"><?php echo !empty($invoice['payer_account_id']) ? 'Payer ID: ' . htmlspecialchars($invoice['payer_account_id']) : 'Bakong KHQR - Khem Sovanny'; ?></p>
                        <?php if (!empty($invoice['verified_sender'])): ?>
                        <p class="text-sm text-slate-700 mt-2"><span class="font-bold text-green-600">Verified Sender:</span> <?php echo htmlspecialchars($invoice['verified_sender']); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="text-right">
                        <h4 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-3">Contact Support</h4>
                        <p class="text-sm text-slate-600">Telegram: @monkey_Dluffy012</p>
                        <p class="text-sm text-slate-600">Phnom Penh, Cambodia</p>
                    </div>
                </div>
                
                <div class="mt-8 text-center">
                    <p class="text-sm text-slate-400 font-bold">Thank you for shopping with Borey.Store!</p>
                    <p class="text-xs text-slate-300 mt-1">© <?php echo date('Y'); ?> Borey Store Co. Ltd</p>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (!empty($invoice['receipt_path']) && file_exists($invoice['receipt_path'])): ?>
    <!-- Bank Transaction Receipt Section -->
    <div class="invoice-container max-w-3xl mx-auto bg-white rounded-3xl shadow-xl border border-slate-200 overflow-hidden mt-8">
        <div class="bg-gradient-to-r from-amber-500 to-orange-600 text-white p-6">
            <h2 class="text-xl font-black flex items-center gap-3">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
                Payment Proof - Bank Transaction
            </h2>
            <p class="text-amber-100 text-sm mt-1">Uploaded by customer as proof of payment</p>
        </div>
        <div class="p-8">
            <div class="bg-slate-50 rounded-2xl p-4 border border-slate-200">
                <img src="<?php echo htmlspecialchars($invoice['receipt_path']); ?>" 
                     alt="Bank Transaction Receipt" 
                     class="max-w-full h-auto mx-auto rounded-lg shadow-lg"
                     style="max-height: 600px; object-fit: contain;">
            </div>
            <p class="text-center text-xs text-slate-400 mt-4">
                This receipt was uploaded by the customer as proof of payment for Order #<?php echo htmlspecialchars($invoice['order_id']); ?>
            </p>
        </div>
    </div>
    <?php endif; ?>

</body>
</html>
