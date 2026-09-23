"use client";

import { APP_DISPLAY_NAME, APP_LOGO_SRC } from "@/lib/branding";
import { useWebChat } from "@/lib/web-chat/web-chat-context";
import Image from "next/image";
import type { ReactNode } from "react";
import { PhoneEntryScreen } from "./phone-entry-screen";

export function WebChatGate({ children }: { children: ReactNode }) {
  const { phase, error, retry } = useWebChat();

  if (phase === "needs-phone" || phase === "needs-password") {
    return <PhoneEntryScreen />;
  }

  if (phase === "loading") {
    return (
      <div className="flex min-h-dvh flex-col items-center justify-center bg-white dark:bg-[#0d0d0d] px-6 select-none transition-colors duration-200">
        <div className="flex flex-col items-center text-center animate-fade-in max-w-sm">
          {/* Brand Icon */}
          <div className="relative mb-5 flex h-14 w-14 items-center justify-center rounded-2xl overflow-hidden shadow-sm">
            <Image
              src={APP_LOGO_SRC}
              alt="UniPod Logo"
              width={56}
              height={56}
              className="h-full w-full object-contain rounded-2xl"
              priority
            />
          </div>

          <h2 className="text-base sm:text-lg font-semibold tracking-tight text-zinc-900 dark:text-zinc-100">
            {APP_DISPLAY_NAME}
          </h2>
          <p className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
            Preparing workspace & community knowledge...
          </p>

          {/* Sleek Minimalist Loading Bar (ChatGPT style) */}
          <div className="mt-6 h-1 w-36 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800/80">
            <div className="h-full w-1/2 rounded-full bg-emerald-500 animate-[indeterminate_1.4s_infinite_ease-in-out]" />
          </div>
        </div>
      </div>
    );
  }

  if (phase === "error") {
    return (
      <div className="flex min-h-dvh flex-col items-center justify-center bg-white dark:bg-[#0d0d0d] px-6 py-12 select-none transition-colors duration-200">
        <div className="w-full max-w-sm rounded-3xl border border-zinc-200 bg-white p-7 text-center shadow-xl dark:border-zinc-800 dark:bg-[#181818] animate-fade-in">
          <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-rose-50 text-rose-600 dark:bg-rose-950/50 dark:text-rose-400">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <circle cx="12" cy="12" r="10" />
              <line x1="12" y1="8" x2="12" y2="12" />
              <line x1="12" y1="16" x2="12.01" y2="16" />
            </svg>
          </div>

          <h3 className="text-base font-semibold text-zinc-900 dark:text-zinc-100">
            Unable to connect
          </h3>
          <p className="mt-2 text-xs leading-relaxed text-zinc-600 dark:text-zinc-400">
            {error || "We're having trouble connecting right now. Please try again in a moment."}
          </p>

          <button
            type="button"
            onClick={retry}
            className="mt-6 w-full cursor-pointer rounded-xl bg-zinc-900 py-3 text-xs font-semibold text-white transition hover:bg-zinc-800 active:scale-[0.99] dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-200 shadow-xs"
          >
            Try again
          </button>
        </div>
      </div>
    );
  }

  return children;
}
