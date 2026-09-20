import { apiFetch } from "./client";
import type { Liveness } from "@/types/api";

/** `GET /api/v1/health/live` is the only product route the backend serves outside auth. */
export function getLiveness(signal?: AbortSignal): Promise<Liveness> {
  return apiFetch<Liveness>("/api/v1/health/live", { signal, timeoutMs: 5000 });
}
