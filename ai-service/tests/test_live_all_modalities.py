import asyncio
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
    timestamp = str(int(datetime.now().timestamp()))
    message = f"{timestamp}.{payload_str}".encode("utf-8")
    signature = hmac.new(SECRET.encode("utf-8"), message, hashlib.sha256).hexdigest()
    return {"X-Timestamp": timestamp, "X-Signature": signature}

def ensure_assets():
    from gtts import gTTS
    from PIL import Image, ImageDraw
    import subprocess
    
    text = "This is a test document. what is about to test."
    if not os.path.exists("test_image.jpg"):
        img = Image.new('RGB', (400, 200), color=(255, 255, 255))
        d = ImageDraw.Draw(img)
        d.text((20, 80), text, fill=(0, 0, 0))
        img.save("test_image.jpg")
    
    if not os.path.exists("test_audio.mp3"):
        tts = gTTS(text=text, lang='en')
        tts.save("test_audio.mp3")

    if not os.path.exists("test_video.mp4"):
        # Create a silent dummy video using ffmpeg
        subprocess.run(["ffmpeg", "-y", "-f", "lavfi", "-i", "color=c=black:s=320x240:d=2", "-i", "test_audio.mp3", "-c:v", "libx264", "-c:a", "aac", "-shortest", "test_video.mp4"], check=True)

async def run_test():
    print("Starting Live Multimodal Integration Suite...")
    ensure_assets()
    
    tenant_id = str(uuid.uuid4())
    community_id = str(uuid.uuid4())
    
    with open("test_image.jpg", "rb") as f: test_image = f.read()
    with open("test_audio.mp3", "rb") as f: test_audio = f.read()
    with open("test_video.mp4", "rb") as f: test_video = f.read()

    async with httpx.AsyncClient(base_url="http://127.0.0.1:8001", timeout=60.0) as client:
        
        # 1. Ingestion of Multimodal data
        tests = [
            ("image", "test_image.jpg", test_image, "image/jpeg"),
            ("audio", "test_audio.mp3", test_audio, "audio/mpeg"),
            ("video", "test_video.mp4", test_video, "video/mp4"),
        ]
        
        for src_type, filename, data, mime in tests:
            print(f"\nIngesting {src_type}...")
            files = {'file': (filename, data, mime)}
            form_data = {
                'tenant_id': tenant_id,
                'community_id': community_id,
                'source_type': src_type,
                'name': f'Test {src_type.capitalize()}',
                'uri': f'test://{src_type}/1',
            }
            resp = await client.post("/ingestion/multimodal", files=files, data=form_data, headers=get_auth_headers(""))
            print(f"Status: {resp.status_code}")
            if resp.status_code != 200:
                print(resp.text)

        # 2. Retrieval asserting rich locator metadata
        print("\nTesting Grounded Retrieval...")
        payload = {
            "tenant_id": tenant_id,
            "community_ids": [community_id],
            "query": "what is about to test",
            "top_k": 5
        }
        
        payload_str = json.dumps(payload, separators=(',', ':'))
        headers = get_auth_headers(payload_str)
        headers["Content-Type"] = "application/json"
        
        resp = await client.post("/retrieval/grounded-answer", content=payload_str, headers=headers)
        data = resp.json()
        
        print("Retrieval Status:", resp.status_code)
        print("Response:", json.dumps(data, indent=2))
        
        if data.get("validated_payload", {}).get("citations"):
            citation = data["validated_payload"]["citations"][0]
            print("Citation 0:", citation)
            assert "locator" in citation, "Missing locator in citation"
            assert "evidence_snippet" in citation, "Missing verbatim evidence snippet"
            print("SUCCESS: Rich deep-link citations confirmed.")

if __name__ == "__main__":
    asyncio.run(run_test())
