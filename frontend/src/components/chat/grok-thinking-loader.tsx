"use client";

import Image from "next/image";
import { APP_LOGO_SRC } from "@/lib/branding";

export function GrokThinkingLoader() {
  return (
    <div className="flex items-end gap-2 self-start py-1">
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
      <div className="flex items-center gap-1.5 rounded-2xl rounded-tl-xs border border-zinc-200/80 bg-white px-4 py-3 shadow-xs">
        <span className="h-2 w-2 rounded-full bg-zinc-500 animate-bounce [animation-delay:-0.32s]" />
        <span className="h-2 w-2 rounded-full bg-zinc-500 animate-bounce [animation-delay:-0.16s]" />
        <span className="h-2 w-2 rounded-full bg-zinc-500 animate-bounce" />
      </div>
    </div>
  );
}
