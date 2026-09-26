import { getApiBaseUrl } from "./config";
import type { ApiErrorBody } from "./types";

const TOKEN_STORAGE_KEY = "zak_api_token";

export class ApiError extends Error {
  status: number;
  body: ApiErrorBody | null;

  constructor(status: number, message: string, body: ApiErrorBody | null = null) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.body = body;
  }
}

export function getStoredBearerToken(): string | null {
  if (typeof window === "undefined") {
    return null;
  }
  const token = window.localStorage.getItem(TOKEN_STORAGE_KEY)?.trim();
  return token || null;
}

export function setStoredBearerToken(token: string | null): void {
  if (typeof window === "undefined") {
    return;
  }
  if (!token) {
    window.localStorage.removeItem(TOKEN_STORAGE_KEY);
    return;
  }
  window.localStorage.setItem(TOKEN_STORAGE_KEY, token);
}

function readCookie(name: string): string | null {
  if (typeof document === "undefined") {
    return null;
  }
  const match = document.cookie.match(new RegExp(`(?:^|; )${name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}=([^;]*)`));
  return match ? decodeURIComponent(match[1]) : null;
}

export async function ensureCsrfCookie(): Promise<void> {
  const base = getApiBaseUrl();
  await fetch(`${base}/sanctum/csrf-cookie`, {
    method: "GET",
    credentials: "include",
  });
}

export async function apiFetch<T>(
  path: string,
  init: RequestInit = {},
): Promise<T> {
  const base = getApiBaseUrl();
  const url = path.startsWith("http") ? path : `${base}${path.startsWith("/") ? path : `/${path}`}`;

  const headers = new Headers(init.headers);
  if (!headers.has("Accept")) {
    headers.set("Accept", "application/json");
  }
  const isFormData = typeof FormData !== "undefined" && init.body instanceof FormData;
  if (init.body && !isFormData && !headers.has("Content-Type")) {
    headers.set("Content-Type", "application/json");
  }

  const bearer = getStoredBearerToken();
  if (bearer && !headers.has("Authorization")) {
    headers.set("Authorization", `Bearer ${bearer}`);
  }

  const xsrf = readCookie("XSRF-TOKEN");
  if (xsrf && !headers.has("X-XSRF-TOKEN")) {
    headers.set("X-XSRF-TOKEN", xsrf);
  }

  let response: Response;
  try {
    response = await fetch(url, {
      ...init,
      headers,
      credentials: "include",
    });
  } catch {
    throw new ApiError(
      0,
      "We're having trouble connecting right now. Please try again in a moment.",
    );
  }

  if (response.status === 204) {
    return undefined as T;
  }

  const text = await response.text();
  let parsed: unknown = null;
  if (text) {
    try {
      parsed = JSON.parse(text) as unknown;
    } catch {
      parsed = null;
    }
  }

  if (!response.ok) {
    const body = (parsed && typeof parsed === "object" ? parsed : null) as ApiErrorBody | null;
    throw new ApiError(response.status, friendlyHttpMessage(response.status, body), body);
  }

  return parsed as T;
}

function firstValidationMessage(body: ApiErrorBody | null): string | null {
  const errors = body?.errors;
  if (!errors || typeof errors !== "object") {
    return null;
  }
  for (const value of Object.values(errors)) {
    if (Array.isArray(value) && typeof value[0] === "string" && value[0].trim() !== "") {
      return value[0].trim();
    }
    if (typeof value === "string" && value.trim() !== "") {
      return value.trim();
    }
  }
  return null;
}

function looksTechnical(message: string): boolean {
  return (
    /laravel|sail|localhost|stack trace|traceback|sqlstate|queryexception|pdoexception|exception|npm |docker|uvicorn|fastapi|symfony|illuminate|pydantic|vendor\/|node_modules/i.test(
      message,
    ) ||
    /method.*not supported|supported methods|route\b|syntax error|undefined (variable|index|property)|call to undefined|null pointer/i.test(
      message,
    ) ||
    /\b(select|insert|update|delete|drop|alter)\b.*\b(from|into|table|where)\b/i.test(
      message,
    ) ||
    /\.php|\.py|\.ts|\.js|line \d+/i.test(message) ||
    // ULID or UUID pattern
    /\b01[0-9a-hj-km-np-za-km-z]{24}\b/i.test(message) ||
    /\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i.test(
      message,
    )
  );
}

function friendlyHttpMessage(status: number, body: ApiErrorBody | null): string {
  const fromBody =
    (typeof body?.message === "string" && body.message.trim()) ||
    firstValidationMessage(body) ||
    "";
  if (fromBody && !looksTechnical(fromBody)) {
    return fromBody;
  }

  if (status === 401 || status === 403) {
    return "Session expired or access restricted. Please sign in with your phone number again.";
  }
  if (status === 404) {
    return "The requested information could not be found. Please try again.";
  }
  if (status === 405) {
    return "This action is currently not supported. Please refresh and try again.";
  }
  if (status === 413) {
    return "Your message or request is too long. Please shorten it and try again.";
  }
  if (status === 422) {
    return "Some details in your request could not be processed. Please check your input and try again.";
  }
  if (status === 429) {
    return "You're sending messages a bit too quickly. Please wait a few seconds and try again.";
  }
  if (status >= 500 || status === 0) {
    return "The assistant service is taking longer than usual to respond. Please try again in a moment.";
  }

  return "Something went wrong while processing your request. Please try again.";
}
