<?php
/**
 * Database Setup Script - Creates necessary tables and generates initial access codes
 * Run this once at: http://localhost/Store/scripts/setup.php
 * IMPORTANT: Delete or restrict this file after running it once.
 */

// ── One-time-run guard ───────────────────────────────────────────────────────
// Allow re-running only via CLI or if the special query param is set.
// Remove this file (or the ?setup_run=1 param) after initial setup.
$allowRun = (PHP_SAPI === 'cli') || (($_GET['setup_run'] ?? '') === '1');
if (!$allowRun) {
    http_response_code(403);
    die('Setup has already been completed. Delete scripts/setup.php from the server.');
}
// ────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/../config/database.php';

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Create access_codes table
$sql_access = "CREATE TABLE IF NOT EXISTS access_codes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME,
    is_active BOOLEAN DEFAULT TRUE,
    used_count INT DEFAULT 0,
    last_used DATETIME,
    created_by VARCHAR(100)
)";

if (mysqli_query($conn, $sql_access)) {
    echo "✓ access_codes table created/verified<br>";
} else {
    echo "✗ Error creating access_codes table: " . mysqli_error($conn) . "<br>";
}

// Create products table if it doesn't exist
$sql_products = "CREATE TABLE IF NOT EXISTS products (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    category VARCHAR(100),
    rating DECIMAL(3, 1) DEFAULT 5.0,
    image VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if (mysqli_query($conn, $sql_products)) {
    echo "✓ products table created/verified<br>";
} else {
    echo "✗ Error creating products table: " . mysqli_error($conn) . "<br>";
}

// Generate 5 initial access codes if none exist
$check = mysqli_query($conn, "SELECT COUNT(*) as count FROM access_codes WHERE is_active = TRUE");
$result = mysqli_fetch_assoc($check);

if ($result['count'] == 0) {
    echo "<br>Generating initial access codes...<br>";
    for ($i = 1; $i <= 5; $i++) {
        // Generate a random 8-character alphanumeric code
        $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        
        // Expires in 90 days
        $expires = date('Y-m-d H:i:s', strtotime('+90 days'));
        
        $sql_insert = "INSERT INTO access_codes (code, expires_at, created_by) VALUES ('$code', '$expires', 'system')";
        
        if (mysqli_query($conn, $sql_insert)) {
            echo "✓ Access Code #$i: <strong style='font-family: monospace; background: #f0f0f0; padding: 4px 8px;'>$code</strong> (Expires: $expires)<br>";
        }
    }
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup | Borey.store</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 40px; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; }
        h1 { color: #333; }
        p { line-height: 1.6; color: #666; }
        .success { background: #e8f5e9; border-left: 4px solid #4caf50; padding: 15px; margin: 20px 0; }
        .warning { background: #fff3e0; border-left: 4px solid #ff9800; padding: 15px; margin: 20px 0; }
        code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Database Setup Complete</h1>
        <div class="success">
            <strong>Setup Status:</strong> Database tables initialized successfully!
        </div>
        <div class="warning">
            <strong>⚠️ Important:</strong> 
            <ul>
                <li>Save your access codes above securely</li>
                <li>Each access code can be used multiple times within its expiration date</li>
                <li>Delete this file (setup.php) after setup for security</li>
                <li><a href="admin/login.php">→ Go to Admin Login</a></li>
            </ul>
        </div>
        <p><strong>Next Steps:</strong></p>
        <ol>
            <li>Copy one of the access codes above</li>
            <li>Go to <code>/admin/login.php</code> and enter the code</li>
            <li>Add products with image uploads</li>
        </ol>
    </div>
</body>
</html>
