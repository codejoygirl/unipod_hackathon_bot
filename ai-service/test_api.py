import hmac
import hashlib
import time
import uuid
import httpx
import asyncio

secret_key = b"prod_secure_hmac_secret_key_minimum_32_bytes_entropy"

async def test_file(file_path, source_type, name):
    timestamp = str(int(time.time()))
    # For multipart/form-data, body_str = "" in dependencies.py
    message = f"{timestamp}."
    signature = hmac.new(secret_key, message.encode("utf-8"), hashlib.sha256).hexdigest()
    
    headers = {
        "X-Signature": signature,
        "X-Timestamp": timestamp
    }
    
    data = {
        "tenant_id": "00000000-0000-0000-0000-000000000001",
        "community_id": "00000000-0000-0000-0000-000000000001",
        "source_type": source_type,
        "name": name,
        "uri": f"local://{name}",
        "authority_tier": "community_discussion"
    }
    
    files = {
        "file": (name, open(file_path, "rb"), "application/octet-stream")
    }
    
    print(f"Uploading {name}...")
    async with httpx.AsyncClient(timeout=30.0) as client:
        resp = await client.post("http://127.0.0.1:8002/ingestion/multimodal", data=data, files=files, headers=headers)
        print(f"[{name}] {resp.status_code} {resp.text}")

async def main():
    await test_file("/home/welela/Music/q1_addis_01.wav", "audio", "q1_addis_01.wav")
    await asyncio.sleep(1)
    await test_file("/home/welela/Music/Pasted image (2).png", "image", "Pasted image (2).png")
    await asyncio.sleep(1)
    await test_file("/home/welela/Music/Letter (6).docx", "document", "Letter (6).docx")
    await asyncio.sleep(1)
    await test_file("/home/welela/Music/001 Session 1 - Voice agent foundations.mp4", "video", "Voice agent foundations.mp4")

if __name__ == "__main__":
    asyncio.run(main())
