import { apiFetch, ApiError } from "@/lib/api/client";
import { getApiBaseUrl } from "@/lib/api/config";
import type {
  AssistantAskResponse,
  MemberVaultArtefact,
  MemberVaultAskResponse,
  MemberVaultDocument,
  MemberVaultSnapshotResponse,
} from "@/lib/api/types";

export const VAULT_ACCEPT =
  ".txt,.md,.csv,.json,.pdf,.doc,.docx,.jpg,.jpeg,.png,.webp,.gif,text/plain,text/markdown,text/csv,application/json,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/jpeg,image/png,image/webp,image/gif";

export const VAULT_MAX_BATCH = 10;

export function formatVaultBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function isVaultFile(file: File): boolean {
  const mime = (file.type || "").toLowerCase();
  if (mime.startsWith("image/")) return true;
  if (
    [
      "text/plain",
      "text/markdown",
      "text/csv",
      "application/json",
      "application/pdf",
      "application/msword",
      "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    ].includes(mime)
  ) {
    return true;
  }
  return /\.(txt|md|csv|json|pdf|doc|docx|jpe?g|png|webp|gif)$/i.test(file.name);
}

export async function fetchVaultSnapshot(phone: string): Promise<MemberVaultSnapshotResponse> {
  const qs = new URLSearchParams({ phone });
  return apiFetch<MemberVaultSnapshotResponse>(`/api/v1/web-chat/vault?${qs.toString()}`);
}

export async function uploadVaultFiles(
  phone: string,
  files: File[],
): Promise<MemberVaultDocument[]> {
  if (files.length === 0) return [];
  const form = new FormData();
  form.append("phone", phone);
  if (files.length === 1) {
    form.append("file", files[0]);
  } else {
    for (const file of files) {
      form.append("files[]", file);
    }
  }
  const res = await apiFetch<
    { data: MemberVaultDocument | { documents: MemberVaultDocument[] } }
  >("/api/v1/web-chat/vault/documents", {
    method: "POST",
    body: form,
  });
  if ("documents" in res.data && Array.isArray(res.data.documents)) {
    return res.data.documents;
  }
  return [res.data as MemberVaultDocument];
}

export async function askVault(
  phone: string,
  query: string,
  documentIds: string[] = [],
): Promise<MemberVaultAskResponse> {
  return apiFetch<MemberVaultAskResponse>("/api/v1/web-chat/vault/ask", {
    method: "POST",
    body: JSON.stringify({
      phone,
      query,
      document_ids: documentIds,
    }),
  });
}

export function vaultAnswerAsAssistant(result: MemberVaultAskResponse): AssistantAskResponse {
  const used = result.data.used_filenames ?? result.data.citations ?? [];
  return {
    data: {
      state: "VERIFIED",
      answer: result.data.answer,
      confidence: null,
      detected_language: null,
      evidence_drawer: used.map((name) => ({
        evidence_id: name,
        source_name: name,
        source_uri: "",
        exact_quote: "",
        context: "Library",
        page: null,
        timestamp: null,
        authority: "member_vault",
      })),
      conflicts: [],
      needs_escalation: false,
      escalation_reason: null,
    },
    meta: {
      latency_ms: 0,
      chunks_evaluated: used.length,
      tenant_id: "",
      community_ids: [],
    },
  };
}

export function vaultUploadError(err: unknown): string {
  return err instanceof ApiError ? err.message : "That file could not be added.";
}

export function downloadTextFile(filename: string, body: string): void {
  const safe = filename.replace(/[^\w.\- ()]+/g, "_").slice(0, 80) || "document";
  const withExt = /\.(md|txt)$/i.test(safe) ? safe : `${safe}.md`;
  const blob = new Blob([body], { type: "text/markdown;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = withExt;
  link.click();
  URL.revokeObjectURL(url);
}

export async function downloadVaultArtefact(
  phone: string,
  artefact: MemberVaultArtefact,
): Promise<void> {
  const qs = new URLSearchParams({ phone });
  const res = await fetch(
    `${getApiBaseUrl()}/api/v1/web-chat/vault/artefacts/${artefact.id}/download?${qs.toString()}`,
    { credentials: "include" },
  );
  if (!res.ok) {
    downloadTextFile(artefact.title || "document", artefact.body);
    return;
  }
  const blob = await res.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  const header = res.headers.get("content-disposition") ?? "";
  const named = /filename="([^"]+)"/i.exec(header)?.[1];
  const ext = artefact.kind === "pdf" || blob.type.includes("pdf") ? "pdf" : "md";
  link.href = url;
  link.download = named || `${(artefact.title || "document").replace(/[^\w.\- ()]+/g, "_").slice(0, 80)}.${ext}`;
  link.click();
  URL.revokeObjectURL(url);
}
