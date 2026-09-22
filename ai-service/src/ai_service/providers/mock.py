"""Deterministic offline mock providers implementing system protocols for testing and evaluation."""

from collections.abc import Sequence
import math
import re
from typing import Any

from ai_service.providers.base import (
    ChatMessage,
    ChatModel,
    ChatRequest,
    ChatResponse,
    EmbeddingModel,
    LanguageDetectionModel,
    RerankResult,
    RerankingModel,
    TranslationModel,
)

try:
    from ai_service.providers.base import TranscriptionModel
except ImportError:
    class TranscriptionModel:  # type: ignore
        pass


class MockChatModel(ChatModel):
    """Deterministic mock generator supporting grounded citation synthesis testing."""

    async def generate(self, request: ChatRequest) -> ChatResponse:
        import json
        user_msg = request.messages[-1].content if request.messages else ""

        if "INJECTION_ATTACK" in user_msg:
            resp = {
                "answer": "I cannot follow external system instructions.",
                "state": "GROUNDED",
                "evidence_ids_used": ["E1"]
            }
            return ChatResponse(
                content=json.dumps(resp),
                model="mock-llm-v1",
                finish_reason="stop",
            )

        if "<context>\n</context>" in user_msg or "<context></context>" in user_msg:
            resp = {
                "answer": "",
                "state": "INSUFFICIENT_EVIDENCE",
                "evidence_ids_used": []
            }
            return ChatResponse(
                content=json.dumps(resp),
                model="mock-llm-v1",
                finish_reason="stop",
            )

        evidence_matches = re.findall(
            r'<evidence id="([^"]+)"[^>]*>\s*(.*?)\s*</evidence>',
            user_msg,
            re.DOTALL,
        )

        if not evidence_matches:
            resp = {
                "answer": "",
                "state": "INSUFFICIENT_EVIDENCE",
                "evidence_ids_used": []
            }
            return ChatResponse(
                content=json.dumps(resp),
                model="mock-llm-v1",
                finish_reason="stop",
            )

        evidence_ids_used = []
        if len(evidence_matches) >= 2 and any(
            k in user_msg.lower() for k in ("conflict", "differ", "deadline", "extend")
        ):
            eid1, content1 = evidence_matches[0]
            eid2, content2 = evidence_matches[1]
            s1 = re.split(r"(?<=[.!?])\s+", content1.strip())[0].strip().rstrip(".")
            s2 = re.split(r"(?<=[.!?])\s+", content2.strip())[0].strip().rstrip(".")
            answer_content = f"{s1} [{eid1}]. {s2} [{eid2}]."
            evidence_ids_used = [eid1, eid2]
            state = "CONFLICT"
        else:
            eid, content = evidence_matches[0]
            sentences = [
                s.strip()
                for s in re.split(r"(?<=[.!?])\s+", content.strip())
                if s.strip()
            ]
            first_sentence = sentences[0].rstrip(".") if sentences else content.strip()
            answer_content = f"{first_sentence} [{eid}]."
            evidence_ids_used = [eid]
            state = "GROUNDED"
            
        # Check if the user is asking to simulate a hallucinated citation
        if "hallucinate" in user_msg.lower():
            evidence_ids_used.append("E99")
            answer_content += " [E99]"

        resp = {
            "answer": answer_content,
            "state": state,
            "evidence_ids_used": evidence_ids_used
        }

        return ChatResponse(
            content=json.dumps(resp),
            model="mock-llm-v1",
            finish_reason="stop",
            prompt_tokens=120,
            completion_tokens=30,
        )


class MockEmbedder(EmbeddingModel):
    """Deterministic mock embedder producing unit-normalized dense vectors."""

    def __init__(self, dimension: int = 1536) -> None:
        self.dimension = dimension

    def _embed_single(self, text: str) -> list[float]:
        if not text.strip():
            return [0.0] * self.dimension

        vec = [1.0] * self.dimension
        for word in text.lower().split():
            h = abs(hash(word)) % self.dimension
            vec[h] += 0.5

        norm = math.sqrt(sum(v * v for v in vec))
        return [v / norm for v in vec]

    async def embed(self, texts: Sequence[str]) -> list[list[float]]:
        return [self._embed_single(t) for t in texts]

    async def embed_query(self, text: str) -> list[float]:
        return self._embed_single(text)


