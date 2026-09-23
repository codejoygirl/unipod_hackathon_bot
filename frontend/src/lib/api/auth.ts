import { apiFetch, ApiError } from "./client";
import type {
  ApiResource,
  LoginPayload,
  RegisterPayload,
  User,
} from "@/types/api";

export function getSession(signal?: AbortSignal): Promise<ApiResource<User>> {
  return apiFetch<ApiResource<User>>("/api/v1/auth/me", { signal });
}

export function login(payload: LoginPayload): Promise<ApiResource<User>> {
  return apiFetch<ApiResource<User>>("/api/v1/auth/login", {
    method: "POST",
    body: payload,
  });
}

export function register(payload: RegisterPayload): Promise<ApiResource<User>> {
  return apiFetch<ApiResource<User>>("/api/v1/auth/register", {
    method: "POST",
    body: payload,
  });
}

export function logout(): Promise<null> {
  return apiFetch<null>("/api/v1/auth/logout", { method: "POST" });
}

/** `me` answers 401 for an anonymous visitor. That is a state, not a failure. */
export function isAnonymousError(error: unknown): boolean {
  return error instanceof ApiError && error.status === 401;
}
