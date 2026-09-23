"""Unit tests for AI knowledge purge request validation helpers."""

from __future__ import annotations

from ai_service.schemas.ingestion import PurgeKnowledgeRequest


def test_purge_request_defaults():
    req = PurgeKnowledgeRequest(community_id="01communitytestid00000000")
    assert req.all is False
    assert req.include_glossary is True
    assert req.tenant_id is None


def test_purge_request_all_flag():
    req = PurgeKnowledgeRequest(all=True)
    assert req.all is True
    assert req.community_id is None
