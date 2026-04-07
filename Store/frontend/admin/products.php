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

// Verify CSRF token on every POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

// Ensure stock column exists
mysqli_query($conn, "ALTER TABLE products ADD COLUMN IF NOT EXISTS stock INT DEFAULT 0");

// Create images folder if it doesn't exist
$img_dir = '../assets/img/products';
if (!is_dir($img_dir)) {
    mkdir($img_dir, 0755, true);
}

// Handle deletion first
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $del_id = intval($_POST['delete_id']);
    $res = mysqli_query($conn, "SELECT image FROM products WHERE id = $del_id");
    if ($row = mysqli_fetch_assoc($res)) {
        if (!empty($row['image'])) {
            // Remap old img/products/ paths to new assets/img/products/ location
            $imgRelative = preg_replace('#^img/products/#', 'assets/img/products/', $row['image']);
            $path = '../' . $imgRelative;
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
    mysqli_query($conn, "DELETE FROM products WHERE id = $del_id");
    $message = "Product removed.";
    $msg_type = "success";
}

// Handle Form Submission for new product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name']) && !isset($_POST['update_stock_id']) && !isset($_POST['delete_id'])) {
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $price = mysqli_real_escape_string($conn, $_POST['price']);
    $category = mysqli_real_escape_string($conn, $_POST['category']);
    $rating = mysqli_real_escape_string($conn, $_POST['rating']);
    $stock = isset($_POST['stock']) ? intval($_POST['stock']) : 0;
    
    $image_path = '';
    
    // Handle image upload
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['image']['tmp_name'];
        $file_name = $_FILES['image']['name'];
        $file_size = $_FILES['image']['size'];
        $file_type = mime_content_type($file_tmp);
        
        // Validate file size (max 5MB)
        if ($file_size > 5 * 1024 * 1024) {
            $message = "Image file too large. Maximum size is 5MB.";
            $msg_type = "error";
        } 
        // Validate file type
        elseif (!in_array($file_type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
            $message = "Invalid image format. Only JPG, PNG, GIF, and WEBP are allowed.";
            $msg_type = "error";
        } else {
            // Generate unique filename
            $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
            $unique_filename = uniqid('product_') . '.' . $file_extension;
            $image_path = 'assets/img/products/' . $unique_filename;
            $full_path = $img_dir . '/' . $unique_filename;
            
            // Move uploaded file
            if (!move_uploaded_file($file_tmp, $full_path)) {
                $message = "Error uploading image. Please try again.";
                $msg_type = "error";
                $image_path = '';
            }
        }
    } else if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $message = "Please select a product image.";
        $msg_type = "error";
    }

    // If image was processed successfully or retrieved from POST, insert product
    $nameEn = trim($_POST['name_en'] ?? '');
    if ($msg_type !== 'error' && !empty($image_path) && !empty($name) && !empty($price)) {
        // Use a prepared statement to prevent SQL injection
        $stmt = mysqli_prepare($conn, "INSERT INTO products (name, name_en, price, category, rating, image, stock) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $priceF   = floatval($price);
        $ratingF  = floatval($rating);
        mysqli_stmt_bind_param($stmt, 'ssdsdsi', $name, $nameEn, $priceF, $category, $ratingF, $image_path, $stock);
        if (mysqli_stmt_execute($stmt)) {
            header('Location: products.php?added=1');
            exit;
        } else {
            $message  = 'Error adding product. Please try again.';
            $msg_type = 'error';
        }
    } elseif (empty($name) || empty($price)) {
        $message = "Name and Price are required.";
        $msg_type = "error";
    }
}

// Show success message from redirect
if (isset($_GET['added'])) {
    $message  = '✓ Product added successfully!';
    $msg_type = 'success';
}

// Update English name inline
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_name_en_id'])) {
    $pid   = intval($_POST['update_name_en_id']);
    $enVal = trim($_POST['name_en_value'] ?? '');
    $stmt  = mysqli_prepare($conn, "UPDATE products SET name_en = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'si', $enVal, $pid);
    mysqli_stmt_execute($stmt);
    $message  = 'English name updated.';
    $msg_type = 'success';
}

// Update stock inline (add or subtract, never below zero)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_stock_id'])) {
    $pid = intval($_POST['update_stock_id']);
    $delta = intval($_POST['row_add'][$pid] ?? 0);
    mysqli_query($conn, "UPDATE products SET stock = GREATEST(0, stock + $delta) WHERE id = $pid");
    $message = $delta >= 0 ? "Stock increased." : "Stock decreased.";
    $msg_type = "success";
}

