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
import {
  applyPhoneToUrl,
  readAccessKeyFromUrl,
  readPhoneFromUrl,
  readWebChatUrlState,
} from "./url-params";

export type WebChatCommunity = {
  id: string;
  name: string;
  slug: string;
};

type BootstrapResponse = {
  data: {
    session_id: string;
    member_phone: string | null;
    member_label: string | null;
    community: WebChatCommunity;
  };
};

export type WebChatPhase = "needs-phone" | "loading" | "ready" | "error";

type WebChatContextValue = {
  phase: WebChatPhase;
  accessKey: string | null;
  sessionId: string | null;
  memberPhone: string | null;
  memberLabel: string | null;
  community: WebChatCommunity | null;
  error: string | null;
  submitMemberPhone: (phoneDigits: string) => void;
  retry: () => void;
};

const WebChatContext = createContext<WebChatContextValue | null>(null);

export function WebChatProvider({ children }: { children: ReactNode }) {
  const [phase, setPhase] = useState<WebChatPhase>("loading");
  const [accessKey, setAccessKey] = useState<string | null>(null);
  const [sessionId, setSessionId] = useState<string | null>(null);
  const [memberPhone, setMemberPhone] = useState<string | null>(null);
  const [memberLabel, setMemberLabel] = useState<string | null>(null);
  const [community, setCommunity] = useState<WebChatCommunity | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [bootToken, setBootToken] = useState(0);

  const runBootstrap = useCallback(async (k: string | null, phone: string, s: string) => {
    setPhase("loading");
    setError(null);
    try {
      const qs = new URLSearchParams({ s, p: phone });
      if (k) {
        qs.set("k", k);
      }
      const result = await apiFetch<BootstrapResponse>(
        `/api/v1/web-chat/bootstrap?${qs.toString()}`,
      );
      setCommunity(result.data.community);
      setMemberPhone(result.data.member_phone);
      setMemberLabel(result.data.member_label);
      setSessionId(result.data.session_id);
      setPhase("ready");
    } catch (err) {
      setCommunity(null);
      setError(
        err instanceof ApiError
          ? err.message
          : "Could not open chat. Check your link or try again later.",
      );
      setPhase("error");
    }
  }, []);

  useEffect(() => {
    let cancelled = false;

    const k = readAccessKeyFromUrl();
    setAccessKey(k);

    const phone = readPhoneFromUrl();
    if (!phone) {
      setPhase("needs-phone");
      return;
    }

    const { sessionId: s } = readWebChatUrlState();
    if (!s) {
      setPhase("needs-phone");
      return;
    }

    setMemberPhone(phone);
    setSessionId(s);

    void (async () => {
      if (cancelled) {
        return;
      }
      await runBootstrap(k, phone, s);
    })();

    return () => {
      cancelled = true;
    };
  }, [bootToken, runBootstrap]);

  const submitMemberPhone = useCallback((phoneDigits: string) => {
    applyPhoneToUrl(readAccessKeyFromUrl(), phoneDigits);
    setBootToken((n) => n + 1);
  }, []);

  const retry = useCallback(() => {
    setBootToken((n) => n + 1);
  }, []);

  const value = useMemo<WebChatContextValue>(
    () => ({
      phase,
      accessKey,
      sessionId,
      memberPhone,
      memberLabel,
      community,
      error,
      submitMemberPhone,
      retry,
    }),
    [
      phase,
      accessKey,
      sessionId,
      memberPhone,
      memberLabel,
      community,
      error,
      submitMemberPhone,
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
