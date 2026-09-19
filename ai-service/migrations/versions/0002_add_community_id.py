"""add_community_id_columns

Upgrade path for databases that already applied 0001 without community_id.
Fresh installs get community_id from 0001 and this migration is a no-op.

Revision ID: 0002
Revises: 0001
Create Date: 2026-09-19 23:15:00.000000

"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa
from sqlalchemy import inspect

revision: str = "0002"
down_revision: Union[str, None] = "0001"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def _has_column(table: str, column: str) -> bool:
    bind = op.get_bind()
    return column in {c["name"] for c in inspect(bind).get_columns(table)}


def upgrade() -> None:
    if not _has_column("knowledge_sources", "community_id"):
        op.add_column(
            "knowledge_sources",
            sa.Column("community_id", sa.Uuid(), nullable=True),
        )
        op.execute(
            """
            UPDATE knowledge_sources
            SET community_id = NULLIF(metadata->>'community_id', '')::uuid
            WHERE metadata ? 'community_id'
            """
        )
        op.execute(
            """
            UPDATE knowledge_sources
            SET community_id = '00000000-0000-0000-0000-000000000000'::uuid
            WHERE community_id IS NULL
            """
        )
        op.alter_column("knowledge_sources", "community_id", nullable=False)
        op.create_index(
            op.f("ix_knowledge_sources_community_id"),
            "knowledge_sources",
            ["community_id"],
        )
        op.create_index(
            "ix_sources_tenant_community",
            "knowledge_sources",
            ["tenant_id", "community_id"],
        )

    if not _has_column("knowledge_chunks", "community_id"):
        op.add_column(
            "knowledge_chunks",
            sa.Column("community_id", sa.Uuid(), nullable=True),
        )
        op.execute(
            """
            UPDATE knowledge_chunks
            SET community_id = NULLIF(metadata->>'community_id', '')::uuid
            WHERE metadata ? 'community_id'
            """
        )
        op.execute(
            """
            UPDATE knowledge_chunks AS kc
            SET community_id = ks.community_id
            FROM knowledge_sources AS ks
            WHERE kc.source_id = ks.id AND kc.community_id IS NULL
            """
        )
        op.execute(
            """
            UPDATE knowledge_chunks
            SET community_id = '00000000-0000-0000-0000-000000000000'::uuid
            WHERE community_id IS NULL
            """
        )
        op.alter_column("knowledge_chunks", "community_id", nullable=False)
        op.create_index(
            op.f("ix_knowledge_chunks_community_id"),
            "knowledge_chunks",
            ["community_id"],
        )
        op.create_index(
            "ix_chunks_tenant_community",
            "knowledge_chunks",
            ["tenant_id", "community_id"],
        )


def downgrade() -> None:
    # Keep columns on downgrade of 0002 when 0001 already defines them.
    pass
