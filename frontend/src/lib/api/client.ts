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
  if (init.body && !headers.has("Content-Type")) {
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
  return /laravel|sail|localhost|stack trace|sqlstate|exception|npm |docker|uvicorn|fastapi/i.test(
    message,
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
    return "You're not allowed to use this chat right now. Please ask your admin for a new link.";
  }
  if (status === 404) {
    return "We couldn't find that page. Please check your link or ask your admin for help.";
  }
  if (status === 422) {
    return "Something in that request didn't look right. Please try again.";
  }
  if (status === 429) {
    return "You're sending messages a bit quickly. Please wait a moment and try again.";
  }
  if (status >= 500 || status === 0) {
    return "Something went wrong on our side. Please try again in a moment.";
  }

  return "We couldn't complete that request. Please try again.";
}
