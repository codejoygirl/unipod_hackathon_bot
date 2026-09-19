# System Architecture

The AI Service is an enterprise-grade retrieval-augmented generation engine built for isolated, multi-tenant environments.

## Data Flow
1. **Ingestion Pipeline**: The `MultimodalProcessor` intercepts media, dispatches it to OpenAI Whisper or Gemini Vision, and embeds the output using dense vectorization.
2. **PostgreSQL pgvector**: Vectors are stored in a schema enforcing `tenant_id`.
3. **Retrieval Engine**: A unified raw SQL CTE executes dense Cosine Similarity against `pgvector` alongside Sparse BM25 via `tsvector`, fusing them using Reciprocal Rank Fusion (RRF).
4. **Synthesis**: LLM generation strictly relies on retrieved chunks. `AnswerVerifier` validates tokens and prevents hallucination, attaching precise locators (timestamps, bounding boxes).

## Role-Based Access Control (RBAC)
FastAPI security injects claims validating `tenant_id` boundaries.

## Observability
A telemetry gap analyzer hooks into the generation lifecycle, logging unanswered or conflicting queries for knowledge base administration.
