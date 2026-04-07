from __future__ import annotations

import os

os.environ.setdefault("BAKONG_MODE", "mock")
os.environ.setdefault("DATABASE_PATH", "./smoke_test_gateway.db")
os.environ.setdefault("DEBUG", "false")

from app import app  # noqa: E402


def run_smoke_test() -> None:
    client = app.test_client()

    health = client.get("/health")
    assert health.status_code == 200, health.get_data(as_text=True)

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
    generated_data = generate.get_json()["data"]
    order_id = generated_data["order_id"]

    check_pending = client.post("/api/payment", data={"action": "check_payment", "order_id": order_id})
    assert check_pending.status_code == 200, check_pending.get_data(as_text=True)
    assert check_pending.get_json()["data"]["status"] == "pending"

    mark_paid = client.post("/api/payment/mark-paid", json={"order_id": order_id})
    assert mark_paid.status_code == 200, mark_paid.get_data(as_text=True)

    check_completed = client.post("/api/payment", data={"action": "check_payment", "order_id": order_id})
    assert check_completed.status_code == 200, check_completed.get_data(as_text=True)
    assert check_completed.get_json()["data"]["status"] == "completed"

    print("SMOKE TEST PASSED")


if __name__ == "__main__":
    run_smoke_test()
