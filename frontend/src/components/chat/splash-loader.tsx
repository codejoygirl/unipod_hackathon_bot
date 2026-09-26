"use client";

import { APP_DISPLAY_NAME, APP_LOGO_SRC } from "@/lib/branding";
import Image from "next/image";

type SplashLoaderProps = {
  title?: string;
  caption?: string;
  compact?: boolean;
};

export function SplashLoader({
  title = APP_DISPLAY_NAME,
  caption,
  compact = false,
}: SplashLoaderProps) {
  return (
    <div
      className={`flex flex-col items-center justify-center text-center select-none animate-fade-in ${
        compact ? "flex-1 px-4 py-10" : "min-h-dvh px-6"
      }`}
      role="status"
      aria-label="Loading"
    >
      <div className="relative mb-5 flex h-14 w-14 items-center justify-center overflow-hidden rounded-2xl shadow-sm">
        <Image
          src={APP_LOGO_SRC}
          alt=""
          width={56}
          height={56}
          className="h-full w-full rounded-2xl object-contain"
          priority
        />
      </div>
      {!compact ? (
        <h2 className="text-base font-semibold tracking-tight text-zinc-900 sm:text-lg dark:text-zinc-100">
          {title}
        </h2>
      ) : null}
      {caption ? (
        <p className="mt-1 max-w-sm text-xs text-zinc-500 dark:text-zinc-400">{caption}</p>
      ) : null}
      <div className="mt-6 h-1 w-36 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800/80">
        <div className="h-full w-1/2 rounded-full bg-blue-500 animate-[indeterminate_1.4s_infinite_ease-in-out]" />
      </div>
    </div>
  );
}
