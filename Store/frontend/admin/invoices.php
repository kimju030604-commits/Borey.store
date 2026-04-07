<?php
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once '../../backend/config/database.php';

// Handle actions
$message = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'delete_invoice' && isset($_POST['invoice_id'])) {
        $invoiceId = intval($_POST['invoice_id']);
        
        // Get PDF path before deleting
        $query = mysqli_query($conn, "SELECT pdf_path FROM invoices WHERE id = $invoiceId");
        $invoice = mysqli_fetch_assoc($query);
        
        if ($invoice && mysqli_query($conn, "DELETE FROM invoices WHERE id = $invoiceId")) {
            // Delete PDF file if exists
            if (!empty($invoice['pdf_path']) && file_exists('../../' . $invoice['pdf_path'])) {
                unlink('../../' . $invoice['pdf_path']);
            }
            $message = "Invoice deleted successfully";
            $msg_type = "success";
        } else {
            $message = "Error deleting invoice";
            $msg_type = "error";
        }
    }
    
    if ($action === 'regenerate_pdf' && isset($_POST['invoice_id'])) {
        $invoiceId = intval($_POST['invoice_id']);
        
        // Get invoice data
        $query = mysqli_query($conn, "SELECT * FROM invoices WHERE id = $invoiceId");
        $invoice = mysqli_fetch_assoc($query);
        
        if ($invoice) {
            try {
                require_once '../../backend/lib/InvoicePDF.php';
                
                $pdfDir = '../../storage/invoices/';
                if (!is_dir($pdfDir)) {
                    mkdir($pdfDir, 0755, true);
                }
                
                $pdfFilename = 'Invoice_' . $invoice['invoice_number'] . '.pdf';
                $pdfFullPath = $pdfDir . $pdfFilename;
                
                generateInvoicePDF($invoice, $pdfFullPath);
                // Update invoice record with PDF path using a prepared statement
                $pdfPath = 'storage/invoices/' . $pdfFilename;
                $updPdf  = mysqli_prepare($conn, "UPDATE invoices SET pdf_path = ? WHERE id = ?");
                mysqli_stmt_bind_param($updPdf, 'si', $pdfPath, $invoiceId);
                mysqli_stmt_execute($updPdf);
                
                $message  = "PDF regenerated successfully";
                $msg_type = "success";
            } catch (Exception $e) {
                $message = "Error regenerating PDF: " . $e->getMessage();
                $msg_type = "error";
            }
        }
    }
}

// ── Date filter ─────────────────────────────────────────────────────────────
$filterDate  = trim($_GET['date'] ?? '');
$hasDate     = ($filterDate !== '' && strtotime($filterDate) !== false);
$safeDate    = $hasDate ? date('Y-m-d', strtotime($filterDate)) : '';
$dateLabel   = $hasDate ? date('F j, Y', strtotime($safeDate)) : 'All time';
$whereClause = $hasDate ? "WHERE DATE(created_at) = '$safeDate'" : '';

// ── Fetch invoices with pagination ───────────────────────────────────────────
$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$totalQuery  = mysqli_query($conn, "SELECT COUNT(*) as total FROM invoices $whereClause");
$totalResult = mysqli_fetch_assoc($totalQuery);
$totalInvoices = $totalResult['total'];
$totalPages  = ceil($totalInvoices / $perPage);

