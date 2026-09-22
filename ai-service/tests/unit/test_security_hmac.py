import hashlib
import hmac
import time
from ai_service.core.security import (
    multipart_canonical_payload,
    verify_hmac_message,
    verify_hmac_signature,
)

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


def test_multipart_canonical_payload_is_stable():
    canonical = multipart_canonical_payload(
        tenant_id="t1",
        community_id="c1",
        uri="doc://x",
        name="n",
        source_type="image",
        authority_tier="community_discussion",
        content_sha256="deadbeef",
        index_status="pending",
    )
    assert "index_status=pending" in canonical
    assert "content_sha256=deadbeef" in canonical

    now = int(time.time())
    sig = hmac.new(
        SECRET.encode("utf-8"),
        f"{now}.{canonical}".encode("utf-8"),
        hashlib.sha256,
    ).hexdigest()
    assert verify_hmac_message(SECRET, sig, str(now), canonical) is True

    tampered = canonical.replace("community_id=c1", "community_id=c2")
    assert verify_hmac_message(SECRET, sig, str(now), tampered) is False
