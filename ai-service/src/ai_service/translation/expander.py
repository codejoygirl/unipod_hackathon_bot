"""Dual-query expansion generating multilingual query variations for hybrid search."""

from collections.abc import Sequence
from dataclasses import dataclass
from ai_service.translation.detector import LanguageDetector
from ai_service.translation.translator import ProtectedTranslator


@dataclass(frozen=True)
class ExpansionResult:
    """Result of query expansion containing original and translated variations."""

    detected_language: str
    queries: list[str]


class QueryExpander:
    """Generates dual query variations across source and target language domains."""

    def __init__(
        self,
        detector: LanguageDetector,
        translator: ProtectedTranslator,
    ) -> None:
        self._detector = detector
        self._translator = translator

    async def expand_query(
        self,
        query: str,
        target_language: str | None = None,
        protected_terms: Sequence[str] = (),
    ) -> ExpansionResult:
        """Analyze query language and generate translated counterpart if necessary.

        Args:
            query: User's raw input query.
            target_language: Optional target ISO language code to expand into.
            protected_terms: Community glossary terms to preserve without translation.

        Returns:
            ExpansionResult with detected language and 1-2 distinct search queries.
        """
        cleaned_query = query.strip()
        detected_lang = await self._detector.detect_language(cleaned_query)

        # If no target language specified or target matches detected language, return single query
        if not target_language or detected_lang == target_language:
            return ExpansionResult(
                detected_language=detected_lang,
                queries=[cleaned_query],
            )

        # Translate query into target language with term protection
        translated_query = await self._translator.translate_with_glossary(
            text=cleaned_query,
            target_language=target_language,
            source_language=detected_lang,
            protected_terms=protected_terms,
        )

        # Avoid redundant query execution if translation matches original
        queries = [cleaned_query]
        if translated_query and translated_query.strip().lower() != cleaned_query.lower():
            queries.append(translated_query.strip())

        return ExpansionResult(
            detected_language=detected_lang,
            queries=queries,
        )