"use client";

import { usePathname } from "next/navigation";

import { useActiveCommunity } from "@/features/communities/hooks/active-community";

const SELECT =
  "min-h-11 rounded-sm border border-rule-strong bg-paper-raised px-2 text-sm text-ink disabled:opacity-55";

export function CommunitySelector() {
  const { tenants, communities, tenantId, communityId, setCommunity, isLoading } =
    useActiveCommunity();
  const pathname = usePathname();

  // Chat is no longer scoped to one chosen community, so the picker would be noise there
  // and read as a requirement. The knowledge surfaces still need it.
  if (pathname?.startsWith("/conversations")) return null;

  if (tenants.length === 0) return null;

  return (
    <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
      {tenants.length > 1 ? (
        <div className="flex items-center gap-2">
          <label htmlFor="active-tenant" className="zak-label text-ink-soft">
            Organisation
          </label>
          <select
            id="active-tenant"
            className={SELECT}
            value={tenantId ?? ""}
            onChange={(event) => {
              const nextTenant = event.target.value;
              // Community ids belong to a tenant, so the community must be re-chosen.
              setCommunity(nextTenant, "");
            }}
          >
            {tenants.map((tenant) => (
              <option key={tenant.id} value={tenant.id}>
                {tenant.name ?? tenant.slug ?? tenant.id}
              </option>
            ))}
          </select>
        </div>
      ) : null}

      <div className="flex items-center gap-2">
        <label htmlFor="active-community" className="zak-label text-ink-soft">
          Community
        </label>
        <select
          id="active-community"
          className={SELECT}
          value={communityId ?? ""}
          disabled={isLoading || communities.length === 0}
          onChange={(event) => {
            if (tenantId) setCommunity(tenantId, event.target.value);
          }}
        >
          {communities.length === 0 ? <option value="">None available</option> : null}
          {communities.map((community) => (
            <option key={community.id} value={community.id}>
              {community.name}
            </option>
          ))}
        </select>
      </div>
    </div>
  );
}
