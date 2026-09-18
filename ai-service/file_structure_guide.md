# AI Service (`ai-service/`): Architecture & Codebase Map

This document is a technical reference for RAG and Systems Engineers working on `ai-service/`. It details the component boundaries, algorithmic mechanisms, and file responsibilities across the ingestion, retrieval, synthesis, and evaluation pipelines.

---

## 1. High-Level Dataflow Architecture
[ Laravel / External Gateway ]
│
▼ (HMAC-SHA256 Signed HTTP)
┌─────────────────────────────────────────────────────────────────────────────┐
│ FastApi Ingress Layer (api/routes/, api/dependencies.py)                    │
├─────────────────────────────────────────────────────────────────────────────┤
│ • Ingestion: /ingestion/sync                                                │
│ • Inference: /retrieval/grounded-answer                                     │
│ • Liveness / Readiness: /health/live, /health/ready                         │
└──────────────┬──────────────────────────────────────────────┬───────────────┘
│                                              │
[ INGESTION PATHWAY ]                          [ RETRIEVAL PATHWAY ]
│                                              │
▼                                              ▼
┌──────────────────────────────┐              ┌──────────────────────────────┐
│ Ingestion Pipeline           │              │ Query Pipeline               │
│ (ingestion/pipeline.py)      │              │ (translation/, retrieval/)   │
│ • NFC Normalization          │              │ • Language detection         │
│ • SHA-256 Deduplication      │              │ • Glossary entity expansion  │
│ • Overlapping Chunking       │              │ • Vector search (pgvector)   │
│ • Batch Embeddings           │              │ • Full-text search (tsvector)│
│ • Atomic DB Upsert           │              │ • Reciprocal Rank Fusion     │
└──────────────┬───────────────┘              │ • Authority re-weighting     │
│                              └──────────────┬───────────────┘
│                                              │
▼                                              ▼
┌──────────────────────────────┐              ┌──────────────────────────────┐
│ Database Layer (PostgreSQL)  │              │ Generation & Citation Audit  │
│ (models/, db/)               │              │ (generation/, citations/)    │
│ • knowledge_sources          │              │ • XML context fencing        │
│ • knowledge_source_versions  │              │ • LLM synthesis (temp=0.0)   │
│ • knowledge_chunks           │              │ • Claim token-overlap audit  │
│   - HNSW index (cosine)      │              │ • Citation ID validation     │
│   - GIN index (tsvector)     │              │ • 4-State resolution         │
└──────────────────────────────┘              └──────────────┬───────────────┘
│
▼
[ GroundedAnswerResponse DTO ]

---

## 2. Directory & File Reference

### `src/ai_service/core/` (Foundational Primitives)
* **`config.py`**: Pydantic `BaseSettings` configuration. Loads environment variables (DB URLs, API keys, pool limits, HMAC secrets, similarity thresholds) with strict validation.
* **`logging.py`**: Context-aware structured JSON logging (`StructuredJSONFormatter`). Injects `correlation_id` and `tenant_id` from async `contextvars` into every log record.
* **`security.py`**: Constant-time HMAC-SHA256 signature verification (`verify_hmac_signature`). Validates request bodies against `X-Signature` and checks timestamp drift ($\pm 300\text{s}$) to prevent replay attacks.

### `src/ai_service/db/` & `src/ai_service/models/` (Data Persistence)
* **`db/base.py`**: Async SQLAlchemy 2.0 engine setup using `create_async_engine` and `async_sessionmaker`. Configures connection pool bounds (`pool_size=20`, `max_overflow=10`).
* **`models/source.py` (`KnowledgeSource`)**: Root entity for an ingested document or channel feed (e.g., PDF, Zoom transcript). Enforces tenant and community isolation.
* **`models/version.py` (`KnowledgeSourceVersion`)**: Tracks revision history and deduplication hashes (`content_sha256`). Prevents duplicate re-indexing.
* **`models/chunk.py` (`KnowledgeChunk`)**: Core retrieval target. Contains:
  * `embedding`: Dense vector (`Vector(1536)`) indexed via HNSW (`m=16`, `ef_construction=64`).
  * `tsv`: Auto-computed PostgreSQL `tsvector` column for full-text search, indexed via GIN.
  * `breadcrumbs`: Section hierarchy array (`['Policy', 'Section 2']`) for contextual ranking.
