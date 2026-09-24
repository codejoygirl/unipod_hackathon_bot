"use client";

import React, { useState } from "react";
import type { AssistantAskResponse } from "@/lib/api/types";
import type { QuotedMessage } from "@/lib/web-chat/storage";
import { APP_LOGO_SRC } from "@/lib/branding";
import { Tooltip } from "@/components/ui/tooltip";
import Image from "next/image";
import { FormattedMessage } from "./formatted-message";
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
}: AssistantMessageProps) {
  const { data } = response;
  const [copied, setCopied] = useState(false);
  const [feedbackNotice, setFeedbackNotice] = useState<string | null>(null);

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

  function handleCopy() {
    navigator.clipboard?.writeText(displayText);
    setCopied(true);
    setTimeout(() => setCopied(false), 1800);
  }

  function handleReactionClick(type: "up" | "down") {
    onReact?.(id, type);
    const notice =
      reaction === type
        ? null
        : type === "up"
        ? "Thanks for your feedback!"
        : "Feedback recorded. We'll improve this.";
    setFeedbackNotice(notice);
    if (notice) {
      setTimeout(() => setFeedbackNotice(null), 2400);
    }
  }

  function handleSuggestion(prompt: string) {
    if (onSuggestionClick) {
      onSuggestionClick(prompt);
    } else {
      insertChatCommand(prompt);
    }
  }

  return (
    <div className="group relative flex flex-col items-start self-start max-w-[95%] sm:max-w-[85%] my-2 animate-fade-in">
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
      <div className="w-full rounded-2xl rounded-tl-sm border border-zinc-200/80 bg-white px-4.5 py-3.5 text-zinc-800 shadow-xs dark:border-zinc-800 dark:bg-[#1a1a1a] dark:text-zinc-200">
        {/* Quoted message preview if responding to a specific query */}
        {quote && (
          <div className="mb-3 flex items-start gap-2 rounded-xl bg-zinc-50 px-3 py-2 text-xs border-l-[3px] border-emerald-500 dark:bg-zinc-900/60">
            <div className="min-w-0 flex-1">
              <div className="flex items-center gap-1.5 font-semibold text-[11px] text-emerald-700 dark:text-emerald-400">
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

      {/* ChatGPT-style Action Toolbar underneath response */}
      <div className="mt-1.5 flex items-center gap-1 px-1 text-zinc-400">
        {/* Copy button */}
        <Tooltip content={copied ? "Copied!" : "Copy response"} position="bottom">
          <button
            type="button"
            onClick={handleCopy}
            className="flex h-7 items-center gap-1 rounded-lg px-2 text-xs text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 transition cursor-pointer dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
            aria-label="Copy response"
          >
            {copied ? (
              <>
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" className="text-emerald-500">
                  <polyline points="20 6 9 17 4 12" />
                </svg>
                <span className="text-[11px] text-emerald-500 font-medium">Copied!</span>
              </>
            ) : (
              <>
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <rect x="9" y="9" width="13" height="13" rx="2" ry="2" />
                  <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
                </svg>
                <span className="text-[11px] hidden sm:inline">Copy</span>
              </>
            )}
          </button>
        </Tooltip>

        {/* Thumbs Up button */}
        <Tooltip content="Good response" position="bottom">
          <button
            type="button"
            onClick={() => handleReactionClick("up")}
            className={`flex h-7 w-7 items-center justify-center rounded-lg transition cursor-pointer ${
              reaction === "up"
                ? "bg-emerald-50 text-emerald-600 ring-1 ring-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-400 dark:ring-emerald-800"
                : "text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
            }`}
            aria-label="Good response"
            aria-pressed={reaction === "up"}
          >
            <svg
              width="13"
              height="13"
              viewBox="0 0 24 24"
              fill={reaction === "up" ? "currentColor" : "none"}
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <path d="M7 10v12" />
              <path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.5L12 3a2 2 0 0 1 3 2.88z" />
            </svg>
          </button>
        </Tooltip>

        {/* Thumbs Down button */}
        <Tooltip content="Bad response" position="bottom">
          <button
            type="button"
            onClick={() => handleReactionClick("down")}
            className={`flex h-7 w-7 items-center justify-center rounded-lg transition cursor-pointer ${
              reaction === "down"
                ? "bg-rose-50 text-rose-600 ring-1 ring-rose-200 dark:bg-rose-950/60 dark:text-rose-400 dark:ring-rose-800"
                : "text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
            }`}
            aria-label="Bad response"
            aria-pressed={reaction === "down"}
          >
            <svg
              width="13"
              height="13"
              viewBox="0 0 24 24"
              fill={reaction === "down" ? "currentColor" : "none"}
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <path d="M17 14V2" />
              <path d="M9 18.12 10 14H4.17a2 2 0 0 1-1.92-2.56l2.33-8A2 2 0 0 1 6.5 2H20a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2.5L12 21a2 2 0 0 1-3-2.88z" />
            </svg>
          </button>
        </Tooltip>

        {/* Reply button */}
        {onQuote && (
          <Tooltip content="Quote & reply" position="bottom">
            <button
              type="button"
              onClick={() => onQuote({ id, sender: "UniPod Assistant", text: displayText })}
              className="flex h-7 items-center gap-1 rounded-lg px-2 text-xs text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 transition cursor-pointer dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
              aria-label="Reply to this response"
            >
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M9 14L4 9l5-5" strokeLinecap="round" strokeLinejoin="round" />
                <path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5v2" strokeLinecap="round" />
              </svg>
              <span className="text-[11px] hidden sm:inline">Reply</span>
            </button>
          </Tooltip>
        )}

        {/* Retry / Regenerate */}
        <Tooltip content="Regenerate response" position="bottom">
          <button
            type="button"
            onClick={() => handleSuggestion(displayText.slice(0, 40))}
            className="flex h-7 w-7 items-center justify-center rounded-lg text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 transition cursor-pointer dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
            aria-label="Regenerate response"
          >
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M3 12a9 9 0 0 1 15-6.7L21 8" />
              <path d="M21 3v5h-5" />
              <path d="M21 12a9 9 0 0 1-15 6.7L3 16" />
              <path d="M3 21v-5h5" />
            </svg>
          </button>
        </Tooltip>

        {/* Temporary reaction feedback indicator */}
        {feedbackNotice && (
          <span className="ml-1 text-[11px] text-zinc-500 dark:text-zinc-400 animate-fade-in font-medium">
            {feedbackNotice}
          </span>
        )}
      </div>
    </div>
  );
}
