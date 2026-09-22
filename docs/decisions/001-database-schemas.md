# Database schema ownership

Status: Accepted (Phase 1 foundation)

## Decision

Use one PostgreSQL instance with two logical schemas:

- `app` — Laravel product data (tenancy, memberships, conversations, audit, …)
- `rag` — AI service retrieval tables (chunks, embeddings, …)

## Consequences

- Laravel migrations own `app` (default `public` until schemas are split in ops).
- AI service Alembic migrations will own `rag`.
- Separate DB roles later: Laravel user (app), AI user (rag), migrator (elevated).
- Neither runtime role should be a database superuser.

## Note

Locally both Laravel and the AI service use the **same Sail Postgres database** (`zak`),
with different tables (Laravel product tables vs Alembic RAG tables / pgvector).

Schema split (`app` vs `rag`) and separate DB roles are applied later in ops; table
ownership stays the same either way.
