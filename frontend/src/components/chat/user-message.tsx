"use client";

import React, { useState } from "react";
import type { QuotedMessage } from "@/lib/web-chat/storage";
import { Tooltip } from "@/components/ui/tooltip";
import { FormattedMessage } from "./formatted-message";
import { ImagePreviewLightbox } from "./image-preview-lightbox";

interface UserMessageProps {
  id: string;
  text: string;
  sentAt: string;
  quote?: QuotedMessage;
  imagePreview?: string;
  imagePreviews?: string[];
  onQuote?: (quote: QuotedMessage) => void;
}

export function UserMessage({
  id,
  text,
  sentAt,
  quote,
  imagePreview,
  imagePreviews,
  onQuote,
}: UserMessageProps) {
  const [copied, setCopied] = useState(false);
  const [lightboxUrl, setLightboxUrl] = useState<string | null>(null);
  const previews =
    imagePreviews && imagePreviews.length > 0
      ? imagePreviews
      : imagePreview
        ? [imagePreview]
        : [];

  function handleCopy() {
    navigator.clipboard?.writeText(text);
    setCopied(true);
    setTimeout(() => setCopied(false), 1800);
  }

  return (
    <div className="group relative flex flex-col items-end self-end max-w-[85%] sm:max-w-[75%] my-1.5 animate-fade-in">
      {/* Message Bubble - ChatGPT style */}
      <div className="rounded-3xl rounded-br-md bg-zinc-900 px-4.5 py-3 text-zinc-100 shadow-xs transition-all selection:bg-zinc-700 dark:bg-[#183660] dark:text-white dark:selection:bg-blue-900">
        {/* Quoted message preview */}
        {quote && (
          <div className="mb-2.5 flex items-start gap-2 rounded-xl bg-black/25 px-3 py-2 text-xs border-l-[3px] border-emerald-400">
            <div className="min-w-0 flex-1">
              <div className="flex items-center gap-1.5 font-semibold text-[11px] text-emerald-400">
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
              <p className="mt-0.5 line-clamp-2 text-zinc-300 text-[11px] leading-relaxed">
                {quote.text}
              </p>
            </div>
          </div>
        )}

        {previews.length > 0 ? (
          <div
            className={`grid gap-1.5 ${previews.length > 1 ? "grid-cols-2" : "grid-cols-1"} ${
              text && !/^\d+ photos?$/.test(text) && text !== "Photo" ? "mb-2.5" : ""
            }`}
          >
            {previews.map((url, i) => (
              <button
                key={`${url.slice(0, 32)}-${i}`}
                type="button"
                onClick={() => setLightboxUrl(url)}
                className="overflow-hidden rounded-2xl ring-1 ring-white/10 transition hover:ring-white/30"
                aria-label="View attached photo"
              >
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img
                  src={url}
                  alt=""
                  className="max-h-56 w-full object-cover"
                />
              </button>
            ))}
          </div>
        ) : null}

        {text && !/^\d+ photos?$/.test(text) && text !== "Photo" ? (
          <FormattedMessage content={text} isUser={true} />
        ) : null}
        {!text && previews.length === 0 ? <FormattedMessage content={text} isUser={true} /> : null}

        {lightboxUrl ? (
          <ImagePreviewLightbox
            previewUrl={lightboxUrl}
            filename={text || "Attached photo"}
            onClose={() => setLightboxUrl(null)}
          />
        ) : null}

        {/* Timestamp */}
        <div className="mt-1 flex items-center justify-end gap-1.5 text-[10px] text-zinc-400 select-none dark:text-blue-200/80">
          <time>{sentAt}</time>
        </div>
      </div>

      {/* Action buttons (Reply & Copy) positioned BELOW the bubble */}
      <div className="mt-1 flex items-center gap-1 opacity-0 group-hover:opacity-100 group-focus-within:opacity-100 transition-opacity text-zinc-400">
        {onQuote && (
          <Tooltip content="Reply" position="bottom">
            <button
              type="button"
              onClick={() => onQuote({ id, sender: "You", text: text || "Photo" })}
              className="flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs text-zinc-500 hover:bg-zinc-200/60 hover:text-zinc-800 transition cursor-pointer dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
              aria-label="Reply to message"
            >
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M9 14L4 9l5-5" strokeLinecap="round" strokeLinejoin="round" />
                <path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5v2" strokeLinecap="round" />
              </svg>
            </button>
          </Tooltip>
        )}

        <Tooltip content={copied ? "Copied!" : "Copy"} position="bottom">
          <button
            type="button"
            onClick={handleCopy}
            className="flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs text-zinc-500 hover:bg-zinc-200/60 hover:text-zinc-800 transition cursor-pointer dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
            aria-label="Copy message"
          >
            {copied ? (
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" className="text-emerald-500">
                <polyline points="20 6 9 17 4 12" />
              </svg>
            ) : (
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <rect x="9" y="9" width="13" height="13" rx="2" ry="2" />
                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
              </svg>
            )}
          </button>
        </Tooltip>
      </div>
    </div>
  );
}
