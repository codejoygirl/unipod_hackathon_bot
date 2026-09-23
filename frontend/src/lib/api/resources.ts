import { apiFetch } from "./client";
import type { ResourceLinkResponse } from "@/types/api";

/**
 * Links extracted from the member's published community knowledge. This scans stored
 * documents, so it gets a longer budget than a plain read.
 */
export function listResources(signal?: AbortSignal): Promise<ResourceLinkResponse> {
  return apiFetch<ResourceLinkResponse>("/api/v1/resources", {
    signal,
    timeoutMs: 30_000,
  });
}
