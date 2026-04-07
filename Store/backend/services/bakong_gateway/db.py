from __future__ import annotations

import sqlite3
from contextlib import contextmanager
from pathlib import Path


class TransactionStore:
    def __init__(self, database_path: str) -> None:
        self.database_path = str(Path(database_path).resolve())
        Path(self.database_path).parent.mkdir(parents=True, exist_ok=True)
        self._init_schema()

    @contextmanager
    def _conn(self):
        conn = sqlite3.connect(self.database_path)
        conn.row_factory = sqlite3.Row
        try:
            yield conn
            conn.commit()
        finally:
            conn.close()

    def _init_schema(self) -> None:
        with self._conn() as conn:
            conn.execute(
                """
                CREATE TABLE IF NOT EXISTS transactions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    order_id TEXT UNIQUE NOT NULL,
                    amount REAL NOT NULL,
                    khqr_code TEXT NOT NULL,
                    khqr_md5 TEXT,
                    tx_hash TEXT,
                    description TEXT,
                    customer_name TEXT,
                    customer_phone TEXT,
                    customer_location TEXT,
                    merchant_name TEXT,
                    status TEXT NOT NULL DEFAULT 'pending',
                    is_mock INTEGER NOT NULL DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
                """
            )

        try:
            with self._conn() as conn:
                conn.execute("ALTER TABLE transactions ADD COLUMN khqr_md5 TEXT")
        except sqlite3.OperationalError:
            pass

        try:
            with self._conn() as conn:
                conn.execute("ALTER TABLE transactions ADD COLUMN tx_hash TEXT")
        except sqlite3.OperationalError:
            pass

    def upsert_transaction(
        self,
        order_id: str,
        amount: float,
        khqr_code: str,
        khqr_md5: str,
        description: str,
        customer_name: str,
        customer_phone: str,
        customer_location: str,
        merchant_name: str,
        is_mock: bool,
    ) -> None:
        with self._conn() as conn:
            conn.execute(
                """
                INSERT INTO transactions (
                    order_id, amount, khqr_code, khqr_md5, description,
                    customer_name, customer_phone, customer_location,
                    merchant_name, status, is_mock, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, CURRENT_TIMESTAMP)
                ON CONFLICT(order_id) DO UPDATE SET
                    amount=excluded.amount,
                    khqr_code=excluded.khqr_code,
                    khqr_md5=excluded.khqr_md5,
                    description=excluded.description,
                    customer_name=excluded.customer_name,
                    customer_phone=excluded.customer_phone,
                    customer_location=excluded.customer_location,
                    merchant_name=excluded.merchant_name,
                    is_mock=excluded.is_mock,
                    updated_at=CURRENT_TIMESTAMP
                """,
                (
                    order_id,
                    amount,
                    khqr_code,
                    khqr_md5,
                    description,
                    customer_name,
                    customer_phone,
                    customer_location,
                    merchant_name,
                    1 if is_mock else 0,
                ),
            )

    def get_transaction(self, order_id: str) -> dict | None:
        with self._conn() as conn:
            row = conn.execute(
                "SELECT order_id, amount, status, is_mock, khqr_md5, tx_hash FROM transactions WHERE order_id = ? LIMIT 1",
                (order_id,),
            ).fetchone()
            if not row:
                return None
            return dict(row)

    def get_status(self, order_id: str) -> str:
        with self._conn() as conn:
            row = conn.execute(
                "SELECT status, is_mock, created_at FROM transactions WHERE order_id = ? LIMIT 1",
                (order_id,),
            ).fetchone()
            if not row:
                return "pending"
            return str(row["status"])

    def update_status(self, order_id: str, status: str) -> bool:
        with self._conn() as conn:
            cursor = conn.execute(
                "UPDATE transactions SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE order_id = ?",
                (status, order_id),
            )
            return cursor.rowcount > 0

    def update_status_and_hash(self, order_id: str, status: str, tx_hash: str | None) -> bool:
        with self._conn() as conn:
            cursor = conn.execute(
                "UPDATE transactions SET status = ?, tx_hash = COALESCE(?, tx_hash), updated_at = CURRENT_TIMESTAMP WHERE order_id = ?",
                (status, tx_hash, order_id),
            )
            return cursor.rowcount > 0
