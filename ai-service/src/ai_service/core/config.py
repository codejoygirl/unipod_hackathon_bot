from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8", extra="ignore")

    app_name: str = "zak-ai"
    app_env: str = "local"
    log_level: str = "info"
    database_url: str = "postgresql+psycopg://community:community@127.0.0.1:5432/community_assistant"
    ai_service_shared_secret: str = ""

    ai_chat_provider: str = "mock"
    ai_chat_model: str = "mock-chat"
    ai_embedding_provider: str = "mock"
    ai_embedding_model: str = "mock-embedding"
    ai_rerank_provider: str = "mock"
    ai_rerank_model: str = "mock-rerank"
    ai_transcription_provider: str = "mock"
    ai_transcription_model: str = "mock-transcription"
    ai_translation_provider: str = "mock"
    ai_translation_model: str = "mock-translation"
    ai_fallback_provider: str = "mock"


settings = Settings()