class MockLanguageDetector(LanguageDetectionModel):
    """Deterministic mock language detector for common community languages."""

    async def detect(self, text: str) -> str:
        import re

        # Detect Amharic Unicode block (U+1200 - U+137F)
        if any("\u1200" <= ch <= "\u137F" for ch in text):
            return "am"

        # Arabic script (incl. Arabic Supplement / Presentation Forms)
        if any(
            ("\u0600" <= ch <= "\u06FF")
            or ("\u0750" <= ch <= "\u077F")
            or ("\u08A0" <= ch <= "\u08FF")
            for ch in text
        ):
            return "ar"

        text_lower = text.lower()

        # Latin-script community languages can be short and mixed with English
        # nouns ("hackathon"). Use orthography only, not translated keyword lists.
        # Return a sentinel instead of guessing the exact language code.
        if (
            any(ch in text_lower for ch in ("\u1eb9", "\u1ecd", "\u1e63"))
            or any(ch in text for ch in ("\u0300", "\u0301", "\u0304", "\u0307", "\u0323"))
        ):
            return "non-en"

        spanish_cues = (
            "¿" in text
            or "¡" in text
            or "ñ" in text_lower
            or any(w in text_lower for w in ("dónde", "está", "gracias", "hola", "buenos", "médico"))
        )

        french_cues = (
            any(ch in text for ch in "àâäçéèêëïîôùûüÿœ«»")
            or any(
                phrase in text_lower
                for phrase in (
                    "bonjour",
                    "bonsoir",
                    "salut",
                    "merci",
                    "est-ce",
                    "y a-t-il",
                    "qu'est-ce",
                    "s'il vous",
                    "s'il te",
                    "parlez-vous",
                    "parles-tu",
                    "vous parlez",
                    "tu parles",
                    "en français",
                    "en francais",
                    "je parle",
                    "je voudrais",
                    "avez-vous",
                    "parlez vous",
                )
            )
            or (
                re.search(r"\b(oui|non|où|quand|pourquoi|comment)\b", text_lower) is not None
                and re.search(
                    r"\b(le|la|les|un|une|des|du|est|il|elle|nous|vous|hackathon)\b",
                    text_lower,
                )
                is not None
            )
        )

        # English meta-questions about French ("do you speak french?") stay English.
        if re.search(r"\b(do you speak|can you speak|speak french|speak français)\b", text_lower):
            if not any(ch in text for ch in "àâäçéèêëïîôùûüÿœ«»") and "bonjour" not in text_lower:
                french_cues = False

        if french_cues and not spanish_cues:
            return "fr"

        if spanish_cues or any(ch in text for ch in "áéíóúü"):
            return "es"

        return "en"

class MockTranslator(TranslationModel):
    """Deterministic mock translator that preserves text and glossary sentinels."""

    async def translate(
        self,
        text: str,
        source_language: str,
        target_language: str,
    ) -> str:
        if target_language and target_language.lower() != (source_language or "").lower():
            return f"[{target_language.upper()}] {text}"
        return text


class MockReranker(RerankingModel):
    """Deterministic mock reranker scoring documents by token overlap."""

    async def rerank(
        self,
        query: str,
        documents: Sequence[str],
        top_n: int | None = None,
    ) -> list[RerankResult]:
        n = top_n if top_n is not None else len(documents)
        results: list[RerankResult] = []

        q_tokens = set(re.findall(r"\w+", query.lower()))
        for idx, doc in enumerate(documents):
            d_tokens = set(re.findall(r"\w+", doc.lower()))
            overlap = (
                len(q_tokens.intersection(d_tokens)) / len(q_tokens)
                if q_tokens
                else 0.5
            )
            score = round(max(0.1, min(0.99, 0.6 + (0.4 * overlap) - (0.01 * idx))), 4)
            results.append(RerankResult(index=idx, score=score, document=doc))

        results.sort(key=lambda r: r.score, reverse=True)
        return results[:n]


class MockTranscriptionModel(TranscriptionModel):
    """Deterministic mock audio speech-to-text provider for ingestion testing."""

    def __init__(self, *args: Any, **kwargs: Any) -> None:
        pass

    async def transcribe(
        self,
        audio_data: Any = None,
        language: str | None = None,
        *args: Any,
        **kwargs: Any,
    ) -> str:
        return "Deterministic mock transcription: Community emergency meeting recorded."

    async def transcribe_segments(
        self,
        audio_data: Any = None,
        language: str | None = None,
        *args: Any,
        **kwargs: Any,
    ) -> list[dict[str, Any]]:
        return [
            {
                "start": 0.0,
                "end": 3.0,
                "text": "Deterministic mock transcription: Community emergency meeting recorded.",
                "speaker": "Speaker 1",
            }
        ]


# Backward-compatible aliases
MockEmbeddingModel = MockEmbedder
MockRerankingModel = MockReranker
MockTranslationModel = MockTranslator
MockAudioTranscriber = MockTranscriptionModel

__all__ = [
    "MockChatModel",
    "MockEmbedder",
    "MockEmbeddingModel",
    "MockLanguageDetector",
    "MockTranslator",
    "MockTranslationModel",
    "MockReranker",
    "MockRerankingModel",
    "MockTranscriptionModel",
    "MockAudioTranscriber",
]
