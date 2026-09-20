import { apiFetch } from "./client";
import type {
  ApiResource,
  ImportWhatsAppPayload,
  KnowledgeSource,
  Paginated,
  StoreKnowledgeSourcePayload,
} from "@/types/api";

export function getKnowledgeSources(
  page = 1,
  signal?: AbortSignal,
): Promise<Paginated<KnowledgeSource>> {
  return apiFetch<Paginated<KnowledgeSource>>(
    `/api/v1/knowledge-sources?page=${page}`,
    { signal },
  );
}

export function createKnowledgeSource(
  payload: StoreKnowledgeSourcePayload,
): Promise<ApiResource<KnowledgeSource>> {
  return apiFetch<ApiResource<KnowledgeSource>>("/api/v1/knowledge-sources", {
    method: "POST",
    body: payload,
  });
}

export function submitForReview(id: string): Promise<ApiResource<KnowledgeSource>> {
  return apiFetch<ApiResource<KnowledgeSource>>(
    `/api/v1/knowledge-sources/${id}/submit-review`,
    { method: "POST" },
  );
}

export function publishKnowledgeSource(id: string): Promise<ApiResource<KnowledgeSource>> {
  return apiFetch<ApiResource<KnowledgeSource>>(
    `/api/v1/knowledge-sources/${id}/publish`,
    { method: "POST" },
  );
}

export function rejectKnowledgeSource(
  id: string,
  reason?: string,
): Promise<ApiResource<KnowledgeSource>> {
  return apiFetch<ApiResource<KnowledgeSource>>(
    `/api/v1/knowledge-sources/${id}/reject`,
    { method: "POST", body: { reason: reason ?? null } },
  );
}

export function importWhatsAppKnowledge(
  payload: ImportWhatsAppPayload,
): Promise<ApiResource<KnowledgeSource>> {
  return apiFetch<ApiResource<KnowledgeSource>>(
    "/api/v1/knowledge-sources/import/whatsapp",
    { method: "POST", body: payload },
  );
}
