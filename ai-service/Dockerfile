# ============================================================================
# STAGE 1: Dependency Builder
# ============================================================================
FROM python:3.12-slim-bookworm AS builder

# Copy standalone uv binary directly from official distribution
COPY --from=ghcr.io/astral-sh/uv:latest /uv /uvx /bin/

WORKDIR /app

# Optimize uv execution inside Docker
ENV UV_LINK_MODE=copy \
    UV_COMPILE_BYTECODE=1 \
    UV_PYTHON_DOWNLOADS=never

# Copy dependency manifests first for maximum Docker layer caching
COPY pyproject.toml uv.lock ./

# Install locked production dependencies into /app/.venv
RUN uv sync --frozen --no-dev --no-install-project

# Copy source tree and install application
COPY --from=ghcr.io/astral-sh/uv:latest /uv /usr/local/bin/uv
COPY src/ ./src/
COPY README.md ./
RUN uv sync --frozen --no-dev

# ============================================================================
# STAGE 2: Minimal Production Runner
# ============================================================================
FROM python:3.12-slim-bookworm AS runner

WORKDIR /app

# Install runtime system dependencies (ffmpeg for video parsing)
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg && rm -rf /var/lib/apt/lists/*

# Ensure security invariants: non-root service user
RUN groupadd -g 10001 appgroup && \
    useradd -u 10001 -g appgroup -s /sbin/nologin -M appuser

# Runtime environment settings
ENV PATH="/app/.venv/bin:$PATH" \
    PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    ENVIRONMENT=production \
    PYTHONPATH=/app/src

# Copy virtual environment and application code from builder
COPY --from=builder --chown=appuser:appgroup /app/.venv /app/.venv
COPY --from=builder --chown=appuser:appgroup /app/src /app/src

# Native healthcheck using built-in urllib (avoids installing curl in slim image)
HEALTHCHECK --interval=15s --timeout=3s --start-period=5s --retries=3 \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8000/health/live', timeout=2)" || exit 1

USER appuser

EXPOSE 8000

# Run uvicorn using production settings
CMD ["uvicorn", "ai_service.main:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "2", "--timeout-keep-alive", "30"]