* **`models/glossary.py` (`GlossaryTerm`)**: Tenant-specific canonical terms and abbreviations for query translation and preservation.

### `src/ai_service/schemas/` (Pydantic v2 Contracts)
* **`schemas/retrieval.py`**: DTOs for search filters, candidate chunks (`CandidateChunk`), authority tier enums (`AuthorityTier`), and raw search parameters.
* **`schemas/evidence.py`**: Contracts for verified evidence chunks (`EvidenceChunk`), citation details, detected conflicts (`ConflictDetail`), and the final 4-state response payload.
* **`schemas/ingestion.py`**: Ingestion request/response payloads, status enumerations (`COMPLETED`, `SKIPPED_DUPLICATE`, `FAILED`).

### `src/ai_service/providers/` (Model Abstraction Layer)
* **`base.py`**: Protocol definitions (`ChatModel`, `EmbeddingModel`, `RerankingModel`, `TranslationModel`, `LanguageDetectionModel`) and domain structures (`ChatMessage`, `TranscriptSegment`).
* **`openai.py`**: Production OpenAI client implementing `ChatModel` and `EmbeddingModel`. Features persistent `AsyncOpenAI` connection pooling, automatic chunk batching, and full-jitter exponential backoff on HTTP 429/5xx.
* **`gemini.py`**: Google GenAI integration (`google-genai`). Implements chat generation and dense text embeddings with async retry policies.
* **`anthropic.py`**: Claude 3.5 Messages API client for high-groundedness reasoning tasks.
* **`mock.py`**: Deterministic offline mock implementations of all provider protocols for testing and CI pipelines.

### `src/ai_service/retrieval/` (Hybrid Search & Ranking)
* **`vector.py`**: Cosine distance similarity retrieval over PostgreSQL `pgvector` HNSW indexes with hard SQL tenant boundaries.
* **`lexical.py`**: PostgreSQL sparse lexical retrieval using `websearch_to_tsquery` and `ts_rank_cd` over chunk GIN indexes.
* **`rrf.py` (`ReciprocalRankFusion`)**: Merges disparate dense and sparse candidate lists into a uniform score using:
  $$RRF(d) = \sum_{m \in M} \frac{1}{k + r_m(d)} \quad (k=60)$$
* **`authority.py`**: Applies authority weights according to document source tier:
  * `OFFICIAL_ANNOUNCEMENT`: $1.00$
  * `POLICY_DOCUMENT`: $0.95$
  * `COMMUNITY_DISCUSSION`: $0.80$
* **`hybrid.py`**: Retrieval orchestrator. Runs dense and sparse searches in parallel, fuses results with RRF, applies authority weighting, and filters by tenant/community boundaries.
* **`conflict.py` (`ConflictDetector`)**: Scans retrieved passages for direct contradictions across equal-authority sources (e.g., mismatched dates, conflicting statuses like open vs. closed).
* **`state_resolver.py`**: Evaluates top candidate retrieval scores and conflict signals to route queries into one of four deterministic states: `VERIFIED`, `POSSIBLE`, `CONFLICT`, or `INSUFFICIENT_EVIDENCE`.
* **`predicates.py`**: Reusable SQLAlchemy binary expressions enforcing tenant and community boundary checks.

### `src/ai_service/reranking/`
* **`service.py`**: Cross-encoder reranking client interface. Evaluates joint query-document relevance pairs to re-order top retrieval candidates before context assembly.

### `src/ai_service/generation/` & `src/ai_service/citations/` (Anti-Hallucination)
* **`prompts.py`**: Assembles XML-fenced context payloads (`<evidence id="E#">...</evidence>`). Sanitizes user and chunk input by stripping control characters and escaping raw XML tags to block prompt injection.
* **`synthesizer.py`**: Coordinates answer synthesis. Enforces temperature $0.0$, audits citation alignment, verifies fact-anchor tokens, and formats structured outputs.
* **`citations/extractor.py`**: Parses inline citation tags (`[E1]`, `[E2]`) and decomposes answers into discrete sentence-level factual assertions.
* **`citations/validator.py` (`CitationValidator`)**: Post-generation grounding auditor:
  1. Detects and strips hallucinated citations referencing non-existent context IDs.
  2. Measures token overlap ($\ge 0.25$) between each claim and its cited chunk. Flags unanchored claims.
