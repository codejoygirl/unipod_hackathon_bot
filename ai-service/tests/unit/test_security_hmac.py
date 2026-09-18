import hashlib
import hmac
import time
from ai_service.core.security import verify_hmac_signature

SECRET = "test_secret_key"


def generate_sig(secret: str, timestamp: int, body: bytes) -> str:
    message = f"{timestamp}.".encode("utf-8") + body
    return hmac.new(secret.encode("utf-8"), message, hashlib.sha256).hexdigest()


def test_hmac_valid_signature():
    now = int(time.time())
    body = b'{"query": "health clinic"}'
    sig = generate_sig(SECRET, now, body)

    assert verify_hmac_signature(SECRET, sig, str(now), body) is True


def test_hmac_rejects_expired_timestamp():
    past_timestamp = int(time.time()) - 400  # Older than 300s tolerance
    body = b'{"query": "health clinic"}'
    sig = generate_sig(SECRET, past_timestamp, body)

    assert verify_hmac_signature(SECRET, sig, str(past_timestamp), body) is False


def test_hmac_rejects_tampered_body():
    now = int(time.time())
    body = b'{"query": "original"}'
    tampered = b'{"query": "tampered"}'
    sig = generate_sig(SECRET, now, body)

    assert verify_hmac_signature(SECRET, sig, str(now), tampered) is False