"""Glossary term masking and unmasking to protect localized terms during translation."""

from collections.abc import Sequence
from dataclasses import dataclass
import re


@dataclass(frozen=True)
class MaskResult:
    """Result of masking protected glossary terms inside a text string."""

    masked_text: str
    token_to_term: dict[str, str]


class GlossaryMasker:
    """Safely masks and unmasks domain terms using deterministic sentinel tokens."""

    TOKEN_PREFIX = "__GLOSSARY_TOKEN_"
    TOKEN_SUFFIX = "__"
    # Matches tokens formatted as __GLOSSARY_TOKEN_\d+__
    SENTINEL_PATTERN = re.compile(rf"{TOKEN_PREFIX}(\d+){TOKEN_SUFFIX}")

    @classmethod
    def mask(
        cls,
        text: str,
        protected_terms: Sequence[str],
    ) -> MaskResult:
        """Replace occurrences of protected terms with unique sentinels.

        Sorts terms by length descending to ensure longer phrases are matched
        before sub-tokens. Uses Unicode-aware boundaries.
        """
        if not text or not protected_terms:
            return MaskResult(masked_text=text, token_to_term={})

        # Filter empty strings and sort by length descending (greedy match)
        valid_terms = sorted(
            {t.strip() for t in protected_terms if t.strip()},
            key=len,
            reverse=True,
        )

        token_to_term: dict[str, str] = {}
        masked_text = text

        for idx, term in enumerate(valid_terms):
            token = f"{cls.TOKEN_PREFIX}{idx}{cls.TOKEN_SUFFIX}"
            # Escape term for regex; enforce boundary checking where possible
            escaped_term = re.escape(term)
            # Use negative lookbehind/lookahead for word characters where applicable
            pattern = re.compile(
                rf"(?<!\w){escaped_term}(?!\w)",
                flags=re.IGNORECASE,
            )

            # If standard word boundaries don't match (e.g. non-Latin scripts), fallback to literal
            if not pattern.search(masked_text):
                pattern = re.compile(escaped_term, flags=re.IGNORECASE)

            if pattern.search(masked_text):
                token_to_term[token] = term
                # Replace with sentinel
                masked_text = pattern.sub(token, masked_text)

        return MaskResult(masked_text=masked_text, token_to_term=token_to_term)

    @classmethod
    def unmask(cls, text: str, token_to_term: dict[str, str]) -> str:
        """Restore sentinels back to their original or translated target terms.

        Handles whitespace variations introduced by translation tokenizers.
        """
        if not text or not token_to_term:
            return text

        result = text
        for token, original_term in token_to_term.items():
            # Handle possible spaces introduced inside sentinel by NMT engines
            token_num = token.replace(cls.TOKEN_PREFIX, "").replace(cls.TOKEN_SUFFIX, "")
            loose_token_pattern = re.compile(
                rf"__\s*GLOSSARY_TOKEN_{token_num}\s*__",
                flags=re.IGNORECASE,
            )
            result = loose_token_pattern.sub(original_term, result)

        return result