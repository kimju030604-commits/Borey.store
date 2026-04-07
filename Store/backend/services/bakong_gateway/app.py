from __future__ import annotations

import logging
import time
from uuid import uuid4

from flask import Flask, jsonify, request

from bakong_client import BakongClient, BakongClientError
from config import load_settings
from db import TransactionStore


settings = load_settings()
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger(__name__)

app = Flask(__name__)
store = TransactionStore(settings.database_path)
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


def json_response(status: str, message: str, code: int = 200, data: dict | None = None):
    payload = {"status": status, "message": message, "data": data or {}}
    return jsonify(payload), code


@app.get("/health")
def health():
    return json_response(
        "success",
        "Gateway running",
        200,
        {
            "mode": settings.mode,
            "merchant_name": settings.merchant_name,
            "relay_base_url": settings.relay_base_url,
            "bank_account": settings.bank_account,
            "currency": settings.currency,
        },
    )


@app.post("/api/payment")
def payment_handler():
    content_type = request.headers.get("Content-Type", "")
    if "application/json" in content_type:
        payload = request.get_json(silent=True) or {}
    else:
        payload = request.form.to_dict(flat=True)

    action = payload.get("action", "").strip()

    if action == "generate_khqr":
        return handle_generate_khqr(payload)

    if action == "check_payment":
        return handle_check_payment(payload)

    return json_response("error", "Unknown or missing action", 400)


@app.post("/api/payment/mark-paid")
def mark_paid():
    payload = request.get_json(silent=True) or request.form.to_dict(flat=True)
    order_id = str(payload.get("order_id", "")).strip()
    if not order_id:
        return json_response("error", "order_id is required", 400)

    updated = store.update_status(order_id, "completed")
    if not updated:
        return json_response("error", "order not found", 404)

    return json_response("success", "Order marked completed", 200, {"order_id": order_id, "status": "completed"})


def handle_generate_khqr(payload: dict):
    try:
        amount = float(payload.get("amount", 0))
    except (TypeError, ValueError):
        return json_response("error", "Invalid amount", 400)

    if amount <= 0:
        return json_response("error", "Invalid amount", 400)

    order_id = str(payload.get("order_id") or f"BRY-{int(time.time())}-{uuid4().hex[:6]}").strip()
    description = str(payload.get("description") or "Borey Store Purchase").strip()
    customer_name = str(payload.get("customer_name", "")).strip()
    customer_phone = str(payload.get("customer_phone", "")).strip()
    customer_location = str(payload.get("customer_location", "")).strip()
    currency = "KHR"

    try:
        khqr, khqr_md5, is_mock = client.generate_khqr(amount, description, order_id, currency=currency)
    except BakongClientError as exc:
        return json_response("error", f"Failed to generate KHQR: {exc}", 500)

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
    qr_image = relay_image or f"https://api.qrserver.com/v1/create-qr-code/?size=400x400&data={khqr}"
    
    # Generate deeplink for mobile payment
    deeplink_result = client.generate_deeplink(khqr)
    deeplink = deeplink_result.get("shortLink") if deeplink_result else None
    
    return json_response(
        "success",
        "KHQR generated",
        200,
        {
            "khqr": khqr,
            "qr_image": qr_image,
            "order_id": order_id,
            "amount": amount,
            "currency": currency,
            "md5": khqr_md5,
            "merchant_name": settings.merchant_name,
            "is_mock": is_mock,
            "deeplink": deeplink,
        },
    )


