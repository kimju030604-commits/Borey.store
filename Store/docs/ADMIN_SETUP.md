# 🔐 Admin Access Code System - Setup Guide

## Overview
This Borey.store admin system uses **secure 8-character access codes** instead of traditional username/password authentication. This makes it easy to grant temporary access to team members without managing individual usernames.

---

## 🚀 Quick Start (3 Steps)

### 1. **Run the Setup Script**
Visit this URL in your browser:
```
http://localhost/Store/setup.php
```
✅ This creates the database tables and generates 5 initial access codes.

### 2. **Copy Your Access Code**
Save one of the generated codes from the setup page. Example: `A3F7B2K9`

### 3. **Login to Admin Panel**
Go to: `http://localhost/Store/admin/login.php`
- Paste your access code
- Click "Unlock Admin Panel"
- You're in! 🎉

---

## 📋 System Features

### Access Codes
- **Format**: 8-character alphanumeric (e.g., `A3F7B2K9`)
- **Validity**: 90 days from creation
- **Reusable**: Multiple people can use the same code
- **Trackable**: System logs usage count and last used date

### Admin Dashboard
Once logged in, you can:
- ✅ **Generate new codes** for team members
- ✅ **View all active codes** with usage stats
- ✅ **Revoke codes** to disable access instantly
- ✅ **Add products** with image uploads

### Product Management
- Upload product images (JPG, PNG, GIF, WEBP)
- Max file size: 5MB per image
- Images stored locally in `img/products/` folder
- Automatic unique filenames to avoid conflicts

---

## 🛠️ Admin Dashboard Guide

### Location
```
http://localhost/Store/admin/dashboard.php
```

### Key Sections

#### 📊 Statistics
- **Total Products**: Number of products in store
- **Active Codes**: Codes currently valid
- **Total Access Codes**: All codes ever generated

#### 🔐 Access Code Management
| Column | Description |
|--------|-------------|
| **Code** | 8-character code |
| **Status** | Active or Inactive |
| **Uses** | Number of times code was used |
| **Expires** | Expiration date (90 days) |
| **Action** | Revoke to disable immediately |

#### ⚡ Quick Actions
- **Generate New Code**: Creates a new 90-day access code
- **Add New Product**: Go to product form
- **Back to Store**: Return to customer view

---

## 📦 Adding Products

### Steps
1. Login with access code
2. Go to Dashboard → **Add New Product**
3. Fill in details:
   - **Product Name**: e.g., "Smart Hub Pro"
   - **Price**: e.g., 145.00
   - **Rating**: 0-5 (default 5.0)
   - **Category**: Electronics, Living, Home Office, or Decor
   - **Image**: Upload a JPG, PNG, GIF, or WEBP file

4. Click **Add Product**
5. Product appears on store immediately

### Accepted Image Formats
- ✅ JPG/JPEG
- ✅ PNG
- ✅ GIF
- ✅ WEBP

### Image Size Limits
Maximum: **5MB per image**

---

## 🤝 Team Collaboration

### How to Grant Access to Team Members
1. Login to Dashboard
2. Click **Generate New Code**
3. A new 8-character code is created
4. Share the code securely with your team member
5. They can login at: `/Store/admin/login.php`

### How to Remove Access
1. Go to Dashboard
2. Find the code in the Access Codes table
3. Click **Revoke** button
4. Code is immediately disabled

---

## 🔒 Security Features

✅ **Session Management**
- Secure session regeneration on login
- Session timeout recommended (add to settings)

✅ **Code Security**
- 8-character codes = 2.8 trillion combinations
- Database encryption recommended for production
- Access codes stored in database (NOT hardcoded)

✅ **File Upload Validation**
- File type checking (MIME type)
- File size limits (5MB max)
- Unique filename generation
- Stored outside web root (recommended)

✅ **Database Security**
- SQL injection prevention (mysqli_real_escape_string)
- Prepared statements recommended for production
- Database credentials in separate file

---

## 📁 File Structure

```
Store/
├── setup.php                    # Run once to initialize database
├── index.php                    # Customer store view
├── admin/
│   ├── login.php               # Access code login
│   ├── dashboard.php           # Admin control panel
│   └── products.php            # Add products with image upload
├── connect/
│   └── database.php            # Database connection
└── img/
    └── products/               # Uploaded product images
```

---

## ⚙️ Database Schema

### access_codes Table
```sql
CREATE TABLE access_codes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME,
    is_active BOOLEAN DEFAULT TRUE,
    used_count INT DEFAULT 0,
    last_used DATETIME,
    created_by VARCHAR(100)
);
```

### products Table
```sql
CREATE TABLE products (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    category VARCHAR(100),
    rating DECIMAL(3, 1) DEFAULT 5.0,
    image VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

---

## 🆘 Troubleshooting

### "Invalid or expired access code"
✓ Check that the code is correct (case-sensitive)
✓ Verify the code hasn't expired (90 days)
✓ Ensure code is still active (not revoked)

### "Image file too large"
✓ Reduce image size to under 5MB
✓ Use image compression tools

### "Invalid image format"
✓ Only JPG, PNG, GIF, and WEBP are supported
✓ Convert your image format if needed

### "Error uploading image"
✓ Check folder permissions on `img/products/`
✓ Ensure folder exists and is writable
✓ Try a different image file

### "Connection failed"
✓ Verify XAMPP MySQL is running
✓ Database name: `borey_store`
✓ Username: `root`
✓ Password: (empty)

---

## 📞 Support

For issues:
1. Check the Troubleshooting section above
2. Verify database is running
3. Check file permissions
4. Review error messages carefully

---

## 🔄 Next Steps (Production)

For a live store, consider:

1. **Security Improvements**
   - Use password hashing (bcrypt) for database
   - Implement prepared statements
   - Add rate limiting to prevent brute force
   - Use HTTPS/SSL certificates
   - Implement CORS if needed

2. **Features**
   - Email notifications for new codes
   - Code expiration reminders
   - User activity logs
   - Role-based permissions (manager, operator)
   - Product editing/deletion

3. **Infrastructure**
   - Host on secure server
   - Database backups
   - Image CDN for fast delivery
   - Monitoring and alerts

---

**Version**: 1.0
**Created**: February 2026
**Last Updated**: February 27, 2026
