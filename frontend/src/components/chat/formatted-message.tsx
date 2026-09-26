"use client";

import React, { useState } from "react";
import { normalizeChatMarkdown, restoreReadableSpacing } from "@/lib/web-chat/readable-text";
import { normalizeWhatsAppEmphasis } from "@/lib/web-chat/whatsapp-emphasis";

type FormattedMessageProps = {
  content: string;
  isUser?: boolean;
};

type LinkInfo = {
  url: string;
  label: string;
  type: "teams" | "zoom" | "meet" | "whatsapp" | "telegram" | "linkedin" | "youtube" | "generic";
};

function parseUrl(rawUrl: string): LinkInfo {
  let url = rawUrl;
  // Clean trailing punctuation
  while (/[.,!?;:)]$/.test(url)) {
    url = url.slice(0, -1);
  }

  try {
    const parsed = new URL(url);
    const host = parsed.hostname.toLowerCase();

    if (host.includes("teams.microsoft.com") || host.includes("teams.live.com")) {
      return {
        url,
        label: "Join Microsoft Teams Meeting",
        type: "teams",
      };
    }
    if (host.includes("zoom.us")) {
      return {
        url,
        label: "Join Zoom Meeting",
        type: "zoom",
      };
    }
    if (host.includes("meet.google.com")) {
      return {
        url,
        label: "Join Google Meet",
        type: "meet",
      };
    }
    if (
      host.includes("wa.me")
      || host.includes("chat.whatsapp.com")
      || host.includes("api.whatsapp.com")
    ) {
      return {
        url,
        label: "Open on WhatsApp",
        type: "whatsapp",
      };
    }
    if (host.includes("t.me")) {
      return {
        url,
        label: "Open on Telegram",
        type: "telegram",
      };
    }
    if (parsed.searchParams.has("phone") && !host.includes("api.")) {
      return {
        url,
        label: "Open web chat",
        type: "generic",
      };
    }
    if (host.includes("linkedin.com")) {
      return {
        url,
        label: "View on LinkedIn",
        type: "linkedin",
      };
    }
    if (host.includes("youtube.com") || host.includes("youtu.be")) {
      return {
        url,
        label: "Watch on YouTube",
        type: "youtube",
      };
    }

    const displayHost = host.replace(/^www\./, "");
    const cleanPath = parsed.pathname.length > 25 ? `${parsed.pathname.slice(0, 25)}…` : parsed.pathname;
    return {
      url,
      label: `${displayHost}${cleanPath === "/" ? "" : cleanPath}`,
      type: "generic",
    };
  } catch {
    return {
      url,
      label: url.length > 40 ? `${url.slice(0, 40)}…` : url,
      type: "generic",
    };
  }
}

function getLinkBadgeClass(type: LinkInfo["type"]): string {
  switch (type) {
    case "teams":
      return "bg-indigo-50 text-indigo-700 hover:bg-indigo-100 border-indigo-200/80 dark:bg-indigo-950/60 dark:text-indigo-300 dark:border-indigo-800";
    case "zoom":
      return "bg-sky-50 text-sky-700 hover:bg-sky-100 border-sky-200/80 dark:bg-sky-950/60 dark:text-sky-300 dark:border-sky-800";
    case "meet":
      return "bg-blue-50 text-blue-700 hover:bg-blue-100 border-blue-200/80 dark:bg-blue-950/60 dark:text-blue-300 dark:border-blue-800";
    case "whatsapp":
      return "bg-blue-50 text-blue-800 hover:bg-blue-100 border-blue-300 font-semibold dark:bg-blue-950/60 dark:text-blue-300 dark:border-blue-800";
    case "telegram":
      return "bg-sky-50 text-sky-800 hover:bg-sky-100 border-sky-300 font-semibold dark:bg-sky-950/60 dark:text-sky-300 dark:border-sky-800";
    default:
      return "bg-zinc-100/90 text-zinc-700 hover:bg-zinc-200/80 border-zinc-200 dark:bg-zinc-800 dark:text-zinc-200 dark:border-zinc-700 dark:hover:bg-zinc-700";
  }
}

function LinkIcon({ type }: { type: LinkInfo["type"] }) {
  if (type === "teams" || type === "zoom" || type === "meet") {
    return (
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="shrink-0">
        <path d="M23 7l-7 5 7 5V7z" />
        <rect x="1" y="5" width="15" height="14" rx="2" ry="2" />
      </svg>
    );
  }
  if (type === "whatsapp") {
    return (
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="shrink-0 text-blue-600 dark:text-blue-400">
        <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" />
      </svg>
    );
  }
  if (type === "telegram") {
    return (
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="shrink-0 text-sky-600 dark:text-sky-400">
        <line x1="22" y1="2" x2="11" y2="13" />
        <polygon points="22 2 15 22 11 13 2 9 22 2" />
      </svg>
    );
  }
  return (
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="shrink-0">
      <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
      <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
    </svg>
  );
}

