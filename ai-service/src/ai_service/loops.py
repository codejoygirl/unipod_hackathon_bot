"""Event-loop factories for uvicorn (Windows + psycopg async compatibility)."""

from __future__ import annotations

import asyncio
import selectors


def selector_loop_factory() -> asyncio.AbstractEventLoop:
    """Zero-arg loop factory for ``uvicorn --loop ai_service.loops:selector_loop_factory``.

    uvicorn's built-in Windows asyncio factory returns ProactorEventLoop, which
    psycopg async refuses. Custom ``--loop`` import paths must return a loop
    instance factory (not the nested ``use_subprocess`` form used by builtins).
    """
    return asyncio.SelectorEventLoop(selectors.SelectSelector())
