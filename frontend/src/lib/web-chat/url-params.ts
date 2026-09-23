import { normalizePhone } from "./phone";

export function readPhoneFromUrl(): string | null {
  if (typeof window === "undefined") {
    return null;
  }
  const params = new URLSearchParams(window.location.search);
  return normalizePhone(params.get("phone"));
}

export function applyPhoneToUrl(phoneDigits: string): void {
  if (typeof window === "undefined") {
    return;
  }
  const params = new URLSearchParams();
  params.set("phone", phoneDigits);
  const next = `${window.location.pathname}?${params.toString()}${window.location.hash}`;
  const current = `${window.location.pathname}${window.location.search}${window.location.hash}`;
  if (current !== next) {
    window.history.replaceState(null, "", next);
  }
}
