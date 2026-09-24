"use client";

import Image from "next/image";
import { APP_LOGO_SRC } from "@/lib/branding";

interface GrokThinkingLoaderProps {
  statusText?: string;
}

export function GrokThinkingLoader({ statusText = "Zak is thinking…" }: GrokThinkingLoaderProps) {
  return (
    <div
      className="flex items-end gap-2 self-start py-1"
      role="status"
      aria-live="polite"
      aria-label={statusText}
    >
      {/* Short bot icon on the left (avatar like WhatsApp) */}
      <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-zinc-200 bg-white p-0.5 shadow-xs overflow-hidden">
        <Image
          src={APP_LOGO_SRC}
          alt="UniPod Bot"
          width={28}
          height={28}
          className="h-full w-full object-contain"
        />
      </div>

      {/* WhatsApp-style typing bubble with 3 animated bouncing dots */}
      <div className="flex items-center rounded-2xl rounded-tl-xs border border-zinc-200/80 bg-white px-4 py-3 shadow-xs dark:border-zinc-700/80 dark:bg-[#1a1a1a]">
        <div className="flex items-center gap-1.5">
          <span className="h-2 w-2 rounded-full bg-zinc-500 animate-bounce [animation-delay:-0.32s] dark:bg-zinc-400" />
          <span className="h-2 w-2 rounded-full bg-zinc-500 animate-bounce [animation-delay:-0.16s] dark:bg-zinc-400" />
          <span className="h-2 w-2 rounded-full bg-zinc-500 animate-bounce dark:bg-zinc-400" />
        </div>
      </div>
    </div>
  );
}
