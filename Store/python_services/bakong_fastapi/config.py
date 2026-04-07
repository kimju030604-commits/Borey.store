from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path
from dotenv import load_dotenv


BASE_DIR = Path(__file__).resolve().parent
load_dotenv(BASE_DIR / ".env")


@dataclass(frozen=True)
class Settings:
    mode: str
    api_token: str
    relay_base_url: str
    bakong_api_url: str
    merchant_name: str
    bank_account: str
    merchant_city: str
    phone_number: str
    store_label: str
    terminal_label: str
    currency: str
    static_qr: bool
    static_khqr: str
    database_path: str
    host: str
    port: int
    debug: bool
    telegram_bot_token: str
    telegram_chat_id: str



def _parse_bool(value: str, default: bool = False) -> bool:
    if value is None:
        return default
    return value.strip().lower() in {"1", "true", "yes", "on"}


def load_settings() -> Settings:
    return Settings(
        mode=os.getenv("BAKONG_MODE", "mock").strip().lower(),
        api_token=os.getenv("BAKONG_API_TOKEN", "").strip(),
        relay_base_url=os.getenv("BAKONG_RELAY_BASE_URL", "https://api.bakongrelay.com").strip().rstrip("/"),
        bakong_api_url=os.getenv("BAKONG_API_URL", "https://api-bakong.nbc.gov.kh").strip().rstrip("/"),
        merchant_name=os.getenv("BAKONG_MERCHANT_NAME", "Khem Sovanny").strip(),
        bank_account=os.getenv("BAKONG_BANK_ACCOUNT", "").strip(),
        merchant_city=os.getenv("BAKONG_MERCHANT_CITY", "Phnom Penh").strip(),
        phone_number=os.getenv("BAKONG_PHONE_NUMBER", "").strip(),
        store_label=os.getenv("BAKONG_STORE_LABEL", "Borey Store").strip(),
        terminal_label=os.getenv("BAKONG_TERMINAL_LABEL", "Checkout").strip(),
        currency=os.getenv("BAKONG_CURRENCY", "USD").strip().upper(),
        static_qr=_parse_bool(os.getenv("BAKONG_STATIC_QR", "false"), default=False),
        static_khqr=os.getenv("BAKONG_STATIC_KHQR", "").strip(),
        database_path=os.getenv("DATABASE_PATH", "./gateway.db").strip(),
        host=os.getenv("HOST", "127.0.0.1").strip(),
        port=int(os.getenv("PORT", "8000")),
        debug=_parse_bool(os.getenv("DEBUG", "true"), default=True),
        telegram_bot_token=os.getenv("TELEGRAM_BOT_TOKEN", "").strip(),
        telegram_chat_id=os.getenv("TELEGRAM_CHAT_ID", "").strip(),
    )
