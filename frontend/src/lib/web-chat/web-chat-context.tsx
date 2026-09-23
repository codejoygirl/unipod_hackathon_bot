"use client";

import { apiFetch, ApiError } from "@/lib/api/client";
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";
import { applyPhoneToUrl, readPhoneFromUrl } from "./url-params";
import { sessionIdFromPhone } from "./phone";

export type WebChatCommunity = {
  id: string;
  name: string;
  slug: string;
};

type BootstrapResponse = {
  data: {
    session_id?: string;
    member_phone: string | null;
    member_label: string | null;
    is_admin?: boolean;
    role?: "admin" | "member";
    admin_name?: string | null;
    admin_token?: string | null;
    requires_password?: boolean;
    community?: WebChatCommunity;
  };
};

export type WebChatPhase = "needs-phone" | "needs-password" | "loading" | "ready" | "error";

type WebChatContextValue = {
  phase: WebChatPhase;
  sessionId: string | null;
  memberPhone: string | null;
  memberLabel: string | null;
  isAdmin: boolean;
  role: "admin" | "member";
  adminName: string | null;
  adminToken: string | null;
  community: WebChatCommunity | null;
  error: string | null;
  submitMemberPhone: (phoneDigits: string) => void;
  submitAdminPassword: (password: string) => Promise<void>;
  backToPhoneEntry: () => void;
  logOut: () => void;
  retry: () => void;
};

const WebChatContext = createContext<WebChatContextValue | null>(null);

