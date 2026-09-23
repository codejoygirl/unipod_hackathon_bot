import { ApiError, apiFetch } from "./client";
import type { AskPayload, AskResponse } from "@/types/api";

/**
 * Diagnostics: trace every question and its raw response in the browser console, so a
 * BLOCKED or empty answer can be read without opening the network tab.
 *
 * Dev only. In a production build these become no-ops, so nothing leaks to the console.
 */
const DEV = process.env.NODE_ENV !== "production";
const trace = (...args: unknown[]) => {
  if (DEV) console.log(...args);
};
const traceError = (...args: unknown[]) => {
  if (DEV) console.error(...args);
};

/**
 * `Ask` is synchronous: there is no job to poll. It can take seconds, so it gets a
 * longer timeout than the default.
 *
 * Known status codes the UI must handle:
 * - 403 when the member is in no community
 * - 404 when the conversation is not theirs
 * - 422 when the requested communities span more than one tenant
 */
export function ask(payload: AskPayload, signal?: AbortSignal): Promise<AskResponse> {
  const sentAt = performance.now();

  trace("[zak:ask] → request", {
    query: payload.query,
    conversation_id: payload.conversation_id ?? "(new thread)",
    community_ids: payload.community_ids ?? "(server resolves from session)",
  });

  return apiFetch<AskResponse>("/api/v1/assistant/ask", {
    method: "POST",
    body: payload,
    signal,
    timeoutMs: 60_000,
  })
    .then((response) => {
      trace("[zak:ask] ← response", {
        ms: Math.round(performance.now() - sentAt),
        state: response.data.state,
        confidence: response.data.confidence,
        answer: response.data.answer,
        escalation_reason: response.data.escalation_reason,
        needs_escalation: response.data.needs_escalation,
        citations: response.data.evidence_drawer,
        conflicts: response.data.conflicts,
        meta: response.meta,
      });

      return response;
    })
    .catch((error: unknown) => {
      traceError("[zak:ask] ✕ failed", {
        ms: Math.round(performance.now() - sentAt),
        status: error instanceof ApiError ? error.status : null,
        message: error instanceof Error ? error.message : String(error),
        body: error instanceof ApiError ? error.payload : undefined,
      });

      throw error;
    });
}
