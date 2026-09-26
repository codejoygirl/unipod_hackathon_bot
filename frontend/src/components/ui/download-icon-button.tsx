"use client";

import { CircularLoader } from "@/components/ui/circular-loader";

type DownloadIconButtonProps = {
  busy?: boolean;
  onClick: () => void;
  label?: string;
};

export function DownloadIconButton({
  busy = false,
  onClick,
  label = "Download",
}: DownloadIconButtonProps) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={busy}
      className="flex h-10 w-10 shrink-0 items-center justify-center rounded-[18px] bg-white text-zinc-800 shadow-[0_10px_28px_rgba(0,0,0,0.14)] ring-1 ring-black/5 transition hover:bg-zinc-50 disabled:cursor-wait disabled:opacity-80 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100"
      aria-label={busy ? "Downloading" : label}
      title={busy ? "Downloading" : label}
    >
      {busy ? (
        <CircularLoader size="xs" className="border-zinc-300/80 border-t-zinc-800" />
      ) : (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden>
          <path d="M12 4v11" strokeLinecap="round" />
          <path d="M7.5 11.5 12 16l4.5-4.5" strokeLinecap="round" strokeLinejoin="round" />
          <path d="M5 19h14" strokeLinecap="round" />
        </svg>
      )}
    </button>
  );
}
