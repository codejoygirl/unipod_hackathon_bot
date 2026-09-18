"""Cryptographic signature validation and replay attack prevention for inter-service communication."""

import hashlib
import hmac
import time


def verify_hmac_signature(
    secret: str,
    signature: str,
    timestamp: str,
    body: bytes,
    tolerance_seconds: int = 300,
) -> bool:
    """Validate request payload signature using HMAC-SHA256 with timestamp replay protection.

    Args:
        secret: Pre-shared secret key shared between API Gateway and ai-service.
        signature: Hex-encoded HMAC-SHA256 signature from X-Signature header.
        timestamp: Unix epoch timestamp string from X-Timestamp header.
        body: Raw bytes of the HTTP request body.
        tolerance_seconds: Maximum allowed clock skew in seconds (default: 300s).

    Returns:
        True if the signature is valid and timestamp is within acceptable window, False otherwise.
    """
    if not secret or not signature or not timestamp:
        return False

    # 1. Replay attack defense: verify timestamp freshness
    try:
        req_timestamp = int(timestamp)
    except (ValueError, TypeError):
        return False

    current_time = int(time.time())
    if abs(current_time - req_timestamp) > tolerance_seconds:
        return False

    # 2. Recompute expected signature: HMAC-SHA256(secret, timestamp + "." + body)
    message = f"{timestamp}.".encode("utf-8") + body
    expected_signature = hmac.new(
        key=secret.encode("utf-8"),
        msg=message,
        digestmod=hashlib.sha256,
    ).hexdigest()

    # 3. Constant-time comparison to prevent timing attacks
    return hmac.compare_digest(expected_signature.lower(), signature.strip().lower())