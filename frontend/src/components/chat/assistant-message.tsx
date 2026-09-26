"use client";

import React from "react";
import type { AssistantAskResponse } from "@/lib/api/types";
import type { QuotedMessage } from "@/lib/web-chat/storage";
import { APP_LOGO_SRC } from "@/lib/branding";
import Image from "next/image";
import { FormattedMessage } from "./formatted-message";
import { AssistantReplyToolbar } from "./assistant-reply-toolbar";
import { insertChatCommand } from "@/lib/chat/commands-data";

interface AssistantMessageProps {
  id: string;
  response: AssistantAskResponse;
  sentAt: string;
  reaction?: "up" | "down" | null;
  quote?: QuotedMessage;
  onQuote?: (quote: QuotedMessage) => void;
  onReact?: (id: string, reaction: "up" | "down") => void;
  onSuggestionClick?: (prompt: string) => void;
  onRegenerate?: () => void;
}

export function AssistantMessage({
  id,
  response,
  sentAt,
  reaction,
  quote,
  onQuote,
  onReact,
  onSuggestionClick,
  onRegenerate,
}: AssistantMessageProps) {
  const { data } = response;

  const displayText =
    data.answer && data.answer.trim().length > 0
      ? data.answer
      : "I don't have a solid answer for that yet.\n\nI've passed it along, and I'll follow up once I have one. No need to keep checking or asking again.";

  // Extract or generate contextual suggestion follow-ups (ChatGPT style, as shown in image 2)
  const suggestions: string[] = [];
  if (displayText.toLowerCase().includes("session") || displayText.toLowerCase().includes("schedule")) {
    suggestions.push("When is the next live session?");
    suggestions.push("Where can I find the calendar link?");
  } else if (displayText.toLowerCase().includes("hackathon") || displayText.toLowerCase().includes("deadline")) {
    suggestions.push("What are the project submission criteria?");
    suggestions.push("How do I register my team roster?");
  } else if (displayText.toLowerCase().includes("handbook") || displayText.toLowerCase().includes("resource")) {
    suggestions.push("/asset handbook");
    suggestions.push("Where are the Wadhwani training modules?");
  } else if (displayText.toLowerCase().includes("telegram") || displayText.toLowerCase().includes("whatsapp")) {
    suggestions.push("How do I get private mentor help?");
    suggestions.push("Clarify what I can ask here");
  }

  function handleSuggestion(prompt: string) {
    if (onSuggestionClick) {
      onSuggestionClick(prompt);
    } else {
      insertChatCommand(prompt);
    }
  }

  return (
    <div className="group relative flex w-full min-w-0 max-w-[min(95%,42rem)] flex-col items-start self-start my-2 animate-fade-in sm:max-w-[85%]">
      {/* Assistant Header: Brand Avatar + Name & Timestamp */}
      <div className="flex items-center gap-2 mb-1.5 px-0.5 select-none">
        <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full overflow-hidden shadow-2xs">
          <Image
            src={APP_LOGO_SRC}
            alt="UniPod Assistant"
            width={24}
            height={24}
            className="h-full w-full object-contain rounded-full"
          />
        </div>
        <span className="text-xs font-semibold text-zinc-900 tracking-tight dark:text-zinc-100">
          UniPod Assistant
        </span>
        <span className="text-[11px] text-zinc-400">·</span>
        <time className="text-[11px] text-zinc-400">{sentAt}</time>
      </div>

      {/* Message Body Container */}
      <div className="w-full min-w-0 overflow-hidden rounded-2xl rounded-tl-sm border border-zinc-200/80 bg-white px-4 py-3.5 text-[14.5px] font-normal leading-relaxed text-zinc-800 shadow-xs sm:px-4.5 dark:border-zinc-800 dark:bg-[#1a1a1a] dark:text-zinc-200">
        {/* Quoted message preview if responding to a specific query */}
        {quote && (
          <div className="mb-3 flex items-start gap-2 rounded-xl bg-zinc-50 px-3 py-2 text-xs border-l-[3px] border-blue-500 dark:bg-zinc-900/60">
            <div className="min-w-0 flex-1">
              <div className="flex items-center gap-1.5 font-semibold text-[11px] text-blue-700 dark:text-blue-400">
                <svg
                  width="11"
                  height="11"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="2.5"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  className="shrink-0"
                >
                  <path d="M9 14L4 9l5-5" />
                  <path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5v2" />
                </svg>
                <span className="truncate">{quote.sender}</span>
              </div>
              <p className="mt-0.5 line-clamp-2 text-zinc-600 text-[11px] leading-relaxed dark:text-zinc-400">
                {quote.text}
              </p>
            </div>
          </div>
        )}

        <FormattedMessage content={displayText} isUser={false} />

        {/* ChatGPT Style Follow-up Suggestions Chips (as seen in image 2) */}
        {suggestions.length > 0 && (
          <div className="mt-3.5 pt-3 border-t border-zinc-100 dark:border-zinc-800/80 flex flex-wrap gap-1.5">
            {suggestions.map((item, idx) => (
              <button
                key={idx}
                type="button"
                onClick={() => handleSuggestion(item)}
                className="inline-flex items-center gap-1 rounded-xl border border-zinc-200/80 bg-zinc-50/80 px-2.5 py-1 text-xs text-zinc-700 transition hover:border-zinc-300 hover:bg-zinc-100 hover:text-zinc-900 active:scale-95 dark:border-zinc-700/60 dark:bg-zinc-800/60 dark:text-zinc-300 dark:hover:border-zinc-600 dark:hover:bg-zinc-700 cursor-pointer"
              >
                <span className="text-zinc-400 dark:text-zinc-500">↳</span>
                <span className="truncate max-w-xs">{item}</span>
              </button>
            ))}
          </div>
        )}
      </div>

      <AssistantReplyToolbar
        id={id}
        text={displayText}
        reaction={reaction}
        onReact={(type) => onReact?.(id, type)}
        onReply={onQuote ? () => onQuote({ id, sender: "UniPod Assistant", text: displayText }) : undefined}
        onRegenerate={onRegenerate}
      />
    </div>
  );
}
