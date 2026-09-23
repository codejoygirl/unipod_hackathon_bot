"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";
import {
  apiFetch,
  ensureCsrfCookie,
  setStoredBearerToken,
} from "@/lib/api/client";
import type { Community, User } from "@/lib/api/types";

type AuthContextValue = {
  user: User | null;
  communities: Community[];
  activeCommunity: Community | null;
  setActiveCommunityId: (id: string) => void;
  loading: boolean;
  login: (email: string, password: string, bearerToken?: string) => Promise<void>;
  logout: () => Promise<void>;
  refresh: () => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

type UserEnvelope = { data: User };

type CommunityEnvelope = { data: Community[] };

function unwrapUser(payload: User | UserEnvelope): User {
  if ("data" in payload && payload.data) {
    return payload.data;
  }
  return payload as User;
}

function unwrapCommunities(payload: Community[] | CommunityEnvelope): Community[] {
  if (Array.isArray(payload)) {
    return payload;
  }
  if ("data" in payload && Array.isArray(payload.data)) {
    return payload.data;
  }
  return [];
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [communities, setCommunities] = useState<Community[]>([]);
  const [activeCommunityId, setActiveCommunityId] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  const loadCommunities = useCallback(async (nextUser: User) => {
    const tenant = nextUser.tenants?.[0];
    if (!tenant?.id) {
      setCommunities([]);
      setActiveCommunityId(null);
      return;
    }

    const listed = await apiFetch<Community[] | CommunityEnvelope>(
      `/api/v1/tenants/${tenant.id}/communities`,
    );
    const all = unwrapCommunities(listed);
    const allowed = new Set(tenant.community_ids ?? []);
    const visible =
      allowed.size > 0 ? all.filter((c) => allowed.has(c.id)) : all;

    setCommunities(visible);
    setActiveCommunityId((prev) => {
      if (prev && visible.some((c) => c.id === prev)) {
        return prev;
      }
      return visible[0]?.id ?? null;
    });
  }, []);

  const refresh = useCallback(async () => {
    try {
      await ensureCsrfCookie();
      const me = await apiFetch<User | UserEnvelope>("/api/v1/auth/me");
      const nextUser = unwrapUser(me);
      setUser(nextUser);
      await loadCommunities(nextUser);
    } catch {
      setUser(null);
      setCommunities([]);
      setActiveCommunityId(null);
    }
  }, [loadCommunities]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      await refresh();
      if (!cancelled) {
        setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [refresh]);

  const login = useCallback(
    async (email: string, password: string, bearerToken?: string) => {
      if (bearerToken?.trim()) {
        setStoredBearerToken(bearerToken.trim());
      } else {
        setStoredBearerToken(null);
        await ensureCsrfCookie();
        await apiFetch<User | UserEnvelope>("/api/v1/auth/login", {
          method: "POST",
          body: JSON.stringify({ email, password }),
        });
      }
      await refresh();
    },
    [refresh],
  );

  const logout = useCallback(async () => {
    try {
      await ensureCsrfCookie();
      await apiFetch<void>("/api/v1/auth/logout", { method: "POST" });
    } catch {
      /* session may already be gone */
    }
    setStoredBearerToken(null);
    setUser(null);
    setCommunities([]);
    setActiveCommunityId(null);
  }, []);

  const activeCommunity = useMemo(
    () => communities.find((c) => c.id === activeCommunityId) ?? null,
    [communities, activeCommunityId],
  );

  const value = useMemo<AuthContextValue>(
    () => ({
      user,
      communities,
      activeCommunity,
      setActiveCommunityId: setActiveCommunityId,
      loading,
      login,
      logout,
      refresh,
    }),
    [user, communities, activeCommunity, loading, login, logout, refresh],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error("useAuth must be used within AuthProvider");
  }
  return ctx;
}
