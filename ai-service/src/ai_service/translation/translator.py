"""Translation service executing sentinel masking around TranslationModel calls."""

from collections.abc import Sequence
from ai_service.providers.base import TranslationModel
from ai_service.translation.glossary import GlossaryMasker


class ProtectedTranslator:
    """Orchestrates glossary term masking, translation, and unmasking."""

    def __init__(self, provider: TranslationModel) -> None:
        self._provider = provider

    async def translate_with_glossary(
        self,
        text: str,
        target_language: str,
        source_language: str | None = None,
        protected_terms: Sequence[str] = (),
    ) -> str:
        """Translate text while guaranteeing protected terms remain uncorrupted."""
        cleaned = text.strip()
        if not cleaned:
            return ""

        if source_language == target_language:
            return cleaned

        # 1. Mask protected glossary terms
        mask_result = GlossaryMasker.mask(cleaned, protected_terms)

        # 2. Perform translation on masked string
        translated_masked = await self._provider.translate(
            text=mask_result.masked_text,
            target_language=target_language,
            source_language=source_language,
        )

        # 3. Unmask sentinels back to original protected terms
        return GlossaryMasker.unmask(translated_masked, mask_result.token_to_term)