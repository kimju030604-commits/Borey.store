<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Quick reference for access codes and their usage
// This file can be used for debugging or creating a printable guide

$system_info = [
    'Setup URL' => 'http://localhost/Store/setup.php',
    'Admin Login' => 'http://localhost/Store/admin/login.php',
    'Dashboard' => 'http://localhost/Store/admin/dashboard.php',
    'Product Upload' => 'http://localhost/Store/admin/products.php',
    'Customer Store' => 'http://localhost/Store/index.php'
];

$access_code_rules = [
    'Format' => '8 alphanumeric characters (e.g., A3F7B2K9)',
    'Validity' => '90 days from generation',
    'Reusable' => 'Yes - multiple users can share one code',
    'Revocable' => 'Yes - instant access revocation',
    'Tracked' => 'Yes - usage count and last used date'
];

$image_requirements = [
    'Format' => 'JPG, PNG, GIF, WEBP',
    'Max Size' => '5MB',
    'Storage' => 'assets/img/products/ folder',
    'Naming' => 'Automatic unique names (product_xxxxx.ext)'
];

$workflow = [
    '1. Initial Setup' => 'Visit setup.php to create database tables',
    '2. Generate Codes' => 'Use dashboard to create access codes for team',
    '3. Share Codes' => 'Securely share 8-character codes with admins',
    '4. Login' => 'Admin enters code at login.php',
    '5. Add Products' => 'Upload products with images from dashboard',
    '6. Live Store' => 'Products immediately visible to customers'
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Code System - Quick Reference</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            padding: 40px 20px;
            line-height: 1.6;
            color: #333;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { 
            text-align: center; 
            margin-bottom: 40px; 
            color: #000;
            font-size: 2.5em;
        }
        
        .card {
            background: white;
            padding: 30px;
            margin-bottom: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .card h2 {
            font-size: 1.5em;
            margin-bottom: 20px;
            color: #0066cc;
            border-bottom: 3px solid #0066cc;
            padding-bottom: 10px;
        }
        
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }
        
        @media (max-width: 768px) {
            .grid-2 { grid-template-columns: 1fr; }
            h1 { font-size: 1.8em; }
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }
        
        .info-row:last-child { border-bottom: none; }
        .label { font-weight: bold; color: #0066cc; }
        .value { color: #666; }
        
        ul { list-style: none; margin-left: 0; }
        li { 
            padding: 10px 0;
            padding-left: 30px;
            position: relative;
        }
        li:before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #00aa00;
            font-weight: bold;
        }
        
        .url-box {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 8px;
            font-family: monospace;
            margin: 10px 0;
            color: #333;
            border-left: 4px solid #0066cc;
        }
        
        .warning {
            background: #fff3cd;
            border-left: 4px solid #ff9800;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        
        .warning strong { color: #ff6600; }
        
        .steps {
            counter-reset: step-counter;
            list-style: none;
            padding: 0;
        }
        
        .steps li {
            counter-increment: step-counter;
            padding: 15px;
            margin: 10px 0;
            background: #f0f5ff;
            border-radius: 8px;
            padding-left: 50px;
            position: relative;
        }
        
        .steps li:before {
            content: counter(step-counter);
            position: absolute;
            left: 15px;
            top: 15px;
            background: #0066cc;
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
        
        .button-group {
            display: flex;
            gap: 10px;
            margin: 20px 0;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: #0066cc;
            color: white;
        }
        
        .btn-primary:hover {
            background: #0052a3;
            box-shadow: 0 4px 12px rgba(0,102,204,0.3);
        }
        
        .btn-secondary {
            background: #666;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #444;
        }
        
        code {
            background: #f5f5f5;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: monospace;
            color: #d63384;
        }
        
        .features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        
        .feature-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        
        .feature-box h3 { margin-bottom: 10px; }
        
        footer {
            text-align: center;
            margin-top: 60px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            color: #999;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 Access Code System - Quick Reference</h1>
        
        <!-- Key URLs -->
        <div class="card">
            <h2>🔗 Important URLs</h2>
            <div class="info-row">
                <span class="label">Initial Setup:</span>
                <div class="url-box"><?php echo $system_info['Setup URL']; ?></div>
            </div>
            <div class="info-row">
                <span class="label">Admin Login:</span>
                <div class="url-box"><?php echo $system_info['Admin Login']; ?></div>
            </div>
            <div class="info-row">
                <span class="label">Dashboard:</span>
                <div class="url-box"><?php echo $system_info['Dashboard']; ?></div>
            </div>
            <div class="info-row">
                <span class="label">Add Products:</span>
                <div class="url-box"><?php echo $system_info['Product Upload']; ?></div>
            </div>
            <div class="info-row">
                <span class="label">Customer Store:</span>
                <div class="url-box"><?php echo $system_info['Customer Store']; ?></div>
            </div>
        </div>

        <!-- Access Code Rules -->
        <div class="grid-2">
            <div class="card">
                <h2>🔐 Access Code Rules</h2>
                <?php foreach ($access_code_rules as $key => $value): ?>
                    <div class="info-row">
                        <span class="label"><?php echo $key; ?>:</span>
                        <span class="value"><?php echo $value; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card">
                <h2>🖼️ Image Requirements</h2>
                <?php foreach ($image_requirements as $key => $value): ?>
                    <div class="info-row">
                        <span class="label"><?php echo $key; ?>:</span>
                        <span class="value"><?php echo $value; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Workflow -->
        <div class="card">
            <h2>📋 Complete Workflow</h2>
            <ul class="steps">
                <?php foreach ($workflow as $step => $description): ?>
                    <li><strong><?php echo $step; ?></strong><br><?php echo $description; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Features -->
        <div class="card">
            <h2>✨ Key Features</h2>
            <div class="features">
                <div class="feature-box">
                    <h3>🎯 No Usernames</h3>
                    <p>Simple 8-character codes instead of complex usernames</p>
                </div>
                <div class="feature-box">
                    <h3>👥 Multiple Users</h3>
                    <p>Share codes with team members securely</p>
                </div>
                <div class="feature-box">
                    <h3>⏰ Auto Expiry</h3>
                    <p>Codes automatically expire after 90 days</p>
                </div>
                <div class="feature-box">
                    <h3>📊 Tracked Usage</h3>
                    <p>See when and how often codes are used</p>
                </div>
                <div class="feature-box">
                    <h3>🖼️ Image Upload</h3>
                    <p>Upload product images directly from admin panel</p>
                </div>
                <div class="feature-box">
                    <h3>🚫 Instant Revoke</h3>
                    <p>Disable access immediately from dashboard</p>
                </div>
            </div>
        </div>

        <!-- Quick Start -->
        <div class="card">
            <h2>🚀 Quick Start (First Time Only)</h2>
            <div class="warning">
                <strong>⚠️ IMPORTANT:</strong> Run setup.php only once to create database tables!
            </div>
            <ol class="steps">
                <li>Visit <code>http://localhost/Store/setup.php</code></li>
                <li>See generated access codes (save them!)</li>
                <li>Go to <code>/admin/login.php</code></li>
                <li>Enter any access code</li>
                <li>You're now in the admin dashboard!</li>
                <li>Click "Add New Product" to upload items with images</li>
            </ol>
            <div class="button-group">
                <a href="setup.php" class="btn btn-primary">▶ Run Setup.php</a>
                <a href="admin/login.php" class="btn btn-secondary">▶ Go to Login</a>
            </div>
        </div>

        <!-- Tips -->
        <div class="card">
            <h2>💡 Pro Tips</h2>
            <ul>
                <li><strong>Generate codes for each team member.</strong> Makes it easier to track who's accessing what.</li>
                <li><strong>Revoke old codes regularly.</strong> Keep your system secure by retiring unused codes.</li>
                <li><strong>Use descriptive names.</strong> When generating codes, note who they're for in your records.</li>
                <li><strong>Backup images regularly.</strong> The <code>assets/img/products/</code> folder contains all uploaded images.</li>
                <li><strong>Monitor usage statistics.</strong> Check the dashboard to see which codes are actively used.</li>
                <li><strong>Keep codes confidential.</strong> Only share codes with trusted team members.</li>
            </ul>
        </div>

        <!-- Troubleshooting -->
        <div class="card">
            <h2>🆘 Common Issues</h2>
            <div style="display: grid; gap: 15px;">
                <div style="border-left: 4px solid #ff6600; padding: 15px; background: #fff5e6; border-radius: 4px;">
                    <strong>❌ "Invalid or expired access code"</strong>
                    <ul>
                        <li>Check spelling (codes are case-sensitive)</li>
                        <li>Verify code hasn't expired (90 days old)</li>
                        <li>Make sure code is still active (not revoked)</li>
                    </ul>
                </div>
                
                <div style="border-left: 4px solid #ff6600; padding: 15px; background: #fff5e6; border-radius: 4px;">
                    <strong>❌ "Image file too large"</strong>
                    <ul>
                        <li>Reduce image size to under 5MB</li>
                        <li>Use online image compressors</li>
                    </ul>
                </div>
                
                <div style="border-left: 4px solid #ff6600; padding: 15px; background: #fff5e6; border-radius: 4px;">
                    <strong>❌ "Invalid image format"</strong>
                    <ul>
                        <li>Only JPG, PNG, GIF, WEBP supported</li>
                        <li>Convert your image using an online tool</li>
                    </ul>
                </div>
            </div>
        </div>

        <footer>
            <p>Borey.store Admin Access Code System v1.0 | Documentation updated February 27, 2026</p>
            <p>For support, refer to ADMIN_SETUP.md in your Store folder</p>
        </footer>
    </div>
</body>
</html>
