"use client";

import { ChatHeader } from "@/components/chat/chat-header";
import { CircularLoader } from "@/components/ui/circular-loader";
import { RemoveFileModal } from "@/components/vault/remove-file-modal";
import { apiFetch, ApiError } from "@/lib/api/client";
import { getApiBaseUrl } from "@/lib/api/config";
import type { MemberVaultDocument } from "@/lib/api/types";
import {
  VAULT_ACCEPT,
  VAULT_MAX_BATCH,
  fetchVaultSnapshot,
  formatVaultBytes,
  isVaultFile,
  uploadVaultFiles,
  vaultUploadError,
} from "@/lib/web-chat/vault";
import { usePageSize } from "@/lib/ui/use-page-size";
import { useWebChat } from "@/lib/web-chat/web-chat-context";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";

type FileViewMode = "grid" | "table";
type FileKindFilter = "all" | "pdf" | "document" | "image" | "text";

function normalizeKind(kind: string | undefined): FileKindFilter {
  if (kind === "pdf" || kind === "image" || kind === "document" || kind === "text") return kind;
  return "text";
}

function fileKindLabel(kind: string | undefined): FileKindFilter | "PDF" | "Image" | "Doc" | "Text" {
  const normalized = normalizeKind(kind);
  if (normalized === "pdf") return "PDF";
  if (normalized === "image") return "Image";
  if (normalized === "document") return "Doc";
  return "Text";
}

function FileActions({
  downloading,
  removeDisabled,
  compact = false,
  onDownload,
  onRemove,
}: {
  downloading: boolean;
  removeDisabled: boolean;
  compact?: boolean;
  onDownload: () => void;
  onRemove: () => void;
}) {
  const pad = compact ? "px-2 text-[11px]" : "px-2.5 text-xs";
  return (
    <div className="flex h-8 flex-nowrap items-center gap-2">
      <button
        type="button"
        disabled={downloading}
        onClick={onDownload}
        aria-busy={downloading}
        className={`inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg border border-zinc-200 bg-white font-semibold text-zinc-700 shadow-2xs transition hover:bg-zinc-50 hover:text-zinc-950 disabled:cursor-wait disabled:opacity-70 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700 cursor-pointer ${pad}`}
      >
        {downloading ? (
          <CircularLoader size="xs" className="border-zinc-300/80 border-t-zinc-700 dark:border-zinc-600 dark:border-t-zinc-200" />
        ) : (
          <DownloadIcon />
        )}
        Download
      </button>
      <button
        type="button"
        disabled={removeDisabled}
        onClick={onRemove}
        className={`inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg border border-transparent font-semibold text-zinc-500 transition hover:border-rose-200/80 hover:bg-rose-50 hover:text-rose-600 disabled:opacity-50 dark:hover:border-rose-900/50 dark:hover:bg-rose-950/40 dark:hover:text-rose-300 cursor-pointer ${pad}`}
      >
        <TrashIcon />
        Remove
      </button>
    </div>
  );
}

