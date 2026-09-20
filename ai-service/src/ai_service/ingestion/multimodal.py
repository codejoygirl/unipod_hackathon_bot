"""Unified pipeline for parsing text, image, audio, and video modalities."""

import logging

from ai_service.providers.factory import ModelFactory
from ai_service.schemas.ingestion import RawDocument
from ai_service.schemas.evidence import MediaLocator

from ai_service.ingestion.parsers.audio import AudioParser
from ai_service.ingestion.parsers.video import VideoParser
from ai_service.ingestion.parsers.image import ImageParser
from ai_service.ingestion.parsers.document import DocumentParser

logger = logging.getLogger(__name__)

class MultimodalProcessor:
    """Process text, images, audio, and video into searchable chunks via robust parsers."""

    async def process_document(self, doc: RawDocument) -> list[dict]:
        """Dispatch document to the appropriate parser based on source type."""
        locator = MediaLocator(media_url=doc.uri).model_dump(exclude_none=True)
        source_type = doc.source_type.lower()

        if source_type in {"image", "jpeg", "jpg", "png", "webp"}:
            return await ImageParser.parse(doc, locator)

        if source_type in {"audio", "mp3", "wav", "m4a", "ogg", "flac"}:
            return await AudioParser.parse(doc, locator)

        if source_type in {"video", "mp4", "mov", "webm", "mkv"}:
            return await VideoParser.parse(doc, locator)

        # Default to Document parsing (PDFs, text)
        return await DocumentParser.parse(doc, locator)
