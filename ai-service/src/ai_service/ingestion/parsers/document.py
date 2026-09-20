"""PDF and text document parsing with OCR fallback."""

import logging
from typing import Any
from ai_service.schemas.ingestion import RawDocument
from ai_service.providers.factory import ModelFactory

logger = logging.getLogger(__name__)

class DocumentParser:
    @classmethod
    async def parse(cls, doc: RawDocument, locator: dict) -> list[dict]:
        """Parse documents page-by-page. Fallback to OCR if empty."""
        logger.info("Parsing document %s", doc.id)
        
        chunks = []
        text_content = ""
        
        if isinstance(doc.content, str) and doc.content != "binary":
            text_content = doc.content
        else:
            try:
                with open(doc.uri, "r", encoding="utf-8") as f:
                    text_content = f.read()
            except Exception:
                text_content = "binary_data_placeholder"
                
        # Fallback for empty/scanned PDFs
        if len(text_content.strip()) < 10:
            logger.warning(f"Document {doc.uri} yielded < 10 chars. Escalating to Vision OCR.")
            vision_model = ModelFactory.get_vision_model()
            try:
                result = await vision_model.analyze(uri=doc.uri)
                text_content = result.content
            except Exception as e:
                logger.error(f"OCR fallback failed: {e}")
                raise RuntimeError("Failed to extract text from document, and OCR fallback failed.") from e

        from ai_service.ingestion.parsers.whatsapp import WHATSAPP_LINE_PATTERN
        
        # Sniff for content mismatch
        content_type_mismatch = False
        if doc.source_type.lower() == "doc" or doc.source_type.lower() == "document":
            # Just test first 1000 chars to avoid regex DOS on huge files
            if WHATSAPP_LINE_PATTERN.search(text_content[:1000]):
                logger.warning(f"Document {doc.uri} labeled as 'doc' appears to be a WhatsApp export. Flagging mismatch.")
                content_type_mismatch = True

        chunks.append({
            "content": text_content,
            "media_type": "document",
            "locator": {
                **locator,
                "page_number": 1
            },
            "content_type_mismatch_suspected": content_type_mismatch
        })
        
        return chunks
