"""Image parsing and Vision LLM OCR/captioning."""

import logging
from typing import Any
from ai_service.providers.factory import ModelFactory
from ai_service.schemas.ingestion import RawDocument

logger = logging.getLogger(__name__)

class ImageParser:
    @classmethod
    async def parse(cls, doc: RawDocument, locator: dict) -> list[dict]:
        logger.info("Processing image for document %s", doc.id)
        
        vision_model = ModelFactory.get_vision_model()
        try:
            result = await vision_model.analyze(uri=doc.uri)
        except Exception as e:
            logger.error(f"Vision OCR failed for {doc.uri}: {e}")
            raise RuntimeError(f"Image processing failed: {str(e)}") from e
            
        return [
            {
                "content": result.content,
                "media_type": "image",
                "locator": locator,
            }
        ]
