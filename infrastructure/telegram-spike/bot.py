"""
Zak Telegram bot (local spike).

Official Telegram Bot API → Laravel → grounded ask.
"""

from __future__ import annotations

import asyncio
import base64
import os
import re
from html import escape as html_escape
from pathlib import Path
from typing import Any

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


def _is_private_chat(message) -> bool:
    chat = getattr(message, "chat", None)
    chat_type = (getattr(chat, "type", None) or "").lower()
    return chat_type in ("private", "")


def natural_name(user) -> str:
    """Plain first name only (never @username). Empty if unknown."""
    if user is None:
        return ""
    name = (user.first_name or "").strip()
    return name


def mention_prefix(user, *, private: bool = False) -> str:
    """Group: @username or first name. Private: plain first name only, never @."""
    if user is None:
        return ""
    if private:
        return natural_name(user)
    if user.username:
        return f"@{user.username}"
    return natural_name(user)


def mention_prefix_html(user, *, private: bool = False) -> str:
    """HTML address. Private: plain escaped name. Groups: @user or deep-link name."""
    if user is None:
        return ""
    if private:
        name = natural_name(user)
        return html_escape(name) if name else ""
    if user.username:
        return f"@{html_escape(user.username)}"
    name = natural_name(user)
    if not name:
        return ""
    return f'<a href="tg://user?id={user.id}">{html_escape(name)}</a>'


def blend_mention(tag: str, body: str, *, force: bool = True) -> str:
    """Optionally address the person. Private chats pass force=False (no prefix)."""
    body = (body or "").lstrip()
    # Strip leftover @handles the model may have prefixed.
    body = re.sub(r"^@[\w_]{2,64}[,\s]+", "", body)
    tag = (tag or "").strip()
    if not force or not tag:
        return body
    if not body:
        return tag
    if re.search(rf"(?i)\b{re.escape(tag)}\b", body):
        return body

    greeting = re.match(
        r"^(hey|hi|hello|howdy|yo)(?:\s+there)?([!.,]|\s)+",
        body,
        flags=re.I,
    )
    if greeting:
        rest = body[greeting.end() :].lstrip()
        rest = re.sub(r"^there\b[!.,\s]*", "", rest, flags=re.I).lstrip()
        if rest and len(rest) > 80:
            return f"{tag}, {rest}"
        word = greeting.group(1)
        word = word[:1].upper() + word[1:].lower()
        if rest:
            return f"{word} {tag}, {rest}"
        return f"{word} {tag}!"

    apology = re.match(r"^(sorry|apologies|whoops|oops)([!.,]|\s)+", body, flags=re.I)
    if apology:
        word = apology.group(1)
        word = word[:1].upper() + word[1:].lower()
        rest = body[apology.end() :].lstrip(" \t,")
        if rest:
            return f"{word} {tag}, {rest}"
        return f"{word} {tag}."

    return f"{tag}, {body}"


async def reply_text_safe(message, text: str, *, mention: bool | None = None) -> None:
    """Reply in-thread. Private: natural (no @, no forced name). Groups: address asker."""
    user = getattr(message, "from_user", None)
    private = _is_private_chat(message)
    # Default: never force address in private; groups still tag the asker.
    if mention is None:
        mention = not private

    plain = strip_markdown_emphasis(text)
    tag = mention_prefix(user, private=private) if mention else ""

    if TELEGRAM_PARSE_MODE == "html":
        try:
            html_body = to_telegram_html(text)
            if mention:
                html_body = blend_mention(
                    mention_prefix_html(user, private=private),
                    html_body,
                    force=not private,
                )
            elif private:
                html_body = blend_mention("", html_body, force=False)
            await message.reply_text(html_body, parse_mode="HTML")
            return
        except Exception as exc:  # noqa: BLE001
            print(f"[zak] html parse_mode failed, falling back to plain: {exc}")

    if mention:
        plain = blend_mention(tag, plain, force=not private)
    else:
        plain = blend_mention("", plain, force=False)
    await message.reply_text(plain)


# /start + /help: same Laravel card as WhatsApp (abilities + rotating examples).


