"""Language detection service wrapping the pluggable LanguageDetectionModel protocol."""

from ai_service.providers.base import LanguageDetectionModel


class LanguageDetector:
    """Service wrapper for identifying query language codes."""

    def __init__(self, provider: LanguageDetectionModel) -> None:
        self._provider = provider

    async def detect_language(self, text: str) -> str:
        """Detect language, defaulting to 'en' if text is whitespace or empty."""
        cleaned = text.strip()
        if not cleaned:
            return "en"
        return await self._provider.detect(cleaned)