* **`citations/drawer.py`**: Generates evidence drawer DTOs (exact quotes, source URIs, page numbers, timestamps) for frontend rendering.

### `src/ai_service/ingestion/` & `src/ai_service/transcription/`
* **`ingestion/pipeline.py`**: Background document ingestion engine:
  * Normalizes text using Unicode NFC and strips non-printable control bytes.
  * Computes SHA-256 content hashes to skip duplicate versions before invoking embedder APIs.
  * Splits text into semantic chunks with header breadcrumb tracking.
  * Batch-embeds text and commits sources, versions, and chunks in a single atomic database transaction.
* **`transcription/parser.py` (`TranscriptParser`)**: Parses WebVTT (`<v Speaker>`), SRT, and plain conversational logs. Coalesces rapid turns by the same speaker (pause $< 3.0\text{s}$) to avoid fragmented micro-chunks.

### `src/ai_service/translation/` (Multilingual Engine)
* **`detector.py`**: Identifies incoming query language (e.g., Amharic script detection via Unicode range `U+1200`–`U+137F`).
* **`glossary.py`**: Masks protected civic and legal entities prior to translation to prevent entity distortion.
* **`expander.py`**: Enriches cross-lingual search queries with synonym and script variants.
* **`translator.py`**: Manages bi-directional translation passes between citizen inputs and canonical knowledge base languages.

### `src/ai_service/evaluations/` (Automated Quality Gates)
* **`metrics.py` (`RagTriadEvaluator`)**: Computes deterministic quality metrics without external LLM judges:
  * **Faithfulness**: Proportion of generated claims grounded in context.
  * **Context Recall**: Presence of reference ground-truth facts within retrieved chunks.
  * **Answer Relevance**: Semantic similarity between query and synthesized response.
* **`datasets.py`**: Curated golden benchmark cases representing edge cases across all four operational states.
* **`runner.py`**: CLI test runner (`python -m ai_service.evaluations.runner`). Evaluates quality gates and emits structured JSON for CI/CD pipelines.

### `src/ai_service/api/` (FastAPI Web Layer)
* **`main.py`**: Application factory, middleware setup, lifecycle handlers, and router mounts.
* **`dependencies.py`**: Injects async database sessions and enforces the `verify_hmac` security gate.
* **`routes/health.py`**: Liveness (`/health/live`) and readiness (`/health/ready`) probes for container orchestration.
* **`routes/retrieval.py`**: Primary inference endpoint (`POST /retrieval/grounded-answer`).
* **`routes/ingestion.py`**: Document ingestion endpoint (`POST /ingestion/sync`).

---

## 3. Core Invariants & Engineering Decisions

1. **Deterministic 4-State Machine**
   Queries never return ungrounded responses. The system routes every output into:
   * `VERIFIED` ($\text{score} \ge 0.82$): Strictly grounded with `[E#]` citations.
   * `POSSIBLE` ($0.75 \le \text{score} < 0.82$): Grounded in informal sources; returned with a disclaimer badge.
   * `CONFLICT`: Diverging assertions detected between high-tier sources; surfaces both views and alerts administrators.
   * `INSUFFICIENT_EVIDENCE` ($\text{score} < 0.75$): Returns an empty string and triggers a human escalation ticket.

2. **Hard Multi-Tenancy via Query Predicates**
   Tenants share PostgreSQL tables, but all queries enforce `tenant_id` and `community_id` filtering at the SQL level. Chunks from other tenants are excluded before similarity ranking occurs.

3. **XML Context Fencing & Sanitization**
   Retrieved context is enclosed in `<evidence id="E#">` tags. Raw inputs are sanitized to convert literal `<` and `>` characters into HTML entities, preventing prompt injections from breaking the XML container.

4. **Fail-Closed Verification**
   If an LLM hallucinates an invalid citation (e.g., `[E99]`) or generates a claim with insufficient token overlap against the cited passage, the validator strips the citation and marks the claim unanchored, ensuring unverified claims do not reach end users.