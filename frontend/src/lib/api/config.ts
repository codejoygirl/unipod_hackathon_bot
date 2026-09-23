/**
 * Resolves the backend base URL.
 * When running in the browser on port 80 or any standard web port (e.g. on localhost or domain),
 * it always uses the current window origin so calls hit the same host.
 */
export function getApiBaseUrl(): string {
  if (typeof window !== "undefined") {
    // If running in browser on localhost without port (port 80) or with port 80/443 or production domain:
    if (!window.location.port || window.location.port === "80" || window.location.port === "443") {
      return window.location.origin.replace(/\/+$/, "");
    }
  }

  const fromEnv = process.env.NEXT_PUBLIC_API_URL?.trim();
  if (fromEnv) {
    return fromEnv.replace(/\/+$/, "");
  }

  if (typeof window !== "undefined") {
    return window.location.origin.replace(/\/+$/, "");
  }

  return "http://localhost";
}
