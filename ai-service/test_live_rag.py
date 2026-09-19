"""Automated live end-to-end RAG verification script using Gemini and real PostgreSQL."""

import hashlib
import hmac
import json
import os
import sys
import time
import uuid
import httpx

# ---------------------------------------------------------------------------
# Configuration & Environment
# ---------------------------------------------------------------------------
BASE_URL = os.getenv("RAG_SERVICE_URL", "http://127.0.0.1:8001")
SECRET = os.getenv(
    "INTERNAL_HMAC_SECRET",
    "prod_secure_hmac_secret_key_minimum_32_bytes_entropy",
)
GEMINI_API_KEY = os.getenv("GEMINI_API_KEY")

if not GEMINI_API_KEY:
    print("⚠️  WARNING: GEMINI_API_KEY is not set in your host environment.")
    print("   Export it before running: export GEMINI_API_KEY='your_api_key'\n")

# Fixed test tenant and community UUIDs
TENANT_ID = os.getenv("TEST_TENANT_ID", str(uuid.uuid4()))
COMMUNITY_ID = TENANT_ID


def create_signed_headers(payload_str: str) -> dict[str, str]:
    """Generate cryptographically signed HMAC-SHA256 headers."""
    timestamp = str(int(time.time()))
    message = f"{timestamp}.{payload_str}".encode("utf-8")
    signature = hmac.new(
        SECRET.encode("utf-8"),
        message,
        hashlib.sha256,
    ).hexdigest()

    return {
        "Content-Type": "application/json",
        "X-Signature": signature,
        "X-Timestamp": timestamp,
    }


def run_e2e_test():
    print("=" * 70)
    print(f"🚀 EXECUTING LIVE RAG TEST AGAINST: {BASE_URL}")
    print(f"🔑 TENANT ID: {TENANT_ID}")
    print("=" * 70)

    client = httpx.Client(timeout=30.0)

    # -----------------------------------------------------------------------
    # Step 1: Healthcheck & Liveness Probe
    # -----------------------------------------------------------------------
    print("\n[Stage 1/4] Checking Microservice Liveness & Readiness...")
    try:
        live_resp = client.get(f"{BASE_URL}/health/live")
        assert live_resp.status_code == 200, f"Liveness returned {live_resp.status_code}"
        print("  ✓ Service liveness check passed.")

        ready_resp = client.get(f"{BASE_URL}/health/ready")
        if ready_resp.status_code == 200:
            print("  ✓ Database connectivity verified (Readiness OK).")
        else:
            print(f"  ⚠️ Readiness check returned {ready_resp.status_code} ({ready_resp.text}).")
    except httpx.ConnectError:
        print(f"\n❌ FATAL: Could not connect to {BASE_URL}.")
        print("   Make sure the server is active: uv run uvicorn ai_service.main:app --port 8001 --reload")
        sys.exit(1)

    # -----------------------------------------------------------------------
    # Step 2: Ingest Production Test Document
    # -----------------------------------------------------------------------
    print("\n[Stage 2/4] Ingesting Structured Ground Truth Document...")
    unique_marker = uuid.uuid4().hex[:6]
    clinic_content = (
        f"Community Health Center Schedule (Reference ID #{unique_marker}):\n"
        "The primary care clinic is open Monday to Friday from 8:00 AM to 5:00 PM. "
        "Emergency walk-ins are admitted until 4:00 PM every weekday. "
        "Pediatric vaccinations are administered exclusively on Wednesdays between 9:00 AM and 1:00 PM. "
        "The facility is completely closed on Saturdays, Sundays, and public holidays."
    )

    ingest_payload = {
        "tenant_id": TENANT_ID,
        "community_id": COMMUNITY_ID,
        "name": f"Clinic Operating Standards {unique_marker}",
        "uri": f"clinic://sop/{unique_marker}",
        "source_type": "document",
        "content": clinic_content,
        "authority_tier": "official_announcement",
    }

    ingest_body = json.dumps(ingest_payload)
    headers = create_signed_headers(ingest_body)

    ingest_resp = client.post(
        f"{BASE_URL}/ingestion/sync",
        content=ingest_body,
        headers=headers,
    )

    if ingest_resp.status_code not in (200, 201):
        print(f"❌ Ingestion failed with status {ingest_resp.status_code}: {ingest_resp.text}")
        sys.exit(1)

    print(f"  ✓ Document ingested and embedded into pgvector successfully.")

    # -----------------------------------------------------------------------
    # Step 3: Test Grounded Retrieval with Gemini Synthesis (Verified Case)
    # -----------------------------------------------------------------------
    print("\n[Stage 3/4] Testing Grounded Synthesis & Citations...")
    query_payload = {
        "tenant_id": TENANT_ID,
        "community_ids": [COMMUNITY_ID],
        "query": f"When are pediatric vaccinations available according to reference #{unique_marker}?",
        "top_k": 3,
        "enable_conflict_detection": True,
        "temperature": 0.0,
    }

    query_body = json.dumps(query_payload)
    headers = create_signed_headers(query_body)

    grounded_resp = client.post(
        f"{BASE_URL}/retrieval/grounded-answer",
        content=query_body,
        headers=headers,
    )

    if grounded_resp.status_code != 200:
        print(f"❌ Grounded query failed with status {grounded_resp.status_code}: {grounded_resp.text}")
        sys.exit(1)

    result = grounded_resp.json()
    val = result.get("validated_payload", {})

    state = val.get("state")
    answer = val.get("answer")
    citations = val.get("citations", [])
    confidence = val.get("confidence_score", 0.0)

    print("-" * 50)
    print(f"Decision State:     {state}")
    print(f"Confidence Score:   {confidence}")
    print(f"Execution Latency:  {result.get('execution_time_ms')} ms")
    print(f"Chunks Scanned:     {result.get('total_chunks_retrieved')}")
    print(f"Generated Answer:\n  {answer}")
    print(f"Citations Returned: {citations}")
    print("-" * 50)

    assert state == "VERIFIED", f"Expected state VERIFIED, got {state}"
    assert len(citations) > 0, "Expected at least one verified citation."
    print("  ✓ Grounded question answered with verified citations.")

    # -----------------------------------------------------------------------
    # Step 4: Adversarial Out-Of-Domain Test (Hallucination Defense)
    # -----------------------------------------------------------------------
    print("\n[Stage 4/4] Testing Hallucination Hard-Failure Defense...")
    ood_payload = {
        "tenant_id": TENANT_ID,
        "community_ids": [COMMUNITY_ID],
        "query": "What is the capital city of Mars and its average winter rainfall?",
        "top_k": 3,
    }
    ood_body = json.dumps(ood_payload)
    headers = create_signed_headers(ood_body)

    ood_resp = client.post(
        f"{BASE_URL}/retrieval/grounded-answer",
        content=ood_body,
        headers=headers,
    )

    ood_val = ood_resp.json().get("validated_payload", {})
    ood_state = ood_val.get("state")
    print(f"  Out-of-Domain State: {ood_state} (Needs Escalation: {ood_val.get('needs_escalation')})")

    assert ood_state == "INSUFFICIENT_EVIDENCE", (
        f"Expected INSUFFICIENT_EVIDENCE for out-of-domain query, got {ood_state}"
    )
    print("  ✓ Hallucination defense rejected ungrounded query cleanly.")

    print("\n" + "=" * 70)
    print("🎉 ALL PRODUCTION INTEGRATION TESTS PASSED SUCCESSFULLY!")
    print("=" * 70)


if __name__ == "__main__":
    run_e2e_test()