function EmailBadge({ email, isUser }: { email: string; isUser: boolean }) {
  const [copied, setCopied] = useState(false);

  function handleCopy(e: React.MouseEvent) {
    e.preventDefault();
    e.stopPropagation();
    navigator.clipboard?.writeText(email);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  }

  return (
    <span
      className={`inline-flex items-center gap-1.5 my-0.5 mx-0.5 rounded-lg border px-2 py-0.5 text-xs font-medium transition shadow-2xs select-none ${
        isUser
          ? "border-zinc-700/80 bg-zinc-800/90 text-sky-200"
          : "border-sky-200/90 bg-sky-50/90 text-sky-900 dark:border-sky-800 dark:bg-sky-950/60 dark:text-sky-300"
      }`}
    >
      <a
        href={`mailto:${email}`}
        className="inline-flex items-center gap-1.5 hover:underline decoration-sky-400"
        title={`Compose email to ${email}`}
      >
        <svg
          width="13"
          height="13"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
          className="shrink-0 text-sky-600 dark:text-sky-400"
        >
          <rect x="2" y="4" width="20" height="16" rx="2" />
          <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />
        </svg>
        <span className="font-mono text-[12px] font-semibold">{email}</span>
      </a>

      <button
        type="button"
        onClick={handleCopy}
        className={`ml-0.5 rounded px-1.5 py-0.5 text-[10px] font-medium transition cursor-pointer ${
          copied
            ? "bg-blue-600 text-white font-semibold"
            : isUser
            ? "bg-zinc-700 text-zinc-300 hover:bg-zinc-600 hover:text-white"
            : "bg-sky-100 text-sky-700 hover:bg-sky-200 dark:bg-sky-900 dark:text-sky-200"
        }`}
        title="Copy email address"
      >
        {copied ? "Copied!" : "Copy"}
      </button>
    </span>
  );
}

function PhoneBadge({ phone, isUser }: { phone: string; isUser: boolean }) {
  const [copied, setCopied] = useState(false);
  const cleanPhone = phone.replace(/[^\d+]/g, "");

  function handleCopy(e: React.MouseEvent) {
    e.preventDefault();
    e.stopPropagation();
    navigator.clipboard?.writeText(cleanPhone);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  }

  return (
    <span
      className={`inline-flex items-center gap-1.5 my-0.5 mx-0.5 rounded-lg border px-2 py-0.5 text-xs font-medium transition shadow-2xs select-none ${
        isUser
          ? "border-zinc-700/80 bg-zinc-800/90 text-blue-300"
          : "border-blue-200/90 bg-blue-50/90 text-blue-900 dark:border-blue-800 dark:bg-blue-950/60 dark:text-blue-300"
      }`}
    >
      <a
        href={`tel:${cleanPhone}`}
        className="inline-flex items-center gap-1.5 hover:underline decoration-blue-400"
        title={`Call ${phone}`}
      >
        <svg
          width="12"
          height="12"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
          className="shrink-0 text-blue-600 dark:text-blue-400"
        >
          <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" />
        </svg>
        <span className="font-mono text-[12px]">{phone}</span>
      </a>

      <button
        type="button"
        onClick={handleCopy}
        className={`ml-0.5 rounded px-1.5 py-0.5 text-[10px] font-medium transition cursor-pointer ${
          copied
            ? "bg-blue-600 text-white font-semibold"
            : isUser
            ? "bg-zinc-700 text-zinc-300 hover:bg-zinc-600 hover:text-white"
            : "bg-blue-100 text-blue-800 hover:bg-blue-200 dark:bg-blue-900 dark:text-blue-200"
        }`}
        title="Copy phone number"
      >
        {copied ? "Copied!" : "Copy"}
      </button>
    </span>
  );
}

// Bot Command chip (e.g. /ask, /share, /feature, /help, /asset, etc.)
function CommandBadge({ command, isUser }: { command: string; isUser: boolean }) {
  const clean = command.replace(/^\*|\*$/g, "").trim();
  const display = clean.startsWith("/") ? clean : `/${clean}`;

  function handleClick() {
    if (typeof window !== "undefined") {
      window.dispatchEvent(
        new CustomEvent("insert-chat-command", { detail: { command: display + " " } })
      );
    }
  }

  return (
    <button
      type="button"
      onClick={handleClick}
      className={`inline-flex items-center font-mono font-bold text-[12px] px-2 py-0.5 rounded-lg border mx-0.5 my-0.5 transition cursor-pointer shadow-2xs select-none active:scale-95 ${
        isUser
          ? "bg-zinc-800 text-blue-300 border-zinc-700 hover:bg-zinc-700 hover:text-white"
          : "bg-blue-50 text-blue-800 border-blue-200 hover:bg-blue-100 hover:border-blue-300 dark:bg-blue-950/60 dark:text-blue-300 dark:border-blue-800 dark:hover:bg-blue-900/60"
      }`}
      title={`Click to populate ${display}`}
    >
      <span>{display}</span>
      <span className="ml-1 text-[9px] opacity-70">↵</span>
    </button>
  );
}

