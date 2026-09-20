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

    try:
        req_timestamp = int(timestamp)
    except (ValueError, TypeError):
        return False

    current_time = int(time.time())
    if abs(current_time - req_timestamp) > tolerance_seconds:
        return False

    message = f"{timestamp}.".encode("utf-8") + body
    expected_signature = hmac.new(
        key=secret.encode("utf-8"),
        msg=message,
        digestmod=hashlib.sha256,
    ).hexdigest()

    return hmac.compare_digest(expected_signature.lower(), signature.strip().lower())


def verify_hmac_message(
    secret: str,
    signature: str,
    timestamp: str,
    payload: str,
    tolerance_seconds: int = 300,
) -> bool:
    """Validate HMAC over ``timestamp.`` + UTF-8 payload (JSON body or canonical multipart)."""
    return verify_hmac_signature(
        secret=secret,
        signature=signature,
        timestamp=timestamp,
        body=payload.encode("utf-8"),
        tolerance_seconds=tolerance_seconds,
    )


def multipart_canonical_payload(
    *,
    tenant_id: str,
    community_id: str,
    uri: str,
    name: str,
    source_type: str,
    authority_tier: str,
    content_sha256: str,
    index_status: str = "pending",
) -> str:
    """Stable string bound into multipart HMAC (must match Laravel AiServiceClient)."""
    return "\n".join(
        [
            "v1",
            f"tenant_id={tenant_id}",
            f"community_id={community_id}",
            f"uri={uri}",
            f"name={name}",
            f"source_type={source_type}",
            f"authority_tier={authority_tier}",
            f"index_status={index_status}",
            f"content_sha256={content_sha256}",
        ]
    )
