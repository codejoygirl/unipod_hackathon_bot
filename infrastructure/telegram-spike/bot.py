"""
Zak Telegram bot (local spike).

Official Telegram Bot API → Laravel → grounded ask.
"""

from __future__ import annotations

import asyncio
import os
import re
from html import escape as html_escape
from pathlib import Path

import httpx
from telegram import Update
from telegram.ext import ApplicationBuilder, CommandHandler, ContextTypes, MessageHandler, filters

ROOT = Path(__file__).resolve().parent


def load_env() -> None:
    env_path = ROOT / ".env"
    if not env_path.exists():
        return
    for line in env_path.read_text(encoding="utf-8").splitlines():
        t = line.strip()
        if not t or t.startswith("#") or "=" not in t:
            continue
        k, v = t.split("=", 1)
        k, v = k.strip(), v.strip()
        if k and k not in os.environ:
            os.environ[k] = v


load_env()

LARAVEL_BASE_URL = os.getenv("LARAVEL_BASE_URL", "http://localhost").rstrip("/")
SPIKE_SECRET = os.getenv("SPIKE_SECRET", "")
TELEGRAM_BOT_TOKEN = os.getenv("TELEGRAM_BOT_TOKEN", "")
TARGET_LANGUAGE = os.getenv("TARGET_LANGUAGE", "en")
# plain (default): strip *bold* markers. html: render with Telegram parse_mode=HTML.
TELEGRAM_PARSE_MODE = os.getenv("TELEGRAM_PARSE_MODE", "plain").strip().lower()


def strip_markdown_emphasis(text: str) -> str:
    text = re.sub(r"\*\*(.+?)\*\*", r"\1", text, flags=re.DOTALL)
    text = re.sub(r"\*(.+?)\*", r"\1", text, flags=re.DOTALL)
    text = re.sub(r"_(.+?)_", r"\1", text, flags=re.DOTALL)
    return text


def to_telegram_html(text: str) -> str:
    """Convert light *bold* markers to Telegram HTML; escape the rest."""
    parts: list[str] = []
    pattern = re.compile(r"\*\*(.+?)\*\*|\*(.+?)\*")
    pos = 0
    for match in pattern.finditer(text):
        parts.append(html_escape(text[pos : match.start()]))
        inner = match.group(1) or match.group(2) or ""
        parts.append(f"<b>{html_escape(inner)}</b>")
        pos = match.end()
    parts.append(html_escape(text[pos:]))
    return "".join(parts)


def mention_prefix(user) -> str:
    """Real @username or first name only. Never invent a fake label."""
    if user is None:
        return ""
    if user.username:
        return f"@{user.username}"
    if user.first_name:
        return str(user.first_name).strip()
    return ""


def mention_prefix_html(user) -> str:
    """HTML mention; skip entirely if we have no real name (cut mention if wrong)."""
    if user is None:
        return ""
    if user.username:
        return f"@{html_escape(user.username)}"
    name = (user.first_name or "").strip()
    if not name:
        return ""
    return f'<a href="tg://user?id={user.id}">{html_escape(name)}</a>'


def blend_mention(tag: str, body: str) -> str:
    """Address the person naturally. Mention is required; 'Hey' is not."""
    body = (body or "").lstrip()
    tag = (tag or "").strip()
    if not tag:
        return body
    if not body:
        return tag
    # Already addressed with this tag.
    if re.search(rf"(?i)\b{re.escape(tag)}\b", body):
        return body

    # Drop a leading bare greeting the model added; we supply the mention ourselves.
    # Keep the greeting word only when it is doing real social work (short hi).
    greeting = re.match(
        r"^(hey|hi|hello|howdy|yo)(?:\s+there)?([!.,]|\s)+",
        body,
        flags=re.I,
    )
    if greeting:
        rest = body[greeting.end() :].lstrip()
        rest = re.sub(r"^there\b[!.,\s]*", "", rest, flags=re.I).lstrip()
        # Long factual answers: just "@user, …" (no Hey).
        if rest and len(rest) > 80:
            return f"{tag}, {rest}"
        word = greeting.group(1)
        word = word[:1].upper() + word[1:].lower()
        if rest:
            return f"{word} {tag}, {rest}"
        return f"{word} {tag}!"

    # Apologies / snags: "Sorry @user, …"
    apology = re.match(r"^(sorry|apologies|whoops|oops)([!.,]|\s)+", body, flags=re.I)
    if apology:
        word = apology.group(1)
        word = word[:1].upper() + word[1:].lower()
        rest = body[apology.end() :].lstrip(" \t,")
        if rest:
            return f"{word} {tag}, {rest}"
        return f"{word} {tag}."

    # Default knowledge / normal replies: mention first, no forced Hey.
    return f"{tag}, {body}"


