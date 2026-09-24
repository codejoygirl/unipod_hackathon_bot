"use client";

import React, { useEffect } from "react";

type ImagePreviewLightboxProps = {
  previewUrl: string;
  filename: string;
  onClose: () => void;
};

export function ImagePreviewLightbox({
  previewUrl,
  filename,
  onClose,
}: ImagePreviewLightboxProps) {
  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if (e.key === "Escape") {
        onClose();
      }
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  return (
    <div
      className="fixed inset-0 z-[100] flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm"
      role="dialog"
      aria-modal="true"
      aria-label={`Preview ${filename}`}
      onClick={onClose}
    >
      <button
        type="button"
        onClick={onClose}
        className="absolute right-4 top-4 rounded-full bg-black/50 p-2 text-white transition hover:bg-black/70"
        aria-label="Close preview"
      >
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M18 6L6 18M6 6l12 12" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      </button>
      <div
        className="flex max-h-[90vh] max-w-[min(960px,100%)] flex-col items-center gap-2"
        onClick={(e) => e.stopPropagation()}
      >
        <p className="max-w-full truncate px-2 text-center text-sm font-medium text-white/90">
          {filename}
        </p>
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          src={previewUrl}
          alt={filename}
          className="max-h-[calc(90vh-2.5rem)] w-auto max-w-full rounded-lg object-contain shadow-2xl"
        />
      </div>
    </div>
  );
}
