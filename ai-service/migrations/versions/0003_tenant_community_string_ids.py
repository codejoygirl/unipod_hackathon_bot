"""Alter tenant/community IDs to strings so Laravel ULIDs are supported.

Revision ID: 0003
Revises: 0002
Create Date: 2026-09-19 23:56:00.000000

"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

revision: str = "0003"
down_revision: Union[str, None] = "0002"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    for table in ("knowledge_sources", "knowledge_source_versions", "knowledge_chunks", "glossary_entries"):
        op.alter_column(
            table,
            "tenant_id",
            existing_type=sa.Uuid(),
            type_=sa.String(length=36),
            existing_nullable=False,
            postgresql_using="tenant_id::text",
        )

    for table in ("knowledge_sources", "knowledge_chunks"):
        op.alter_column(
            table,
            "community_id",
            existing_type=sa.Uuid(),
            type_=sa.String(length=36),
            existing_nullable=False,
            postgresql_using="community_id::text",
        )


def downgrade() -> None:
    # Downgrade omitted: ULID strings are not valid UUID casts.
    pass
