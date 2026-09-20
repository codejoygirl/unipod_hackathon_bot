"""Video parsing, async FFmpeg extraction, and keyframe processing."""

import asyncio
import logging
import os
import tempfile
from typing import Any
from ai_service.ingestion.parsers.audio import AudioParser
from ai_service.schemas.ingestion import RawDocument

logger = logging.getLogger(__name__)

class VideoParser:
    @classmethod
    async def _extract_audio_track(cls, video_uri: str) -> str:
        """Extract audio non-blockingly with asyncio and strict timeouts."""
        fd, audio_path = tempfile.mkstemp(suffix=".m4a")
        os.close(fd)
        
        cmd = [
            "ffmpeg", "-y", "-i", video_uri,
            "-vn", "-acodec", "aac", "-q:a", "2",
            audio_path
        ]
        
        try:
            process = await asyncio.create_subprocess_exec(
                *cmd,
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE
            )
            
            # Kill timeout (max 120s)
            try:
                stdout, stderr = await asyncio.wait_for(process.communicate(), timeout=120.0)
            except asyncio.TimeoutError:
                process.kill()
                await process.communicate()
                raise RuntimeError("FFmpeg audio extraction timed out after 120s")
                
            if process.returncode != 0:
                raise RuntimeError(f"FFmpeg failed with code {process.returncode}: {stderr.decode()}")
                
            try:
                result = await audio_model.transcribe(source=audio_path)
            except Exception as e:
                if os.path.exists(audio_path):
                    os.unlink(audio_path)
                raise e
                
            return audio_path
        except Exception as e:
            if os.path.exists(audio_path):
                os.unlink(audio_path)
            raise e

    @classmethod
    async def parse(cls, doc: RawDocument, locator: dict) -> list[dict]:
        logger.info("Processing video for document %s", doc.id)
        
        audio_uri = await cls._extract_audio_track(doc.uri)
        try:
            temp_doc = RawDocument(
                id=doc.id,
                uri=audio_uri,
                source_type="audio",
                content="binary"
            )
            
            audio_chunks = await AudioParser.parse(temp_doc, locator)
            
            for chunk in audio_chunks:
                chunk["media_type"] = "video"
                
            return audio_chunks
        finally:
            if os.path.exists(audio_uri):
                os.unlink(audio_uri)
