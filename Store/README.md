# BOREY.STORE - Project Structure

## Directory Organization

```
Store/
├── index.php           # Frontend - Main storefront
├── invoice.php         # Frontend - Invoice viewer/printer
│
├── admin/              # Backend - Admin Panel
│   ├── index.php       # Admin entry point
│   ├── login.php       # Admin authentication
│   ├── dashboard.php   # Admin dashboard
│   ├── products.php    # Product management
│   ├── invoices.php    # Invoice management
│   ├── reference.php   # Documentation reference
│   └── visual-guide.php
│
├── api/                # Backend - API Endpoints
│   ├── invoice.php     # Invoice CRUD operations
│   └── payment.php     # Payment processing proxy
│
├── config/             # Database Configuration
│   └── database.php    # MySQL connection settings
│
├── services/           # Backend Services
│   └── bakong_gateway/ # Python Bakong KHQR payment gateway
│       ├── app.py      # Flask API server
│       ├── bakong_client.py
│       ├── config.py
│       └── db.py
│
├── lib/                # Shared Libraries
│   ├── fpdf.php        # PDF library
│   ├── InvoicePDF.php  # Invoice PDF generator
│   ├── OCRHelper.php   # OCR utilities
│   └── font/           # Font files
│
├── assets/             # Frontend Assets
│   └── img/
│       └── products/   # Product images
│
├── storage/            # File Storage
│   ├── invoices/       # Generated PDF invoices
│   └── uploads/
│       └── receipts/   # Uploaded payment receipts
│
├── scripts/            # Utility Scripts
│   └── setup.php       # Database setup/migration
│
└── docs/               # Documentation
    ├── ADMIN_SETUP.md
    └── IMPLEMENTATION_SUMMARY.md
```

## Layer Separation

### Frontend
- `index.php` - Customer-facing storefront
- `invoice.php` - Invoice viewing/downloading
- `assets/` - Static assets (images, CSS, JS)

### Backend
- `admin/` - Admin panel pages
- `api/` - REST API endpoints
- `services/` - External service integrations
- `lib/` - Shared PHP libraries

### Database
- `config/database.php` - Database connection
- `scripts/setup.php` - Schema migrations

### Storage
- `storage/invoices/` - Generated PDFs
- `storage/uploads/` - User uploads

## Starting the Payment Gateway

```powershell
cd Store/services/bakong_gateway
python app.py
```

The gateway runs on `http://127.0.0.1:8000`

## Telegram Bot Configuration

```
TELEGRAM_BOT_TOKEN=123456789:ABCxyz...
TELEGRAM_CHAT_ID=-1001234567890
```

## New Payment Received!

📦 Order: BRY-20260301-abc123
💰 Amount: 2050 KHR
👤 From: Khem Sovanny
🏦 Hash: a70ce866
✅ Status: Confirmed
