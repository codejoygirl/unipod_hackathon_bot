import { normalizePhone } from "./phone";

export function readPhoneFromUrl(): string | null {
  if (typeof window === "undefined") {
    return null;
  }
  const params = new URLSearchParams(window.location.search);
  const fromQuery = normalizePhone(params.get("phone"));
  if (fromQuery) {
    try {
      localStorage.setItem("zak_active_phone", fromQuery);
    } catch {
      // Storage unavailable
    }
    return fromQuery;
  }
  try {
    const cached = localStorage.getItem("zak_active_phone");
    if (cached) {
      return normalizePhone(cached);
    }
  } catch {
    // Storage unavailable
  }
  return null;
}

export function applyPhoneToUrl(phoneDigits: string): void {
  if (typeof window === "undefined") {
    return;
  }
  try {
    localStorage.setItem("zak_active_phone", phoneDigits);
  } catch {
    // Storage unavailable
  }
  const params = new URLSearchParams(window.location.search);
  params.set("phone", phoneDigits);
  const next = `${window.location.pathname}?${params.toString()}${window.location.hash}`;
  const current = `${window.location.pathname}${window.location.search}${window.location.hash}`;
  if (current !== next) {
    window.history.replaceState(null, "", next);
  }
}

export function withPhoneQuery(href: string, phone: string | null): string {
  if (!phone) {
    return href;
  }
  const [pathname, searchAndHash = ""] = href.split("?");
  const [search = "", hash = ""] = searchAndHash.split("#");
  const params = new URLSearchParams(search);
  params.set("phone", phone);
  const qs = params.toString() ? `?${params.toString()}` : "";
  const h = hash ? `#${hash}` : "";
  return `${pathname}${qs}${h}`;
}
