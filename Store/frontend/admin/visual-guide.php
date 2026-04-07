<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}
// Visual system diagram and getting started guide
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visual System Guide - Borey.store Admin Access Codes</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 40px 20px;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        h1 {
            text-align: center;
            color: white;
            margin-bottom: 50px;
            font-size: 3em;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        
        .section {
            background: white;
            border-radius: 15px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        
        .section h2 {
            font-size: 2em;
            color: #667eea;
            margin-bottom: 30px;
            border-bottom: 3px solid #667eea;
            padding-bottom: 15px;
        }
        
        .flowchart {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }
        
        .flow-item {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 10px;
            text-align: center;
            font-weight: bold;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            transition: transform 0.3s ease;
        }
        
        .flow-item:hover {
            transform: translateY(-5px);
        }
        
        .flow-number {
            display: inline-block;
            background: white;
            color: #667eea;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            line-height: 40px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .code-display {
            background: #f5f5f5;
            border: 2px solid #667eea;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            font-family: 'Courier New', monospace;
            font-size: 2em;
            font-weight: bold;
            color: #333;
            letter-spacing: 5px;
            margin: 20px 0;
        }
        
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin: 30px 0;
        }
        
        @media (max-width: 768px) {
            .grid-2 { grid-template-columns: 1fr; }
            h1 { font-size: 2em; }
            .section { padding: 20px; }
            .section h2 { font-size: 1.5em; }
        }
        
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }
        
        .feature-card {
            background: linear-gradient(135deg, #e0f7ff 0%, #f0e6ff 100%);
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid #667eea;
        }
        
        .feature-card h3 {
            color: #667eea;
            margin-bottom: 10px;
        }
        
        .feature-card p {
            color: #666;
            font-size: 0.9em;
            line-height: 1.5;
        }
        
        .timeline {
            position: relative;
            padding: 20px 0;
        }
        
        .timeline-item {
            display: flex;
            margin-bottom: 30px;
            position: relative;
        }
        
        .timeline-item:not(:last-child):before {
            content: '';
            position: absolute;
            left: 35px;
            top: 60px;
            width: 2px;
            height: 30px;
            background: #667eea;
        }
        
        .timeline-dot {
            width: 70px;
            height: 70px;
            background: #667eea;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.5em;
            flex-shrink: 0;
        }
        
        .timeline-content {
            margin-left: 20px;
            flex-grow: 1;
        }
        
        .timeline-content h3 {
            color: #333;
            margin-bottom: 5px;
        }
        
        .timeline-content p {
            color: #666;
            line-height: 1.6;
        }
        
        .url-box {
            background: #f0f0f0;
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 8px;
            font-family: monospace;
            color: #333;
            margin: 15px 0;
            word-break: break-all;
        }
        
        .button-group {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 15px 30px;
            border: none;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
            font-size: 1em;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }
        
        .btn-secondary:hover {
            background: #667eea;
            color: white;
        }
        
        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #ff9800;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        
        .success-box {
            background: #e8f5e9;
            border-left: 4px solid #4caf50;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .info-box {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        
        .info-box strong {
            color: #667eea;
            display: block;
            font-size: 1.2em;
            margin-bottom: 5px;
        }
        
        footer {
            text-align: center;
            color: white;
            margin-top: 50px;
            padding-top: 30px;
            border-top: 1px solid rgba(255,255,255,0.2);
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 Borey.store Admin System</h1>
        
        <!-- Getting Started -->
        <div class="section">
            <h2>⚡ Quick Start (3 Simple Steps)</h2>
            
            <div class="timeline">
                <div class="timeline-item">
                    <div class="timeline-dot">1️⃣</div>
                    <div class="timeline-content">
                        <h3>Run Setup Script</h3>
                        <p>Visit the setup page to initialize your database and generate access codes.</p>
                        <div class="url-box">http://localhost/Store/setup.php</div>
                    </div>
                </div>
                
                <div class="timeline-item">
                    <div class="timeline-dot">2️⃣</div>
                    <div class="timeline-content">
                        <h3>Get Your Code</h3>
                        <p>Save one of the 8-character access codes shown on the setup page.</p>
                        <div class="code-display">A3F7B2K9</div>
                    </div>
                </div>
                
                <div class="timeline-item">
                    <div class="timeline-dot">3️⃣</div>
                    <div class="timeline-content">
                        <h3>Login & Start Adding Products</h3>
                        <p>Go to admin login, enter your code, and upload products with images!</p>
                        <div class="url-box">http://localhost/Store/admin/login.php</div>
                    </div>
                </div>
            </div>
            
            <div class="button-group">
                <a href="../backend/scripts/setup.php" class="btn btn-primary">▶ Start Setup</a>
                <a href="../admin/login.php" class="btn btn-secondary">▶ Go to Login</a>
                <a href="../admin/reference.php" class="btn btn-secondary">▶ View Reference</a>
            </div>
        </div>

        <!-- System Workflow -->
        <div class="section">
            <h2>🔄 System Workflow</h2>
            
            <div class="flowchart">
                <div class="flow-item">
                    <div class="flow-number">1</div>
                    <div>📋 Setup Database</div>
                </div>
                <div class="flow-item">
                    <div class="flow-number">→</div>
                </div>
                <div class="flow-item">
                    <div class="flow-number">2</div>
                    <div>🔑 Generate Codes</div>
                </div>
                <div class="flow-item">
                    <div class="flow-number">→</div>
                </div>
                <div class="flow-item">
                    <div class="flow-number">3</div>
                    <div>👤 Login</div>
                </div>
                <div class="flow-item">
                    <div class="flow-number">→</div>
                </div>
                <div class="flow-item">
                    <div class="flow-number">4</div>
                    <div>📦 Add Products</div>
                </div>
                <div class="flow-item">
                    <div class="flow-number">→</div>
                </div>
                <div class="flow-item">
                    <div class="flow-number">5</div>
                    <div>🌐 Live on Store</div>
                </div>
            </div>
        </div>

        <!-- Access Code Details -->
        <div class="section">
            <h2>🔐 Access Code Details</h2>
            
            <div class="grid-2">
                <div>
                    <h3 style="color: #667eea; margin-bottom: 15px;">Code Format</h3>
                    <div class="code-display">A3F7B2K9</div>
                    <div class="info-grid">
                        <div class="info-box">
                            <strong>Length</strong><br>8 characters
                        </div>
                        <div class="info-box">
                            <strong>Type</strong><br>Alphanumeric
                        </div>
                        <div class="info-box">
                            <strong>Case</strong><br>Uppercase
                        </div>
                        <div class="info-box">
                            <strong>Expiry</strong><br>90 Days
                        </div>
                    </div>
                </div>
                
                <div>
                    <h3 style="color: #667eea; margin-bottom: 15px;">Key Features</h3>
                    <div class="feature-grid" style="grid-template-columns: 1fr;">
                        <div class="feature-card">
                            <h4>✅ Reusable</h4>
                            <p>Multiple people can share one code</p>
                        </div>
                        <div class="feature-card">
                            <h4>✅ Trackable</h4>
                            <p>View usage count and last used date</p>
                        </div>
                        <div class="feature-card">
                            <h4>✅ Revocable</h4>
                            <p>Instantly disable access from dashboard</p>
                        </div>
                        <div class="feature-card">
                            <h4>✅ Secure</h4>
                            <p>Case-sensitive, database-stored</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Image Upload Info -->
        <div class="section">
            <h2>🖼️ Product Image Upload</h2>
            
            <div class="grid-2">
                <div>
                    <h3 style="color: #667eea; margin-bottom: 15px;">Supported Formats</h3>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <div style="background: #e8f5e9; padding: 12px; border-radius: 6px; border-left: 4px solid #4caf50;">
                            ✅ JPG / JPEG
                        </div>
                        <div style="background: #e8f5e9; padding: 12px; border-radius: 6px; border-left: 4px solid #4caf50;">
                            ✅ PNG
                        </div>
                        <div style="background: #e8f5e9; padding: 12px; border-radius: 6px; border-left: 4px solid #4caf50;">
                            ✅ GIF
                        </div>
                        <div style="background: #e8f5e9; padding: 12px; border-radius: 6px; border-left: 4px solid #4caf50;">
                            ✅ WEBP (Modern)
                        </div>
                    </div>
                </div>
                
                <div>
                    <h3 style="color: #667eea; margin-bottom: 15px;">Size & Storage</h3>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <div style="background: #f3e5f5; padding: 12px; border-radius: 6px; border-left: 4px solid #9c27b0;">
                            📏 Max Size: <strong>5 MB</strong>
                        </div>
                        <div style="background: #f3e5f5; padding: 12px; border-radius: 6px; border-left: 4px solid #9c27b0;">
                            🗂️ Location: <strong>/assets/img/products/</strong>
                        </div>
                        <div style="background: #f3e5f5; padding: 12px; border-radius: 6px; border-left: 4px solid #9c27b0;">
                            📝 Name: <strong>Auto-generated</strong>
                        </div>
                        <div style="background: #f3e5f5; padding: 12px; border-radius: 6px; border-left: 4px solid #9c27b0;">
                            🔗 Access: <strong>Public URLs</strong>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="warning-box">
                <strong>⚠️ Important:</strong> Images must be smaller than 5MB. If your image is too large, use an online image compressor or resize it before uploading.
            </div>
        </div>

        <!-- Key Features -->
        <div class="section">
            <h2>✨ Key Features</h2>
            
            <div class="feature-grid">
                <div class="feature-card">
                    <h3>🔐 No Passwords</h3>
                    <p>Simple 8-character codes instead of complex passwords</p>
                </div>
                <div class="feature-card">
                    <h3>👥 Team Sharing</h3>
                    <p>Share one code with multiple team members</p>
                </div>
                <div class="feature-card">
                    <h3>⏰ Auto Expiry</h3>
                    <p>Codes automatically expire after 90 days</p>
                </div>
                <div class="feature-card">
                    <h3>📊 Usage Stats</h3>
                    <p>Track who uses codes and when</p>
                </div>
                <div class="feature-card">
                    <h3>🖼️ Image Upload</h3>
                    <p>Upload product images directly from panel</p>
                </div>
                <div class="feature-card">
                    <h3>🚫 Instant Revoke</h3>
                    <p>Disable any code immediately</p>
                </div>
            </div>
        </div>

        <!-- URLs Reference -->
        <div class="section">
            <h2>🔗 Important URLs</h2>
            
            <div style="display: grid; gap: 15px;">
                <div style="border-left: 4px solid #667eea; padding: 15px; background: #f5f5f5; border-radius: 6px;">
                    <strong>Initial Setup</strong>
                    <div class="url-box">http://localhost/Store/setup.php</div>
                </div>
                
                <div style="border-left: 4px solid #667eea; padding: 15px; background: #f5f5f5; border-radius: 6px;">
                    <strong>Admin Login</strong>
                    <div class="url-box">http://localhost/Store/admin/login.php</div>
                </div>
                
                <div style="border-left: 4px solid #667eea; padding: 15px; background: #f5f5f5; border-radius: 6px;">
                    <strong>Dashboard (After Login)</strong>
                    <div class="url-box">http://localhost/Store/admin/dashboard.php</div>
                </div>
                
                <div style="border-left: 4px solid #667eea; padding: 15px; background: #f5f5f5; border-radius: 6px;">
                    <strong>Add Products</strong>
                    <div class="url-box">http://localhost/Store/admin/products.php</div>
                </div>
                
                <div style="border-left: 4px solid #667eea; padding: 15px; background: #f5f5f5; border-radius: 6px;">
                    <strong>Quick Reference Guide</strong>
                    <div class="url-box">http://localhost/Store/admin/reference.php</div>
                </div>
            </div>
        </div>

        <!-- Next Steps -->
        <div class="section">
            <h2>🚀 Next Steps</h2>
            
            <div class="success-box">
                <h3 style="color: #4caf50; margin-bottom: 15px;">✅ You're All Set!</h3>
                <p style="margin-bottom: 20px;">Your admin system with access codes is ready to use. Follow these steps to get started:</p>
                
                <ol style="line-height: 2; margin-left: 20px;">
                    <li><strong>Run setup.php</strong> - Create database tables (one time only)</li>
                    <li><strong>Copy your access code</strong> - Save the 8-character code provided</li>
                    <li><strong>Login to admin</strong> - Use /admin/login.php with your code</li>
                    <li><strong>Go to dashboard</strong> - View stats and generate new codes for team</li>
                    <li><strong>Add first product</strong> - Upload a product with an image</li>
                    <li><strong>View on store</strong> - Check your product on the customer homepage</li>
                </ol>
            </div>
            
            <div class="button-group">
                <a href="../backend/scripts/setup.php" class="btn btn-primary" style="flex: 1; text-align: center;">
                    ▶ START SETUP
                </a>
                <a href="../admin/login.php" class="btn btn-primary" style="flex: 1; text-align: center;">
                    ▶ GO TO LOGIN
                </a>
            </div>
        </div>

        <!-- Support -->
        <div class="section">
            <h2>❓ Need Help?</h2>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div style="border: 2px solid #667eea; padding: 20px; border-radius: 8px;">
                    <h3 style="color: #667eea; margin-bottom: 10px;">📚 Documentation</h3>
                    <p style="color: #666; line-height: 1.6;">
                        Check out <code>ADMIN_SETUP.md</code> for complete documentation and advanced features.
                    </p>
                </div>
                
                <div style="border: 2px solid #667eea; padding: 20px; border-radius: 8px;">
                    <h3 style="color: #667eea; margin-bottom: 10px;">💬 Quick Reference</h3>
                    <p style="color: #666; line-height: 1.6;">
                        Visit <code>/admin/reference.php</code> for a comprehensive visual guide.
                    </p>
                </div>
            </div>
        </div>

        <footer>
            <p>🔐 Borey.store Admin Access Code System v1.0</p>
            <p style="margin-top: 10px; font-size: 0.9em;">Ready for production use | Built with security in mind</p>
        </footer>
    </div>
</body>
</html>
