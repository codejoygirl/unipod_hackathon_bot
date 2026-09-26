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
      className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-800 disabled:cursor-wait disabled:opacity-70 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
      aria-label={busy ? "Downloading" : label}
      title={busy ? "Downloading" : label}
    >
      {busy ? (
        <CircularLoader size="xs" className="border-zinc-300/80 border-t-zinc-700 dark:border-zinc-600 dark:border-t-zinc-200" />
      ) : (
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden>
          <path d="M12 4v11" strokeLinecap="round" />
          <path d="M7.5 11.5 12 16l4.5-4.5" strokeLinecap="round" strokeLinejoin="round" />
          <path d="M5 19h14" strokeLinecap="round" />
        </svg>
      )}
    </button>
  );
}
