"""Unified pipeline for parsing text, image, audio, and video modalities."""

import logging

from ai_service.ingestion.chat_export import ChatExportNormalizer
from ai_service.providers.factory import ModelFactory
from ai_service.schemas.ingestion import RawDocument
from ai_service.schemas.evidence import MediaLocator

logger = logging.getLogger(__name__)


class MultimodalProcessor:
    """Process text, images, audio, and video into searchable chunks."""

    def __init__(self):
        self.vision_model = ModelFactory.get_vision_model()
        self.audio_model = ModelFactory.get_transcription_model()
        self.chat_normalizer = ChatExportNormalizer()

    async def process_document(self, doc: RawDocument) -> list[dict]:
        """Process a document according to its source type."""

        locator = MediaLocator(
            media_url=doc.uri
        ).model_dump(exclude_none=True)

        source_type = doc.source_type.lower()

        if source_type in {"image", "jpeg", "jpg", "png", "webp"}:
            return await self._process_image(doc, locator)

        if source_type in {"audio", "mp3", "wav", "m4a", "ogg", "flac"}:
            return await self._process_audio(doc, locator)

        if source_type in {"video", "mp4", "mov", "webm", "mkv"}:
            return await self._process_video(doc, locator)

        content = doc.content if isinstance(doc.content, str) else ""
        chatish = (
            source_type in ChatExportNormalizer.CHAT_SOURCE_HINTS
            or ChatExportNormalizer.looks_like_chat(content)
        )
        if chatish:
            export_type, windows = self.chat_normalizer.process(
                content,
                source_type_hint=source_type,
                uri=doc.uri,
            )
            if windows:
                logger.info(
                    "chat_export_normalized type=%s windows=%s uri=%s",
                    export_type.value,
                    len(windows),
                    doc.uri,
                )
                return windows
            # Detected as chat but nothing useful remained after cleaning —
            # do not fall through to indexing the raw noisy export.
            logger.info(
                "chat_export_empty_after_clean type=%s uri=%s",
                export_type.value,
                doc.uri,
            )
            return []

        return [
            {
                "content": content,
                "media_type": "text",
                "locator": locator,
            }
        ]

    async def _process_audio(
        self,
        doc: RawDocument,
        locator: dict,
    ) -> list[dict]:
        """Transcribe an audio document."""

        logger.info("Transcribing audio for document %s", doc.id)

        result = await self.audio_model.transcribe(
            uri=doc.uri,
        )

        chunks = []

        for segment in result.segments:
            chunks.append(
                {
                    "content": segment.text,
                    "media_type": "audio",
                    "locator": {
                        **locator,
                        "timestamp_seconds": segment.start,
                        "timestamp_end_seconds": segment.end,
                    },
                }
            )

        return chunks

    async def _process_video(
        self,
        doc: RawDocument,
        locator: dict,
    ) -> list[dict]:
        """Extract and transcribe the audio track from a video."""

        logger.info("Processing video for document %s", doc.id)

        # The video pipeline should first extract its audio track.
        audio_uri = await self._extract_audio_track(doc.uri)

        result = await self.audio_model.transcribe(
            uri=audio_uri,
        )

        chunks = []

        for segment in result.segments:
            chunks.append(
                {
                    "content": segment.text,
                    "media_type": "video",
                    "locator": {
                        **locator,
                        "timestamp_seconds": segment.start,
                        "timestamp_end_seconds": segment.end,
                    },
                }
            )

        return chunks

    async def _process_image(
        self,
        doc: RawDocument,
        locator: dict,
    ) -> list[dict]:
        """Run OCR/visual understanding on an image."""

        logger.info("Processing image for document %s", doc.id)

        result = await self.vision_model.analyze(
            uri=doc.uri,
        )

        return [
            {
                "content": result.text,
                "media_type": "image",
                "locator": locator,
            }
        ]

    async def _extract_audio_track(self, video_uri: str) -> str:
        """Extract the audio stream from a video.

        This should use ffmpeg or another media-processing backend.
        """
        raise NotImplementedError(
            "Video audio extraction must be implemented with ffmpeg."
        )
