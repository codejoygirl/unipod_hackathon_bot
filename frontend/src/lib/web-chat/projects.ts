import { apiFetch } from "@/lib/api/client";
import type {
  MemberProjectChatResponse,
  MemberProjectSnapshotResponse,
  MemberProjectSummary,
  MemberVaultDocument,
} from "@/lib/api/types";

export async function listProjects(phone: string): Promise<MemberProjectSummary[]> {
  const qs = new URLSearchParams({ phone });
  const res = await apiFetch<{ data: MemberProjectSummary[] }>(`/api/v1/web-chat/projects?${qs.toString()}`);
  return res.data ?? [];
}

export async function createProject(phone: string, name: string): Promise<MemberProjectSnapshotResponse> {
  const res = await apiFetch<MemberProjectSnapshotResponse>("/api/v1/web-chat/projects", {
    method: "POST",
    body: JSON.stringify({ phone, name }),
  });
  notifyProjectsChanged();
  return res;
}

export async function fetchProject(phone: string, projectId: string): Promise<MemberProjectSnapshotResponse> {
  const qs = new URLSearchParams({ phone });
  return apiFetch<MemberProjectSnapshotResponse>(`/api/v1/web-chat/projects/${projectId}?${qs.toString()}`);
}

export async function deleteProject(phone: string, projectId: string): Promise<void> {
  const qs = new URLSearchParams({ phone });
  await apiFetch(`/api/v1/web-chat/projects/${projectId}?${qs.toString()}`, { method: "DELETE" });
  notifyProjectsChanged();
}

export async function renameProject(
  phone: string,
  projectId: string,
  name: string,
): Promise<MemberProjectSnapshotResponse> {
  const res = await apiFetch<MemberProjectSnapshotResponse>(`/api/v1/web-chat/projects/${projectId}`, {
    method: "PATCH",
    body: JSON.stringify({ phone, name }),
  });
  notifyProjectsChanged();
  return res;
}

const PROJECTS_CHANGED = "zak-projects-changed";

export function notifyProjectsChanged(): void {
  if (typeof window === "undefined") return;
  window.dispatchEvent(new Event(PROJECTS_CHANGED));
}

export function onProjectsChanged(listener: () => void): () => void {
  if (typeof window === "undefined") return () => {};
  window.addEventListener(PROJECTS_CHANGED, listener);
  return () => window.removeEventListener(PROJECTS_CHANGED, listener);
}

export async function attachProjectFile(
  phone: string,
  projectId: string,
  file: File,
): Promise<MemberVaultDocument[]> {
  const form = new FormData();
  form.append("phone", phone);
  form.append("file", file);
  const res = await apiFetch<{ data: { documents: MemberVaultDocument[]; uploaded: MemberVaultDocument[] } }>(
    `/api/v1/web-chat/projects/${projectId}/files`,
    { method: "POST", body: form },
  );
  return res.data.documents ?? [];
}

export async function attachProjectDocuments(
  phone: string,
  projectId: string,
  documentIds: string[],
): Promise<MemberVaultDocument[]> {
  const res = await apiFetch<{ data: { documents: MemberVaultDocument[] } }>(
    `/api/v1/web-chat/projects/${projectId}/files`,
    {
      method: "POST",
      body: JSON.stringify({ phone, document_ids: documentIds }),
    },
  );
  return res.data.documents ?? [];
}

export async function detachProjectFile(phone: string, projectId: string, documentId: string): Promise<void> {
  const qs = new URLSearchParams({ phone });
  await apiFetch(`/api/v1/web-chat/projects/${projectId}/files/${documentId}?${qs.toString()}`, {
    method: "DELETE",
  });
}

export async function createProjectChat(phone: string, projectId: string, title = "New chat") {
  return apiFetch<MemberProjectChatResponse>(`/api/v1/web-chat/projects/${projectId}/chats`, {
    method: "POST",
    body: JSON.stringify({ phone, title }),
  });
}

export async function fetchProjectChat(phone: string, projectId: string, chatId: string) {
  const qs = new URLSearchParams({ phone });
  return apiFetch<MemberProjectChatResponse>(
    `/api/v1/web-chat/projects/${projectId}/chats/${chatId}?${qs.toString()}`,
  );
}

export async function deleteProjectChat(phone: string, projectId: string, chatId: string): Promise<void> {
  const qs = new URLSearchParams({ phone });
  await apiFetch(`/api/v1/web-chat/projects/${projectId}/chats/${chatId}?${qs.toString()}`, {
    method: "DELETE",
  });
}

export async function askProjectChat(
  phone: string,
  projectId: string,
  chatId: string,
  query: string,
  documentIds: string[] = [],
  kind = "ask",
  replaceMessageId?: string,
) {
  return apiFetch<{
    data: {
      chat: { id: string; title: string };
      answer: string;
      used_filenames: string[];
      artefact?: { id: string; kind: string; title: string; body: string; created_at: string | null } | null;
      message: {
        id: string;
        role: string;
        body: string;
        used_filenames: string[];
        artefact?: { id: string; kind: string; title: string; body: string; created_at: string | null } | null;
      };
    };
  }>(`/api/v1/web-chat/projects/${projectId}/chats/${chatId}/ask`, {
    method: "POST",
    body: JSON.stringify({
      phone,
      query,
      document_ids: documentIds,
      kind,
      ...(replaceMessageId ? { replace_message_id: replaceMessageId } : {}),
    }),
  });
}
