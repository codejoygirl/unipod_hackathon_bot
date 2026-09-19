import asyncio
from sqlalchemy.ext.asyncio import create_async_engine
import os
import sys

async def main():
    db_url = "postgresql+psycopg://community:community_secure_production_password_2026@127.0.0.1:5432/community_ai"
    engine = create_async_engine(db_url, isolation_level="AUTOCOMMIT")
    async with engine.begin() as conn:
        from sqlalchemy import text
        await conn.execute(text("DROP SCHEMA public CASCADE; CREATE SCHEMA public;"))
    print("DB reset complete.")

if __name__ == "__main__":
    asyncio.run(main())