async def reply_text_safe(message, text: str, *, mention: bool = True) -> None:
    """Reply in-thread and address the asker inside the first sentence."""
    user = getattr(message, "from_user", None)
    plain = strip_markdown_emphasis(text)

    if TELEGRAM_PARSE_MODE == "html":
        try:
            html_body = to_telegram_html(text)
            if mention:
                html_body = blend_mention(mention_prefix_html(user), html_body)
            await message.reply_text(html_body, parse_mode="HTML")
            return
        except Exception as exc:  # noqa: BLE001
            print(f"[zak] html parse_mode failed, falling back to plain: {exc}")

    if mention:
        plain = blend_mention(mention_prefix(user), plain)
    await message.reply_text(plain)


# /start: greeting + honest scope + one next step (keep short for mobile).
# /help: commands live here, not on the welcome screen.
WELCOME = (
    "I'm Zak.\n\n"
    "Ask me anything about this community. That often includes "
    "deadlines, schedules, announcements, meeting notes, and links, "
    "and I'm happy to help with whatever else has been shared here.\n\n"
    "If I can't answer something right now, I'll let you know "
    "and come back once I have an answer.\n\n"
    "Just ask whenever you're ready.\n"
    "Need commands? Send /help."
)

HELP = (
    "Quick guide:\n\n"
    "Ask in plain language about anything in this community. "
    "Common questions cover deadlines, schedules, announcements, "
    "meeting notes, and links, but you can ask about anything "
    "that has been shared.\n\n"
    "If I can't answer yet, I'll say so and follow up when I can.\n\n"
    "You can also just say hi.\n\n"
    "/join <code> - connect with an invite code\n"
    "/share <text> - suggest something to keep\n"
    "   e.g. /share Clinic closed Friday afternoon\n\n"
    "That's all you need."
)


async def call_laravel_inbound(
    *,
    from_id: str,
    text: str,
    message_id: str | None,
    chat_type: str = "private",
    from_name: str | None = None,
    from_username: str | None = None,
    reply_to_message_id: str | None = None,
) -> str | None:
    payload = {
        "from": from_id,
        "text": text,
        "message_id": message_id,
        "target_language": TARGET_LANGUAGE,
        "chat_type": chat_type,
    }
    if from_name:
        payload["from_name"] = from_name
    if from_username:
        payload["from_username"] = from_username
    if reply_to_message_id:
        payload["reply_to_message_id"] = reply_to_message_id
    async with httpx.AsyncClient(timeout=90.0) as client:
        res = await client.post(
            f"{LARAVEL_BASE_URL}/api/v1/internal/telegram-spike/inbound",
            headers={
                "Accept": "application/json",
                "Content-Type": "application/json",
                "X-Spike-Secret": SPIKE_SECRET,
            },
            json=payload,
        )
    try:
        body = res.json()
    except Exception:
        body = {}
    if res.status_code >= 400:
        raise RuntimeError(f"Laravel inbound {res.status_code}: {body}")
    data = body.get("data") or {}
    return data.get("reply")


