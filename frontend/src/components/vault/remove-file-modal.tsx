"use client";

import { useCallback, useEffect, useRef, useState } from "react";

type RemoveFileModalProps = {
  filename: string | null;
  busy?: boolean;
  onClose: () => void;
  onConfirm: () => void;
};

export function RemoveFileModal({ filename, busy = false, onClose, onConfirm }: RemoveFileModalProps) {
  const [isClosing, setIsClosing] = useState(false);
  const cancelRef = useRef<HTMLButtonElement>(null);

  const handleClose = useCallback(() => {
    if (busy) return;
    setIsClosing(true);
    setTimeout(() => {
      onClose();
      setIsClosing(false);
    }, 180);
  }, [busy, onClose]);

  useEffect(() => {
    if (!filename) return;
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") handleClose();
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [filename, handleClose]);

  useEffect(() => {
    if (!filename) return;
    const timer = window.setTimeout(() => cancelRef.current?.focus(), 40);
    return () => window.clearTimeout(timer);
  }, [filename]);

  if (!filename) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4">
      <div
        className={`fixed inset-0 bg-black/60 backdrop-blur-xs transition-opacity duration-200 ease-out ${
          isClosing ? "opacity-0" : "opacity-100"
        }`}
        onClick={handleClose}
        aria-hidden="true"
      />
      <div
        className={`relative z-10 w-full max-w-md overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-[#181818] ${
          isClosing ? "animate-modal-out" : "animate-modal-in"
        }`}
        role="alertdialog"
        aria-labelledby="remove-file-title"
        aria-describedby="remove-file-copy"
        aria-modal="true"
      >
        <div className="px-5 pt-5">
          <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-rose-50 text-rose-600 dark:bg-rose-950/50 dark:text-rose-400">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden>
              <polyline points="3 6 5 6 21 6" />
              <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
              <path d="M10 11v6M14 11v6" />
              <path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2" />
            </svg>
          </div>
          <h2 id="remove-file-title" className="text-base font-semibold text-zinc-900 dark:text-zinc-100">
            Remove this file?
          </h2>
          <p id="remove-file-copy" className="mt-1.5 text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">
            “{filename}” will be removed from Library. This cannot be undone.
          </p>
        </div>
        <div className="mt-5 flex items-center justify-end gap-2 border-t border-zinc-100 px-5 py-3 dark:border-zinc-800">
          <button
            ref={cancelRef}
            type="button"
            onClick={handleClose}
            disabled={busy}
            className="rounded-lg px-3 py-1.5 text-xs font-semibold text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800 cursor-pointer disabled:opacity-50"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={onConfirm}
            disabled={busy}
            className="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-rose-500 disabled:opacity-50 cursor-pointer"
          >
            {busy ? "Removing…" : "Remove file"}
          </button>
        </div>
      </div>
    </div>
  );
}