$invoicesQuery = mysqli_query($conn, "SELECT * FROM invoices $whereClause ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$invoices = [];
while ($row = mysqli_fetch_assoc($invoicesQuery)) {
    $row['items'] = json_decode($row['items'], true);
    $invoices[] = $row;
}

// ── Stats for current filter ─────────────────────────────────────────────────
$statsQuery = mysqli_query($conn, "SELECT SUM(total_usd) as total_revenue, COUNT(*) as total_orders FROM invoices $whereClause");
$stats = mysqli_fetch_assoc($statsQuery);

// ── Days that have invoices (for the date picker highlights) ─────────────────
$daysQuery = mysqli_query($conn, "SELECT DATE(created_at) as day, COUNT(*) as cnt FROM invoices GROUP BY DATE(created_at) ORDER BY day DESC");
$invoiceDays = [];
while ($d = mysqli_fetch_assoc($daysQuery)) {
    $invoiceDays[$d['day']] = intval($d['cnt']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoices | Admin Dashboard | Borey.store</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }

        /* ── Action buttons: always a 2×2 grid of icon buttons ── */
        .invoice-actions { display: grid; grid-template-columns: repeat(2, 1fr); gap: 5px; width: fit-content; }
        .invoice-actions form { display: contents; }
        .invoice-actions .btn-action { display: flex; align-items: center; justify-content: center; padding: 8px; border-radius: 8px; transition: background-color .15s; }

        @media (min-width: 769px) {
            /* On desktop keep all 4 in a single row */
            .invoice-actions { grid-template-columns: repeat(4, 1fr); }
        }

        @media (max-width: 768px) {
            body { overflow-x: hidden; }
            .responsive-table, .responsive-table tbody, .responsive-table tr { display: block; width: 100%; }
            .responsive-table thead { display: none; }
            .responsive-table tr { margin-bottom: 12px; background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
            .responsive-table td { display: grid; grid-template-columns: 120px 1fr; gap: 10px; padding: 10px 6px; align-items: center; word-break: break-word; }
            .responsive-table td::before { content: attr(data-label); font-size: 11px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.08em; }
            .invoice-actions .btn-action { padding: 10px; }
        }
    </style>
</head>
<body class="bg-slate-50 min-h-screen">
    <!-- Top Navigation -->
    <nav class="bg-white border-b border-slate-200 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-6 py-4 flex justify-between items-center">
            <div class="flex items-center gap-8">
                <h1 class="text-xl font-black text-slate-900">BOREY<span class="text-blue-600">.ADMIN</span></h1>
                <div class="hidden md:flex items-center gap-6 text-sm font-bold">
                    <a href="dashboard.php" class="text-slate-500 hover:text-slate-900">Dashboard</a>
                    <a href="products.php" class="text-slate-500 hover:text-slate-900">Products</a>
                    <a href="invoices.php" class="text-blue-600">Invoices</a>
                </div>
            </div>
            <div class="flex items-center gap-4">
                <a href="../index.php" class="text-sm font-bold text-slate-500 hover:text-slate-900">View Store</a>
                <a href="login.php?logout=1" class="text-sm font-bold text-red-500 hover:text-red-700">Logout</a>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto p-6">
        <!-- Header -->
        <div class="flex flex-wrap justify-between items-start gap-4 mb-8">
            <div>
                <h2 class="text-3xl font-black text-slate-900">Invoices</h2>
                <p class="text-slate-500 font-bold mt-1">
                    <?php echo $hasDate ? 'Showing invoices for <span class="text-blue-600">' . $dateLabel . '</span>' : 'All invoices'; ?>
                </p>
            </div>
            <!-- Date filter + Download All -->
            <div class="flex flex-wrap items-center gap-3">
                <form method="GET" class="flex items-center gap-2">
                    <input type="date"
                           name="date"
                           value="<?php echo htmlspecialchars($safeDate); ?>"
                           max="<?php echo date('Y-m-d'); ?>"
                           class="border border-slate-300 rounded-xl px-4 py-2 text-sm font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-400"
                           onchange="this.form.submit()">
                    <?php if ($hasDate): ?>
                        <a href="invoices.php"
                           class="px-4 py-2 bg-slate-200 text-slate-700 rounded-xl text-sm font-bold hover:bg-slate-300 transition-colors">
                            Show All
                        </a>
                    <?php endif; ?>
                </form>
                <?php if ($hasDate && $totalInvoices > 0): ?>
                    <a href="../backend/api/invoice.php?action=download_day_zip&date=<?php echo urlencode($safeDate); ?>"
                       class="flex items-center gap-2 px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-sm font-bold transition-colors shadow">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                        Download All <?php echo $totalInvoices; ?> PDF<?php echo $totalInvoices > 1 ? 's' : ''; ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Day shortcut chips -->
        <?php if (!empty($invoiceDays)): ?>
        <div class="flex flex-wrap gap-2 mb-6">
            <?php foreach (array_slice($invoiceDays, 0, 10, true) as $day => $cnt): 
                $isActive = ($safeDate === $day);
            ?>
                <a href="invoices.php?date=<?php echo urlencode($day); ?>"
                   class="px-3 py-1 rounded-full text-xs font-bold border transition-colors <?php echo $isActive ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-600 border-slate-300 hover:bg-slate-100'; ?>">
                    <?php echo date('M j', strtotime($day)); ?>
                    <span class="opacity-70">(<?php echo $cnt; ?>)</span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="p-4 rounded-2xl mb-6 font-bold <?php echo $msg_type === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white p-6 rounded-2xl shadow-lg border border-slate-100">
                <p class="text-slate-500 font-bold text-xs uppercase tracking-widest mb-2"><?php echo $hasDate ? 'Orders on Day' : 'Total Invoices'; ?></p>
                <p class="text-3xl font-black text-slate-900"><?php echo $totalInvoices; ?></p>
                <?php if ($hasDate): ?><p class="text-xs text-slate-400 mt-1"><?php echo $dateLabel; ?></p><?php endif; ?>
            </div>
            <div class="bg-white p-6 rounded-2xl shadow-lg border border-slate-100">
                <p class="text-slate-500 font-bold text-xs uppercase tracking-widest mb-2"><?php echo $hasDate ? 'Revenue on Day' : 'Total Revenue'; ?> (USD)</p>
                <p class="text-3xl font-black text-green-600">$<?php echo number_format($stats['total_revenue'] ?? 0, 2); ?></p>
            </div>
            <div class="bg-white p-6 rounded-2xl shadow-lg border border-slate-100">
                <p class="text-slate-500 font-bold text-xs uppercase tracking-widest mb-2">This Month</p>
                <?php
                $monthQuery = mysqli_query($conn, "SELECT COUNT(*) as count, SUM(total_usd) as sum FROM invoices WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())");
                $monthStats = mysqli_fetch_assoc($monthQuery);
                ?>
                <p class="text-3xl font-black text-blue-600"><?php echo $monthStats['count'] ?? 0; ?></p>
                <p class="text-xs text-slate-400 mt-1">$<?php echo number_format($monthStats['sum'] ?? 0, 2); ?> revenue</p>
            </div>
            <div class="bg-white p-6 rounded-2xl shadow-lg border border-slate-100">
                <p class="text-slate-500 font-bold text-xs uppercase tracking-widest mb-2">Avg Order Value</p>
                <?php $avgOrder = $totalInvoices > 0 ? (($stats['total_revenue'] ?? 0) / $totalInvoices) : 0; ?>
                <p class="text-3xl font-black text-amber-600">$<?php echo number_format($avgOrder, 2); ?></p>
            </div>
        </div>

        <!-- Invoices Table -->
        <div class="bg-white rounded-2xl shadow-xl border border-slate-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full responsive-table">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="text-left py-4 px-6 text-xs font-bold text-slate-500 uppercase tracking-widest">Invoice</th>
                            <th class="text-left py-4 px-6 text-xs font-bold text-slate-500 uppercase tracking-widest">Customer</th>
                            <th class="text-left py-4 px-6 text-xs font-bold text-slate-500 uppercase tracking-widest">Items</th>
                            <th class="text-left py-4 px-6 text-xs font-bold text-slate-500 uppercase tracking-widest">Total</th>
                            <th class="text-left py-4 px-6 text-xs font-bold text-slate-500 uppercase tracking-widest">Payment</th>
                            <th class="text-left py-4 px-6 text-xs font-bold text-slate-500 uppercase tracking-widest">Date</th>
                            <th class="text-left py-4 px-6 text-xs font-bold text-slate-500 uppercase tracking-widest">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($invoices)): ?>
                            <tr>
                                <td colspan="7" class="py-12 text-center text-slate-400 font-bold">No invoices found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($invoices as $invoice): ?>
                                <tr class="hover:bg-slate-50">
                                    <td class="py-4 px-6" data-label="Invoice">
                                        <div>
                                            <p class="font-bold text-slate-900"><?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
                                            <p class="text-xs text-slate-400"><?php echo htmlspecialchars($invoice['order_id']); ?></p>
                                        </div>
                                    </td>
                                    <td class="py-4 px-6" data-label="Customer">
                                        <div>
                                            <p class="font-bold text-slate-900"><?php echo htmlspecialchars($invoice['customer_name']); ?></p>
                                            <p class="text-xs text-slate-500"><?php echo htmlspecialchars($invoice['customer_phone']); ?></p>
                                        </div>
                                    </td>
                                    <td class="py-4 px-6" data-label="Items">
                                        <span class="bg-slate-100 text-slate-700 px-2 py-1 rounded-full text-xs font-bold">
                                            <?php echo count($invoice['items'] ?? []); ?> items
                                        </span>
                                    </td>
                                    <td class="py-4 px-6" data-label="Total">
                                        <p class="font-black text-slate-900">$<?php echo number_format($invoice['total_usd'], 2); ?></p>
                                        <p class="text-xs text-slate-400"><?php echo number_format($invoice['total_khr']); ?> ៛</p>
                                    </td>
                                    <td class="py-4 px-6" data-label="Payment">
                                        <span class="bg-green-100 text-green-700 px-2 py-1 rounded-full text-xs font-bold">
                                            <?php echo htmlspecialchars($invoice['payment_method'] ?? 'KHQR'); ?>
                                        </span>
                                    </td>
                                    <td class="py-4 px-6 text-sm text-slate-600" data-label="Date">
                                        <?php echo date('M d, Y', strtotime($invoice['created_at'])); ?>
                                        <br>
                                        <span class="text-xs text-slate-400"><?php echo date('g:i A', strtotime($invoice['created_at'])); ?></span>
                                        <?php if (!empty($invoice['payment_time'])): ?>
                                        <br>
                                        <span class="text-xs text-green-600 font-bold">Paid: <?php echo date('g:i A', strtotime($invoice['payment_time'])); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-4 px-6" data-label="Actions">
                                        <div class="invoice-actions">
                                            <!-- View Invoice -->
                                            <a href="../invoice.php?id=<?php echo urlencode($invoice['invoice_number']); ?>" 
                                               target="_blank"
                                               class="btn-action p-2 bg-blue-100 text-blue-600 rounded-lg hover:bg-blue-200 transition-colors" 
                                               title="View Invoice">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            </a>
                                            
                                            <!-- Download PDF -->
                                            <a href="../backend/api/invoice.php?action=download_pdf&invoice_number=<?php echo urlencode($invoice['invoice_number']); ?>" 
                                               class="btn-action p-2 bg-green-100 text-green-600 rounded-lg hover:bg-green-200 transition-colors" 
                                               title="Download PDF">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                                            </a>
                                            
                                            <!-- Regenerate PDF -->
                                            <form method="POST" style="display:contents">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="regenerate_pdf">
                                                <input type="hidden" name="invoice_id" value="<?php echo $invoice['id']; ?>">
                                                <button type="submit" 
                                                        class="btn-action p-2 bg-amber-100 text-amber-600 rounded-lg hover:bg-amber-200 transition-colors" 
                                                        title="Regenerate PDF">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/></svg>
                                                </button>
                                            </form>
                                            
                                            <!-- Delete -->
                                            <form method="POST" style="display:contents" onsubmit="return confirm('Are you sure you want to delete this invoice?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="delete_invoice">
                                                <input type="hidden" name="invoice_id" value="<?php echo $invoice['id']; ?>">
                                                <button type="submit" 
                                                        class="btn-action p-2 bg-red-100 text-red-600 rounded-lg hover:bg-red-200 transition-colors" 
                                                        title="Delete Invoice">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <?php
                    $pageBase = 'invoices.php?';
                    if ($hasDate) $pageBase .= 'date=' . urlencode($safeDate) . '&';
                    $pageBase .= 'page=';
                ?>
                <div class="px-6 py-4 border-t border-slate-100 flex justify-between items-center">
                    <p class="text-sm text-slate-500">
                        Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $perPage, $totalInvoices); ?> of <?php echo $totalInvoices; ?> invoices
                    </p>
                    <div class="flex gap-2">
                        <?php if ($page > 1): ?>
                            <a href="<?php echo $pageBase . ($page - 1); ?>" class="px-4 py-2 bg-slate-100 text-slate-700 rounded-lg font-bold hover:bg-slate-200 transition-colors">Previous</a>
                        <?php endif; ?>
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <a href="<?php echo $pageBase . $i; ?>"
                               class="px-4 py-2 rounded-lg font-bold transition-colors <?php echo $i === $page ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?>
                            <a href="<?php echo $pageBase . ($page + 1); ?>" class="px-4 py-2 bg-slate-100 text-slate-700 rounded-lg font-bold hover:bg-slate-200 transition-colors">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
