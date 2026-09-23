"use client";

import { useWebChat } from "@/lib/web-chat/web-chat-context";
import type { ReactNode } from "react";
import { PhoneEntryScreen } from "./phone-entry-screen";

export function WebChatGate({ children }: { children: ReactNode }) {
  const { phase, error, retry } = useWebChat();

  if (phase === "needs-phone") {
    return <PhoneEntryScreen />;
  }

  if (phase === "loading") {
    return (
      <div className="flex min-h-dvh items-center justify-center bg-[#f6f4fa] text-sm text-zinc-500">
        Opening chat…
      </div>
    );
  }

  if (phase === "error") {
    return (
      <div className="flex min-h-dvh flex-col items-center justify-center gap-4 bg-[#f6f4fa] px-6 text-center">
        <p className="max-w-sm text-sm text-zinc-700">{error}</p>
        <button
          type="button"
          onClick={retry}
          className="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-medium text-white"
        >
          Try again
        </button>
      </div>
    );
  }

  return children;
}
