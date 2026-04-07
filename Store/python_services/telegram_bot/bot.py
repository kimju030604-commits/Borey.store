"""
Borey Store — Telegram Bot
Handles admin commands and order notifications.
Run:  python bot.py
"""
from __future__ import annotations

import logging
import os
import sqlite3
from pathlib import Path

import httpx
from dotenv import load_dotenv
from telegram import Update, BotCommand
from telegram.ext import (
    Application,
    CommandHandler,
    ContextTypes,
    MessageHandler,
    filters,
)

# ── Config ───────────────────────────────────────────────────────────────────
BASE_DIR = Path(__file__).resolve().parent
load_dotenv(BASE_DIR / ".env")

BOT_TOKEN        = os.getenv("TELEGRAM_BOT_TOKEN", "")
ALLOWED_CHAT_IDS = set(filter(None, os.getenv("TELEGRAM_CHAT_ID", "").split(",")))
LARAVEL_API      = os.getenv("LARAVEL_API_URL", "http://127.0.0.1:8001/api")
BAKONG_API       = os.getenv("BAKONG_GATEWAY_URL", "http://127.0.0.1:8000")

logging.basicConfig(
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    level=logging.INFO,
)
logger = logging.getLogger(__name__)


# ── Auth helper ───────────────────────────────────────────────────────────────
def is_allowed(update: Update) -> bool:
    cid = str(update.effective_chat.id)
    return not ALLOWED_CHAT_IDS or cid in ALLOWED_CHAT_IDS


# ── Commands ──────────────────────────────────────────────────────────────────
async def cmd_start(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    await update.message.reply_html(
        "👋 <b>Welcome to Borey Store Bot!</b>\n\n"
        "Available commands:\n"
        "/status <code>ORDER_ID</code> — Check payment status\n"
        "/invoice <code>INVOICE_NUMBER</code> — Look up invoice\n"
        "/stats — Store statistics\n"
        "/health — Check all services\n"
        "/help — Show this menu"
    )


async def cmd_help(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    await cmd_start(update, context)


async def cmd_health(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    lines = ["🔍 <b>Service Health Check</b>\n"]

    # FastAPI Bakong gateway
    try:
        r = httpx.get(f"{BAKONG_API}/health", timeout=5)
        data = r.json().get("data", {})
        mode = data.get("mode", "?")
        lines.append(f"✅ <b>Bakong Gateway</b> — {r.status_code} (mode: {mode})")
    except Exception as e:
        lines.append(f"❌ <b>Bakong Gateway</b> — {e}")

    # Laravel API
    try:
        r = httpx.get(f"{LARAVEL_API}/products", timeout=5)
        lines.append(f"✅ <b>Laravel API</b> — {r.status_code}")
    except Exception as e:
        lines.append(f"❌ <b>Laravel API</b> — {e}")

    await update.message.reply_html("\n".join(lines))


async def cmd_status(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not is_allowed(update):
        return

    if not context.args:
        await update.message.reply_text("Usage: /status ORDER_ID")
        return

    order_id = context.args[0].strip()
    try:
        r = httpx.post(
            f"{BAKONG_API}/api/payment",
            data={"action": "check_payment", "order_id": order_id},
            timeout=10,
        )
        data = r.json().get("data", {})
        status = data.get("status", "unknown")
        emoji  = "✅" if status == "completed" else ("⏳" if status == "pending" else "❓")
        msg    = f"{emoji} <b>Order:</b> <code>{order_id}</code>\n<b>Status:</b> {status}"
        if data.get("transaction", {}).get("hash"):
            msg += f"\n🔗 <b>Hash:</b> <code>{data['transaction']['hash'][:12]}…</code>"
    except Exception as e:
        msg = f"⚠️ Could not check status: {e}"

    await update.message.reply_html(msg)


async def cmd_invoice(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not is_allowed(update):
        return

    if not context.args:
        await update.message.reply_text("Usage: /invoice INVOICE_NUMBER\nExample: /invoice Borey.0001")
        return

    inv_num = context.args[0].strip()
    try:
        r = httpx.get(f"{LARAVEL_API}/invoice", params={"id": inv_num}, timeout=10)
        body = r.json()
        if body.get("status") != "success":
            await update.message.reply_text(f"❌ {body.get('message', 'Not found')}")
            return

        d = body["data"]
        total_khr = int(d.get("total_khr", 0))
        msg = (
            f"🧾 <b>Invoice #{d['invoice_number']}</b>\n"
            f"👤 {d['customer_name']} — {d['customer_phone']}\n"
            f"📍 {d.get('customer_location','')}\n"
            f"💰 {total_khr:,} ៛\n"
            f"💳 {d.get('payment_method','')} / {d.get('payment_bank','')}\n"
            f"📅 {str(d.get('created_at',''))[:10]}\n"
            f"✅ {d.get('payment_status','')}"
        )
    except Exception as e:
        msg = f"⚠️ Error: {e}"

    await update.message.reply_html(msg)


async def cmd_stats(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not is_allowed(update):
        return

    # Stats requires admin session — unavailable via bot without auth token.
    # We query the database directly via the FastAPI gateway health, or
    # connect to MariaDB if available.
    try:
        # Use the Laravel products endpoint as a proxy sanity check
        r = httpx.get(f"{LARAVEL_API}/products", timeout=8)
        products = r.json().get("data", [])
        in_stock     = sum(1 for p in products if int(p.get("stock", 0)) > 0)
        out_of_stock = len(products) - in_stock
        msg = (
            f"📊 <b>Store Stats</b>\n\n"
            f"📦 Products: {len(products)}\n"
            f"✅ In stock: {in_stock}\n"
            f"❌ Out of stock: {out_of_stock}"
        )
    except Exception as e:
        msg = f"⚠️ Could not fetch stats: {e}"

    await update.message.reply_html(msg)


async def unknown_command(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    await update.message.reply_text("❓ Unknown command. Use /help to see available commands.")


# ── Main ──────────────────────────────────────────────────────────────────────
def main() -> None:
    if not BOT_TOKEN:
        raise RuntimeError("TELEGRAM_BOT_TOKEN is not set in .env")

    app = Application.builder().token(BOT_TOKEN).build()

    app.add_handler(CommandHandler("start",   cmd_start))
    app.add_handler(CommandHandler("help",    cmd_help))
    app.add_handler(CommandHandler("health",  cmd_health))
    app.add_handler(CommandHandler("status",  cmd_status))
    app.add_handler(CommandHandler("invoice", cmd_invoice))
    app.add_handler(CommandHandler("stats",   cmd_stats))
    app.add_handler(MessageHandler(filters.COMMAND, unknown_command))

    # Set bot commands (shows in Telegram menu)
    async def post_init(application: Application) -> None:
        await application.bot.set_my_commands([
            BotCommand("status",  "Check order payment status"),
            BotCommand("invoice", "Look up an invoice"),
            BotCommand("stats",   "Store product statistics"),
            BotCommand("health",  "Check service health"),
            BotCommand("help",    "Show help menu"),
        ])

    app.post_init = post_init

    logger.info("Borey Store bot starting (polling mode)…")
    app.run_polling(allowed_updates=Update.ALL_TYPES)


if __name__ == "__main__":
    main()