export function WebChatProvider({ children }: { children: ReactNode }) {
  const [phase, setPhase] = useState<WebChatPhase>("loading");
  const [sessionId, setSessionId] = useState<string | null>(null);
  const [memberPhone, setMemberPhone] = useState<string | null>(null);
  const [memberLabel, setMemberLabel] = useState<string | null>(null);
  const [isAdmin, setIsAdmin] = useState(false);
  const [role, setRole] = useState<"admin" | "member">("member");
  const [adminName, setAdminName] = useState<string | null>(null);
  const [adminToken, setAdminToken] = useState<string | null>(null);
  const [community, setCommunity] = useState<WebChatCommunity | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [bootToken, setBootToken] = useState(0);

  const runBootstrap = useCallback(async (phone: string) => {
    setPhase("loading");
    setError(null);
    try {
      let storedToken = "";
      try {
        storedToken = localStorage.getItem(`zak_adm_token_${phone}`) || "";
      } catch {
        // Storage disabled or unavailable
      }

      const qs = new URLSearchParams({ phone });
      if (storedToken) {
        qs.set("admin_token", storedToken);
      }

      const result = await apiFetch<BootstrapResponse>(
        `/api/v1/web-chat/bootstrap?${qs.toString()}`,
        storedToken ? { headers: { "X-Admin-Token": storedToken } } : undefined,
      );

      // Admin requires web password
      if (result.data.requires_password) {
        setIsAdmin(true);
        setRole("admin");
        setAdminName(result.data.admin_name ?? null);
        setMemberPhone(result.data.member_phone ?? phone);
        setMemberLabel(result.data.member_label ?? phone);
        setPhase("needs-password");
        return;
      }

      if (result.data.admin_token) {
        try {
          localStorage.setItem(`zak_adm_token_${phone}`, result.data.admin_token);
        } catch {
          // Ignore
        }
        setAdminToken(result.data.admin_token);
      }

      if (result.data.community) {
        setCommunity(result.data.community);
      }
      setMemberPhone(result.data.member_phone);
      setMemberLabel(result.data.member_label);
      if (result.data.session_id) {
        setSessionId(result.data.session_id);
      }
      setIsAdmin(Boolean(result.data.is_admin));
      setRole(result.data.role ?? (result.data.is_admin ? "admin" : "member"));
      setAdminName(result.data.admin_name ?? null);
      setPhase("ready");
    } catch (err) {
      setCommunity(null);
      if (err instanceof ApiError) {
        setError(err.message);
      } else if (
        err instanceof TypeError
        || (err instanceof Error && /fetch|network|failed to fetch|load failed/i.test(err.message))
      ) {
        setError(
          "We're having trouble connecting right now. Please try again in a moment.",
        );
      } else {
        setError("Could not load your conversation. Please try again in a moment.");
      }
      setPhase("error");
    }
  }, []);

  useEffect(() => {
    let cancelled = false;

    const phone = readPhoneFromUrl();
    if (!phone) {
      queueMicrotask(() => {
        if (!cancelled) {
          setPhase("needs-phone");
        }
      });
      return;
    }

    queueMicrotask(() => {
      if (!cancelled) {
        setMemberPhone(phone);
        setSessionId(sessionIdFromPhone(phone));
      }
    });

    void (async () => {
      if (cancelled) {
        return;
      }
      await runBootstrap(phone);
    })();

    return () => {
      cancelled = true;
    };
  }, [bootToken, runBootstrap]);

  const submitMemberPhone = useCallback((phoneDigits: string) => {
    applyPhoneToUrl(phoneDigits);
    setBootToken((n) => n + 1);
  }, []);

  const submitAdminPassword = useCallback(
    async (password: string) => {
      if (!memberPhone) return;
      setPhase("loading");
      setError(null);
      try {
        const qs = new URLSearchParams({ phone: memberPhone, password });
        const result = await apiFetch<BootstrapResponse>(
          `/api/v1/web-chat/bootstrap?${qs.toString()}`,
        );

        if (result.data.requires_password) {
          setError("Admin verification required.");
          setPhase("needs-password");
          return;
        }

        if (result.data.admin_token) {
          try {
            localStorage.setItem(`zak_adm_token_${memberPhone}`, result.data.admin_token);
          } catch {
            // Ignore
          }
          setAdminToken(result.data.admin_token);
        }

        if (result.data.community) {
          setCommunity(result.data.community);
        }
        setMemberPhone(result.data.member_phone);
        setMemberLabel(result.data.member_label);
        if (result.data.session_id) {
          setSessionId(result.data.session_id);
        }
        setIsAdmin(true);
        setRole("admin");
        setAdminName(result.data.admin_name ?? null);
        setPhase("ready");
      } catch (err) {
        if (err instanceof ApiError) {
          setError(err.message || "Incorrect admin password.");
        } else {
          setError("Incorrect admin password. Please try again.");
        }
        setPhase("needs-password");
      }
    },
    [memberPhone],
  );

  const backToPhoneEntry = useCallback(() => {
    if (typeof window !== "undefined") {
      const url = new URL(window.location.href);
      url.searchParams.delete("phone");
      url.searchParams.delete("k");
      window.history.replaceState({}, "", url.pathname + (url.search ? url.search : ""));
    }
    setMemberPhone(null);
    setMemberLabel(null);
    setIsAdmin(false);
    setRole("member");
    setAdminName(null);
    setError(null);
    setPhase("needs-phone");
  }, []);

  const retry = useCallback(() => {
    setBootToken((n) => n + 1);
  }, []);

  const value = useMemo<WebChatContextValue>(
    () => ({
      phase,
      sessionId,
      memberPhone,
      memberLabel,
      isAdmin,
      role,
      adminName,
      adminToken,
      community,
      error,
      submitMemberPhone,
      submitAdminPassword,
      backToPhoneEntry,
      logOut: backToPhoneEntry,
      retry,
    }),
    [
      phase,
      sessionId,
      memberPhone,
      memberLabel,
      isAdmin,
      role,
      adminName,
      adminToken,
      community,
      error,
      submitMemberPhone,
      submitAdminPassword,
      backToPhoneEntry,
      retry,
    ],
  );

  return <WebChatContext.Provider value={value}>{children}</WebChatContext.Provider>;
}

export function useWebChat(): WebChatContextValue {
  const ctx = useContext(WebChatContext);
  if (!ctx) {
    throw new Error("useWebChat must be used within WebChatProvider");
  }
  return ctx;
}
