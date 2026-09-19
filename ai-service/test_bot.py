import hashlib
import hmac
import json
import os
import time
import httpx
from telegram import Update
from telegram.ext import (
    ApplicationBuilder,
    CommandHandler,
    ContextTypes,
    MessageHandler,
    filters,
)

TELEGRAM_BOT_TOKEN = os.getenv("TELEGRAM_BOT_TOKEN")
HMAC_SECRET = os.getenv(
    "INTERNAL_HMAC_SECRET",
    "prod_secure_hmac_secret_key_minimum_32_bytes_entropy",
)
RAG_URL = os.getenv("RAG_URL", "http://localhost:8000/retrieval/grounded-answer")

if not TELEGRAM_BOT_TOKEN and __name__ == "__main__":
    raise ValueError("TELEGRAM_BOT_TOKEN environment variable is missing.")


def create_hmac_headers(body: str) -> dict:
    timestamp = str(int(time.time()))
    signature = hmac.new(
        HMAC_SECRET.encode(),
        f"{timestamp}.{body}".encode(),
        hashlib.sha256,
    ).hexdigest()
    return {
        "Content-Type": "application/json",
        "X-Signature": signature,
        "X-Timestamp": timestamp,
    }


async def start(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    await update.message.reply_text(
        "👋 Welcome! Send a query to test grounded RAG retrieval."
    )


async def handle_message(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    query = update.message.text
    if not query:
        return

    await context.bot.send_chat_action(
        chat_id=update.effective_chat.id, action="typing"
    )

    payload = {
        "tenant_id": "19333900-de82-487b-beeb-5b959660425b",
        "community_ids": ["19333900-de82-487b-beeb-5b959660425b"],
        "query": query,
        "top_k": 3,
    }
    body = json.dumps(payload)
    headers = create_hmac_headers(body)

    try:
        async with httpx.AsyncClient(timeout=30.0) as client:
            resp = await client.post(RAG_URL, content=body, headers=headers)

        if resp.status_code == 200:
            data = resp.json()
            answer = data.get("answer") or data.get(
                "response", "No answer field found."
            )
            citations = data.get("citations", [])

            reply = f"💡 **Answer:**\n{answer}"
            if citations:
                reply += "\n\n📚 **Citations:**\n" + "\n".join(
                    [
                        f"• [{c.get('citation_id', 'E1')}] {c.get('source_title', 'Knowledge Base')}"
                        for c in citations
                    ]
                )
        else:
            reply = f"⚠️ AI Service returned {resp.status_code}: {resp.text}"

    except httpx.ConnectError:
        reply = "🚨 Failed to reach FastAPI. Ensure `community_ai_service` is running on port 8000."
    except Exception as exc:
        reply = f"❌ Error: {str(exc)}"

    await update.message.reply_text(reply)


def main() -> None:
    app = ApplicationBuilder().token(TELEGRAM_BOT_TOKEN).build()
    app.add_handler(CommandHandler("start", start))
    app.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, handle_message))

    print("🤖 Telegram bot is running. Waiting for messages...")
    app.run_polling()


if __name__ == "__main__":
    main()