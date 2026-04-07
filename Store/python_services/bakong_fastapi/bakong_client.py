from __future__ import annotations

import hashlib
import logging
from dataclasses import dataclass
from uuid import uuid4

import requests


logger = logging.getLogger(__name__)


class BakongClientError(RuntimeError):
    pass


@dataclass
class BakongClient:
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

    def _extract_khqr_and_md5(self, data: dict) -> tuple[str | None, str | None]:
        if not isinstance(data, dict):
            return None, None

        relay_data = data.get("data") if isinstance(data.get("data"), dict) else {}
        qr = relay_data.get("qr")
        md5 = relay_data.get("md5")

        if isinstance(qr, str) and len(qr.strip()) > 20:
            cleaned_qr = qr.strip()
            cleaned_md5 = md5.strip() if isinstance(md5, str) and md5.strip() else hashlib.md5(cleaned_qr.encode("utf-8")).hexdigest()
            return cleaned_qr, cleaned_md5

        return None, None

    def _mock_khqr(self, order_id: str) -> tuple[str, str]:
        if self.static_khqr and len(self.static_khqr) > 20:
            khqr = self.static_khqr
            return khqr, hashlib.md5(khqr.encode("utf-8")).hexdigest()

        khqr = f"MOCK-KHQR-{self.merchant_name.replace(' ', '').upper()}-{order_id}-{uuid4().hex[:8]}"
        return khqr, hashlib.md5(khqr.encode("utf-8")).hexdigest()

    def generate_khqr(self, amount: float, description: str, merchant_trx_id: str, currency: str | None = None) -> tuple[str, str, bool]:
        if self.mode == "mock":
            logger.info("Using mock mode KHQR generation")
            khqr, md5 = self._mock_khqr(merchant_trx_id)
            return khqr, md5, True

        if not self.bank_account:
            raise BakongClientError("BAKONG_BANK_ACCOUNT is required in live mode")
        if not self.phone_number:
            raise BakongClientError("BAKONG_PHONE_NUMBER is required in live mode")

        selected_currency = (currency or self.currency or "USD").strip().upper()

        # Truncate fields to API limits
        payload = {
            "merchant_name": self.merchant_name[:25],
            "bank_account": self.bank_account[:32],
            "merchant_city": self.merchant_city[:15],
            "amount": round(float(amount), 2),
            "currency": selected_currency[:3],
            "store_label": self.store_label[:25],
            "phone_number": self.phone_number[:25],
            "bill_number": merchant_trx_id[:25],
            "terminal_label": (description[:25] if description else self.terminal_label[:25]),
            "static": self.static_qr,
        }

        last_error = "Unknown Bakong API error"
        endpoint = f"{self.relay_base_url}/v1/generate_qr"
        # generate_qr endpoint does NOT require authorization per API docs
        headers = {
            "Content-Type": "application/json",
        }

        try:
            logger.info("Trying Bakong Relay endpoint: %s", endpoint)
            response = requests.post(endpoint, json=payload, headers=headers, timeout=20)
            status_code = response.status_code
            text = response.text[:500]
            logger.info("Bakong Relay response %s: %s", status_code, text)

            if status_code < 200 or status_code >= 300:
                if status_code == 422:
                    last_error = "Bakong Relay validation failed (422). Check merchant fields and field lengths."
                else:
                    last_error = f"Bakong Relay returned HTTP {status_code}"
            else:
                data = response.json()
                if int(data.get("responseCode", 1)) != 0:
                    last_error = str(data.get("responseMessage") or "Bakong Relay failed to generate KHQR")
                else:
                    khqr, md5 = self._extract_khqr_and_md5(data)
                    if khqr and md5:
                        return khqr, md5, False
                    last_error = "Bakong Relay response missing qr/md5"

        except requests.RequestException as exc:
            last_error = f"Bakong Relay request failed: {exc}"
            logger.exception("Bakong Relay request exception")
        except ValueError:
            last_error = "Bakong Relay returned invalid JSON"

        if self.static_khqr and len(self.static_khqr) > 20:
            logger.warning("Falling back to static KHQR")
            khqr = self.static_khqr
            md5 = hashlib.md5(khqr.encode("utf-8")).hexdigest()
            return khqr, md5, False

        raise BakongClientError(last_error)

    def check_transaction_by_md5(self, md5: str) -> bool:
        if not self.api_token:
            logger.info("Skipping live status check: BAKONG_API_TOKEN is not configured")
            return False

        endpoint = f"{self.bakong_api_url}/v1/check_transaction_by_md5"
        headers = {
            "Content-Type": "application/json",
            "Authorization": f"Bearer {self.api_token}",
        }
        payload = {"md5": md5}

        try:
            logger.info("Checking payment status for md5: %s", md5)
            response = requests.post(endpoint, json=payload, headers=headers, timeout=20)
            logger.info("check_transaction_by_md5 HTTP %s: %s", response.status_code, response.text[:500])
            
            if response.status_code < 200 or response.status_code >= 300:
                return False

            body = response.json()
            response_code = int(body.get("responseCode", 1))
            data = body.get("data")
            
            # responseCode 0 means success, and data should contain transaction info
            if response_code == 0 and isinstance(data, dict):
                # Check if transaction exists (has hash or any payment data)
                has_payment = bool(data.get("hash") or data.get("fromAccountId") or data.get("amount"))
                logger.info("Payment check result: responseCode=%s, has_payment=%s, data=%s", response_code, has_payment, data)
                return has_payment
            
            logger.info("Payment not found: responseCode=%s", response_code)
            return False
        except (requests.RequestException, ValueError) as exc:
            logger.exception("Failed to check transaction by md5: %s", exc)
            return False

    def generate_khqr_image(self, qr: str) -> str | None:
        if self.mode == "mock":
            return None

        endpoint = f"{self.relay_base_url}/v1/generate_khqr_image"
        # generate_khqr_image endpoint does NOT require authorization per API docs
        headers = {
            "Content-Type": "application/json",
        }
        payload = {"qr": qr[:255]}

        try:
            response = requests.post(endpoint, json=payload, headers=headers, timeout=20)
            if response.status_code < 200 or response.status_code >= 300:
                logger.info("generate_khqr_image HTTP %s: %s", response.status_code, response.text[:300])
                return None

            body = response.json()
            if int(body.get("responseCode", 1)) != 0:
                return None

            data = body.get("data") if isinstance(body.get("data"), dict) else {}
            image_data = data.get("image")
            if isinstance(image_data, str) and image_data.startswith("data:image"):
                return image_data
            return None
        except (requests.RequestException, ValueError):
            logger.exception("Failed to generate KHQR image via relay")
            return None

    def generate_deeplink(self, qr: str, callback: str = "https://bakong.nbc.org.kh", 
                          app_icon_url: str = "https://bakong.nbc.gov.kh/images/logo.svg",
                          app_name: str = "Borey.Store") -> dict | None:
        """Generate a deep link for the QR code."""
        if self.mode == "mock":
            return {"shortLink": f"https://bakong.mock/pay/{qr[:20]}"}

        endpoint = f"{self.relay_base_url}/v1/generate_deeplink_by_qr"
        headers = {"Content-Type": "application/json"}
        payload = {
            "qr": qr[:255],
            "appIconUrl": app_icon_url,
            "appName": app_name,
            "callback": callback,
        }

        try:
            logger.info("Generating deeplink for QR")
            response = requests.post(endpoint, json=payload, headers=headers, timeout=20)
            logger.info("generate_deeplink HTTP %s: %s", response.status_code, response.text[:300])
            
            if response.status_code < 200 or response.status_code >= 300:
                return None

            body = response.json()
            if int(body.get("responseCode", 1)) != 0:
                return None

            data = body.get("data") if isinstance(body.get("data"), dict) else {}
            return data if data.get("shortLink") else None
        except (requests.RequestException, ValueError):
            logger.exception("Failed to generate deeplink")
            return None

    def check_bakong_account(self, account_id: str) -> dict | None:
        """Check if a Bakong account exists."""
        endpoint = f"{self.bakong_api_url}/v1/check_bakong_account"
        headers = {"Content-Type": "application/json"}
        payload = {"accountId": account_id[:32]}

        try:
            logger.info("Checking Bakong account: %s", account_id)
            response = requests.post(endpoint, json=payload, headers=headers, timeout=20)
            logger.info("check_bakong_account HTTP %s: %s", response.status_code, response.text[:300])
            
            if response.status_code == 404:
                return {"exists": False, "message": "Account not found"}
            if response.status_code < 200 or response.status_code >= 300:
                return None

            body = response.json()
            if int(body.get("responseCode", 1)) != 0:
                return {"exists": False, "message": body.get("responseMessage", "Account not found")}

            data = body.get("data") if isinstance(body.get("data"), dict) else {}
            return {
                "exists": True,
                "fullName": data.get("fullName", ""),
                "accountStatus": data.get("accountStatus", ""),
                "kycStatus": data.get("kycStatus", ""),
                "canReceive": data.get("canReceive", False),
            }
        except (requests.RequestException, ValueError):
            logger.exception("Failed to check Bakong account")
            return None

    def check_transaction_by_hash(self, tx_hash: str) -> dict | None:
        """Check transaction status by full hash (64 chars)."""
        if not self.api_token:
            logger.info("Skipping hash check: BAKONG_API_TOKEN not configured")
            return None

        endpoint = f"{self.bakong_api_url}/v1/check_transaction_by_hash"
        headers = {
            "Content-Type": "application/json",
            "Authorization": f"Bearer {self.api_token}",
        }
        payload = {"hash": tx_hash[:255]}

        try:
            logger.info("Checking transaction by hash: %s", tx_hash[:16])
            response = requests.post(endpoint, json=payload, headers=headers, timeout=20)
            logger.info("check_transaction_by_hash HTTP %s: %s", response.status_code, response.text[:300])
            
            if response.status_code < 200 or response.status_code >= 300:
                return None

            body = response.json()
            if int(body.get("responseCode", 1)) != 0:
                return None

            return body.get("data") if isinstance(body.get("data"), dict) else None
        except (requests.RequestException, ValueError):
            logger.exception("Failed to check transaction by hash")
            return None

    def check_transaction_by_short_hash(self, short_hash: str, amount: float, currency: str = "KHR") -> dict | None:
        """Check transaction status by short hash (8 chars)."""
        if not self.api_token:
            logger.info("Skipping short hash check: BAKONG_API_TOKEN not configured")
            return None

        endpoint = f"{self.bakong_api_url}/v1/check_transaction_by_short_hash"
        headers = {
            "Content-Type": "application/json",
            "Authorization": f"Bearer {self.api_token}",
        }
        payload = {
            "hash": short_hash[:8],
            "amount": round(float(amount), 2),
            "currency": currency[:3].upper(),
        }

        try:
            logger.info("Checking transaction by short hash: %s", short_hash)
            response = requests.post(endpoint, json=payload, headers=headers, timeout=20)
            logger.info("check_transaction_by_short_hash HTTP %s: %s", response.status_code, response.text[:300])
            
            if response.status_code < 200 or response.status_code >= 300:
                return None

            body = response.json()
            if int(body.get("responseCode", 1)) != 0:
                return None

            return body.get("data") if isinstance(body.get("data"), dict) else None
        except (requests.RequestException, ValueError):
            logger.exception("Failed to check transaction by short hash")
            return None

    def get_transaction_details_by_md5(self, md5: str) -> dict | None:
        """Get full transaction details by MD5 (returns dict with all info, not just bool)."""
        if not self.api_token:
            logger.info("Skipping md5 details: BAKONG_API_TOKEN not configured")
            return None

        endpoint = f"{self.bakong_api_url}/v1/check_transaction_by_md5"
        headers = {
            "Content-Type": "application/json",
            "Authorization": f"Bearer {self.api_token}",
        }
        payload = {"md5": md5[:255]}

        try:
            logger.info("Getting transaction details for md5: %s", md5)
            response = requests.post(endpoint, json=payload, headers=headers, timeout=20)
            logger.info("get_transaction_details_by_md5 HTTP %s: %s", response.status_code, response.text[:300])
            
            if response.status_code < 200 or response.status_code >= 300:
                return None

            body = response.json()
            if int(body.get("responseCode", 1)) != 0:
                return None

            return body.get("data") if isinstance(body.get("data"), dict) else None
        except (requests.RequestException, ValueError):
            logger.exception("Failed to get transaction details by md5")
            return None
