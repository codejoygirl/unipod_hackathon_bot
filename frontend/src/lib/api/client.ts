/**
 * The browser talks to Laravel and nothing else.
 *
 * The AI service is private: channel webhooks and the browser both enter through
 * Laravel, which is the security boundary. Never call the AI service from here.
 *
 * Auth is a Sanctum first-party cookie session. No bearer token is ever issued, so:
 * - every request sends cookies (`credentials: "include"`)
 * - every non-safe method first obtains `/sanctum/csrf-cookie` and echoes the
 *   `XSRF-TOKEN` cookie back as `X-XSRF-TOKEN`
 *
 * The session cookie is scoped to `SESSION_DOMAIN=localhost`, so the app must be served
 * from `http://localhost:3000`. `127.0.0.1:3000` will not receive the cookie.
 */

const API_URL = (process.env.NEXT_PUBLIC_API_URL ?? "http://localhost").replace(/\/+$/, "");

const SAFE_METHODS = new Set(["GET", "HEAD", "OPTIONS"]);
const CSRF_COOKIE = "XSRF-TOKEN";
const CSRF_HEADER = "X-XSRF-TOKEN";

export class ApiError extends Error {
  readonly status: number;
  readonly payload: unknown;

  constructor(message: string, status: number, payload: unknown) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.payload = payload;
  }

  /** Field-keyed messages from a Laravel 422, for wiring straight into form errors. */
  errors(): Record<string, string[]> | undefined {
    if (this.payload && typeof this.payload === "object" && "errors" in this.payload) {
      const errors = (this.payload as { errors?: unknown }).errors;
      if (errors && typeof errors === "object") return errors as Record<string, string[]>;
    }
    return undefined;
  }
}

/** Reads `XSRF-TOKEN` and URL-decodes it, as Sanctum expects. */
export function readCsrfCookie(): string | null {
  if (typeof document === "undefined") return null;

  const match = document.cookie.match(new RegExp(`(?:^|;\\s*)${CSRF_COOKIE}=([^;]*)`));
  if (!match) return null;

  const raw = match[1];
  if (!raw) return null;

  try {
    return decodeURIComponent(raw);
  } catch {
    return raw;
  }
}

let csrfBootstrap: Promise<void> | null = null;

/**
 * Concurrent unsafe requests share one bootstrap call. The promise is cleared once it
 * settles so a later request re-reads the cookie rather than trusting a stale cache.
 */
async function ensureCsrfToken(signal?: AbortSignal): Promise<string | null> {
  const existing = readCsrfCookie();
  if (existing) return existing;

  csrfBootstrap ??= rawFetch(
    `${API_URL}/sanctum/csrf-cookie`,
    { method: "GET", credentials: "include", headers: { Accept: "application/json" } },
    10_000,
    signal,
  )
    .then(() => undefined)
    .finally(() => {
      csrfBootstrap = null;
    });

  await csrfBootstrap;

  return readCsrfCookie();
}

async function rawFetch(
  url: string,
  init: RequestInit,
  timeoutMs: number,
  signal?: AbortSignal,
): Promise<Response> {
  const controller = new AbortController();
  let timedOut = false;

  const timer = setTimeout(() => {
    timedOut = true;
    controller.abort();
  }, timeoutMs);

  if (signal) {
    if (signal.aborted) controller.abort();
    else signal.addEventListener("abort", () => controller.abort(), { once: true });
  }

  try {
    return await fetch(url, { ...init, signal: controller.signal });
  } catch (cause) {
    // A caller cancellation must propagate: TanStack Query treats it as cancellation.
    if (!timedOut && (cause as Error | undefined)?.name === "AbortError") throw cause;
    throw new ApiError(`No response from the API at ${API_URL}.`, 0, cause);
  } finally {
    clearTimeout(timer);
  }
}

export type ApiRequestOptions = {
  method?: string;
  body?: unknown;
  signal?: AbortSignal;
  timeoutMs?: number;
};

export async function apiFetch<T>(path: string, options: ApiRequestOptions = {}): Promise<T> {
  const { body, signal, timeoutMs = 10_000 } = options;
  const method = (options.method ?? "GET").toUpperCase();
  const url = `${API_URL}${path.startsWith("/") ? path : `/${path}`}`;

  const headers: Record<string, string> = { Accept: "application/json" };

  if (body !== undefined) headers["Content-Type"] = "application/json";

  if (!SAFE_METHODS.has(method)) {
    const token = await ensureCsrfToken(signal);
    if (token) headers[CSRF_HEADER] = token;
  }

  const response = await rawFetch(
    url,
    {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      credentials: "include",
    },
    timeoutMs,
    signal,
  );

  const payload = await parseBody(response);

  if (!response.ok) {
    throw new ApiError(readMessage(payload, response.status), response.status, payload);
  }

  return payload as T;
}

async function parseBody(response: Response): Promise<unknown> {
  if (response.status === 204) return null;

  const text = await response.text();
  if (!text) return null;

  try {
    return JSON.parse(text);
  } catch {
    return text;
  }
}

function readMessage(payload: unknown, status: number): string {
  if (payload && typeof payload === "object" && "message" in payload) {
    const message = (payload as { message?: unknown }).message;
    if (typeof message === "string" && message.length > 0) return message;
  }

  return `Request failed with status ${status}.`;
}
