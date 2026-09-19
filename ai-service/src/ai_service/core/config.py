"""Centralized application settings validated via pydantic-settings."""

from enum import StrEnum
from functools import lru_cache
from pydantic import Field, model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


class EnvironmentType(StrEnum):
    DEVELOPMENT = "development"
    STAGING = "staging"
    PRODUCTION = "production"
    TEST = "test"


class Settings(BaseSettings):
    """Application configuration loaded from environment variables and .env."""

    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
        case_sensitive=True,
    )

    # Environment
    ENVIRONMENT: EnvironmentType = EnvironmentType.DEVELOPMENT
    SERVICE_NAME: str = "zak-ai"
    DEBUG: bool = False

    # Security & Inter-Service HMAC Authentication
    INTERNAL_HMAC_SECRET: str = Field(
        default="dev_insecure_secret_key_change_in_prod",
        min_length=16,
        description="Shared secret for HMAC-SHA256 inter-service signatures from Laravel.",
    )
    HMAC_TIMESTAMP_TOLERANCE_SECONDS: int = Field(
        default=300,
        ge=10,
        le=3600,
        description="Allowed clock skew window for incoming HTTP signatures.",
    )

    # Database Configuration
    DATABASE_URL: str = Field(
        default="postgresql+psycopg://community:community_pass@127.0.0.1:5432/community_ai",
        description="PostgreSQL async connection string (using psycopg or asyncpg).",
    )
    DB_POOL_SIZE: int = Field(default=20, ge=5, le=100)
    DB_MAX_OVERFLOW: int = Field(default=10, ge=0, le=50)
    DB_POOL_TIMEOUT: float = Field(default=30.0, ge=1.0)
    DB_POOL_RECYCLE: int = Field(default=1800, description="Recycle connections every 30 minutes.")

    # External AI Provider API Keys
    OPENAI_API_KEY: str | None = None
    GEMINI_API_KEY: str | None = None
    COHERE_API_KEY: str | None = None

    # Model Defaults
    EMBEDDING_MODEL_NAME: str = "text-embedding-3-small"
    EMBEDDING_DIMENSION: int = 1536
    CHAT_MODEL_NAME: str = "gpt-4o-mini"
    RERANKER_MODEL_NAME: str = "rerank-v3.5"

    @model_validator(mode="after")
    def validate_production_invariants(self) -> "Settings":
        """Prevent insecure defaults in production environments."""
        if self.ENVIRONMENT == EnvironmentType.PRODUCTION:
            if "dev_insecure" in self.INTERNAL_HMAC_SECRET:
                raise ValueError("Cannot boot in production with default INTERNAL_HMAC_SECRET.")
            if self.DEBUG:
                raise ValueError("DEBUG mode must be False in production.")
        return self


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    """Return cached Settings instance."""
    return Settings()


settings = get_settings()
