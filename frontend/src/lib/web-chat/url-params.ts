import { normalizePhone, sessionIdFromPhone } from "./phone";

export type WebChatUrlState = {
  accessKey: string | null;
  phone: string | null;
  sessionId: string | null;
};

export function readAccessKeyFromUrl(): string | null {
  if (typeof window === "undefined") {
    return null;
  }
  return new URLSearchParams(window.location.search).get("k")?.trim() || null;
}

export function readPhoneFromUrl(): string | null {
  if (typeof window === "undefined") {
    return null;
  }
  return normalizePhone(new URLSearchParams(window.location.search).get("p"));
}

/** Read k / p / s from the URL without inventing a session when phone is missing. */
export function readWebChatUrlState(): WebChatUrlState {
  const params = new URLSearchParams(window.location.search);
  const accessKey = params.get("k")?.trim() || null;
  const phone = normalizePhone(params.get("p"));
  let sessionId = params.get("s")?.trim() || null;

  if (phone) {
    const expected = sessionIdFromPhone(phone);
    if (sessionId !== expected) {
      sessionId = expected;
      syncWebChatUrl({ accessKey, phone, sessionId });
    }
  }

  return { accessKey, phone, sessionId };
}

export function applyPhoneToUrl(accessKey: string | null, phoneDigits: string): void {
  const sessionId = sessionIdFromPhone(phoneDigits);
  syncWebChatUrl({ accessKey, phone: phoneDigits, sessionId });
}

export function syncWebChatUrl(state: WebChatUrlState): void {
  if (typeof window === "undefined" || !state.sessionId) {
    return;
  }
  const params = new URLSearchParams();
  if (state.accessKey) {
    params.set("k", state.accessKey);
  }
  if (state.phone) {
    params.set("p", state.phone);
  }
  params.set("s", state.sessionId);
  const next = `${window.location.pathname}?${params.toString()}${window.location.hash}`;
  const current = `${window.location.pathname}${window.location.search}${window.location.hash}`;
  if (current !== next) {
    window.history.replaceState(null, "", next);
  }
}
