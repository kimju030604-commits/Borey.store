"""
Bakong KHQR Payment Gateway — FastAPI
Replaces the Flask app.py with FastAPI/uvicorn.
Reuses bakong_client.py, db.py, config.py unchanged.
"""
from __future__ import annotations

import logging
import time
from uuid import uuid4

from fastapi import FastAPI, Form, Request
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse

# Reuse existing helpers (no changes needed)
from bakong_client import BakongClient, BakongClientError
from config import load_settings
from db import TransactionStore

# ── Bootstrap ────────────────────────────────────────────────────────────────
settings = load_settings()
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger(__name__)

store  = TransactionStore(settings.database_path)
client = BakongClient(
    mode=settings.mode,
    api_token=settings.api_token,
    relay_base_url=settings.relay_base_url,
    bakong_api_url=settings.bakong_api_url,
    merchant_name=settings.merchant_name,
    bank_account=settings.bank_account,
    merchant_city=settings.merchant_city,
    phone_number=settings.phone_number,
    store_label=settings.store_label,
    terminal_label=settings.terminal_label,
    currency=settings.currency,
    static_qr=settings.static_qr,
    static_khqr=settings.static_khqr,
)

app = FastAPI(title="Bakong KHQR Gateway", version="2.0.0")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["http://localhost:5173", "http://localhost:8001", "http://127.0.0.1:8001"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


# ── Helpers ──────────────────────────────────────────────────────────────────
def ok(message: str, data: dict | None = None, status: int = 200):
    return JSONResponse({"status": "success", "message": message, "data": data or {}}, status_code=status)


def err(message: str, status: int = 400):
    return JSONResponse({"status": "error", "message": message, "data": {}}, status_code=status)


# ── Routes ───────────────────────────────────────────────────────────────────
@app.get("/health")
def health():
    return ok("Gateway running", {
        "mode":          settings.mode,
        "merchant_name": settings.merchant_name,
        "bank_account":  settings.bank_account,
        "currency":      settings.currency,
    })


@app.post("/api/payment")
async def payment_handler(request: Request):
    """Accepts both JSON and form-encoded bodies."""
    content_type = request.headers.get("content-type", "")
    if "application/json" in content_type:
        payload = await request.json()
    else:
        form = await request.form()
        payload = dict(form)

    action = str(payload.get("action", "")).strip()

    if action == "generate_khqr":
        return _generate_khqr(payload)
    if action == "check_payment":
        return _check_payment(payload)

    return err("Unknown or missing action")


@app.post("/api/payment/mark-paid")
async def mark_paid(request: Request):
    content_type = request.headers.get("content-type", "")
    if "application/json" in content_type:
        payload = await request.json()
    else:
        form = await request.form()
        payload = dict(form)

    order_id = str(payload.get("order_id", "")).strip()
    if not order_id:
        return err("order_id is required")

    updated = store.update_status(order_id, "completed")
    if not updated:
        return err("order not found", 404)

    return ok("Order marked completed", {"order_id": order_id, "status": "completed"})


# ── Handler functions ────────────────────────────────────────────────────────
def _generate_khqr(payload: dict):
    try:
        amount = float(payload.get("amount", 0))
    except (TypeError, ValueError):
        return err("Invalid amount")

    if amount <= 0:
        return err("Invalid amount")

    order_id    = str(payload.get("order_id") or f"BRY-{int(time.time())}-{uuid4().hex[:6]}").strip()
    description = str(payload.get("description") or "Borey Store Purchase").strip()
    customer_name     = str(payload.get("customer_name", "")).strip()
    customer_phone    = str(payload.get("customer_phone", "")).strip()
    customer_location = str(payload.get("customer_location", "")).strip()

    try:
        khqr, khqr_md5, is_mock = client.generate_khqr(amount, description, order_id, currency="KHR")
    except BakongClientError as exc:
        return err(f"Failed to generate KHQR: {exc}", 500)

    store.upsert_transaction(
        order_id=order_id,
        amount=amount,
        khqr_code=khqr,
        khqr_md5=khqr_md5,
        description=description,
        customer_name=customer_name,
        customer_phone=customer_phone,
        customer_location=customer_location,
        merchant_name=settings.merchant_name,
        is_mock=is_mock,
    )

    relay_image = client.generate_khqr_image(khqr)
    qr_image    = relay_image or f"https://api.qrserver.com/v1/create-qr-code/?size=400x400&data={khqr}"
    deeplink_result = client.generate_deeplink(khqr)
    deeplink    = deeplink_result.get("shortLink") if deeplink_result else None

    return ok("KHQR generated", {
        "khqr":          khqr,
        "qr_image":      qr_image,
        "order_id":      order_id,
        "amount":        amount,
        "currency":      "KHR",
        "md5":           khqr_md5,
        "merchant_name": settings.merchant_name,
        "is_mock":       is_mock,
        "deeplink":      deeplink,
    })


def _check_payment(payload: dict):
    order_id = str(payload.get("order_id", "")).strip()
    if not order_id:
        return err("order_id is required")

    tx = store.get_transaction(order_id)
    if not tx:
        return ok("Status checked", {"order_id": order_id, "status": "pending"})

    status = str(tx.get("status") or "pending")
    transaction_data = None

    if settings.mode == "live" and status != "completed":
        md5 = str(tx.get("khqr_md5") or "").strip()
        if md5:
            tx_details = client.get_transaction_details_by_md5(md5)
            if tx_details and (tx_details.get("hash") or tx_details.get("fromAccountId")):
                store.update_status_and_hash(order_id, "completed", tx_details.get("hash"))
                status = "completed"

                payer_name = None
                from_account_id = tx_details.get("fromAccountId", "")
                if from_account_id:
                    try:
                        account_info = client.check_bakong_account(from_account_id)
                        if account_info and account_info.get("fullName"):
                            payer_name = account_info["fullName"]
                    except Exception as exc:
                        logger.warning("Could not resolve payer name: %s", exc)

                transaction_data = {
                    "hash":             tx_details.get("hash"),
                    "from_account":     from_account_id,
                    "to_account":       tx_details.get("toAccountId"),
                    "amount":           tx_details.get("amount"),
                    "currency":         tx_details.get("currency"),
                    "payer_name":       payer_name,
                    "tracking_status":  tx_details.get("trackingStatus"),
                }

    response_data: dict = {"order_id": order_id, "status": status}
    if transaction_data:
        response_data["transaction"] = transaction_data
    elif status == "completed" and tx.get("tx_hash"):
        response_data["transaction"] = {"hash": tx["tx_hash"]}

    return ok("Status checked", response_data)


# ── Entry point ───────────────────────────────────────────────────────────────
if __name__ == "__main__":
    import uvicorn
    uvicorn.run("main:app", host=settings.host, port=settings.port, reload=settings.debug)
