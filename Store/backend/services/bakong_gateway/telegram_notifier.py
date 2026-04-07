from __future__ import annotations

import logging
import urllib.request
import urllib.parse
import json as _json

logger = logging.getLogger(__name__)

_BASE = "https://api.telegram.org/bot{token}/sendMessage"


def _send(token: str, chat_id: str, text: str, parse_mode: str = "HTML") -> bool:
    """Fire-and-forget Telegram sendMessage. Returns True on success."""
    if not token or not chat_id:
        logger.warning("Telegram notifier: token or chat_id not configured – skipping.")
        return False
    url = _BASE.format(token=token)
    payload = {
        "chat_id": chat_id,
        "text": text,
        "parse_mode": parse_mode,
        "disable_web_page_preview": True,
    }
    data = _json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(
        url,
        data=data,
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=8) as resp:
            body = _json.loads(resp.read())
            if body.get("ok"):
                return True
            logger.warning("Telegram API error: %s", body)
            return False
    except Exception as exc:
        logger.warning("Telegram notification failed: %s", exc)
        return False


def notify_payment_success(
    token: str,
    chat_id: str,
    *,
    order_id: str,
    amount: float | int | str,
    currency: str,
    description: str,
    customer_name: str = "",
    customer_phone: str = "",
    tx_hash: str = "",
    payer_name: str = "",
    merchant_name: str = "",
) -> bool:
    """Send a rich payment-success notification to the configured Telegram group."""

    # Format amount nicely
    try:
        amt_val = float(amount)
        if currency.upper() == "KHR":
            amt_str = f"{amt_val:,.0f} ៛"
        else:
            amt_str = f"${amt_val:,.2f}"
    except (TypeError, ValueError):
        amt_str = f"{amount} {currency}"

    lines = [
        "✅ <b>Payment Received!</b>",
        "",
        f"🏪 <b>Store:</b> {merchant_name or 'Borey Store'}",
        f"📦 <b>Order ID:</b> <code>{order_id}</code>",
        f"💰 <b>Amount:</b> {amt_str}",
        f"📝 <b>Description:</b> {description}",
    ]

    if payer_name:
        lines.append(f"👤 <b>Paid by:</b> {payer_name}")
    elif customer_name:
        lines.append(f"👤 <b>Customer:</b> {customer_name}")

    if customer_phone:
        lines.append(f"📞 <b>Phone:</b> {customer_phone}")

    if tx_hash:
        short = tx_hash[:16] + "…" if len(tx_hash) > 16 else tx_hash
        lines.append(f"🔗 <b>Tx Hash:</b> <code>{short}</code>")

    lines += ["", "🎉 Thank you for your purchase!"]

    text = "\n".join(lines)
    return _send(token, chat_id, text)
