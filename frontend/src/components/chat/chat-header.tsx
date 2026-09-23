"use client";

import { useWebChat } from "@/lib/web-chat/web-chat-context";

export function ChatHeader() {
  const { community, memberLabel, sessionId } = useWebChat();

  return (
    <header className="sticky top-0 z-30 border-b border-zinc-200/70 bg-[#f6f4fa]/90 px-4 py-3 backdrop-blur-md">
      <div className="mx-auto flex max-w-lg items-center gap-3">
        <div className="min-w-0 flex-1">
          <h1 className="truncate text-base font-semibold text-zinc-900">
            {community?.name ?? "Community chat"}
          </h1>
          <p className="truncate text-[11px] text-zinc-400">
            {memberLabel ? `Member ${memberLabel}` : sessionId ? `Session ${sessionId.slice(0, 10)}…` : ""}
          </p>
        </div>
        <div
          className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-zinc-900 text-white"
          aria-hidden
        >
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
            <path
              d="M12 3v3M8 7h8M7 11h10v7a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2v-7Z"
              stroke="currentColor"
              strokeWidth="1.75"
              strokeLinecap="round"
            />
          </svg>
        </div>
      </div>
    </header>
  );
}