// Update price inline
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_price_id'])) {
    $pid = intval($_POST['update_price_id']);
    $newPrice = isset($_POST['row_price'][$pid]) ? floatval($_POST['row_price'][$pid]) : null;
    if ($newPrice !== null && $newPrice >= 0) {
        $safePrice = round($newPrice, 2);
        mysqli_query($conn, "UPDATE products SET price = $safePrice WHERE id = $pid");
        $message = "Price updated.";
        $msg_type = "success";
    } else {
        $message = "Enter a valid price.";
        $msg_type = "error";
    }
}

// Bulk stock update (add or subtract, never below zero)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_update'])) {
    $bulkDelta = intval($_POST['bulk_stock_value'] ?? 0);
    $ids = isset($_POST['bulk_ids']) && is_array($_POST['bulk_ids']) ? array_map('intval', $_POST['bulk_ids']) : [];
    $ids = array_values(array_unique(array_filter($ids)));
    if ($ids) {
        $idList = implode(',', $ids);
        mysqli_query($conn, "UPDATE products SET stock = GREATEST(0, stock + $bulkDelta) WHERE id IN ($idList)");
        $message = $bulkDelta >= 0 ? "Stock increased for selected products." : "Stock decreased for selected products.";
        $msg_type = "success";
    } else {
        $message = "Select at least one product for bulk update.";
        $msg_type = "error";
    }
}

