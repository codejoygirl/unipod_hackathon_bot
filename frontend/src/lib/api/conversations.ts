import { apiFetch } from "./client";
import type { ApiResource, Conversation, ConversationSummary } from "@/types/api";

/** Sidebar list: the signed-in member's own threads, most recent first. */
export function listConversations(
  signal?: AbortSignal,
): Promise<ApiResource<ConversationSummary[]>> {
  return apiFetch<ApiResource<ConversationSummary[]>>("/api/v1/conversations", { signal });
}

/** One thread with its ordered messages. */
export function getConversation(
  id: string,
  signal?: AbortSignal,
): Promise<ApiResource<Conversation>> {
  return apiFetch<ApiResource<Conversation>>(`/api/v1/conversations/${id}`, { signal });
}

/**
 * Start an empty thread. The tenant is optional: the API falls back to the member's
 * first accessible one, so opening a chat never requires picking anything.
 */
export function createConversation(
  tenantId?: string | null,
): Promise<ApiResource<ConversationSummary>> {
  return apiFetch<ApiResource<ConversationSummary>>("/api/v1/conversations", {
    method: "POST",
    body: tenantId ? { tenant_id: tenantId } : {},
  });
}

export function deleteConversation(id: string): Promise<void> {
  return apiFetch<void>(`/api/v1/conversations/${id}`, { method: "DELETE" });
}