def handle_check_payment(payload: dict):
    order_id = str(payload.get("order_id", "")).strip()
    if not order_id:
        return json_response("error", "order_id is required", 400)

    tx = store.get_transaction(order_id)
    if not tx:
        return json_response("success", "Status checked", 200, {"order_id": order_id, "status": "pending"})

    status = str(tx.get("status") or "pending")
    transaction_data = None

    if settings.mode == "live" and status != "completed":
        md5 = str(tx.get("khqr_md5") or "").strip()
        if md5:
            # Get full transaction details
            tx_details = client.get_transaction_details_by_md5(md5)
            if tx_details and (tx_details.get("hash") or tx_details.get("fromAccountId")):
                store.update_status_and_hash(order_id, "completed", tx_details.get("hash"))
                status = "completed"

                # Look up the payer's real full name from Bakong account registry
                from_account_id = tx_details.get("fromAccountId", "")
                payer_name = None
                if from_account_id:
                    try:
                        account_info = client.check_bakong_account(from_account_id)
                        if account_info and account_info.get("fullName"):
                            payer_name = account_info["fullName"]
                            logger.info("Resolved payer name for %s: %s", from_account_id, payer_name)
                    except Exception as exc:
                        logger.warning("Could not resolve payer name for %s: %s", from_account_id, exc)

                transaction_data = {
                    "hash": tx_details.get("hash"),
                    "from_account": from_account_id,
                    "to_account": tx_details.get("toAccountId"),
                    "amount": tx_details.get("amount"),
                    "currency": tx_details.get("currency"),
                    "created_at": tx_details.get("createdDateMs"),
                    "acknowledged_at": tx_details.get("acknowledgedDateMs"),
                    "receiver_bank": tx_details.get("receiverBank"),
                    "tracking_status": tx_details.get("trackingStatus"),
                    "payer_name": payer_name,
                }

    response_data = {"order_id": order_id, "status": status}
    if transaction_data:
        response_data["transaction"] = transaction_data
    elif status == "completed" and tx.get("tx_hash"):
        # Already-completed order: return the stored hash so the invoice can record it
        response_data["transaction"] = {"hash": tx["tx_hash"]}

    return json_response("success", "Status checked", 200, response_data)


@app.post("/api/generate_deeplink")
def generate_deeplink_handler():
    """Generate a deep link for the QR code."""
    payload = request.get_json(silent=True) or request.form.to_dict(flat=True)
    
    qr = str(payload.get("qr", "")).strip()
    if not qr:
        return json_response("error", "qr is required", 400)
    
    callback = str(payload.get("callback", "https://bakong.nbc.org.kh")).strip()
    app_icon_url = str(payload.get("app_icon_url", "https://bakong.nbc.gov.kh/images/logo.svg")).strip()
    app_name = str(payload.get("app_name", settings.store_label)).strip()
    
    result = client.generate_deeplink(qr, callback, app_icon_url, app_name)
    if result:
        return json_response("success", "Deeplink generated", 200, result)
    return json_response("error", "Failed to generate deeplink", 500)


@app.post("/api/check_account")
def check_account_handler():
    """Check if a Bakong account exists."""
    payload = request.get_json(silent=True) or request.form.to_dict(flat=True)
    
    account_id = str(payload.get("account_id", "")).strip()
    if not account_id:
        return json_response("error", "account_id is required", 400)
    
    result = client.check_bakong_account(account_id)
    if result:
        return json_response("success", "Account checked", 200, result)
    return json_response("error", "Failed to check account", 500)


@app.post("/api/check_transaction")
def check_transaction_handler():
    """Check transaction status by various methods (md5, hash, short_hash)."""
    payload = request.get_json(silent=True) or request.form.to_dict(flat=True)
    
    # Try md5 first
    md5 = str(payload.get("md5", "")).strip()
    if md5:
        result = client.get_transaction_details_by_md5(md5)
        if result:
            return json_response("success", "Transaction found", 200, result)
        return json_response("error", "Transaction not found", 404)
    
    # Try full hash
    tx_hash = str(payload.get("hash", "")).strip()
    if tx_hash and len(tx_hash) > 8:
        result = client.check_transaction_by_hash(tx_hash)
        if result:
            return json_response("success", "Transaction found", 200, result)
        return json_response("error", "Transaction not found", 404)
    
    # Try short hash (requires amount and currency)
    short_hash = str(payload.get("short_hash", "")).strip()
    if short_hash:
        try:
            amount = float(payload.get("amount", 0))
        except (TypeError, ValueError):
            return json_response("error", "Invalid amount for short_hash lookup", 400)
        
        currency = str(payload.get("currency", "KHR")).strip().upper()
        result = client.check_transaction_by_short_hash(short_hash, amount, currency)
        if result:
            return json_response("success", "Transaction found", 200, result)
        return json_response("error", "Transaction not found", 404)
    
    return json_response("error", "Provide md5, hash, or short_hash (with amount/currency)", 400)


if __name__ == "__main__":
    app.run(host=settings.host, port=settings.port, debug=settings.debug)
