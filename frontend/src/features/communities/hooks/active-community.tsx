"use client";

import { useQuery } from "@tanstack/react-query";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { createContext, useContext, useMemo, type ReactNode } from "react";

import { getCommunities } from "@/lib/api/tenancy";
import { useSession } from "@/lib/auth/session";
import { queryKeys } from "@/lib/query/keys";
import type { Community, UserTenant } from "@/types/api";

type ActiveCommunityValue = {
  tenants: UserTenant[];
  communities: Community[];
  tenantId: string | null;
  communityId: string | null;
  communityName: string | null;
  isLoading: boolean;
  error: unknown;
  /** True when a question can be scoped to a community. */
  isReady: boolean;
  /** Why a question cannot be asked yet, when it cannot. Null once ready. */
  blockedReason: string | null;
  setCommunity: (tenantId: string, communityId: string) => void;
};

const ActiveCommunityContext = createContext<ActiveCommunityValue | null>(null);

/**
 * The active tenant and community, shared by the header selector and every surface that
 * scopes a request to a community.
 *
 * Exactly one tenant is active at a time because `POST /assistant/ask` rejects a request
 * whose `community_ids` span two tenants with a 422.
 *
 * Selection lives in the URL so a view can be shared, which means the provider must be
 * rendered inside a Suspense boundary.
 */
export function ActiveCommunityProvider({ children }: { children: ReactNode }) {
  const { user } = useSession();
  const searchParams = useSearchParams();
  const pathname = usePathname();
  const router = useRouter();

  const tenants = useMemo(() => user?.tenants ?? [], [user]);

  const tenantParam = searchParams.get("tenant");
  const communityParam = searchParams.get("community");

  const tenantId = useMemo(() => {
    if (tenants.length === 0) return null;
    return tenants.some((tenant) => tenant.id === tenantParam) ? tenantParam : tenants[0].id;
  }, [tenants, tenantParam]);

  const communitiesQuery = useQuery({
    queryKey: queryKeys.communities(tenantId ?? "none"),
    enabled: Boolean(tenantId),
    queryFn: async ({ signal }): Promise<Community[]> => {
      if (!tenantId) return [];
      const response = await getCommunities(tenantId, signal);
      return response.data;
    },
  });

  const communities = useMemo(() => communitiesQuery.data ?? [], [communitiesQuery.data]);

  const communityId = useMemo(() => {
    if (communities.length === 0) return null;
    return communities.some((community) => community.id === communityParam)
      ? communityParam
      : communities[0].id;
  }, [communities, communityParam]);

  const isLoading = communitiesQuery.isPending;

  const blockedReason = useMemo(() => {
    if (tenants.length === 0) return "You are not a member of a community yet.";
    if (isLoading) return "Loading your communities";
    if (communities.length === 0) return "No communities are visible to you here.";
    if (!communityId) return "Choose a community";
    return null;
  }, [tenants.length, isLoading, communities.length, communityId]);

  const value = useMemo<ActiveCommunityValue>(() => {
    const active = communities.find((community) => community.id === communityId) ?? null;

    return {
      tenants,
      communities,
      tenantId,
      communityId,
      communityName: active?.name ?? null,
      isLoading,
      error: communitiesQuery.error,
      isReady: Boolean(tenantId && communityId),
      blockedReason,
      setCommunity: (nextTenantId, nextCommunityId) => {
        const params = new URLSearchParams(searchParams);
        params.set("tenant", nextTenantId);
        params.set("community", nextCommunityId);
        router.replace(`${pathname}?${params.toString()}`);
      },
    };
  }, [
    tenants,
    communities,
    tenantId,
    communityId,
    isLoading,
    blockedReason,
    communitiesQuery.error,
    searchParams,
    pathname,
    router,
  ]);

  return (
    <ActiveCommunityContext.Provider value={value}>{children}</ActiveCommunityContext.Provider>
  );
}

export function useActiveCommunity(): ActiveCommunityValue {
  const value = useContext(ActiveCommunityContext);

  if (!value) {
    throw new Error("useActiveCommunity must be used inside ActiveCommunityProvider.");
  }

  return value;
}
