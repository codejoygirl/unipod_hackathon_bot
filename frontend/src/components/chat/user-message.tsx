"use client";

import type { QuotedMessage } from "@/lib/web-chat/storage";
import { useState } from "react";
import { FormattedMessage } from "./formatted-message";

type UserMessageProps = {
  id: string;
  text: string;
  sentAt: string;
  quote?: QuotedMessage;
  onQuote?: (quote: QuotedMessage) => void;
};

export function UserMessage({ id, text, sentAt, quote, onQuote }: UserMessageProps) {
  const [copied, setCopied] = useState(false);

  function handleCopy() {
    navigator.clipboard?.writeText(text);
    setCopied(true);
    setTimeout(() => setCopied(false), 1500);
  }

  return (
    <div className="group relative flex flex-col items-end gap-1 self-end max-w-[85%] sm:max-w-[75%]">
      {/* Action buttons (Reply & Copy) visible on hover or mobile tap */}
      <div className="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity mb-0.5 text-zinc-400">
        {onQuote ? (
          <button
            type="button"
            onClick={() => onQuote({ id, sender: "You", text })}
            className="rounded p-1 hover:bg-zinc-100 hover:text-zinc-700 transition cursor-pointer"
            title="Reply"
          >
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M9 14L4 9l5-5" strokeLinecap="round" strokeLinejoin="round" />
              <path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5v2" strokeLinecap="round" />
            </svg>
          </button>
        ) : null}
        <button
          type="button"
          onClick={handleCopy}
          className="rounded p-1 hover:bg-zinc-100 hover:text-zinc-700 transition cursor-pointer"
          title="Copy"
        >
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <rect x="9" y="9" width="13" height="13" rx="2" ry="2" />
            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
          </svg>
        </button>
      </div>

      <div className="rounded-2xl rounded-tr-xs bg-zinc-900 px-4 py-2.5 text-zinc-100 shadow-xs selection:bg-zinc-700">
        {/* Quoted message preview if replied to something */}
        {quote ? (
          <div className="mb-2 rounded-lg border-l-3 border-emerald-400 bg-zinc-800/80 px-2.5 py-1.5 text-xs text-zinc-300">
            <p className="font-semibold text-emerald-400 text-[11px]">{quote.sender}</p>
            <p className="truncate text-zinc-300 text-[11px]">{quote.text}</p>
          </div>
        ) : null}

        <FormattedMessage content={text} isUser={true} />

        {/* Timestamp and ticks inlined like WhatsApp */}
        <div className="mt-1 flex items-center justify-end gap-1 text-[10px] text-zinc-400">
          <time>{sentAt}</time>
          <span className="text-[10px] text-emerald-400" aria-label="Delivered">✓✓</span>
        </div>
      </div>
    </div>
  );
}
