"""Destructive purge of knowledge sources, versions, chunks (embeddings), glossary."""

from __future__ import annotations

from sqlalchemy import delete, func, select
from sqlalchemy.ext.asyncio import AsyncSession

from ai_service.models.chunk import KnowledgeChunk
from ai_service.models.glossary import GlossaryEntry
from ai_service.models.source import KnowledgeSource
from ai_service.models.version import KnowledgeSourceVersion
from ai_service.schemas.ingestion import PurgeKnowledgeRequest, PurgeKnowledgeResponse


async def purge_knowledge(
    session: AsyncSession,
    request: PurgeKnowledgeRequest,
) -> PurgeKnowledgeResponse:
    """Delete AI-side knowledge + embeddings for the requested scope.

    Postgres ON DELETE CASCADE on chunks/versions means deleting sources is enough
    for those child rows; we still count children first for the ops reply.
    """
    community_id = (request.community_id or "").strip() or None
    tenant_id = (request.tenant_id or "").strip() or None

    if request.all:
        if community_id or tenant_id:
            raise ValueError("Do not combine all=true with community_id or tenant_id.")
        scope = "all"
        source_filter = True
        glossary_filter = True
    elif community_id:
        scope = f"community:{community_id}"
        source_filter = KnowledgeSource.community_id == community_id
        # Glossary has no community column — only wipe it on tenant/all.
        glossary_filter = None
        if tenant_id:
            source_filter = (KnowledgeSource.community_id == community_id) & (
                KnowledgeSource.tenant_id == tenant_id
            )
    elif tenant_id:
        scope = f"tenant:{tenant_id}"
        source_filter = KnowledgeSource.tenant_id == tenant_id
        glossary_filter = GlossaryEntry.tenant_id == tenant_id
    else:
        raise ValueError("Provide community_id, tenant_id, or all=true.")

    if request.all:
        chunks_deleted = int(
            await session.scalar(select(func.count()).select_from(KnowledgeChunk)) or 0
        )
        versions_deleted = int(
            await session.scalar(select(func.count()).select_from(KnowledgeSourceVersion)) or 0
        )
        sources_deleted = int(
            await session.scalar(select(func.count()).select_from(KnowledgeSource)) or 0
        )
        await session.execute(delete(KnowledgeChunk))
        await session.execute(delete(KnowledgeSourceVersion))
        await session.execute(delete(KnowledgeSource))
    else:
        source_ids_result = await session.execute(
            select(KnowledgeSource.id).where(source_filter)
        )
        source_ids = list(source_ids_result.scalars().all())
        sources_deleted = len(source_ids)

        if source_ids:
            chunks_deleted = int(
                await session.scalar(
                    select(func.count())
                    .select_from(KnowledgeChunk)
                    .where(KnowledgeChunk.source_id.in_(source_ids))
                )
                or 0
            )
            versions_deleted = int(
                await session.scalar(
                    select(func.count())
                    .select_from(KnowledgeSourceVersion)
                    .where(KnowledgeSourceVersion.source_id.in_(source_ids))
                )
                or 0
            )
            await session.execute(
                delete(KnowledgeChunk).where(KnowledgeChunk.source_id.in_(source_ids))
            )
            await session.execute(
                delete(KnowledgeSourceVersion).where(
                    KnowledgeSourceVersion.source_id.in_(source_ids)
                )
            )
            await session.execute(delete(KnowledgeSource).where(source_filter))
        else:
            chunks_deleted = 0
            versions_deleted = 0

    glossary_deleted = 0
    if request.include_glossary and glossary_filter is not None:
        if request.all:
            glossary_deleted = int(
                await session.scalar(select(func.count()).select_from(GlossaryEntry)) or 0
            )
            await session.execute(delete(GlossaryEntry))
        else:
            glossary_deleted = int(
                await session.scalar(
                    select(func.count()).select_from(GlossaryEntry).where(glossary_filter)
                )
                or 0
            )
            if glossary_deleted:
                await session.execute(delete(GlossaryEntry).where(glossary_filter))

    await session.commit()

    return PurgeKnowledgeResponse(
        sources_deleted=sources_deleted,
        versions_deleted=versions_deleted,
        chunks_deleted=chunks_deleted,
        glossary_deleted=glossary_deleted,
        scope=scope,
        message=(
            f"Purged AI knowledge for {scope}: "
            f"{sources_deleted} sources, {versions_deleted} versions, "
            f"{chunks_deleted} chunks/embeddings, {glossary_deleted} glossary rows."
        ),
    )
