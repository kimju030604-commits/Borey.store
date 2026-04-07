<?php
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once '../../backend/config/database.php';

$message = '';
$msg_type = '';

// Ensure stock column exists on products (idempotent)
mysqli_query($conn, "ALTER TABLE products ADD COLUMN IF NOT EXISTS stock INT DEFAULT 0");

// Handle generating new access code
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_verify();

    if ($_POST['action'] === 'generate_code') {
        // Generate a new 8-character alphanumeric code
        $code    = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $expires = date('Y-m-d H:i:s', strtotime('+90 days'));
        $creator = 'admin_panel';

        $stmt = mysqli_prepare($conn, "INSERT INTO access_codes (code, expires_at, created_by) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'sss', $code, $expires, $creator);

        if (mysqli_stmt_execute($stmt)) {
            // Safe: code is hex-only; wrap in htmlspecialchars for extra safety
            $message  = 'New access code generated: <strong>' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</strong>';
            $msg_type = 'success';
        } else {
            $message  = 'Error generating code. Please try again.';
            $msg_type = 'error';
        }
    } 
    elseif ($_POST['action'] === 'deactivate_code' && isset($_POST['code_id'])) {
        $code_id = intval($_POST['code_id']);
        $stmt    = mysqli_prepare($conn, "UPDATE access_codes SET is_active = FALSE WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $code_id);

        if (mysqli_stmt_execute($stmt)) {
            $message  = 'Access code deactivated';
            $msg_type = 'success';
        } else {
            $message  = 'Error deactivating code. Please try again.';
            $msg_type = 'error';
        }
    }
}

// Fetch all access codes
$codes_query = mysqli_query($conn, "SELECT * FROM access_codes ORDER BY created_at DESC");
$codes = mysqli_fetch_all($codes_query, MYSQLI_ASSOC);

// Fetch products count
$products_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM products");
$products_result = mysqli_fetch_assoc($products_query);

// Fetch invoices stats
$invoices_query = mysqli_query($conn, "SELECT COUNT(*) as total, SUM(total_usd) as revenue FROM invoices");
$invoices_result = mysqli_fetch_assoc($invoices_query);
$total_invoices = $invoices_result['total'] ?? 0;
$total_revenue = $invoices_result['revenue'] ?? 0;

// Stock stats
$stock_query = mysqli_query($conn, "SELECT SUM(stock) AS total_stock, SUM(stock = 0) AS out_of_stock FROM products");
$stock_result = mysqli_fetch_assoc($stock_query);
$total_stock = $stock_result['total_stock'] ?? 0;
$out_of_stock = $stock_result['out_of_stock'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Borey.store</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="bg-slate-50 min-h-screen p-6">
    <div class="max-w-7xl mx-auto">
        <!-- Header -->
        <div class="flex justify-between items-center mb-10">
            <div>
                <h1 class="text-4xl font-black text-slate-900">Admin Dashboard</h1>
                <p class="text-slate-500 font-bold mt-2">Manage products and access codes</p>
            </div>
            <a href="login.php?logout=1" class="text-sm font-bold text-slate-500 hover:text-red-600 bg-white px-6 py-3 rounded-2xl shadow-md">Logout</a>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="p-4 rounded-2xl mb-6 font-bold <?php echo $msg_type === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                <!-- $message may contain intentional HTML (e.g. <strong> for code display) -->
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-10">
            <div class="bg-white p-8 rounded-[2rem] shadow-lg">
                <p class="text-slate-500 font-bold text-sm uppercase tracking-widest mb-2">Total Products</p>
                <p class="text-4xl font-black text-slate-900"><?php echo $products_result['total']; ?></p>
            </div>
            <div class="bg-white p-8 rounded-[2rem] shadow-lg">
                <p class="text-slate-500 font-bold text-sm uppercase tracking-widest mb-2">Total Invoices</p>
                <p class="text-4xl font-black text-blue-600"><?php echo $total_invoices; ?></p>
            </div>
            <div class="bg-white p-8 rounded-[2rem] shadow-lg">
                <p class="text-slate-500 font-bold text-sm uppercase tracking-widest mb-2">Total Revenue</p>
                <p class="text-4xl font-black text-green-600">$<?php echo number_format($total_revenue, 2); ?></p>
            </div>
            <div class="bg-white p-8 rounded-[2rem] shadow-lg">
                <p class="text-slate-500 font-bold text-sm uppercase tracking-widest mb-2">Active Codes</p>
                <p class="text-4xl font-black text-slate-900"><?php echo count(array_filter($codes, fn($c) => $c['is_active'])); ?></p>
            </div>
            <div class="bg-white p-8 rounded-[2rem] shadow-lg md:col-span-2">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-500 font-bold text-sm uppercase tracking-widest mb-2">Inventory</p>
                        <p class="text-3xl font-black text-slate-900"><?php echo intval($total_stock); ?> units</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs font-bold text-red-500 uppercase tracking-widest">Out of stock</p>
                        <p class="text-2xl font-black text-red-600"><?php echo intval($out_of_stock); ?></p>
                    </div>
                </div>
                <a href="products.php" class="inline-flex mt-4 items-center gap-2 text-sm font-bold text-blue-600 hover:text-blue-800">Manage stock →</a>
            </div>
        </div>

        <!-- Two Column Layout -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Left Column - Access Codes -->
            <div class="lg:col-span-2">
                <div class="bg-white p-8 rounded-[2rem] shadow-xl">
                    <h2 class="text-2xl font-black text-slate-900 mb-6">Access Codes</h2>
                    
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-200">
                                    <th class="text-left py-3 px-3 font-bold text-slate-500 uppercase text-xs tracking-widest">Code</th>
                                    <th class="text-left py-3 px-3 font-bold text-slate-500 uppercase text-xs tracking-widest">Status</th>
                                    <th class="text-left py-3 px-3 font-bold text-slate-500 uppercase text-xs tracking-widest">Uses</th>
                                    <th class="text-left py-3 px-3 font-bold text-slate-500 uppercase text-xs tracking-widest">Expires</th>
                                    <th class="text-left py-3 px-3 font-bold text-slate-500 uppercase text-xs tracking-widest">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($codes as $code): ?>
                                    <tr class="border-b border-slate-50 hover:bg-slate-50">
                                        <td class="py-4 px-3">
                                            <code class="bg-slate-100 px-3 py-2 rounded-lg font-mono font-bold text-slate-900"><?php echo $code['code']; ?></code>
                                        </td>
                                        <td class="py-4 px-3">
                                            <span class="<?php echo $code['is_active'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?> px-3 py-1 rounded-full text-xs font-bold">
                                                <?php echo $code['is_active'] ? '✓ Active' : '✗ Inactive'; ?>
                                            </span>
                                        </td>
                                        <td class="py-4 px-3 font-bold text-slate-900"><?php echo $code['used_count']; ?></td>
                                        <td class="py-4 px-3 text-slate-600 text-xs">
                                            <?php echo $code['expires_at'] ? date('M d, Y', strtotime($code['expires_at'])) : 'Never'; ?>
                                        </td>
                                        <td class="py-4 px-3">
                                            <?php if ($code['is_active']): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="action" value="deactivate_code">
                                                    <input type="hidden" name="code_id" value="<?php echo $code['id']; ?>">
                                                    <button type="submit" class="text-xs font-bold text-red-600 hover:bg-red-50 px-2 py-1 rounded">Revoke</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Right Column - Quick Actions -->
            <div>
                <div class="bg-white p-8 rounded-[2rem] shadow-xl">
                    <h2 class="text-2xl font-black text-slate-900 mb-8">Quick Actions</h2>
                    
                    <div class="space-y-4">
                        <!-- Generate Code -->
                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="generate_code">
                            <button type="submit" class="w-full bg-blue-600 text-white py-4 rounded-2xl font-black hover:bg-blue-700 transition-all active:scale-95">
                                🔐 Generate New Code
                            </button>
                        </form>

                        <!-- Add Product Button -->
                        <a href="products.php" class="w-full bg-slate-900 text-white py-4 rounded-2xl font-black hover:bg-slate-800 transition-all active:scale-95 text-center block">
                            📦 Add New Product
                        </a>
                        
                        <!-- View Invoices -->
                        <a href="invoices.php" class="w-full bg-green-600 text-white py-4 rounded-2xl font-black hover:bg-green-700 transition-all active:scale-95 text-center block">
                            🧾 View All Invoices
                        </a>

                        <!-- Back to Store -->
                        <a href="../index.php" class="w-full bg-white text-slate-900 border-2 border-slate-200 py-4 rounded-2xl font-black hover:border-slate-900 transition-all text-center block">
                            🏪 Back to Store
                        </a>
                    </div>

                    <!-- Info Box -->
                    <div class="mt-8 p-6 bg-blue-50 rounded-2xl border border-blue-200">
                        <p class="text-xs font-bold text-blue-900 mb-3">💡 ABOUT ACCESS CODES</p>
                        <ul class="text-xs text-blue-800 space-y-2 leading-relaxed">
                            <li>• Each code is valid for 90 days</li>
                            <li>• Codes can be reused multiple times</li>
                            <li>• Share codes securely with trusted team members</li>
                            <li>• Revoke codes to disable access</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