function MentionBadge({ mention, isUser }: { mention: string; isUser: boolean }) {
  return (
    <span
      className={`inline-flex items-center font-medium px-1.5 py-0.5 rounded-md text-xs mx-0.5 ${
        isUser
          ? "bg-zinc-800 text-blue-300 border border-zinc-700/80"
          : "bg-zinc-100 text-zinc-800 border border-zinc-200/80 dark:bg-zinc-800 dark:text-zinc-200 dark:border-zinc-700"
      }`}
    >
      {mention}
    </span>
  );
}

function CodeBlock({ code, language }: { code: string; language?: string }) {
  const [copied, setCopied] = useState(false);

  function handleCopy() {
    navigator.clipboard?.writeText(code);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  }

  return (
    <div className="my-3 overflow-hidden rounded-2xl border border-zinc-800 bg-[#1e1e20] text-zinc-100 shadow-xs dark:border-zinc-700/80 dark:bg-[#141414]">
      <div className="flex items-center justify-between border-b border-zinc-800/80 bg-[#18181b] px-4 py-2 text-[12px] font-mono text-zinc-400 dark:border-zinc-800 dark:bg-[#18181a]">
        <span className="text-[11px] text-zinc-300 font-medium tracking-wide">
          {language || "snippet"}
        </span>
        <button
          type="button"
          onClick={handleCopy}
          className="flex items-center gap-1.5 rounded-md px-2 py-1 text-xs text-zinc-300 hover:bg-zinc-700/60 hover:text-white transition cursor-pointer"
          title="Copy code"
        >
          {copied ? (
            <>
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" className="text-blue-400">
                <polyline points="20 6 9 17 4 12" />
              </svg>
              <span className="text-[11px] text-blue-400 font-medium">Copied!</span>
            </>
          ) : (
            <>
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <rect x="9" y="9" width="13" height="13" rx="2" ry="2" />
                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
              </svg>
              <span className="text-[11px]">Copy</span>
            </>
          )}
        </button>
      </div>
      <pre className="overflow-x-auto p-4 font-mono text-[13px] leading-relaxed text-zinc-200">
        <code>{code}</code>
      </pre>
    </div>
  );
}

