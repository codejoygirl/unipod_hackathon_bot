"use client";

import type { AssistantAskResponse } from "@/lib/api/types";
import type { QuotedMessage } from "@/lib/web-chat/storage";
import { useState } from "react";
import { FormattedMessage } from "./formatted-message";

type AssistantMessageProps = {
  id: string;
  response: AssistantAskResponse;
  sentAt: string;
  onQuote?: (quote: QuotedMessage) => void;
};

export function AssistantMessage({ id, response, sentAt, onQuote }: AssistantMessageProps) {
  const { data } = response;
  const [copied, setCopied] = useState(false);

  // Natural WhatsApp / Telegram style response matching the channels
  const displayText =
    data.answer && data.answer.trim().length > 0
      ? data.answer
      : "I don't have a solid answer for that yet.\n\nI've passed it along, and I'll follow up once I have one. No need to keep checking or asking again.";

  function handleCopy() {
    navigator.clipboard?.writeText(displayText);
    setCopied(true);
    setTimeout(() => setCopied(false), 1500);
  }

  return (
    <div className="group relative flex flex-col items-start gap-1 self-start max-w-[92%] sm:max-w-[80%]">
      {/* Quick Action buttons (Reply & Copy) on hover / tap */}
      <div className="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity mb-0.5 text-zinc-400">
        {onQuote ? (
          <button
            type="button"
            onClick={() => onQuote({ id, sender: "UniPod Assistant", text: displayText })}
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
        {copied ? <span className="text-[10px] text-emerald-600 font-medium">Copied!</span> : null}
      </div>

      {/* Message Bubble - WhatsApp / Grok style with nicely formatted links & zero evidence jargon */}
      <div className="rounded-2xl rounded-tl-xs border border-zinc-200/80 bg-white px-4 py-3 text-zinc-800 shadow-xs">
        <FormattedMessage content={displayText} isUser={false} />

        {/* Inlined subtle timestamp */}
        <div className="mt-1 flex items-center justify-end text-[10px] text-zinc-400">
          <time>{sentAt}</time>
        </div>
      </div>
    </div>
  );
}
