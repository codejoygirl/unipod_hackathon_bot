import { apiFetch } from "./client";
import type { AskPayload, AskResponse } from "@/types/api";

/**
 * `Ask` is synchronous: there is no job to poll. It can take seconds, so it gets a
 * longer timeout than the default.
 *
 * Known status codes the UI must handle:
 * - 403 when none of the requested communities are accessible
 * - 422 when the requested communities span more than one tenant
 */
export function ask(payload: AskPayload, signal?: AbortSignal): Promise<AskResponse> {
  return apiFetch<AskResponse>("/api/v1/assistant/ask", {
    method: "POST",
    body: payload,
    signal,
    timeoutMs: 60_000,
  });
}
