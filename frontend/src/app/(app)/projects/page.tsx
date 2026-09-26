"use client";

import { ChatHeader } from "@/components/chat/chat-header";
import { DeleteProjectModal } from "@/components/projects/delete-project-modal";
import { RenameProjectModal } from "@/components/projects/rename-project-modal";
import { ApiError } from "@/lib/api/client";
import type { MemberProjectSummary } from "@/lib/api/types";
import { deleteProject, listProjects, onProjectsChanged } from "@/lib/web-chat/projects";
import { useSidebar } from "@/lib/sidebar/sidebar-context";
import { useWebChat } from "@/lib/web-chat/web-chat-context";
import { projectWorkspaceHref } from "@/lib/web-chat/url-params";
import Link from "next/link";
import { useCallback, useEffect, useState } from "react";

export default function ProjectsPage() {
  const { memberPhone } = useWebChat();
  const { openProjectModal } = useSidebar();
  const [projects, setProjects] = useState<MemberProjectSummary[]>([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [pendingDelete, setPendingDelete] = useState<MemberProjectSummary | null>(null);
  const [pendingRename, setPendingRename] = useState<MemberProjectSummary | null>(null);

  const load = useCallback(async () => {
    if (!memberPhone) {
      setLoading(false);
      return;
    }
    setLoading(true);
    try {
      setProjects(await listProjects(memberPhone));
      setError(null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load projects.");
    } finally {
      setLoading(false);
    }
  }, [memberPhone]);

  useEffect(() => {
    void load();
  }, [load]);

  useEffect(() => onProjectsChanged(() => void load()), [load]);

  const handleCreate = () => {
    if (!memberPhone) return;
    openProjectModal();
  };

  const handleDelete = async () => {
    if (!memberPhone || !pendingDelete) return;
    setBusy(true);
    try {
      await deleteProject(memberPhone, pendingDelete.id);
      setProjects((prev) => prev.filter((item) => item.id !== pendingDelete.id));
      setPendingDelete(null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not delete that project.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="relative flex h-full min-w-0 flex-1 flex-col overflow-hidden bg-white text-zinc-900 dark:bg-[#0d0d0d] dark:text-zinc-100">
      <ChatHeader />
      <div className="flex-1 overflow-y-auto px-4 py-6 sm:px-6">
        <div className="mx-auto w-full max-w-2xl space-y-5">
          <div className="flex items-start justify-between gap-3 border-b border-zinc-200/80 pb-5 dark:border-zinc-800/80">
            <div className="min-w-0">
              <h1 className="text-xl font-bold tracking-tight sm:text-2xl">Projects</h1>
              <p className="mt-1 text-xs text-zinc-500 sm:text-sm dark:text-zinc-400">
                Each project keeps its own files and chat history, so you can work in different contexts.
              </p>
            </div>
            <button
              type="button"
              disabled={busy || !memberPhone}
              onClick={() => void handleCreate()}
              className="inline-flex shrink-0 items-center gap-1.5 self-start rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-blue-500 disabled:opacity-50 cursor-pointer sm:self-center"
            >
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" aria-hidden>
                <line x1="12" y1="5" x2="12" y2="19" />
                <line x1="5" y1="12" x2="19" y2="12" />
              </svg>
              Create project
            </button>
          </div>

          {error ? <p className="text-xs text-red-600 dark:text-red-300">{error}</p> : null}

          {loading ? (
            <ul className="space-y-0.5" aria-busy="true" aria-label="Loading projects">
              {[1, 2, 3].map((i) => (
                <li key={i} className="flex items-center gap-2.5 rounded-xl px-2.5 py-2 animate-pulse">
                  <div className="h-4 w-4 shrink-0 rounded bg-zinc-200 dark:bg-zinc-800" />
                  <div className="h-3.5 flex-1 rounded bg-zinc-200 dark:bg-zinc-800" />
                  <div className="h-3 w-20 shrink-0 rounded bg-zinc-100 dark:bg-zinc-800/60" />
                </li>
              ))}
            </ul>
          ) : projects.length === 0 ? (
            <p className="px-0.5 py-2 text-sm text-zinc-400 dark:text-zinc-500" role="status">
              No projects
            </p>
          ) : (
            <ul className="space-y-0.5">
              {projects.map((project) => (
                <li
                  key={project.id}
                  className="group flex items-center rounded-xl pr-1 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800/70"
                >
                  <Link
                    href={projectWorkspaceHref(project.id, memberPhone)}
                    className="flex min-w-0 flex-1 items-center gap-2.5 px-2.5 py-2"
                  >
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" className="shrink-0" aria-hidden>
                      <path
                        d="M3 7h6l2 2h10v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"
                        stroke="currentColor"
                        strokeWidth="1.75"
                        strokeLinejoin="round"
                      />
                    </svg>
                    <span className="min-w-0 flex-1 truncate text-sm font-medium">{project.name}</span>
                    <span className="shrink-0 text-[11px] text-zinc-400">
                      {project.file_count} files · {project.chat_count} chats
                    </span>
                  </Link>
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() => setPendingRename(project)}
                    className="flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-zinc-400 opacity-0 transition hover:bg-zinc-200 hover:text-zinc-700 group-hover:opacity-100 dark:hover:bg-zinc-700 dark:hover:text-zinc-200"
                    aria-label={`Rename ${project.name}`}
                  >
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
                      <path d="M12 20h9" />
                      <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z" />
                    </svg>
                  </button>
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() => setPendingDelete(project)}
                    className="flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-zinc-400 opacity-0 transition hover:bg-zinc-200 hover:text-zinc-700 group-hover:opacity-100 dark:hover:bg-zinc-700 dark:hover:text-zinc-200"
                    aria-label={`Delete ${project.name}`}
                  >
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" aria-hidden>
                      <line x1="18" y1="6" x2="6" y2="18" />
                      <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
      <RenameProjectModal
        project={pendingRename}
        phone={memberPhone}
        onClose={() => setPendingRename(null)}
        onRenamed={(name) => {
          setProjects((prev) =>
            prev.map((item) => (item.id === pendingRename?.id ? { ...item, name } : item)),
          );
        }}
      />
      <DeleteProjectModal
        project={pendingDelete}
        busy={busy}
        onClose={() => setPendingDelete(null)}
        onConfirm={() => void handleDelete()}
      />
    </div>
  );
}