function MarkdownTable({ rows, isUser }: { rows: string[][]; isUser: boolean }) {
  if (rows.length === 0) return null;
  const header = rows[0];
  const body = rows.slice(1);

  return (
    <div className="my-2.5 overflow-x-auto rounded-xl border border-zinc-200/90 shadow-2xs dark:border-zinc-800">
      <table className="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800 text-xs">
        <thead className={isUser ? "bg-zinc-800 text-zinc-200" : "bg-zinc-50 text-zinc-700 dark:bg-zinc-900 dark:text-zinc-300"}>
          <tr>
            {header.map((col, idx) => (
              <th
                key={idx}
                className="px-3 py-2 text-left font-semibold uppercase tracking-wider text-[11px]"
              >
                {col}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className={`divide-y divide-zinc-100 dark:divide-zinc-800/60 ${isUser ? "bg-zinc-900 text-zinc-200" : "bg-white text-zinc-700 dark:bg-[#1a1a1a] dark:text-zinc-300"}`}>
          {body.map((row, rIdx) => (
            <tr key={rIdx} className={isUser ? "hover:bg-zinc-800/50" : "hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30"}>
              {row.map((cell, cIdx) => (
                <td key={cIdx} className="px-3 py-2">
                  {cell}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/**
 * Tokenize and render inline text elements (URLs, emails, commands, phones, mentions, bold, italic, strikethrough, inline code)
 */
function renderInlineContent(text: string, isUser = false): React.ReactNode[] {
  const nodes: React.ReactNode[] = [];
  let remaining = normalizeWhatsAppEmphasis(
    text.replace(/(?<=\s|^)(\.\*|\*\.)(?=\s|$)/g, ""),
  );
  let keyIndex = 0;

  const emailRegex = /(?:[*_`'"<(\[]+)?([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})(?:(?:\.\*|\*\.|\.|\*)+|[*_`'">)\]]+)?/;
  const mdLinkRegex = /\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/;
  const urlRegex = /(https?:\/\/[^\s<>"'()]+)/;
  const commandRegex = /(?:^|(?<=\s|[([{"']))(\/(?:ask|share|feature|help|start|catchup|summary|join|export|import|publish|kb|knowledge|features|approve|decline|reply|logins|loginas|asset)\b|\*(?:catchup|summary|help|ask|share|feature|start|logins|loginas|asset)\*)(?=[\s,.:;!?)\]}"']|$)/i;
  const phoneRegex = /(?:^|(?<=\s))(\+(?:[0-9][\s-]?){7,14}[0-9])(?=[\s,.]|$)/;
  const mentionRegex = /(?:^|(?<=\s))(@[a-zA-Z0-9_]{2,30})(?=[\s,.]|$)/;
  // WhatsApp *bold* (no spaces next to asterisks) and markdown **bold**
  const boldRegex = /\*\*([^*\n]+?)\*\*|\*([^*\n\s][^*\n]*?[^*\n\s]|\S)\*/;
  const italicRegex = /(?<!\w)_([^_\n\s](?:[^_\n]*?[^_\n\s])?)_(?!\w)/;
  const strikeRegex = /~~([^~]+?)~~|(?<!\w)~([^~\n]+?)~(?!\w)/;
  const codeRegex = /`([^`]+?)`/;

  const typePrecedence: Record<string, number> = {
    mdLink: 0,
    email: 1,
    url: 2,
    command: 3,
    phone: 3,
    code: 4,
    mention: 5,
    strike: 6,
    bold: 7,
    italic: 8,
  };

  while (remaining.length > 0) {
    const matches: {
      type: "mdLink" | "email" | "url" | "command" | "phone" | "code" | "mention" | "strike" | "bold" | "italic";
      index: number;
      raw: string;
      value: string;
      href?: string;
    }[] = [];

    const mMd = remaining.match(mdLinkRegex);
    if (mMd && mMd.index !== undefined) {
      matches.push({
        type: "mdLink",
        index: mMd.index,
        raw: mMd[0],
        value: mMd[1],
        href: mMd[2],
      });
    }

    const mEmail = remaining.match(emailRegex);
    if (mEmail && mEmail.index !== undefined) {
      matches.push({ type: "email", index: mEmail.index, raw: mEmail[0], value: mEmail[1] });
    }

    const mUrl = remaining.match(urlRegex);
    if (mUrl && mUrl.index !== undefined) {
      matches.push({ type: "url", index: mUrl.index, raw: mUrl[0], value: mUrl[1] });
    }

    const mCmd = remaining.match(commandRegex);
    if (mCmd && mCmd.index !== undefined) {
      const matchText = mCmd[0];
      const matchVal = mCmd[1];
      const offset = matchText.indexOf(matchVal);
      matches.push({
        type: "command",
        index: mCmd.index + offset,
        raw: matchVal,
        value: matchVal,
      });
    }

    const mPhone = remaining.match(phoneRegex);
    if (mPhone && mPhone.index !== undefined) {
      matches.push({ type: "phone", index: mPhone.index, raw: mPhone[0], value: mPhone[1] });
    }

    const mMention = remaining.match(mentionRegex);
    if (mMention && mMention.index !== undefined) {
      matches.push({ type: "mention", index: mMention.index, raw: mMention[0], value: mMention[1] });
    }

    const mCode = remaining.match(codeRegex);
    if (mCode && mCode.index !== undefined) {
      matches.push({ type: "code", index: mCode.index, raw: mCode[0], value: mCode[1] });
    }

    const mStrike = remaining.match(strikeRegex);
    if (mStrike && mStrike.index !== undefined) {
      const strikeVal = mStrike[1] || mStrike[2] || "";
      matches.push({ type: "strike", index: mStrike.index, raw: mStrike[0], value: strikeVal });
    }

    const mBold = remaining.match(boldRegex);
    if (mBold && mBold.index !== undefined) {
      const boldVal = mBold[1] || mBold[2] || "";
      matches.push({ type: "bold", index: mBold.index, raw: mBold[0], value: boldVal });
    }

    const mItalic = remaining.match(italicRegex);
    if (mItalic && mItalic.index !== undefined) {
      matches.push({ type: "italic", index: mItalic.index, raw: mItalic[0], value: mItalic[1] });
    }

    if (matches.length === 0) {
      nodes.push(remaining);
      break;
    }

    matches.sort((a, b) => {
      if (a.index !== b.index) return a.index - b.index;
      return (typePrecedence[a.type] ?? 99) - (typePrecedence[b.type] ?? 99);
    });

    const earliest = matches[0];

    if (earliest.index > 0) {
      nodes.push(remaining.slice(0, earliest.index));
    }

    const k = keyIndex++;
    if (earliest.type === "mdLink") {
      nodes.push(
        <CitationLink
          key={k}
          href={earliest.href || earliest.value}
          label={citationLabel(earliest.value, earliest.href || "")}
          isUser={isUser}
        />,
      );
    } else if (earliest.type === "email") {
      nodes.push(<EmailBadge key={k} email={earliest.value} isUser={isUser} />);
    } else if (earliest.type === "command") {
      nodes.push(<CommandBadge key={k} command={earliest.value} isUser={isUser} />);
    } else if (earliest.type === "url") {
      const link = parseUrl(earliest.value);
      if (isUser) {
        nodes.push(
          <a
            key={k}
            href={link.url}
            target="_blank"
            rel="noopener noreferrer"
            className="underline underline-offset-2 break-all text-sky-200 hover:text-white transition"
          >
            {link.label}
          </a>
        );
      } else {
        nodes.push(
          <a
            key={k}
            href={link.url}
            target="_blank"
            rel="noopener noreferrer"
            className={`inline-flex items-center gap-1.5 my-1 mx-0.5 rounded-lg border px-2.5 py-1 text-xs font-medium transition cursor-pointer shadow-2xs ${getLinkBadgeClass(
              link.type
            )}`}
          >
            <LinkIcon type={link.type} />
            <span>{link.label}</span>
            <span className="text-[10px] opacity-70">↗</span>
          </a>
        );
      }
    } else if (earliest.type === "phone") {
      nodes.push(<PhoneBadge key={k} phone={earliest.value} isUser={isUser} />);
    } else if (earliest.type === "mention") {
      nodes.push(<MentionBadge key={k} mention={earliest.value} isUser={isUser} />);
    } else if (earliest.type === "bold") {
      nodes.push(
        <strong key={k} className={isUser ? "font-semibold text-white" : "font-semibold text-zinc-900 dark:text-zinc-100"}>
          {earliest.value}
        </strong>
      );
    } else if (earliest.type === "italic") {
      nodes.push(
        <em key={k} className="italic">
          {earliest.value}
        </em>
      );
    } else if (earliest.type === "strike") {
      nodes.push(
        <span key={k} className="line-through opacity-80">
          {earliest.value}
        </span>
      );
    } else if (earliest.type === "code") {
      nodes.push(
        <code
          key={k}
          className={`rounded px-1.5 py-0.5 text-[12px] font-mono border ${
            isUser
              ? "bg-zinc-800 text-zinc-200 border-zinc-700"
              : "bg-zinc-100 text-zinc-800 border-zinc-200/80 dark:bg-zinc-800 dark:text-zinc-200 dark:border-zinc-700"
          }`}
        >
          {earliest.value}
        </code>
      );
    }

    remaining = remaining.slice(earliest.index + earliest.raw.length);
  }

  return nodes;
}

function citationLabel(raw: string, url: string): string {
  const trimmed = raw.trim();
  const collapsed = trimmed.replace(/\s+/g, "");
  if (/^(www\.)?[a-z0-9.-]+\.[a-z]{2,}$/i.test(collapsed)) {
    return collapsed.replace(/^www\./i, "");
  }
  if (trimmed) return trimmed;
  return parseUrl(url).label;
}

function CitationLink({
  href,
  label,
  isUser,
}: {
  href: string;
  label: string;
  isUser: boolean;
}) {
  return (
    <a
      href={href}
      target="_blank"
      rel="noopener noreferrer"
      className={
        isUser
          ? "underline underline-offset-2 break-words text-sky-200 hover:text-white"
          : "font-medium text-sky-700 underline decoration-sky-400/70 underline-offset-[3px] hover:text-sky-800 hover:decoration-sky-600 dark:text-sky-300 dark:decoration-sky-700 dark:hover:text-sky-200"
      }
    >
      {label}
    </a>
  );
}

function isSourcesHeading(trimmed: string): boolean {
  return /^(?:#{1,3}\s+)?sources\b/i.test(trimmed);
}

function isNumberedStep(trimmed: string): RegExpMatchArray | null {
  return trimmed.match(/^(?:#{1,3}\s+)?(\d{1,2})\.\s+(.+)$/);
}

function isStructuredLine(trimmed: string): boolean {
  if (!trimmed) return true;
  if (trimmed.startsWith("```")) return true;
  if (trimmed.startsWith("|") && trimmed.endsWith("|")) return true;
  if (/^#{1,3}$/.test(trimmed)) return true;
  if (/^#{1,3}\s/.test(trimmed)) return true;
  if (isSourcesHeading(trimmed)) return true;
  if (/^[A-Za-z][A-Za-z0-9 /&+]{0,40}:\s*/.test(trimmed) && trimmed.split(":")[0].trim().split(/\s+/).length <= 5) return true;
  if (/^[-*_]{3,}$/.test(trimmed)) return true;
  if (/^\s*[-*•]\s+/.test(trimmed)) return true;
  if (/^\s*\d+\.\s+/.test(trimmed)) return true;
  if (/^\[[^\]]+\]\(https?:\/\//i.test(trimmed)) return true;
  if (trimmed.startsWith("> ")) return true;
  if (/^\*([^*]+)\*[.:!]?$/.test(trimmed)) return true;
  if (/^\*([^*]+):\*\s+/.test(trimmed)) return true;
  if (/^\[[^\]]+\][,.]?$/.test(trimmed)) return true;
  return false;
}

const proseClass = "whitespace-pre-wrap break-words [overflow-wrap:anywhere] leading-8";

function isFieldLine(trimmed: string): { label: string; value: string } | null {
  const match = trimmed.match(/^([A-Za-z][A-Za-z0-9 /&+]{0,40}):\s*(.*)$/);
  if (!match) return null;
  const label = match[1].trim();
  if (label.split(/\s+/).length > 5 || /[.!?]$/.test(label)) return null;
  if (/^https?:/i.test(match[2])) return null;
  return { label, value: match[2].trim() };
}

export function FormattedMessage({ content, isUser = false }: FormattedMessageProps) {
  const urlRegex = /(https?:\/\/[^\s<>"'()]+)/g;
  const detectedLinks: LinkInfo[] = [];
  const matches = content.match(urlRegex);
  if (matches) {
    for (const m of matches) {
      detectedLinks.push(parseUrl(m));
    }
  }

  const uniquePreviewLinks = detectedLinks.filter(
    (l, idx, arr) => arr.findIndex((x) => x.url === l.url) === idx
  );

  const sanitizedContent = normalizeWhatsAppEmphasis(
    restoreReadableSpacing(
      normalizeChatMarkdown(content.replace(/(?<=\s|^)(\.\*|\*\.)(?=\s|$)/g, "")),
    ),
  );
  const rawLines = sanitizedContent.split("\n");
  const blocks: React.ReactNode[] = [];
  let i = 0;

  while (i < rawLines.length) {
    const line = rawLines[i];
    const trimmed = line.trim();

    // 1. Fenced Code Block ```
    if (trimmed.startsWith("```")) {
      const lang = trimmed.slice(3).trim();
      const codeLines: string[] = [];
      i++;
      while (i < rawLines.length && !rawLines[i].trim().startsWith("```")) {
        codeLines.push(rawLines[i]);
        i++;
      }
      i++; // Skip closing ```
      blocks.push(
        <CodeBlock
          key={`code-${i}`}
          code={codeLines.join("\n")}
          language={lang || undefined}
        />
      );
      continue;
    }

    // 2. Markdown Table
    if (trimmed.startsWith("|") && trimmed.endsWith("|")) {
      const tableRows: string[][] = [];
      while (i < rawLines.length && rawLines[i].trim().startsWith("|") && rawLines[i].trim().endsWith("|")) {
        const rowLine = rawLines[i].trim();
        if (!/^\|(?:\s*[-:]+[-| :]*)\|$/.test(rowLine)) {
          const cells = rowLine
            .slice(1, -1)
            .split("|")
            .map((c) => c.trim());
          tableRows.push(cells);
        }
        i++;
      }
      if (tableRows.length > 0) {
        blocks.push(
          <MarkdownTable key={`table-${i}`} rows={tableRows} isUser={isUser} />
        );
      }
      continue;
    }

    // Empty line separator
    if (!trimmed) {
      i++;
      continue;
    }

    if (/^[-*_]{3,}$/.test(trimmed) || /^#{1,3}$/.test(trimmed)) {
      if (/^[-*_]{3,}$/.test(trimmed)) {
        blocks.push(
          <hr
            key={`hr-${i}`}
            className={`my-1 border-0 border-t ${isUser ? "border-zinc-600" : "border-zinc-200 dark:border-zinc-700"}`}
          />,
        );
      }
      i++;
      continue;
    }

    if (isSourcesHeading(trimmed)) {
      const items: string[] = [];
      i += 1;
      while (i < rawLines.length) {
        const next = rawLines[i].trim();
        if (!next) {
          const peek = rawLines[i + 1]?.trim() ?? "";
          if (!peek || isSourcesHeading(peek) || /^#{1,3}\s+/.test(peek) || isNumberedStep(peek)) break;
          i += 1;
          continue;
        }
        if (isSourcesHeading(next) || (/^#{1,3}\s+/.test(next) && !isNumberedStep(next))) break;
        if (isNumberedStep(next) && items.length > 0) break;
        items.push(next.replace(/^[-*•]\s+/, ""));
        i += 1;
        if (items.length >= 12) break;
      }
      if (items.length > 0) {
        blocks.push(
          <div
            key={`sources-${i}`}
            className={`mt-4 border-t pt-3 ${isUser ? "border-zinc-600" : "border-zinc-200 dark:border-zinc-800"}`}
          >
            <p
              className={`mb-2 text-[11px] font-semibold uppercase tracking-[0.08em] ${
                isUser ? "text-zinc-400" : "text-zinc-500 dark:text-zinc-400"
              }`}
            >
              Sources
            </p>
            <div className="flex flex-col gap-1.5">
              {items.map((item, idx) => (
                <div key={`src-${idx}`} className="flex items-start gap-2 pl-0.5">
                  <span
                    className={`mt-2 h-1 w-1 shrink-0 rounded-full ${
                      isUser ? "bg-zinc-400" : "bg-zinc-400 dark:bg-zinc-500"
                    }`}
                    aria-hidden
                  />
                  <div className="min-w-0 text-[13.5px] leading-6">
                    {renderInlineContent(item, isUser)}
                  </div>
                </div>
              ))}
            </div>
          </div>,
        );
      }
      continue;
    }

    if (/^\[[^\]]+\][,.]?$/.test(trimmed)) {
      blocks.push(
        <div
          key={`field-${i}`}
          className={`${proseClass} font-medium ${isUser ? "text-zinc-100" : "text-zinc-800 dark:text-zinc-200"}`}
        >
          {renderInlineContent(trimmed, isUser)}
        </div>,
      );
      i++;
      continue;
    }

    const numberedStep = isNumberedStep(trimmed);
    if (numberedStep) {
      const kids: string[] = [];
      i += 1;
      while (i < rawLines.length) {
        const next = rawLines[i].trim();
        if (!next) {
          const peek = rawLines[i + 1]?.trim() ?? "";
          if (peek && /^[-*•]\s+/.test(peek)) {
            i += 1;
            continue;
          }
          break;
        }
        if (isSourcesHeading(next) || isNumberedStep(next)) break;
        if (/^#{1,3}$/.test(next) || /^#{1,3}\s+/.test(next)) break;
        if (
          /^[-*•]\s+/.test(next)
          || /^\[[^\]]+\]\(https?:\/\//i.test(next)
          || !isStructuredLine(next)
        ) {
          kids.push(rawLines[i]);
          i += 1;
          continue;
        }
        break;
      }
      blocks.push(
        <div key={`step-${i}`} className="mt-3 first:mt-0">
          <div className="flex items-start gap-2.5">
            <span
              className={`mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[12px] font-semibold ${
                isUser
                  ? "bg-zinc-700 text-zinc-100"
                  : "bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
              }`}
            >
              {numberedStep[1]}
            </span>
            <div className={`min-w-0 flex-1 font-semibold tracking-tight text-[16px] leading-7 ${
              isUser ? "text-white" : "text-zinc-950 dark:text-white"
            }`}>
              {renderInlineContent(numberedStep[2], isUser)}
            </div>
          </div>
          {kids.length > 0 ? (
            <div className="mt-1 flex flex-col gap-1 pl-8">
              {kids.map((kid, idx) => {
                const body = kid.trim();
                const bullet = body.match(/^[-*•]\s+(.*)$/);
                if (bullet) {
                  return (
                    <div key={`step-kid-${idx}`} className="flex items-start gap-2.5">
                      <span
                        className={`mt-2.5 h-1.5 w-1.5 shrink-0 rounded-full ${
                          isUser ? "bg-zinc-400" : "bg-zinc-400 dark:bg-zinc-500"
                        }`}
                        aria-hidden
                      />
                      <div className={isUser ? "text-zinc-100" : "text-zinc-800 dark:text-zinc-200"}>
                        {renderInlineContent(bullet[1], isUser)}
                      </div>
                    </div>
                  );
                }
                return (
                  <p key={`step-kid-${idx}`} className={`${proseClass} text-[15px] leading-7`}>
                    {renderInlineContent(body, isUser)}
                  </p>
                );
              })}
            </div>
          ) : null}
        </div>,
      );
      continue;
    }

    // 3. Headings
    const heading = trimmed.match(/^(#{1,3})\s+(.+)$/);
    if (heading) {
      const level = heading[1].length;
      const HeadingTag = level === 1 ? "h2" : level === 2 ? "h3" : "h4";
      const headingClass =
        level === 1
          ? "font-semibold tracking-tight text-[18px] mt-4 mb-1"
          : level === 2
            ? "font-semibold tracking-tight text-[16.5px] mt-3.5 mb-1"
            : "font-semibold tracking-tight text-[15px] mt-3 mb-1";
      const headingColor = isUser
        ? "text-white"
        : level === 3
          ? "text-sky-800 dark:text-sky-200"
          : "text-zinc-950 dark:text-white";
      blocks.push(
        <HeadingTag key={`h-${i}`} className={`${headingClass} ${headingColor}`}>
          {renderInlineContent(heading[2], isUser)}
        </HeadingTag>
      );
      i++;
      continue;
    }

    const field = isFieldLine(trimmed);
    if (field) {
      blocks.push(
        <div
          key={`field-row-${i}`}
          className={`grid grid-cols-[minmax(6.5rem,9.5rem)_1fr] items-baseline gap-x-3 border-b py-1.5 ${
            isUser ? "border-zinc-700/70" : "border-zinc-100 dark:border-zinc-800"
          }`}
        >
          <span
            className={`text-[11px] font-semibold uppercase tracking-[0.08em] ${
              isUser ? "text-zinc-400" : "text-zinc-500 dark:text-zinc-400"
            }`}
          >
            {field.label}
          </span>
          <span className={isUser ? "text-zinc-100" : "text-zinc-950 dark:text-zinc-50"}>
            {field.value ? renderInlineContent(field.value, isUser) : <span className="text-zinc-400">—</span>}
          </span>
        </div>
      );
      i++;
      continue;
    }

    // Section Header: *Header text*  or  *Header:* body on same line
    const sectionOnly = trimmed.match(/^\*([^*]+)\*[.:!]?$/);
    if (sectionOnly) {
      blocks.push(
        <div key={`section-${i}`} className={`font-bold text-[14.5px] mt-2.5 mb-1 ${isUser ? "text-white" : "text-zinc-950 dark:text-zinc-100"}`}>
          {sectionOnly[1].trim()}
        </div>
      );
      i++;
      continue;
    }
    // Section with body: *Title:* rest  (WhatsApp bold title + description)
    const sectionWithBody = trimmed.match(/^\*([^*]+):\*\s*(.+)$/);
    if (sectionWithBody && sectionWithBody[1].trim().length <= 80) {
      const title = sectionWithBody[1].trim();
      const body = sectionWithBody[2].trim();
      blocks.push(
        <div key={`section-body-${i}`} className="my-1">
          <span className={`font-bold text-[14.5px] ${isUser ? "text-white" : "text-zinc-950 dark:text-zinc-100"}`}>
            {title}:
          </span>
          {body ? (
            <span className={isUser ? "text-zinc-100" : "text-zinc-800 dark:text-zinc-200"}>
              {" "}
              {renderInlineContent(body, isUser)}
            </span>
          ) : null}
        </div>
      );
      i++;
      continue;
    }

    // 4. Bullet lists
    const bulletMatch = line.match(/^\s*[-*•]\s+(.*)$/);
    if (bulletMatch) {
      blocks.push(
        <div key={`bullet-${i}`} className="flex items-start gap-2.5 my-1 pl-0.5">
          <span
            className={`mt-2.5 h-1.5 w-1.5 rounded-full shrink-0 ${
              isUser ? "bg-zinc-400" : "bg-zinc-400 dark:bg-zinc-500"
            }`}
            aria-hidden
          />
          <div className={`flex-1 ${isUser ? "text-zinc-100" : "text-zinc-900 dark:text-zinc-100"}`}>
            {renderInlineContent(bulletMatch[1], isUser)}
          </div>
        </div>
      );
      i++;
      continue;
    }

    // 5. Numbered lists
    const numberMatch = line.match(/^\s*(\d+)\.\s+(.*)$/);
    if (numberMatch) {
      blocks.push(
        <div key={`num-${i}`} className="flex items-start gap-2 my-1 pl-1">
          <span className={`font-semibold text-xs mt-0.5 shrink-0 ${isUser ? "text-zinc-300" : "text-zinc-500 dark:text-zinc-400"}`}>
            {numberMatch[1]}.
          </span>
          <div className="flex-1">{renderInlineContent(numberMatch[2], isUser)}</div>
        </div>
      );
      i++;
      continue;
    }

    // 6. Blockquotes
    if (line.startsWith("> ")) {
      blocks.push(
        <blockquote
          key={`quote-${i}`}
          className={`border-l-2 pl-3 my-1.5 italic text-xs ${
            isUser ? "border-zinc-500 text-zinc-300" : "border-zinc-300 text-zinc-600 dark:border-zinc-700 dark:text-zinc-400"
          }`}
        >
          {renderInlineContent(line.slice(2), isUser)}
        </blockquote>
      );
      i++;
      continue;
    }

    // Group consecutive prose lines into one paragraph (ChatGPT-style).
    const prose: string[] = [line.trim()];
    i++;
    while (i < rawLines.length) {
      const next = rawLines[i].trim();
      if (!next || isStructuredLine(next)) break;
      prose.push(next);
      i++;
    }
    blocks.push(
      <p key={`p-${i}`} className={proseClass}>
        {renderInlineContent(prose.join(" "), isUser)}
      </p>
    );
  }

  return (
    <div className="max-w-none">
      <div className={`flex flex-col gap-2.5 text-[15.5px] ${isUser ? "text-zinc-100" : "text-zinc-900 dark:text-zinc-100"}`}>
        {blocks}
      </div>

      {/* Meeting rich preview cards */}
      {!isUser &&
        uniquePreviewLinks
          .filter((l) => ["teams", "zoom", "meet"].includes(l.type))
          .map((link) => (
            <div
              key={link.url}
              className="mt-3 rounded-xl border border-indigo-200/90 bg-indigo-50/70 p-3 text-indigo-950 shadow-2xs dark:border-indigo-800/80 dark:bg-indigo-950/40 dark:text-indigo-200"
            >
              <div className="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-2.5 min-w-0">
                  <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-indigo-600 text-white shadow-xs">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                      <path d="M23 7l-7 5 7 5V7z" />
                      <rect x="1" y="5" width="15" height="14" rx="2" ry="2" />
                    </svg>
                  </div>
                  <div className="min-w-0">
                    <p className="text-xs font-semibold tracking-tight text-indigo-950 truncate dark:text-indigo-100">
                      {link.type === "teams"
                        ? "Microsoft Teams Meeting"
                        : link.type === "zoom"
                        ? "Zoom Meeting"
                        : "Google Meet Video Call"}
                    </p>
                    <p className="text-[11px] text-indigo-700/80 truncate dark:text-indigo-300">
                      Online Community Session
                    </p>
                  </div>
                </div>

                <a
                  href={link.url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-indigo-600 px-3.5 py-1.5 text-xs font-medium text-white shadow-xs hover:bg-indigo-700 transition cursor-pointer"
                >
                  <span>Join Call</span>
                  <span className="text-[11px]">↗</span>
                </a>
              </div>
            </div>
          ))}
    </div>
  );
}
