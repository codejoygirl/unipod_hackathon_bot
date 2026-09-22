"""Context-aware structured JSON logging with async request correlation."""

import contextvars
from datetime import datetime, timezone
import json
import logging
import sys
from typing import Any

# Asynchronous request context variables
correlation_id_ctx: contextvars.ContextVar[str | None] = contextvars.ContextVar(
    "correlation_id", default=None
)
tenant_id_ctx: contextvars.ContextVar[str | None] = contextvars.ContextVar(
    "tenant_id", default=None
)


class StructuredJSONFormatter(logging.Formatter):
    """Custom formatter outputting logs as parseable single-line JSON objects."""

    def format(self, record: logging.LogRecord) -> str:
        log_data: dict[str, Any] = {
            "timestamp": datetime.now(timezone.utc).isoformat(),
            "level": record.levelname,
            "logger": record.name,
            "message": record.getMessage(),
            "file": f"{record.filename}:{record.lineno}",
            "process_id": record.process,
            "thread_name": record.threadName,
        }

        # Inject request context if bound in current coroutine
        corr_id = correlation_id_ctx.get()
        if corr_id:
            log_data["correlation_id"] = corr_id

        t_id = tenant_id_ctx.get()
        if t_id:
            log_data["tenant_id"] = t_id

        # Attach exception tracebacks
        if record.exc_info:
            log_data["exception"] = self.formatException(record.exc_info)

        # Attach extra structured fields
        if hasattr(record, "extra_data") and isinstance(record.extra_data, dict):
            log_data.update(record.extra_data)

        return json.dumps(log_data, default=str)


def setup_logging(level: str = "INFO") -> None:
    """Initialize root handler with JSON formatter."""
    root_logger = logging.getLogger()
    root_logger.setLevel(level.upper())

    # Avoid duplicate handlers on reload
    for handler in list(root_logger.handlers):
        root_logger.removeHandler(handler)

    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(StructuredJSONFormatter())
    root_logger.addHandler(handler)

    # Silence noisy third-party loggers
    for noisy in ("uvicorn.access", "sqlalchemy.engine", "httpcore", "httpx"):
        logging.getLogger(noisy).setLevel(logging.WARNING)


def get_logger(name: str) -> logging.Logger:
    """Return named logger instance."""
    return logging.getLogger(name)