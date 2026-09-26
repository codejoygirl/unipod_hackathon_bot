"use client";

import React, { useState, type ReactNode } from "react";
import { Tooltip } from "@/components/ui/tooltip";
import { SpeakResponseButton } from "./speak-response-button";

type Reaction = "up" | "down";

interface AssistantReplyToolbarProps {
  id: string;
  text: string;
  reaction?: Reaction | null;
  onReact?: (type: Reaction) => void;
  onReply?: () => void;
  onRegenerate?: () => void;
  extra?: ReactNode;
}

export function AssistantReplyToolbar({
  id,
  text,
  reaction = null,
  onReact,
  onReply,
  onRegenerate,
  extra,
}: AssistantReplyToolbarProps) {
  const [copied, setCopied] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  function handleCopy() {
    if (!text.trim()) return;
    void navigator.clipboard?.writeText(text);
    setCopied(true);
    window.setTimeout(() => setCopied(false), 1800);
  }

  function handleReaction(type: Reaction) {
    onReact?.(type);
    const next =
      reaction === type
        ? null
        : type === "up"
          ? "Thanks for your feedback!"
          : "Feedback recorded. We'll improve this.";
    setNotice(next);
    if (next) {
      window.setTimeout(() => setNotice(null), 2400);
    }
  }

  return (
    <div className="mt-1.5 flex min-w-0 flex-wrap items-center gap-1 px-1 text-zinc-400">
      <Tooltip content={copied ? "Copied!" : "Copy response"} position="bottom">
        <button
          type="button"
          onClick={handleCopy}
          className="flex h-7 items-center gap-1 rounded-lg px-2 text-xs text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 transition cursor-pointer dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
          aria-label="Copy response"
        >
          {copied ? (
            <>
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" className="text-blue-500">
                <polyline points="20 6 9 17 4 12" />
              </svg>
              <span className="text-[11px] text-blue-500 font-medium">Copied!</span>
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

      <SpeakResponseButton id={id} text={text} />

      {onReact ? (
        <>
          <Tooltip content="Good response" position="bottom">
            <button
              type="button"
              onClick={() => handleReaction("up")}
              className={`flex h-7 w-7 items-center justify-center rounded-lg transition cursor-pointer ${
                reaction === "up"
                  ? "bg-blue-50 text-blue-600 ring-1 ring-blue-200 dark:bg-blue-950/60 dark:text-blue-400 dark:ring-blue-800"
                  : "text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
              }`}
              aria-label="Good response"
              aria-pressed={reaction === "up"}
            >
              <svg width="13" height="13" viewBox="0 0 24 24" fill={reaction === "up" ? "currentColor" : "none"} stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M7 10v12" />
                <path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.5L12 3a2 2 0 0 1 3 2.88z" />
              </svg>
            </button>
          </Tooltip>
          <Tooltip content="Bad response" position="bottom">
            <button
              type="button"
              onClick={() => handleReaction("down")}
              className={`flex h-7 w-7 items-center justify-center rounded-lg transition cursor-pointer ${
                reaction === "down"
                  ? "bg-rose-50 text-rose-600 ring-1 ring-rose-200 dark:bg-rose-950/60 dark:text-rose-400 dark:ring-rose-800"
                  : "text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
              }`}
              aria-label="Bad response"
              aria-pressed={reaction === "down"}
            >
              <svg width="13" height="13" viewBox="0 0 24 24" fill={reaction === "down" ? "currentColor" : "none"} stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M17 14V2" />
                <path d="M9 18.12 10 14H4.17a2 2 0 0 1-1.92-2.56l2.33-8A2 2 0 0 1 6.5 2H20a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2.5L12 21a2 2 0 0 1-3-2.88z" />
              </svg>
            </button>
          </Tooltip>
        </>
      ) : null}

      {onReply ? (
        <Tooltip content="Quote & reply" position="bottom">
          <button
            type="button"
            onClick={onReply}
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
      ) : null}

      {onRegenerate ? (
        <Tooltip content="Regenerate response" position="bottom">
          <button
            type="button"
            onClick={onRegenerate}
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
      ) : null}

      {extra}

      {notice ? (
        <span className="ml-1 text-[11px] text-zinc-500 dark:text-zinc-400 animate-fade-in font-medium">
          {notice}
        </span>
      ) : null}
    </div>
  );
}
