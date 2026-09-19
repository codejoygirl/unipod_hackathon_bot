import hashlib
import hmac
import json
import os
import time
import httpx

url = "http://localhost:8001/retrieval/grounded-answer"
secret = os.getenv(
    "INTERNAL_HMAC_SECRET",
    "prod_secure_hmac_secret_key_minimum_32_bytes_entropy",
)
tenant_id = "19333900-de82-487b-beeb-5b959660425b"

payload = {
    "tenant_id": tenant_id,
    "community_ids": [tenant_id],
    "query": "What are the clinic operating hours?",
    "top_k": 3,
}

body_str = json.dumps(payload)
timestamp = str(int(time.time()))
sig = hmac.new(
    secret.encode(),
    f"{timestamp}.{body_str}".encode(),
    hashlib.sha256,
).hexdigest()

headers = {
    "Content-Type": "application/json",
    "X-Signature": sig,
    "X-Timestamp": timestamp,
}

resp = httpx.post(url, content=body_str, headers=headers, timeout=25.0)

print(f"Status: {resp.status_code}")

if resp.status_code == 200:
    data = resp.json()
    validated = data.get("validated_payload", {})
    print(f"State: {validated.get('state')}")
    print(f"Answer: {validated.get('answer')}")
    print(f"Citations: {validated.get('citations')}")
else:
    print("Server Error Detail:")
    print(resp.text)