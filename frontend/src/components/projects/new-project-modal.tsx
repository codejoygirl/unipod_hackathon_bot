"use client";

import { ApiError } from "@/lib/api/client";
import type { MemberVaultDocument } from "@/lib/api/types";
import { attachProjectDocuments, attachProjectFile, createProject } from "@/lib/web-chat/projects";
import { projectWorkspaceHref } from "@/lib/web-chat/url-params";
import { fetchVaultSnapshot, isVaultFile, VAULT_ACCEPT, VAULT_MAX_BATCH } from "@/lib/web-chat/vault";
import { useWebChat } from "@/lib/web-chat/web-chat-context";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useRef, useState } from "react";

type NewProjectModalProps = {
  isOpen: boolean;
  onClose: () => void;
};

export function NewProjectModal({ isOpen, onClose }: NewProjectModalProps) {
  const { memberPhone } = useWebChat();
  const router = useRouter();
  const fileInputRef = useRef<HTMLInputElement>(null);
  const nameInputRef = useRef<HTMLInputElement>(null);

  const [name, setName] = useState("");
  const [files, setFiles] = useState<File[]>([]);
  const [libraryDocs, setLibraryDocs] = useState<MemberVaultDocument[]>([]);
  const [libraryIds, setLibraryIds] = useState<string[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [nameError, setNameError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [isClosing, setIsClosing] = useState(false);

  const handleClose = useCallback((force = false) => {
    if (busy && !force) return;
    setIsClosing(true);
    setTimeout(() => {
      onClose();
      setIsClosing(false);
      setBusy(false);
      setName("");
      setFiles([]);
      setLibraryIds([]);
      setError(null);
      setNameError(null);
    }, 180);
  }, [busy, onClose]);

  useEffect(() => {
    if (!isOpen) return;
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") handleClose();
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [isOpen, handleClose]);

  useEffect(() => {
    if (!isOpen) return;
    const timer = window.setTimeout(() => nameInputRef.current?.focus(), 40);
    return () => window.clearTimeout(timer);
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen || !memberPhone) return;
    fetchVaultSnapshot(memberPhone)
      .then((res) => setLibraryDocs(res.data.documents ?? []))
      .catch(() => setLibraryDocs([]));
  }, [isOpen, memberPhone]);

  const addFiles = (incoming: FileList | File[] | null) => {
    if (!incoming) return;
    const next = Array.from(incoming).filter(isVaultFile);
    if (next.length === 0) {
      setError("Use a PDF, Word, text, markdown, CSV, or image file.");
      return;
    }
    setError(null);
    setFiles((prev) => [...prev, ...next].slice(0, VAULT_MAX_BATCH));
  };

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!memberPhone || busy) return;
    const trimmed = name.trim();
    if (!trimmed) {
      setNameError("Give this project a name.");
      return;
    }
    setNameError(null);
    setBusy(true);
    setError(null);
    try {
      const res = await createProject(memberPhone, trimmed);
      const projectId = res.data.project.id;
      for (const file of files) {
        await attachProjectFile(memberPhone, projectId, file);
      }
      if (libraryIds.length > 0) {
        await attachProjectDocuments(memberPhone, projectId, libraryIds);
      }
      handleClose(true);
      router.push(projectWorkspaceHref(projectId, memberPhone));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not create that project.");
      setBusy(false);
    }
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4">
      <div
        className={`fixed inset-0 bg-black/60 backdrop-blur-xs transition-opacity duration-200 ease-out ${
          isClosing ? "opacity-0" : "opacity-100"
        }`}
        onClick={() => handleClose()}
        aria-hidden="true"
      />

      <div
        className={`relative z-10 flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-[#181818] ${
          isClosing ? "animate-modal-out" : "animate-modal-in"
        }`}
        role="dialog"
        aria-labelledby="new-project-title"
        aria-modal="true"
      >
        <div className="flex items-center justify-between border-b border-zinc-100 px-5 py-4 dark:border-zinc-800">
          <div className="flex items-center gap-2.5">
            <div className="flex h-8 w-8 items-center justify-center rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden>
                <path
                  d="M3 7h6l2 2h10v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"
                  stroke="currentColor"
                  strokeWidth="1.75"
                  strokeLinejoin="round"
                />
              </svg>
            </div>
            <div>
              <h3 id="new-project-title" className="text-base font-semibold text-zinc-900 dark:text-zinc-100">
                New project
              </h3>
              <p className="text-xs text-zinc-500 dark:text-zinc-400">Give it a name. You can add files now or later.</p>
            </div>
          </div>
          <button
            type="button"
            onClick={() => handleClose()}
            disabled={busy}
            className="rounded-lg p-1.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200 cursor-pointer"
            aria-label="Close"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <line x1="18" y1="6" x2="6" y2="18" />
              <line x1="6" y1="6" x2="18" y2="18" />
            </svg>
          </button>
        </div>

        <form onSubmit={(event) => void handleSubmit(event)} className="flex min-h-0 flex-1 flex-col">
          <div className="space-y-4 overflow-y-auto p-5 no-scrollbar">
            <div>
              <label htmlFor="project-name" className="mb-1.5 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                Project name <span className="text-blue-600 dark:text-blue-400">*</span>
              </label>
              <input
                ref={nameInputRef}
                id="project-name"
                type="text"
                maxLength={80}
                value={name}
                onChange={(event) => {
                  setName(event.target.value);
                  if (nameError) setNameError(null);
                }}
                placeholder="e.g. Journal publication"
                className={`w-full rounded-xl border bg-white px-3.5 py-2.5 text-xs text-zinc-900 shadow-2xs placeholder:text-zinc-400 focus:outline-none focus:ring-1 dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder:text-zinc-500 ${
                  nameError
                    ? "border-rose-400 focus:border-rose-500 focus:ring-rose-500/20"
                    : "border-zinc-300 focus:border-blue-500 focus:ring-blue-500 dark:border-zinc-700"
                }`}
              />
              {nameError ? (
                <p className="mt-1 text-[11px] font-medium text-rose-600 dark:text-rose-400">{nameError}</p>
              ) : null}
            </div>

            <div>
              <p className="mb-1.5 text-xs font-semibold text-zinc-700 dark:text-zinc-300">Files</p>
              <input
                ref={fileInputRef}
                type="file"
                multiple
                className="sr-only"
                accept={VAULT_ACCEPT}
                onChange={(event) => {
                  addFiles(event.target.files);
                  event.target.value = "";
                }}
              />
              <button
                type="button"
                onClick={() => fileInputRef.current?.click()}
                onDragOver={(event) => {
                  if (event.dataTransfer.types.includes("Files")) event.preventDefault();
                }}
                onDrop={(event) => {
                  event.preventDefault();
                  addFiles(event.dataTransfer.files);
                }}
                className="flex w-full cursor-pointer flex-col items-center justify-center rounded-xl border border-dashed border-zinc-300 px-4 py-5 text-center hover:border-zinc-400 dark:border-zinc-700 dark:hover:border-zinc-500"
              >
                <p className="text-xs font-medium text-zinc-700 dark:text-zinc-200">Drop files here or click to add</p>
                <p className="mt-1 text-[11px] text-zinc-500">PDF, Word, text, markdown, CSV, or images.</p>
              </button>
              {files.length > 0 ? (
                <ul className="mt-2 flex flex-wrap gap-1.5">
                  {files.map((file, index) => (
                    <li
                      key={`${file.name}-${index}`}
                      title={file.name}
                      className="inline-flex max-w-[220px] min-w-0 items-center gap-1 rounded-full border border-zinc-200 px-2 py-1 text-[11px] font-medium text-zinc-600 dark:border-zinc-700 dark:text-zinc-300"
                    >
                      <span className="min-w-0 truncate">{file.name}</span>
                      <button
                        type="button"
                        className="shrink-0 text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200"
                        onClick={() => setFiles((prev) => prev.filter((_, i) => i !== index))}
                        aria-label={`Remove ${file.name}`}
                      >
                        ×
                      </button>
                    </li>
                  ))}
                </ul>
              ) : null}
            </div>

            {libraryDocs.length > 0 ? (
              <div>
                <p className="mb-1.5 text-xs font-semibold text-zinc-700 dark:text-zinc-300">From Library</p>
                <div className="flex flex-wrap gap-1.5">
                  {libraryDocs.slice(0, 12).map((doc) => {
                    const on = libraryIds.includes(doc.id);
                    return (
                      <button
                        key={doc.id}
                        type="button"
                        title={doc.filename}
                        onClick={() =>
                          setLibraryIds((prev) =>
                            prev.includes(doc.id) ? prev.filter((id) => id !== doc.id) : [...prev, doc.id],
                          )
                        }
                        className={`max-w-[160px] truncate rounded-full border px-2.5 py-1 text-[11px] font-medium cursor-pointer ${
                          on
                            ? "border-blue-500 bg-blue-50 text-blue-800 dark:border-blue-400 dark:bg-blue-950/50 dark:text-blue-200"
                            : "border-zinc-200 text-zinc-500 hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
                        }`}
                      >
                        {doc.filename}
                      </button>
                    );
                  })}
                </div>
              </div>
            ) : null}

            {error ? <p className="text-xs text-red-600 dark:text-red-300">{error}</p> : null}
          </div>

          <div className="flex items-center justify-end gap-2 border-t border-zinc-100 px-5 py-3 dark:border-zinc-800">
            <button
              type="button"
              onClick={() => handleClose()}
              disabled={busy}
              className="rounded-lg px-3 py-1.5 text-xs font-semibold text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800 cursor-pointer"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={busy || !memberPhone}
              className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-blue-500 disabled:opacity-50 cursor-pointer"
            >
              {busy ? "Creating…" : "Create project"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
