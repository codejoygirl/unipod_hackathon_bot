import asyncio
import base64
import os
import uuid
import httpx
from datetime import datetime

import hmac
import hashlib
import json
from dotenv import load_dotenv

load_dotenv()
SECRET = os.getenv("INTERNAL_HMAC_SECRET", "super-secret-key")

def get_auth_headers(payload_str: str) -> dict:
    """Generate HMAC auth headers."""
    timestamp = str(int(datetime.now().timestamp()))
    message = f"{timestamp}.{payload_str}".encode("utf-8")
    signature = hmac.new(SECRET.encode("utf-8"), message, hashlib.sha256).hexdigest()
    
    return {
        "X-Timestamp": timestamp,
        "X-Signature": signature
    }

async def run_test():
    """Run a simple multimodal ingestion and retrieval test."""
    print("Starting Multimodal RAG Test...")
    tenant_id = str(uuid.uuid4())
    community_id = str(uuid.uuid4())
    
    # Read the generated test files
    try:
        with open("test_image.jpg", "rb") as f:
            test_image = f.read()
        with open("test_audio.mp3", "rb") as f:
            test_audio = f.read()
        with open("test_video.mp4", "rb") as f:
            test_video = f.read()
    except FileNotFoundError as e:
        print(f"Missing test file. Run generate_media.py first and generate video: {e}")
        return

    async with httpx.AsyncClient(base_url="http://127.0.0.1:8002", timeout=60.0) as client:
        headers = get_auth_headers("")
        
        # Test configurations
        tests = [
            ("image", "test_image.jpg", test_image, "image/jpeg"),
            ("audio", "test_audio.mp3", test_audio, "audio/mpeg"),
            ("video", "test_video.mp4", test_video, "video/mp4"),
        ]

        # 1. Ingest
        for src_type, filename, data, mime in tests:
            print(f"\n1. Testing {src_type.capitalize()} Ingestion...")
            files = {'file': (filename, data, mime)}
            form_data = {
                'tenant_id': tenant_id,
                'community_id': community_id,
                'source_type': src_type,
                'name': f'Test {src_type.capitalize()}',
                'uri': f'test://{src_type}/1',
                'authority_tier': 'official_announcement'
            }
            
            try:
                resp = await client.post("/ingestion/multimodal", files=files, data=form_data, headers=headers)
                print(f"{src_type.capitalize()} Ingestion Status:", resp.status_code)
                print("Response:", resp.text)
            except Exception as e:
                print(f"Ingestion failed for {src_type}: {e}")

        # 2. Retrieval
        print("\n2. Testing Multimodal Retrieval...")
        retrieval_payload = {
            "tenant_id": tenant_id,
            "community_ids": [community_id],
            "query": "What is about to test?",
            "top_k": 5,
            "enable_conflict_detection": False,
            "temperature": 0.0
        }
        
        payload_str = json.dumps(retrieval_payload, separators=(',', ':'))
        headers = get_auth_headers(payload_str)
        headers["Content-Type"] = "application/json"
        
        try:
            resp = await client.post("/retrieval/grounded-answer", content=payload_str, headers=headers)
            print("Retrieval Status:", resp.status_code)
            print("Response:", json.dumps(resp.json(), indent=2))
        except Exception as e:
            print(f"Retrieval failed: {e}")

if __name__ == "__main__":
    asyncio.run(run_test())
