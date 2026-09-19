import json
import logging
import pytest
from pydantic import ValidationError

from ai_service.core.config import EnvironmentType, Settings
from ai_service.core.logging import (
    StructuredJSONFormatter,
    correlation_id_ctx,
    tenant_id_ctx,
)


def test_settings_development_defaults():
    s = Settings(ENVIRONMENT=EnvironmentType.DEVELOPMENT)
    assert s.SERVICE_NAME == "ai-service"
    assert s.DEBUG is False
    assert s.DB_POOL_SIZE == 20


def test_settings_production_guards():
    # Production with default insecure secret must raise ValidationError
    with pytest.raises(ValidationError, match="Cannot boot in production with default INTERNAL_HMAC_SECRET"):
        Settings(
            ENVIRONMENT=EnvironmentType.PRODUCTION,
            INTERNAL_HMAC_SECRET="dev_insecure_secret_key_change_in_prod",
        )

    # Production with DEBUG=True must raise ValidationError
    with pytest.raises(ValidationError, match="DEBUG mode must be False in production"):
        Settings(
            ENVIRONMENT=EnvironmentType.PRODUCTION,
            INTERNAL_HMAC_SECRET="valid_production_secret_32_chars_long_entropy",
            DEBUG=True,
        )


def test_structured_json_formatter_context_injection():
    formatter = StructuredJSONFormatter()
    record = logging.LogRecord(
        name="test_logger",
        level=logging.INFO,
        pathname="test.py",
        lineno=42,
        msg="Test operation succeeded",
        args=(),
        exc_info=None,
    )

    # Set context variables
    token_corr = correlation_id_ctx.set("corr-uuid-1234")
    token_tenant = tenant_id_ctx.set("tenant-uuid-5678")

    try:
        formatted = formatter.format(record)
        data = json.loads(formatted)

        assert data["message"] == "Test operation succeeded"
        assert data["level"] == "INFO"
        assert data["correlation_id"] == "corr-uuid-1234"
        assert data["tenant_id"] == "tenant-uuid-5678"
        assert "timestamp" in data
    finally:
        correlation_id_ctx.reset(token_corr)
        tenant_id_ctx.reset(token_tenant)