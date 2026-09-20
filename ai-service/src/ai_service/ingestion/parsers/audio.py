"""Audio parsing and transcription with error handling for empty tracks."""

import logging
from typing import Any
from ai_service.providers.factory import ModelFactory
from ai_service.schemas.ingestion import RawDocument

logger = logging.getLogger(__name__)

class AudioParser:
    @classmethod
    async def parse(cls, doc: RawDocument, locator: dict) -> list[dict]:
        logger.info("Transcribing audio for document %s", doc.id)
        audio_model = ModelFactory.get_transcription_model()
        
        try:
            result = await audio_model.transcribe(source=doc.uri)
        except Exception as e:
            logger.error(f"Audio transcription failed for {doc.uri}: {e}")
            raise RuntimeError(f"Audio processing failed: {str(e)}") from e
            
        if not result.text or not result.segments:
            logger.warning(f"Audio file {doc.uri} returned empty transcription (silent or unsupported codec).")
            return []
            
        chunks = []
        for segment in result.segments:
            # Format timecode (e.g. 02:15)
            mins = int(segment.start_seconds // 60)
            secs = int(segment.start_seconds % 60)
            timecode = f"{mins:02d}:{secs:02d}"
            
            chunks.append(
                {
                    "content": segment.text,
                    "media_type": "audio",
                    "locator": {
                        **locator,
                        "timestamp_seconds": segment.start_seconds,
                        "timestamp_end_seconds": segment.end_seconds,
                        "timecode": timecode,
                    },
                }
            )
        return chunks