async def start_cmd(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if update.message:
        await send_via_laravel(update, context, "/start")


async def help_cmd(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if update.message:
        await send_via_laravel(update, context, "/help")


async def call_laravel_inbound(
    *,
    from_id: str,
    text: str,
    message_id: str | None,
    chat_type: str = "private",
    chat_id: str | None = None,
    from_name: str | None = None,
    from_username: str | None = None,
    reply_to_message_id: str | None = None,
    bot_mentioned: bool = False,
    reply_to_bot: bool = False,
    quoted_text: str | None = None,
    media: dict[str, Any] | None = None,
) -> str | None:
    payload = {
        "from": from_id,
        "text": text,
        "message_id": message_id,
        "target_language": TARGET_LANGUAGE,
        "chat_type": chat_type,
        "bot_mentioned": bot_mentioned,
        "reply_to_bot": reply_to_bot,
    }
    if chat_id:
        payload["chat_id"] = chat_id
    if from_name:
        payload["from_name"] = from_name
    if from_username:
        payload["from_username"] = from_username
    if reply_to_message_id:
        payload["reply_to_message_id"] = reply_to_message_id
    if quoted_text:
        payload["quoted_text"] = quoted_text[:1500]
    if media:
        payload["media"] = media
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
    if res.status_code >= 400 and res.status_code != 202:
        raise RuntimeError(f"Laravel inbound {res.status_code}: {body}")
    data = body.get("data") or {}
    if data.get("queued") is True:
        print(f"[zak] laravel queued=yes from={from_id} (worker will send)")
        return None
    return data.get("reply")


def _message_mentions_bot(message, bot_id: int | None, bot_username: str | None) -> bool:
    if message is None:
        return False
    text = message.text or message.caption or ""
    if bot_username:
        uname = bot_username.lstrip("@").lower()
        if uname and re.search(rf"@{re.escape(uname)}\b", text, flags=re.I):
            return True
    entities = list(message.entities or message.caption_entities or [])
    for ent in entities:
        if ent.type == "mention" and bot_username:
            frag = text[ent.offset : ent.offset + ent.length]
            if frag.lstrip("@").lower() == bot_username.lstrip("@").lower():
                return True
        if ent.type == "text_mention" and bot_id and ent.user and ent.user.id == bot_id:
            return True
    return False


# Keep binary under ~2.5MB so base64 stays within Laravel validation (3.5M chars).
_MAX_VOICE_BYTES = 2_500_000


async def _download_voice_media(message, bot) -> dict[str, Any] | None:
    """Download Telegram voice/audio as base64 for Laravel STT."""
    kind = None
    file_id = None
    mime = "audio/ogg"
    filename = "voice.ogg"
    if message.voice is not None:
        kind = "voice"
        file_id = message.voice.file_id
        mime = message.voice.mime_type or "audio/ogg"
        filename = "voice.ogg"
    elif message.audio is not None:
        kind = "audio"
        file_id = message.audio.file_id
        mime = message.audio.mime_type or "audio/mpeg"
        filename = message.audio.file_name or "voice.m4a"
    if not kind or not file_id:
        return None
    try:
        tg_file = await bot.get_file(file_id)
        raw = bytes(await tg_file.download_as_bytearray())
    except Exception as exc:  # noqa: BLE001
        print(f"[zak] voice download failed: {exc}")
        return None
    if not raw:
        return None
    if len(raw) > _MAX_VOICE_BYTES:
        print(f"[zak] voice too large ({len(raw)} bytes); skip")
        return None
    print(
        f"[zak] voice downloaded kind={kind} bytes={len(raw)} "
        f"mime={mime} file={filename}"
    )
    return {
        "kind": kind,
        "mime_type": mime,
        "filename": filename,
        "data_base64": base64.b64encode(raw).decode("ascii"),
    }


def _reply_targets_bot(message, bot_id: int | None) -> tuple[bool, str | None]:
    replied = message.reply_to_message if message else None
    if replied is None:
        return False, None
    quoted = ((replied.text or replied.caption or "")).strip() or None
    from_user = replied.from_user
    if bot_id and from_user and from_user.id == bot_id:
        return True, quoted
    return False, quoted


async def send_via_laravel(
    update: Update,
    context: ContextTypes.DEFAULT_TYPE,
    text: str,
    media: dict[str, Any] | None = None,
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

    bot = context.bot
    bot_id = bot.id if bot else None
    bot_username = bot.username if bot else None
    reply_to_bot, quoted_text = _reply_targets_bot(update.message, bot_id)
    bot_mentioned = _message_mentions_bot(update.message, bot_id, bot_username) or reply_to_bot

    in_group = chat_type in ("group", "supergroup")
    # Groups: only voice/text when directed (mention or reply-to-bot). Private: always.
    if in_group and not bot_mentioned and not reply_to_bot and not (text or "").strip().startswith("/"):
        # Undirected group chatter (incl. voice) — stay silent.
        if media and not (text or "").strip():
            print(f"[zak] silent voice from={from_id} chat={chat_type} (not directed)")
            return

    await context.bot.send_chat_action(chat_id=update.effective_chat.id, action="typing")
    preview = (text or ("[voice]" if media else ""))[:80]
    print(
        f"[zak] inbound from={from_id} chat={chat_type}"
        f" mentioned={bot_mentioned} reply_to_bot={reply_to_bot}"
        f"{' media=' + str(media.get('kind')) if media else ''}: {preview}"
    )
    try:
        reply = await call_laravel_inbound(
            from_id=from_id,
            text=text,
            message_id=str(update.message.message_id),
            chat_type=chat_type,
            chat_id=str(update.effective_chat.id),
            from_name=from_name,
            from_username=from_username,
            reply_to_message_id=reply_to_message_id,
            bot_mentioned=bot_mentioned,
            reply_to_bot=reply_to_bot,
            quoted_text=quoted_text,
            media=media,
        )
        if not reply:
            # Laravel returned null (silent / not directed at Zak) — do not nag.
            print(f"[zak] silent from={from_id} chat={chat_type} media={bool(media)}")
            return
        print(
            f"[zak] laravel reply chars={len(reply)} "
            f"preview={reply[:160].replace(chr(10), ' ')!r}"
        )
        if len(reply) > 4000:
            reply = reply[:3990] + "..."
        await reply_text_safe(update.message, reply)
    except Exception as exc:  # noqa: BLE001
        print(f"[zak] error: {exc}")
        await reply_text_safe(
            update.message,
            "Thanks — I've got your message 🙂\n\n"
            "I'll reply as soon as I can. No need to send it again.",
        )


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
            "Share something the community should know, like:\n"
            "/share Water will be off tomorrow morning\n\n"
            "Or reply to a message with /share.\n\n"
            "An admin will review it before Zak can use it in answers.",
        )
        return
    await send_via_laravel(update, context, f"SHARE {body}")


async def export_cmd(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Admin-only ingest — not the same as member /share."""
    if update.message is None:
        return
    body = " ".join(context.args).strip() if context.args else ""
    if not body and update.message.reply_to_message and update.message.reply_to_message.text:
        body = update.message.reply_to_message.text.strip()
    if not body:
        await reply_text_safe(
            update.message,
            "Admins can ingest text like this:\n"
            "/export Pasted announcement or notes\n\n"
            "Members should use /share instead (goes to admin review).",
        )
        return
    await send_via_laravel(update, context, f"EXPORT {body}")


async def ask_cmd(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if update.message is None:
        return
    body = " ".join(context.args).strip() if context.args else ""
    if not body and update.message.reply_to_message and update.message.reply_to_message.text:
        body = update.message.reply_to_message.text.strip()
    if not body:
        await reply_text_safe(
            update.message,
            "Of course. Send it like this:\n"
            "/ask Can someone approve my UniPods form?\n\n"
            "Or reply to a message with /ask.\n\n"
            "An admin will see it and can reply from their side.",
        )
        return
    await send_via_laravel(update, context, f"ASK {body}")


async def handle_text(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if update.message is None or not update.message.text:
        return
    await send_via_laravel(update, context, update.message.text.strip())


async def handle_voice(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Voice note or audio → download → Laravel STT → same text pipeline."""
    if update.message is None or update.effective_chat is None:
        return
    chat_type = update.effective_chat.type or "private"
    in_group = chat_type in ("group", "supergroup")
    bot = context.bot
    bot_id = bot.id if bot else None
    bot_username = bot.username if bot else None
    reply_to_bot, _ = _reply_targets_bot(update.message, bot_id)
    bot_mentioned = _message_mentions_bot(update.message, bot_id, bot_username) or reply_to_bot
    caption = (update.message.caption or "").strip()
    if in_group and not bot_mentioned and not reply_to_bot:
        print(f"[zak] silent voice chat={chat_type} (group needs @mention or reply-to-bot)")
        return

    media = await _download_voice_media(update.message, context.bot)
    if media is None and not caption:
        print("[zak] voice: download empty and no caption")
        await reply_text_safe(
            update.message,
            "I couldn't download that voice note. Mind trying again?",
        )
        return
    print(
        f"[zak] voice → Laravel caption={caption[:80]!r} "
        f"media={'yes' if media else 'no'} mentioned={bot_mentioned} "
        f"reply_to_bot={reply_to_bot}"
    )
    await send_via_laravel(update, context, caption, media=media)


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
    app.add_handler(CommandHandler("export", export_cmd))
    app.add_handler(CommandHandler("ask", ask_cmd))
    app.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, handle_text))
    app.add_handler(MessageHandler(filters.VOICE | filters.AUDIO, handle_voice))
    print("[zak] Polling... message your bot in Telegram.")
    try:
        asyncio.get_event_loop()
    except RuntimeError:
        asyncio.set_event_loop(asyncio.new_event_loop())
    app.run_polling(drop_pending_updates=True)


if __name__ == "__main__":
    main()
