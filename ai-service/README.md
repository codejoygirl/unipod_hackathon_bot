# AI Service Microservice

Enterprise Multimodal Grounded RAG with strict tenant isolation, hybrid retrieval, and interactive deep-linked citations.

## Features
- **Dual Providers**: Google Gemini and OpenAI integration.
- **Multimodal Support**: Audio, Video, Image, and Text parsing with dynamic MIME sniffing.
- **Enterprise Security**: PII Redaction, RBAC, and strict HMAC Auth.
- **RAG Observability**: Granular telemetry on classification, expansion, vector vs lexical search latency, and generation timings.
- **Advanced Integrations**: WhatsApp message parser scaffolding and robust temporal context resolution.

## Running Locally

1. `cp .env.example .env` and populate `OPENAI_API_KEY` and `GEMINI_API_KEY`.
2. Boot the stack via Docker:
   ```bash
   docker compose up -d --build
   ```
3. Run the live verification suite:
   ```bash
   uv run tests/test_live_all_modalities.py
   ```
