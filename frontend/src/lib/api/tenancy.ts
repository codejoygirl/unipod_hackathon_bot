import { apiFetch } from "./client";
import type { ApiResource, Community, Tenant } from "@/types/api";

export function getTenants(signal?: AbortSignal): Promise<ApiResource<Tenant[]>> {
  return apiFetch<ApiResource<Tenant[]>>("/api/v1/tenants", { signal });
}

export function getCommunities(
  tenantId: string,
  signal?: AbortSignal,
): Promise<ApiResource<Community[]>> {
  return apiFetch<ApiResource<Community[]>>(
    `/api/v1/tenants/${tenantId}/communities`,
    { signal },
  );
}
