/**
 * Same-origin when unset: UI is served from Laravel `public/` on the same host as `/api/v1`.
 * Set NEXT_PUBLIC_API_URL only for `npm run dev` on :3000 → Sail on :80.
 */
export function getApiBaseUrl(): string {
  const fromEnv = process.env.NEXT_PUBLIC_API_URL?.trim();
  if (fromEnv) {
    return fromEnv.replace(/\/+$/, "");
  }
  if (typeof window !== "undefined") {
    return window.location.origin.replace(/\/+$/, "");
  }
  return "";
}