async def send_via_laravel(
    update: Update, context: ContextTypes.DEFAULT_TYPE, text: str
) -> None:
    if update.message is None or update.effective_chat is None:
        return
    user = update.effective_user
    from_id = str(user.id) if user else "unknown"
    chat_type = update.effective_chat.type or "private"
    from_name = None
    from_username = None
    if user:
        parts = [p for p in [user.first_name, user.last_name] if p]
        from_name = " ".join(parts).strip() or None
        from_username = user.username
    reply_to_message_id = None
    if update.message.reply_to_message is not None:
        reply_to_message_id = str(update.message.reply_to_message.message_id)
    await context.bot.send_chat_action(chat_id=update.effective_chat.id, action="typing")
    print(f"[zak] inbound from={from_id} chat={chat_type}: {text[:80]}")
    try:
        reply = await call_laravel_inbound(
            from_id=from_id,
            text=text,
            message_id=str(update.message.message_id),
            chat_type=chat_type,
            from_name=from_name,
            from_username=from_username,
            reply_to_message_id=reply_to_message_id,
        )
        if not reply:
            reply = "Sorry, I didn't quite catch that. Could you try asking another way?"
        if len(reply) > 4000:
            reply = reply[:3990] + "..."
        await reply_text_safe(update.message, reply)
    except Exception as exc:  # noqa: BLE001
        print(f"[zak] error: {exc}")
        await reply_text_safe(
            update.message,
            "I hit a snag answering that just now. "
            "Mind sending it again in a moment?",
        )


async def start_cmd(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if update.message:
        await reply_text_safe(update.message, WELCOME, mention=True)


async def help_cmd(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if update.message:
        await reply_text_safe(update.message, HELP, mention=True)


async def id_cmd(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Show this chat's Telegram id (for TELEGRAM_SPIKE_ADMIN_CHAT_ID)."""
    if update.message is None or update.effective_chat is None:
        return
    chat = update.effective_chat
    user = update.effective_user
    await reply_text_safe(
        update.message,
        f"Your Telegram chat id is: {chat.id}\n"
        f"Put that in backend/.env as:\n"
        f"TELEGRAM_SPIKE_ADMIN_CHAT_ID={chat.id}"
        + (f"\n\nUser: {user.username or user.first_name}" if user else ""),
    )


async def join_cmd(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if update.message is None:
        return
    if not context.args:
        await reply_text_safe(
            update.message,
            "Of course. Ask your admin for a join code, then send:\n"
            "/join YOURCODE",
        )
        return
    code = context.args[0].strip()
    if code.upper().startswith("JOIN-"):
        code = code[5:]
    await send_via_laravel(update, context, f"JOIN-{code}")


async def share_cmd(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if update.message is None:
        return
    body = " ".join(context.args).strip() if context.args else ""
    if not body and update.message.reply_to_message and update.message.reply_to_message.text:
        body = update.message.reply_to_message.text.strip()
    if not body:
        await reply_text_safe(
            update.message,
            "Happy to help. Send the note like this:\n"
            "/share Water will be off tomorrow morning\n\n"
            "Or reply to a message with /share.\n\n"
            "An admin will review it before it becomes part of the "
            "community knowledge base.",
        )
        return
    await send_via_laravel(update, context, f"SHARE {body}")


async def handle_text(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if update.message is None or not update.message.text:
        return
    await send_via_laravel(update, context, update.message.text.strip())


def main() -> None:
    print("===================================================")
    print(" Zak Telegram bot (local)")
    print("===================================================")

    if not TELEGRAM_BOT_TOKEN:
        raise SystemExit("TELEGRAM_BOT_TOKEN is required (from @BotFather)")
    if not SPIKE_SECRET:
        raise SystemExit("SPIKE_SECRET is required (must match TELEGRAM_SPIKE_SECRET)")

    print(f"[zak] Laravel -> {LARAVEL_BASE_URL}")

    app = ApplicationBuilder().token(TELEGRAM_BOT_TOKEN).build()
    app.add_handler(CommandHandler("start", start_cmd))
    app.add_handler(CommandHandler("help", help_cmd))
    app.add_handler(CommandHandler("id", id_cmd))
    app.add_handler(CommandHandler("join", join_cmd))
    app.add_handler(CommandHandler("share", share_cmd))
    # Keep /export as a quiet alias so old muscle memory still works.
    app.add_handler(CommandHandler("export", share_cmd))
    app.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, handle_text))
    print("[zak] Polling... message your bot in Telegram.")
    try:
        asyncio.get_event_loop()
    except RuntimeError:
        asyncio.set_event_loop(asyncio.new_event_loop())
    app.run_polling(drop_pending_updates=True)


if __name__ == "__main__":
    main()
