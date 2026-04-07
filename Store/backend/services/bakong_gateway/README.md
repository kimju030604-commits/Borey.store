# Python Bakong KHQR Payment Gateway

This is a full Python payment gateway service for your store, built with Flask.

## Features
- Generate KHQR via Bakong Relay API (`live` mode) or local mock mode (`mock` mode)
- Save transactions in SQLite
- Check payment status by `order_id` (live mode verifies via `md5`)
- Return Relay image (`data:image/...`) from `POST /v1/generate_khqr_image` when available
- Mark payment completed endpoint for integration/webhook simulation
- Health endpoint

## Merchant
- Merchant Name configured as: **Khem Sovanny**

## Project Files
- `app.py` - Flask API server
- `bakong_client.py` - Bakong API integration logic
- `db.py` - SQLite transaction storage
- `config.py` - Environment configuration
- `smoke_test.py` - local end-to-end smoke test

## Quick Start
1. Open terminal in this folder:
   ```powershell
   cd c:\xampp\htdocs\Store\python_bakong_gateway
   ```
2. Install dependencies:
   ```powershell
   C:/Users/PC/AppData/Local/Programs/Python/Python314/python.exe -m pip install -r requirements.txt
   ```
3. Create env file:
   ```powershell
   copy .env.example .env
   ```
4. Run gateway:
   ```powershell
   C:/Users/PC/AppData/Local/Programs/Python/Python314/python.exe app.py
   ```

Gateway runs at `http://127.0.0.1:8000` by default.

## API Endpoints
### Health
- `GET /health`

### Payment Actions
- `POST /api/payment`
  - `action=generate_khqr`
  - `action=check_payment`

### Mark Completed
- `POST /api/payment/mark-paid`
  - body: `{ "order_id": "..." }`

## Bakong Relay Mapping
- KHQR generation uses `POST /v1/generate_qr`
- Live status verification uses `POST /v1/check_transaction_by_md5`
- Generated `data.md5` is saved and used during `check_payment`

## Example Requests
### Generate KHQR
```bash
curl -X POST http://127.0.0.1:8000/api/payment \
  -d "action=generate_khqr" \
  -d "amount=2.50" \
  -d "description=Borey Store - 1 items" \
  -d "customer_name=User" \
  -d "customer_phone=+85512345678" \
  -d "customer_location=Phnom Penh"
```

### Check Status
```bash
curl -X POST http://127.0.0.1:8000/api/payment \
  -d "action=check_payment" \
  -d "order_id=BRY-123"
```

## Live Bakong Relay Mode
In `.env`:
- Set `BAKONG_MODE=live`
- Set `BAKONG_RELAY_BASE_URL=https://api.bakongrelay.com`
- Set `BAKONG_BANK_ACCOUNT=your_name@bank`
- Set `BAKONG_PHONE_NUMBER=85512345678`
- Set `BAKONG_API_TOKEN=...` (RBK token for `check_transaction_by_md5` only)
- Optional: `BAKONG_MERCHANT_CITY`, `BAKONG_STORE_LABEL`, `BAKONG_TERMINAL_LABEL`, `BAKONG_CURRENCY`
- Optionally set `BAKONG_STATIC_KHQR=` (real fallback KHQR string)

If Relay credentials are not ready yet, keep `BAKONG_MODE=mock` for local testing continuity.

## Validation
Run smoke test:
```powershell
C:/Users/PC/AppData/Local/Programs/Python/Python314/python.exe smoke_test.py
```
Expected output:
- `SMOKE TEST PASSED`
