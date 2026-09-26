"use client";

import { ApiError } from "@/lib/api/client";
import { renameProject } from "@/lib/web-chat/projects";
import { useCallback, useEffect, useRef, useState } from "react";

type RenameProjectModalProps = {
  project: { id: string; name: string } | null;
  phone: string | null;
  onClose: () => void;
  onRenamed: (name: string) => void;
};

export function RenameProjectModal({ project, phone, onClose, onRenamed }: RenameProjectModalProps) {
  const [isClosing, setIsClosing] = useState(false);
  const [name, setName] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (!project) return;
    setName(project.name);
    setError(null);
    setBusy(false);
    const timer = window.setTimeout(() => {
      inputRef.current?.focus();
      inputRef.current?.select();
    }, 40);
    return () => window.clearTimeout(timer);
  }, [project]);

  const closeModal = useCallback(() => {
    setIsClosing(true);
    setTimeout(() => {
      onClose();
      setIsClosing(false);
      setBusy(false);
    }, 180);
  }, [onClose]);

  const handleClose = useCallback(() => {
    if (busy) return;
    closeModal();
  }, [busy, closeModal]);

  useEffect(() => {
    if (!project) return;
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") handleClose();
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [project, handleClose]);

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!project || !phone || busy) return;
    const trimmed = name.trim();
    if (!trimmed) {
      setError("Give this project a name.");
      return;
    }
    if (trimmed === project.name) {
      closeModal();
      return;
    }
    setBusy(true);
    setError(null);
    try {
      const res = await renameProject(phone, project.id, trimmed);
      onRenamed(res.data.project.name);
      closeModal();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not rename that project.");
      setBusy(false);
    }
  };

  if (!project) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4">
      <div
        className={`fixed inset-0 bg-black/60 backdrop-blur-xs transition-opacity duration-200 ease-out ${
          isClosing ? "opacity-0" : "opacity-100"
        }`}
        onClick={handleClose}
        aria-hidden="true"
      />
      <form
        onSubmit={(event) => void handleSubmit(event)}
        className={`relative z-10 w-full max-w-md overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-[#181818] ${
          isClosing ? "animate-modal-out" : "animate-modal-in"
        }`}
        role="dialog"
        aria-labelledby="rename-project-title"
        aria-modal="true"
      >
        <div className="px-5 pt-5">
          <h2 id="rename-project-title" className="text-base font-semibold text-zinc-900 dark:text-zinc-100">
            Rename project
          </h2>
          <label htmlFor="rename-project-name" className="mt-3 mb-1.5 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">
            Name
          </label>
          <input
            ref={inputRef}
            id="rename-project-name"
            value={name}
            maxLength={80}
            onChange={(event) => setName(event.target.value)}
            className="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none ring-0 focus:border-blue-500 dark:border-zinc-700 dark:bg-[#111] dark:text-zinc-100"
          />
          {error ? <p className="mt-2 text-xs text-red-600 dark:text-red-300">{error}</p> : null}
        </div>
        <div className="mt-5 flex items-center justify-end gap-2 border-t border-zinc-100 px-5 py-3 dark:border-zinc-800">
          <button
            type="button"
            onClick={handleClose}
            disabled={busy}
            className="rounded-lg px-3 py-1.5 text-xs font-semibold text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
          >
            Cancel
          </button>
          <button
            type="submit"
            disabled={busy}
            className="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-500 disabled:opacity-50"
          >
            {busy ? "Saving…" : "Save"}
          </button>
        </div>
      </form>
    </div>
  );
}
