"use client";

import { useWebChat } from "@/lib/web-chat/web-chat-context";
import type { ReactNode } from "react";
import { PhoneEntryScreen } from "./phone-entry-screen";
import { SplashLoader } from "./splash-loader";

export function WebChatGate({ children }: { children: ReactNode }) {
  const { phase, error, retry, logOut } = useWebChat();

  if (phase === "needs-phone" || phase === "needs-password") {
    return <PhoneEntryScreen />;
  }

  if (phase === "loading") {
    return (
      <div className="bg-white transition-colors duration-200 dark:bg-[#0d0d0d]">
        <SplashLoader caption="Preparing workspace & community knowledge..." />
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

          <div className="mt-6 flex flex-col gap-2">
            <button
              type="button"
              onClick={retry}
              className="w-full cursor-pointer rounded-xl bg-zinc-900 py-3 text-xs font-semibold text-white transition hover:bg-zinc-800 active:scale-[0.99] dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-200 shadow-xs"
            >
              Try again
            </button>
            <button
              type="button"
              onClick={logOut}
              className="w-full cursor-pointer rounded-xl border border-zinc-200 bg-white py-3 text-xs font-semibold text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-transparent dark:text-zinc-200 dark:hover:bg-zinc-800"
            >
              Log out
            </button>
          </div>
        </div>
      </div>
    );
  }

  return children;
}
