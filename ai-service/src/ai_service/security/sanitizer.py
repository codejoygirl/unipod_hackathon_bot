"""Query sanitization, prompt injection defense, and PII masking."""

import re
import logging

logger = logging.getLogger(__name__)

# Basic heuristics for injection defense
INJECTION_PATTERNS = [
    re.compile(r"(ignore\s+all\s+previous\s+instructions)", re.IGNORECASE),
    re.compile(r"(system\s+prompt|you\s+are\s+a\b)", re.IGNORECASE),
]

# Basic PII regex masks
PII_PATTERNS = [
    (re.compile(r"\b\d{3}-\d{2}-\d{4}\b"), "[SSN REDACTED]"), # SSN
    (re.compile(r"\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b"), "[EMAIL REDACTED]"),
    (re.compile(r"\b(?:\+?1[-.●]?)?\(?([0-9]{3})\)?[-.●]?([0-9]{3})[-.●]?([0-9]{4})\b"), "[PHONE REDACTED]")
]

def sanitize_query(query: str) -> str:
    """Blocks injection attempts and masks PII in the query string."""
    # 1. Injection Defense
    for pattern in INJECTION_PATTERNS:
        if pattern.search(query):
            logger.warning(f"Prompt injection attempt detected in query: {query}")
            raise ValueError("Malicious query detected and blocked.")
    
    # 2. PII Redaction
    sanitized_query = query
    for pattern, mask in PII_PATTERNS:
        sanitized_query = pattern.sub(mask, sanitized_query)
        
    return sanitized_query