// Fetch current products for display (with optional name search)
$searchTerm = trim($_GET['q'] ?? '');
$product_list = null;
if ($searchTerm !== '') {
    $like = '%' . $searchTerm . '%';
    $stmt = mysqli_prepare($conn, "SELECT * FROM products WHERE name LIKE ? ORDER BY id DESC");
    mysqli_stmt_bind_param($stmt, 's', $like);
    mysqli_stmt_execute($stmt);
    $product_list = mysqli_stmt_get_result($stmt);
} else {
    $product_list = mysqli_query($conn, "SELECT * FROM products ORDER BY id DESC");
}
$product_count = $product_list ? mysqli_num_rows($product_list) : 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Product | Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        @media (max-width: 640px) {
            body { overflow-x: hidden; }
            .responsive-table, .responsive-table tbody, .responsive-table tr { display: block; width: 100%; }
            .responsive-table thead { display: none; }
            .responsive-table tr { margin-bottom: 12px; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 10px; box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
            .responsive-table td { display: grid; grid-template-columns: 110px 1fr; align-items: center; gap: 8px; padding: 8px 6px; word-break: break-word; }
            .responsive-table td::before { content: attr(data-label); font-size: 11px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.08em; }
            .responsive-table img { max-width: 72px; height: auto; border-radius: 10px; }
            .responsive-actions { display: flex; flex-direction: column; gap: 6px; align-items: flex-start; }
            .bulk-controls { flex-direction: column; align-items: stretch; gap: 8px; }
        }
    </style>
</head>
<body class="bg-slate-50 min-h-screen p-6">
    <div class="max-w-2xl mx-auto">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-black text-slate-900">Add New Product</h1>
            <div class="flex gap-3">
                <a href="dashboard.php" class="text-sm font-bold text-slate-500 hover:text-blue-600 bg-white px-6 py-3 rounded-2xl shadow-md">← Dashboard</a>
                <a href="login.php?logout=1" class="text-sm font-bold text-slate-500 hover:text-red-600 bg-white px-6 py-3 rounded-2xl shadow-md">Logout</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="p-4 rounded-2xl mb-6 font-bold <?php echo $msg_type === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <div class="bg-white p-8 rounded-[2rem] shadow-xl shadow-slate-200/50">
            <form action="products.php" method="POST" enctype="multipart/form-data" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <div>
                    <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2">Product Name (Khmer)</label>
                    <input type="text" name="name" required class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500 font-bold text-slate-900" placeholder="e.g. ទឹក">
                </div>
                <div>
                    <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2">English Name <span class="text-slate-300 font-normal normal-case">(for PDF invoice)</span></label>
                    <input type="text" name="name_en" class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500 font-bold text-slate-900" placeholder="e.g. Water 500ml">
                </div>

                <div class="grid grid-cols-2 gap-6">
                    <div>
                        <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2">Price ($)</label>
                        <input type="number" step="0.01" name="price" required class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500 font-bold text-slate-900" placeholder="0.00">
                    </div>
                    <div>
                        <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2">Stock</label>
                        <input type="number" name="stock" min="0" value="0" required class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500 font-bold text-slate-900" placeholder="0">
                    </div>
                    <div>
                        <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2">Rating (0-5)</label>
                        <input type="number" step="0.1" max="5" name="rating" value="5.0" class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500 font-bold text-slate-900">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2">Category</label>
                    <select name="category" class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500 font-bold text-slate-900">
                        <option value="Drinks">Drinks</option>
                        <option value="Water">Water</option>
                        <option value="Achoholic">Achoholic</option>
                        <option value="Snacks">Snacks</option>
                        
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2">Product Image</label>
                    <div class="relative">
                        <input type="file" name="image" id="image" required accept="image/jpeg,image/png,image/gif,image/webp" class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500 font-bold text-slate-900 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-bold file:bg-blue-600 file:text-white hover:file:bg-blue-700">
                        <p class="text-[10px] text-slate-400 mt-2">Supported formats: JPG, PNG, GIF, WEBP (Max 5MB)</p>
                    </div>
                </div>

                <button type="submit" class="w-full bg-slate-900 text-white py-4 rounded-2xl font-black text-lg shadow-lg shadow-slate-900/20 hover:bg-slate-800 transition-all active:scale-[0.98]">
                    Add Product
                </button>
            </form>
        </div>

        <!-- Existing Products -->
        <div class="mt-12">
            <h2 class="text-2xl font-black mb-6">Current Products</h2>
            <form method="GET" class="mb-4 flex items-center gap-3 bg-white p-4 rounded-2xl border border-slate-100 shadow-sm">
                <input type="text" name="q" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Search by product name" class="flex-1 p-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold focus:outline-none focus:ring-2 focus:ring-blue-500" aria-label="Search products">
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm font-black rounded-xl hover:bg-blue-700 transition-colors">Search</button>
                <?php if ($searchTerm !== ''): ?>
                    <a href="products.php" class="text-xs font-bold text-slate-500 hover:text-blue-600">Clear</a>
                <?php endif; ?>
            </form>

            <?php if ($product_count > 0): ?>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between bg-white p-4 rounded-2xl border border-slate-100 shadow-sm bulk-controls">
                        <div class="flex items-center gap-3">
                            <input type="number" name="bulk_stock_value" step="1" placeholder="+/- amount" class="w-32 p-2 bg-slate-50 border border-slate-200 rounded-lg text-sm font-bold" aria-label="Bulk add or subtract amount">
                            <button type="submit" name="bulk_update" value="1" class="text-xs font-black bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors">Apply to selected</button>
                        </div>
                        <p class="text-xs text-slate-500">Tip: positive numbers add stock, negative numbers subtract. Clamp stays at zero.</p>
                    </div>

                    <div>
                        <table class="w-full text-sm border-collapse responsive-table">
                            <thead>
                                <tr class="border-b border-slate-200">
                                    <th class="py-2 px-3 text-left"><input type="checkbox" id="select-all" class="w-4 h-4"></th>
                                    <th class="py-2 px-3 text-left">Image</th>
                                    <th class="py-2 px-2 text-left">Name</th>
                                    <th class="py-2 px-3 text-left">Price</th>
                                    <th class="py-2 px-3 text-left">Category</th>
                                    <th class="py-2 px-3 text-left">Stock</th>
                                    <th class="py-2 px-3 text-left whitespace-nowrap w-24">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($prod = mysqli_fetch_assoc($product_list)): ?>
                                    <tr class="border-b border-slate-50 hover:bg-slate-50">
                                        <td class="py-2 px-3 align-top" data-label="Select"><input type="checkbox" name="bulk_ids[]" value="<?php echo $prod['id']; ?>" class="row-check w-4 h-4 mt-1"></td>
                                        <td class="py-2 px-3 w-20" data-label="Image"><img src="../frontend/<?php echo htmlspecialchars(preg_replace('#^img/products/#', 'assets/img/products/', $prod['image'])); ?>" class="w-16 h-16 object-cover rounded-lg"></td>
                                        <td class="py-2 px-2 align-top whitespace-normal break-words text-sm font-semibold text-slate-800" data-label="Name">
                                            <?php echo htmlspecialchars($prod['name']); ?>
                                            <?php if (!empty($prod['name_en'])): ?>
                                            <div class="text-[11px] text-slate-400 font-normal"><?php echo htmlspecialchars($prod['name_en']); ?></div>
                                            <?php else: ?>
                                            <form method="POST" style="display:contents">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="update_name_en_id" value="<?php echo $prod['id']; ?>">
                                                <div class="flex items-center gap-1 mt-1">
                                                    <input type="text" name="name_en_value" placeholder="Add English name" class="w-32 p-1 text-xs bg-slate-50 border border-slate-200 rounded-lg">
                                                    <button type="submit" class="text-xs font-bold text-green-600 hover:bg-green-50 px-2 py-1 rounded">Save</button>
                                                </div>
                                            </form>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-2 px-3 align-top" data-label="Price">$<?php echo number_format($prod['price'],2); ?></td>
                                        <td class="py-2 px-3 align-top" data-label="Category">&zwj;<?php echo htmlspecialchars($prod['category']); ?></td>
                                        <td class="py-2 px-3 align-top" data-label="Stock">
                                            <div class="flex flex-col gap-1">
                                                <div class="flex items-center gap-2">
                                                    <input type="number" step="0.01" name="row_price[<?php echo $prod['id']; ?>]" min="0" value="<?php echo htmlspecialchars($prod['price']); ?>" class="w-28 p-2 bg-slate-50 border border-slate-200 rounded-lg text-sm font-bold" aria-label="New price for <?php echo htmlspecialchars($prod['name']); ?>">
                                                    <button type="submit" name="update_price_id" value="<?php echo $prod['id']; ?>" class="text-xs font-bold text-amber-600 hover:bg-amber-50 px-2 py-1 rounded">Update Price</button>
                                                </div>
                                                <div class="text-[11px] text-slate-500 font-semibold">Current: <?php echo intval($prod['stock']); ?></div>
                                                <div class="flex items-center gap-2">
                                                    <input type="number" name="row_add[<?php echo $prod['id']; ?>]" step="1" value="0" class="w-28 p-2 bg-slate-50 border border-slate-200 rounded-lg text-sm font-bold add-input" data-current="<?php echo intval($prod['stock']); ?>" aria-label="Add or subtract for <?php echo htmlspecialchars($prod['name']); ?>" placeholder="+/- amount">
                                                    <button type="submit" name="update_stock_id" value="<?php echo $prod['id']; ?>" class="text-xs font-bold text-blue-600 hover:bg-blue-50 px-2 py-1 rounded">Update Stock</button>
                                                </div>
                                                <div class="text-[11px] text-slate-600" data-total-label>New total: <?php echo intval($prod['stock']); ?></div>
                                            </div>
                                        </td>
                                        <td class="py-2 px-3 align-top whitespace-nowrap w-24 responsive-actions" data-label="Action">
                                            <button type="submit" name="delete_id" value="<?php echo $prod['id']; ?>" class="inline-flex items-center justify-center text-xs font-bold text-red-600 hover:bg-red-50 px-3 py-1 rounded border border-red-100" onclick="return confirm('Remove this product?');">Delete</button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            <?php else: ?>
                <div class="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm">
                    <p class="text-slate-500 font-bold mb-2">No products found<?php echo $searchTerm !== '' ? ' for "' . htmlspecialchars($searchTerm) . '"' : ''; ?>.</p>
                    <?php if ($searchTerm !== ''): ?>
                        <a href="products.php" class="inline-flex items-center text-sm font-bold text-blue-600 hover:text-blue-700">Clear search and show all</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const selectAll = document.getElementById('select-all');
        const rowChecks = document.querySelectorAll('.row-check');
        if (selectAll && rowChecks.length) {
            selectAll.addEventListener('change', () => {
                rowChecks.forEach(cb => cb.checked = selectAll.checked);
            });
            rowChecks.forEach(cb => cb.addEventListener('change', () => {
                const allChecked = Array.from(rowChecks).every(c => c.checked);
                selectAll.checked = allChecked;
                if (!allChecked) {
                    selectAll.indeterminate = Array.from(rowChecks).some(c => c.checked);
                }
            }));
        }

        // Live update of "New total" labels for per-row adjustments (add/subtract)
        document.querySelectorAll('.add-input').forEach(input => {
            const label = input.closest('td').querySelector('[data-total-label]');
            const current = parseInt(input.dataset.current || '0', 10);
            const updateLabel = () => {
                const addVal = parseInt(input.value || '0', 10) || 0;
                const total = Math.max(0, current + addVal);
                if (label) {
                    label.textContent = `New total: ${total}`;
                }
            };
            input.addEventListener('input', updateLabel);
            updateLabel();
        });
    </script>
</body>
</html>