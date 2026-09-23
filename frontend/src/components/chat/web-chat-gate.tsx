"use client";

import { AppBrand } from "@/components/branding/app-brand";
import { CircularLoader } from "@/components/ui/circular-loader";
import { useWebChat } from "@/lib/web-chat/web-chat-context";
import type { ReactNode } from "react";
import { PhoneEntryScreen } from "./phone-entry-screen";

export function WebChatGate({ children }: { children: ReactNode }) {
  const { phase, error, retry } = useWebChat();

  if (phase === "needs-phone" || phase === "needs-password") {
    return <PhoneEntryScreen />;
  }

  if (phase === "loading") {
    return (
      <div className="flex min-h-dvh flex-col items-center justify-center gap-6 bg-zinc-50 px-6">
        <AppBrand size="sm" />
        <div className="py-2">
          <CircularLoader size="md" />
        </div>
      </div>
    );
  }

  if (phase === "error") {
    return (
      <div className="flex min-h-dvh flex-col items-center justify-center gap-6 bg-zinc-50 px-6 py-12">
        <AppBrand size="sm" />
        <div className="w-full max-w-sm rounded-2xl border border-zinc-200 bg-white p-6 text-center shadow-sm">
          <p className="text-sm leading-relaxed text-zinc-700">{error}</p>
          <button
            type="button"
            onClick={retry}
            className="mt-5 w-full cursor-pointer rounded-xl bg-zinc-900 py-2.5 text-sm font-medium text-white transition hover:bg-zinc-800 active:scale-[0.99]"
          >
            Try again
          </button>
        </div>
      </div>
    );
  }

  return children;
}
