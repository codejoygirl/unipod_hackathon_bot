"use client";

import { FormEvent, useState } from "react";

type ChatComposerProps = {
  disabled?: boolean;
  onSend: (text: string) => void;
};

export function ChatComposer({ disabled, onSend }: ChatComposerProps) {
  const [value, setValue] = useState("");

  function handleSubmit(e: FormEvent) {
    e.preventDefault();
    const trimmed = value.trim();
    if (!trimmed || disabled) {
      return;
    }
    onSend(trimmed);
    setValue("");
  }

  return (
    <form
      onSubmit={handleSubmit}
      className="border-t border-zinc-200/80 bg-white/95 px-3 py-3 backdrop-blur-md"
    >
      <div className="mx-auto flex max-w-lg items-end gap-2">
        <div className="relative min-w-0 flex-1">
          <label className="sr-only" htmlFor="chat-input">
            Message
          </label>
          <textarea
            id="chat-input"
            rows={1}
            value={value}
            onChange={(e) => setValue(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter" && !e.shiftKey) {
                e.preventDefault();
                handleSubmit(e);
              }
            }}
            placeholder="Ask Zak anything about the community…"
            disabled={disabled}
            className="max-h-32 min-h-[44px] w-full resize-none rounded-2xl border border-zinc-200 bg-white px-4 py-3 pr-10 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-zinc-400 focus:outline-none focus:ring-2 focus:ring-zinc-200 disabled:opacity-60"
          />
        </div>
        <button
          type="submit"
          disabled={disabled || !value.trim()}
          className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-zinc-900 text-white transition-opacity disabled:opacity-40"
          aria-label="Send"
        >
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden>
            <path
              d="M5 12h14M13 6l6 6-6 6"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
          </svg>
        </button>
      </div>
    </form>
  );
}