export default function MyDocsPage() {
  const { memberPhone } = useWebChat();
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [documents, setDocuments] = useState<MemberVaultDocument[]>([]);
  const [maxFiles, setMaxFiles] = useState(30);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [dragging, setDragging] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fileQuery, setFileQuery] = useState("");
  const [viewMode, setViewMode] = useState<FileViewMode>("grid");
  const [kindFilter, setKindFilter] = useState<FileKindFilter>("all");
  const [currentPage, setCurrentPage] = useState(1);
  const [downloadingId, setDownloadingId] = useState<string | null>(null);
  const [pendingRemove, setPendingRemove] = useState<MemberVaultDocument | null>(null);
  const pageSize = usePageSize();

  const loadVault = useCallback(async () => {
    if (!memberPhone) {
      setLoading(false);
      return;
    }
    setLoading(true);
    try {
      const res = await fetchVaultSnapshot(memberPhone);
      setDocuments(res.data.documents ?? []);
      setMaxFiles(res.data.limits?.max_files ?? 30);
      setError(null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load Library.");
    } finally {
      setLoading(false);
    }
  }, [memberPhone]);

  useEffect(() => {
    void loadVault();
  }, [loadVault]);

  const visibleFiles = useMemo(() => {
    const q = fileQuery.trim().toLowerCase();
    return documents.filter((doc) => {
      const kind = normalizeKind(doc.kind);
      if (kindFilter !== "all" && kind !== kindFilter) return false;
      if (!q) return true;
      return (
        doc.filename.toLowerCase().includes(q) ||
        (doc.excerpt || "").toLowerCase().includes(q) ||
        kind.includes(q)
      );
    });
  }, [documents, fileQuery, kindFilter]);

  const totalPages = Math.max(1, Math.ceil(visibleFiles.length / pageSize));
  const paginatedFiles = useMemo(() => {
    const start = (currentPage - 1) * pageSize;
    return visibleFiles.slice(start, start + pageSize);
  }, [visibleFiles, currentPage, pageSize]);

  useEffect(() => {
    setCurrentPage(1);
  }, [fileQuery, kindFilter]);

  useEffect(() => {
    if (currentPage > totalPages) setCurrentPage(totalPages);
  }, [currentPage, totalPages]);

  const kindCounts = useMemo(
    () => ({
      all: documents.length,
      pdf: documents.filter((doc) => normalizeKind(doc.kind) === "pdf").length,
      document: documents.filter((doc) => normalizeKind(doc.kind) === "document").length,
      image: documents.filter((doc) => normalizeKind(doc.kind) === "image").length,
      text: documents.filter((doc) => normalizeKind(doc.kind) === "text").length,
    }),
    [documents],
  );

  const handleUpload = async (incoming: FileList | File[] | null | undefined) => {
    if (!memberPhone || !incoming) return;
    const files = Array.from(incoming).filter(isVaultFile).slice(0, VAULT_MAX_BATCH);
    if (files.length === 0) {
      setError("Use a PDF, Word, text, markdown, CSV, or image file.");
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await uploadVaultFiles(memberPhone, files);
      await loadVault();
    } catch (err) {
      setError(vaultUploadError(err));
    } finally {
      setBusy(false);
      if (fileInputRef.current) fileInputRef.current.value = "";
    }
  };

  const handleDelete = async () => {
    if (!memberPhone || !pendingRemove) return;
    setBusy(true);
    setError(null);
    try {
      const qs = new URLSearchParams({ phone: memberPhone });
      await apiFetch(`/api/v1/web-chat/vault/documents/${pendingRemove.id}?${qs.toString()}`, {
        method: "DELETE",
      });
      const removedId = pendingRemove.id;
      setDocuments((prev) => prev.filter((doc) => doc.id !== removedId));
      setPendingRemove(null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not remove that file.");
    } finally {
      setBusy(false);
    }
  };

  const handleDownload = async (doc: MemberVaultDocument) => {
    if (!memberPhone || downloadingId) return;
    setDownloadingId(doc.id);
    setError(null);
    try {
      const qs = new URLSearchParams({ phone: memberPhone });
      const res = await fetch(
        `${getApiBaseUrl()}/api/v1/web-chat/vault/documents/${doc.id}/download?${qs.toString()}`,
        { credentials: "include" },
      );
      if (!res.ok) throw new Error("download");
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = doc.filename;
      link.click();
      URL.revokeObjectURL(url);
    } catch {
      setError("Could not download that file.");
    } finally {
      setDownloadingId(null);
    }
  };

  return (
    <div className="relative flex h-full min-w-0 flex-1 flex-col overflow-hidden bg-white text-zinc-900 dark:bg-[#0d0d0d] dark:text-zinc-100">
      <ChatHeader />

      <div className="flex-1 overflow-y-auto no-scrollbar px-4 py-6 sm:px-6">
        <div className="mx-auto w-full max-w-5xl space-y-6">
          <div className="flex items-start justify-between gap-3 border-b border-zinc-200/80 pb-5 dark:border-zinc-800/80">
            <div className="min-w-0">
              <h1 className="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100 sm:text-2xl">
                Library
              </h1>
              <p className="mt-1 text-xs text-zinc-500 sm:text-sm dark:text-zinc-400">
                Upload, download, and remove your private files.
              </p>
            </div>
            <div className="flex shrink-0 items-center gap-2 self-start sm:self-center">
              <span className="tabular-nums text-[11px] text-zinc-400">{documents.length}/{maxFiles}</span>
              <label className="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-blue-500">
                <input
                  ref={fileInputRef}
                  type="file"
                  multiple
                  className="sr-only"
                  accept={VAULT_ACCEPT}
                  disabled={busy || !memberPhone}
                  onChange={(event) => void handleUpload(event.target.files)}
                />
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                  <line x1="12" y1="5" x2="12" y2="19" />
                  <line x1="5" y1="12" x2="19" y2="12" />
                </svg>
                Add files
              </label>
            </div>
          </div>

          {error ? (
            <p className="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-300">
              {error}
            </p>
          ) : null}

          <section className="space-y-4">
            <div
              role="button"
              tabIndex={0}
              onClick={() => fileInputRef.current?.click()}
              onKeyDown={(event) => {
                if (event.key === "Enter" || event.key === " ") fileInputRef.current?.click();
              }}
              onDragOver={(event) => {
                if (event.dataTransfer.types.includes("Files")) {
                  event.preventDefault();
                  setDragging(true);
                }
              }}
              onDragLeave={() => setDragging(false)}
              onDrop={(event) => {
                event.preventDefault();
                setDragging(false);
                void handleUpload(event.dataTransfer.files);
              }}
              className={`flex cursor-pointer flex-col items-center justify-center rounded-2xl border border-dashed px-4 py-7 text-center shadow-2xs transition ${
                dragging
                  ? "border-blue-500 bg-blue-50/70 dark:border-blue-400 dark:bg-blue-950/20"
                  : "border-zinc-200 bg-white hover:border-zinc-300 dark:border-zinc-800 dark:bg-[#181818] dark:hover:border-zinc-700"
              }`}
            >
              <p className="text-sm font-semibold text-zinc-800 dark:text-zinc-100">
                Drop files here or use Add files
              </p>
              <p className="mt-1 max-w-md text-xs text-zinc-500 dark:text-zinc-400">
                PDF, Word, text, markdown, CSV, or images. 8 MB each.
              </p>
            </div>

            <div className="flex flex-col gap-3">
              <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="relative max-w-md flex-1">
                  <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-zinc-400">
                    <SearchIcon />
                  </div>
                  <input
                    type="text"
                    value={fileQuery}
                    onChange={(event) => setFileQuery(event.target.value)}
                    placeholder="Search files..."
                    className="w-full rounded-xl border border-zinc-200/80 bg-white py-2 pl-9 pr-14 text-xs text-zinc-900 shadow-2xs placeholder:text-zinc-400 focus:border-zinc-400 focus:outline-hidden sm:text-sm dark:border-zinc-800 dark:bg-[#1a1a1a] dark:text-zinc-100 dark:placeholder:text-zinc-500 dark:focus:border-zinc-600"
                  />
                  {fileQuery ? (
                    <button
                      type="button"
                      onClick={() => setFileQuery("")}
                      className="absolute inset-y-0 right-0 flex items-center pr-3 text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200"
                    >
                      Clear
                    </button>
                  ) : null}
                </div>

                <div className="flex shrink-0 items-center self-end rounded-xl border border-zinc-200/80 bg-white p-1 shadow-2xs sm:self-auto dark:border-zinc-800 dark:bg-[#1a1a1a]">
                  <button
                    type="button"
                    onClick={() => setViewMode("grid")}
                    className={`inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition cursor-pointer ${
                      viewMode === "grid"
                        ? "bg-zinc-900 text-white shadow-2xs dark:bg-zinc-100 dark:text-zinc-900"
                        : "text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200"
                    }`}
                    aria-label="Grid view"
                  >
                    <GridIcon />
                    <span>Grid</span>
                  </button>
                  <button
                    type="button"
                    onClick={() => setViewMode("table")}
                    className={`inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition cursor-pointer ${
                      viewMode === "table"
                        ? "bg-zinc-900 text-white shadow-2xs dark:bg-zinc-100 dark:text-zinc-900"
                        : "text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200"
                    }`}
                    aria-label="Table view"
                  >
                    <TableIcon />
                    <span>Table</span>
                  </button>
                </div>
              </div>

              <div className="flex items-center gap-1.5 overflow-x-auto no-scrollbar py-0.5">
                {(
                  [
                    ["all", "All"],
                    ["pdf", "PDFs"],
                    ["document", "Docs"],
                    ["image", "Images"],
                    ["text", "Text"],
                  ] as const
                ).map(([value, label]) => (
                  <button
                    key={value}
                    type="button"
                    onClick={() => setKindFilter(value)}
                    className={`inline-flex shrink-0 items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium transition cursor-pointer ${
                      kindFilter === value
                        ? "bg-zinc-900 text-white shadow-2xs dark:bg-zinc-100 dark:text-zinc-900"
                        : "border border-zinc-200/80 bg-white text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:border-zinc-800 dark:bg-[#1a1a1a] dark:text-zinc-400 dark:hover:bg-zinc-800/80 dark:hover:text-zinc-200"
                    }`}
                  >
                    <span>{label}</span>
                    <span
                      className={`rounded-full px-1.5 text-[10px] font-semibold ${
                        kindFilter === value
                          ? "bg-zinc-800 text-zinc-200 dark:bg-zinc-200 dark:text-zinc-800"
                          : "bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400"
                      }`}
                    >
                      {kindCounts[value]}
                    </span>
                  </button>
                ))}
              </div>
            </div>

            {loading ? (
              <div className="grid gap-3.5 sm:grid-cols-2">
                {[1, 2, 3, 4].map((n) => (
                  <div
                    key={n}
                    className="animate-pulse rounded-2xl border border-zinc-200/80 bg-white p-4 shadow-2xs dark:border-zinc-800 dark:bg-[#181818]"
                  >
                    <div className="h-4 w-3/4 rounded bg-zinc-200 dark:bg-zinc-800" />
                    <div className="mt-2 h-3 w-1/2 rounded bg-zinc-100 dark:bg-zinc-800/60" />
                  </div>
                ))}
              </div>
            ) : visibleFiles.length === 0 ? (
              <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-zinc-200 bg-white p-8 text-center shadow-2xs dark:border-zinc-800 dark:bg-[#181818]">
                <h3 className="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                  {documents.length === 0 ? "No files yet" : "No files found"}
                </h3>
                <p className="mt-1 max-w-sm text-xs text-zinc-500 dark:text-zinc-400">
                  {documents.length === 0
                    ? "Upload a file to start your library."
                    : fileQuery
                      ? `No files match “${fileQuery}”.`
                      : "Nothing in this type yet."}
                </p>
              </div>
            ) : viewMode === "grid" ? (
              <ul className="grid gap-3.5 sm:grid-cols-2">
                {paginatedFiles.map((doc) => (
                  <li
                    key={doc.id}
                    className="flex flex-col justify-between rounded-2xl border border-zinc-200/80 bg-white p-4 shadow-2xs dark:border-zinc-800 dark:bg-[#181818]"
                  >
                    <div className="min-w-0">
                      <div className="flex items-center gap-2">
                        <span className="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                          {fileKindLabel(doc.kind)}
                        </span>
                        <span className="text-[11px] text-zinc-400">{formatVaultBytes(doc.byte_size)}</span>
                      </div>
                      <p className="mt-2 truncate text-sm font-semibold text-zinc-900 dark:text-zinc-100">{doc.filename}</p>
                      {doc.excerpt ? (
                        <p className="mt-1 line-clamp-2 text-xs text-zinc-500 dark:text-zinc-400">{doc.excerpt}</p>
                      ) : null}
                    </div>
                      <div className="mt-3 border-t border-zinc-100 pt-3 dark:border-zinc-800/60">
                        <FileActions
                          downloading={downloadingId === doc.id}
                          removeDisabled={busy}
                          onDownload={() => void handleDownload(doc)}
                          onRemove={() => setPendingRemove(doc)}
                        />
                      </div>
                  </li>
                ))}
              </ul>
            ) : (
              <div className="overflow-x-auto rounded-2xl border border-zinc-200/80 bg-white shadow-2xs dark:border-zinc-800 dark:bg-[#181818]">
                <table className="min-w-full text-left text-xs">
                  <thead className="border-b border-zinc-200/80 text-[11px] uppercase tracking-wide text-zinc-500 dark:border-zinc-800">
                    <tr>
                      <th className="px-4 py-2.5 font-semibold">File</th>
                      <th className="px-4 py-2.5 font-semibold">Type</th>
                      <th className="px-4 py-2.5 font-semibold">Size</th>
                      <th className="px-4 py-2.5 font-semibold">Status</th>
                      <th className="px-4 py-2.5 font-semibold text-right">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {paginatedFiles.map((doc) => (
                      <tr key={doc.id} className="border-b border-zinc-100 last:border-0 dark:border-zinc-800/80">
                        <td className="max-w-[240px] truncate px-4 py-3 font-medium text-zinc-900 dark:text-zinc-100">
                          {doc.filename}
                        </td>
                        <td className="px-4 py-3 text-zinc-500">{fileKindLabel(doc.kind)}</td>
                        <td className="px-4 py-3 text-zinc-500">{formatVaultBytes(doc.byte_size)}</td>
                        <td className="px-4 py-3 capitalize text-zinc-500">{doc.status}</td>
                        <td className="px-4 py-3 text-right">
                          <div className="inline-flex justify-end">
                            <FileActions
                              compact
                              downloading={downloadingId === doc.id}
                              removeDisabled={busy}
                              onDownload={() => void handleDownload(doc)}
                              onRemove={() => setPendingRemove(doc)}
                            />
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {visibleFiles.length > 0 ? (
              <div className="flex flex-col items-center justify-between gap-3 border-t border-zinc-200/80 pt-3 sm:flex-row dark:border-zinc-800/80">
                <span className="text-xs font-medium text-zinc-500 dark:text-zinc-400">
                  Showing {(currentPage - 1) * pageSize + 1}–
                  {Math.min(currentPage * pageSize, visibleFiles.length)} of {visibleFiles.length} files
                </span>
                {totalPages > 1 ? (
                  <div className="flex items-center gap-1.5">
                    <button
                      type="button"
                      disabled={currentPage <= 1}
                      onClick={() => setCurrentPage((page) => Math.max(1, page - 1))}
                      className="inline-flex items-center gap-1 rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-xs font-medium text-zinc-700 shadow-2xs transition hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-zinc-800 dark:bg-[#1a1a1a] dark:text-zinc-300 dark:hover:bg-zinc-800 cursor-pointer"
                    >
                      ‹ Previous
                    </button>
                    {Array.from({ length: totalPages }, (_, i) => i + 1).map((pageNum) => (
                      <button
                        key={pageNum}
                        type="button"
                        onClick={() => setCurrentPage(pageNum)}
                        className={`h-7 w-7 rounded-lg text-xs font-semibold transition cursor-pointer ${
                          currentPage === pageNum
                            ? "bg-zinc-900 text-white shadow-2xs dark:bg-zinc-100 dark:text-zinc-900"
                            : "border border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-[#1a1a1a] dark:text-zinc-400 dark:hover:bg-zinc-800"
                        }`}
                      >
                        {pageNum}
                      </button>
                    ))}
                    <button
                      type="button"
                      disabled={currentPage >= totalPages}
                      onClick={() => setCurrentPage((page) => Math.min(totalPages, page + 1))}
                      className="inline-flex items-center gap-1 rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-xs font-medium text-zinc-700 shadow-2xs transition hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-zinc-800 dark:bg-[#1a1a1a] dark:text-zinc-300 dark:hover:bg-zinc-800 cursor-pointer"
                    >
                      Next ›
                    </button>
                  </div>
                ) : null}
              </div>
            ) : null}
          </section>
        </div>
      </div>
      <RemoveFileModal
        filename={pendingRemove?.filename ?? null}
        busy={busy}
        onClose={() => {
          if (!busy) setPendingRemove(null);
        }}
        onConfirm={() => void handleDelete()}
      />
    </div>
  );
}

function SearchIcon() {
  return (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden>
      <circle cx="11" cy="11" r="8" stroke="currentColor" strokeWidth="2" />
      <path d="m21 21-4.35-4.35" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
    </svg>
  );
}

function GridIcon() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden>
      <rect x="3" y="3" width="7" height="7" rx="1.5" />
      <rect x="14" y="3" width="7" height="7" rx="1.5" />
      <rect x="14" y="14" width="7" height="7" rx="1.5" />
      <rect x="3" y="14" width="7" height="7" rx="1.5" />
    </svg>
  );
}

function TableIcon() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden>
      <rect x="3" y="3" width="18" height="18" rx="2" />
      <path d="M3 9h18M3 15h18M9 9v12" />
    </svg>
  );
}

function DownloadIcon() {
  return (
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden>
      <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" strokeLinecap="round" strokeLinejoin="round" />
      <polyline points="7 10 12 15 17 10" strokeLinecap="round" strokeLinejoin="round" />
      <line x1="12" y1="15" x2="12" y2="3" strokeLinecap="round" />
    </svg>
  );
}

function TrashIcon() {
  return (
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden>
      <polyline points="3 6 5 6 21 6" strokeLinecap="round" strokeLinejoin="round" />
      <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" strokeLinecap="round" strokeLinejoin="round" />
      <path d="M10 11v6M14 11v6M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" strokeLinecap="round" />
    </svg>
  );
}
