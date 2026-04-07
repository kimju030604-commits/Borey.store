from __future__ import annotations

import os
import sys
import pathlib

# ── Resolve the real gateway package ─────────────────────────────────────────
# The app lives in services/bakong_gateway/, not in this directory.
_GATEWAY_DIR = pathlib.Path(__file__).resolve().parent.parent / "services" / "bakong_gateway"
if str(_GATEWAY_DIR) not in sys.path:
    sys.path.insert(0, str(_GATEWAY_DIR))
# Change to that directory so relative paths in config (e.g. .env) resolve correctly
os.chdir(_GATEWAY_DIR)

os.environ.setdefault("BAKONG_MODE", "mock")
os.environ.setdefault("DATABASE_PATH", "./smoke_test_gateway.db")
os.environ.setdefault("DEBUG", "false")

from app import app  # noqa: E402

_TEST_DB = _GATEWAY_DIR / "smoke_test_gateway.db"


def run_smoke_test() -> None:
    app.testing = True
    client = app.test_client()

    # ── Health check ──────────────────────────────────────────────────────
    health = client.get("/health")
    assert health.status_code == 200, health.get_data(as_text=True)

    # ── Generate KHQR ─────────────────────────────────────────────────────
    generate = client.post(
        "/api/payment",
        data={
            "action": "generate_khqr",
            "amount": "1.25",
            "description": "Smoke test",
            "customer_name": "Test User",
            "customer_phone": "+85512345678",
            "customer_location": "Phnom Penh",
        },
    )
    assert generate.status_code == 200, generate.get_data(as_text=True)
    generate_json = generate.get_json()
    assert generate_json is not None, "Response was not valid JSON"
    assert "data" in generate_json, f"Missing 'data' key: {generate_json}"
    generated_data = generate_json["data"]
    assert "order_id" in generated_data, f"Missing 'order_id' in data: {generated_data}"
    order_id = generated_data["order_id"]

    # ── Check status — should be pending ──────────────────────────────────
    check_pending = client.post("/api/payment", data={"action": "check_payment", "order_id": order_id})
    assert check_pending.status_code == 200, check_pending.get_data(as_text=True)
    assert check_pending.get_json()["data"]["status"] == "pending", check_pending.get_data(as_text=True)

    # ── Mark paid ─────────────────────────────────────────────────────────
    mark_paid = client.post("/api/payment/mark-paid", json={"order_id": order_id})
    assert mark_paid.status_code == 200, mark_paid.get_data(as_text=True)

    # ── Check status — should be completed ───────────────────────────────
    check_completed = client.post("/api/payment", data={"action": "check_payment", "order_id": order_id})
    assert check_completed.status_code == 200, check_completed.get_data(as_text=True)
    assert check_completed.get_json()["data"]["status"] == "completed", check_completed.get_data(as_text=True)

    print("SMOKE TEST PASSED")


if __name__ == "__main__":
    try:
        run_smoke_test()
    finally:
        # Clean up the temporary test database
        if _TEST_DB.exists():
            _TEST_DB.unlink()
