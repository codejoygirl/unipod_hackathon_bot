"""initial_rag_schema

Revision ID: 0001
Revises: 
Create Date: 2026-09-18 12:00:00.000000

"""
from typing import Sequence, Union
from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql
import pgvector.sqlalchemy

revision: str = "0001"
down_revision: Union[str, None] = None
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    # 1. Enable Required Extensions
    op.execute('CREATE EXTENSION IF NOT EXISTS "uuid-ossp";')
    op.execute('CREATE EXTENSION IF NOT EXISTS "pg_trgm";')
    op.execute('CREATE EXTENSION IF NOT EXISTS "vector";')

    # 2. knowledge_sources
    op.create_table(
        "knowledge_sources",
        sa.Column("id", sa.Uuid(), nullable=False, default=sa.text("uuid_generate_v4()")),
        sa.Column("tenant_id", sa.Uuid(), nullable=False),
        sa.Column("uri", sa.String(length=1024), nullable=False),
        sa.Column("name", sa.String(length=255), nullable=False),
        sa.Column("source_type", sa.String(length=50), nullable=False),
        sa.Column("status", sa.String(length=50), nullable=False, server_default="active"),
        sa.Column("metadata", postgresql.JSONB(astext_type=sa.Text()), nullable=False, server_default="{}"),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_knowledge_sources")),
    )
    op.create_index(op.f("ix_knowledge_sources_tenant_id"), "knowledge_sources", ["tenant_id"])
    op.create_index("ix_sources_tenant_uri", "knowledge_sources", ["tenant_id", "uri"], unique=True)
    op.create_index("ix_sources_tenant_status", "knowledge_sources", ["tenant_id", "status"])

    # 3. knowledge_source_versions
    op.create_table(
        "knowledge_source_versions",
        sa.Column("id", sa.Uuid(), nullable=False, default=sa.text("uuid_generate_v4()")),
        sa.Column("tenant_id", sa.Uuid(), nullable=False),
        sa.Column("source_id", sa.Uuid(), nullable=False),
        sa.Column("version_number", sa.Integer(), nullable=False, server_default="1"),
        sa.Column("content_sha256", sa.String(length=64), nullable=False),
        sa.Column("storage_path", sa.String(length=1024), nullable=False),
        sa.Column("metadata", postgresql.JSONB(astext_type=sa.Text()), nullable=False, server_default="{}"),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
        sa.ForeignKeyConstraint(["source_id"], ["knowledge_sources.id"], name=op.f("fk_knowledge_source_versions_source_id_knowledge_sources"), ondelete="CASCADE"),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_knowledge_source_versions")),
    )
    op.create_index(op.f("ix_knowledge_source_versions_tenant_id"), "knowledge_source_versions", ["tenant_id"])
    op.create_index("ix_source_versions_unique_num", "knowledge_source_versions", ["tenant_id", "source_id", "version_number"], unique=True)
    op.create_index("ix_source_versions_hash", "knowledge_source_versions", ["tenant_id", "content_sha256"])

    # 4. knowledge_chunks
    op.create_table(
        "knowledge_chunks",
        sa.Column("id", sa.Uuid(), nullable=False, default=sa.text("uuid_generate_v4()")),
        sa.Column("tenant_id", sa.Uuid(), nullable=False),
        sa.Column("source_id", sa.Uuid(), nullable=False),
        sa.Column("version_id", sa.Uuid(), nullable=False),
        sa.Column("chunk_index", sa.Integer(), nullable=False),
        sa.Column("content", sa.Text(), nullable=False),
        sa.Column("content_sha256", sa.String(length=64), nullable=False),
        sa.Column("token_count", sa.Integer(), nullable=False),
        sa.Column("breadcrumbs", postgresql.JSONB(astext_type=sa.Text()), nullable=False, server_default="[]"),
        sa.Column("metadata", postgresql.JSONB(astext_type=sa.Text()), nullable=False, server_default="{}"),
        sa.Column("embedding", pgvector.sqlalchemy.Vector(), nullable=False),
        sa.Column("tsv", postgresql.TSVECTOR(), sa.Computed("to_tsvector('simple', content)", persisted=True), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
        sa.ForeignKeyConstraint(["source_id"], ["knowledge_sources.id"], name=op.f("fk_knowledge_chunks_source_id_knowledge_sources"), ondelete="CASCADE"),
        sa.ForeignKeyConstraint(["version_id"], ["knowledge_source_versions.id"], name=op.f("fk_knowledge_chunks_version_id_knowledge_source_versions"), ondelete="CASCADE"),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_knowledge_chunks")),
    )
    op.create_index(op.f("ix_knowledge_chunks_tenant_id"), "knowledge_chunks", ["tenant_id"])
    op.create_index("ix_chunks_tenant_hash", "knowledge_chunks", ["tenant_id", "content_sha256"])
    op.create_index("ix_chunks_tenant_version", "knowledge_chunks", ["tenant_id", "version_id"])
    op.create_index("ix_chunks_tsv", "knowledge_chunks", ["tsv"], postgresql_using="gin")


    # 5. glossary_entries
    op.create_table(
        "glossary_entries",
        sa.Column("id", sa.Uuid(), nullable=False, default=sa.text("uuid_generate_v4()")),
        sa.Column("tenant_id", sa.Uuid(), nullable=False),
        sa.Column("term", sa.String(length=255), nullable=False),
        sa.Column("language_code", sa.String(length=10), nullable=False),
        sa.Column("target_term", sa.String(length=255), nullable=False),
        sa.Column("definition", sa.Text(), nullable=True),
        sa.Column("is_active", sa.Boolean(), nullable=False, server_default="true"),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_glossary_entries")),
    )
    op.create_index(op.f("ix_glossary_entries_tenant_id"), "glossary_entries", ["tenant_id"])
    op.create_index("ix_glossary_tenant_term_lang", "glossary_entries", ["tenant_id", "term", "language_code"], unique=True)


def downgrade() -> None:
    op.drop_table("glossary_entries")
    op.drop_table("knowledge_chunks")
    op.drop_table("knowledge_source_versions")
    op.drop_table("knowledge_sources")