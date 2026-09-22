import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError, apiFetch, readCsrfCookie } from "../client";

const CSRF_COOKIE = "XSRF-TOKEN";

/** A minimal Response stand-in: `apiFetch` only reads ok, status and text(). */
function reply(status: number, body?: unknown) {
  return {
    ok: status >= 200 && status < 300,
    status,
    text: async () => (body === undefined ? "" : JSON.stringify(body)),
  };
}

const fetchMock = vi.fn();

function clearCookies() {
  for (const entry of document.cookie.split(";")) {
    const name = entry.split("=")[0].trim();
    if (name) {
      document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
    }
  }
}

beforeEach(() => {
  clearCookies();
  fetchMock.mockReset();
  vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("readCsrfCookie", () => {
  it("returns null when the cookie is absent", () => {
    expect(readCsrfCookie()).toBeNull();
  });

  it("URL-decodes the cookie value, as Sanctum expects", () => {
    document.cookie = `${CSRF_COOKIE}=abc%3D123`;
    expect(readCsrfCookie()).toBe("abc=123");
  });
});

describe("apiFetch", () => {
  it("does not touch the CSRF endpoint for a safe method", async () => {
    fetchMock.mockResolvedValueOnce(reply(200, { data: [] }));

    await apiFetch("/api/v1/health/live");

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe("http://localhost/api/v1/health/live");
    expect(init.headers["X-XSRF-TOKEN"]).toBeUndefined();
    expect(init.credentials).toBe("include");
  });

  it("attaches the existing CSRF cookie to an unsafe method", async () => {
    document.cookie = `${CSRF_COOKIE}=token%3D1`;
    fetchMock.mockResolvedValueOnce(reply(200, { data: { ok: true } }));

    await apiFetch("/api/v1/auth/login", { method: "POST", body: { email: "a@b.c" } });

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe("http://localhost/api/v1/auth/login");
    expect(init.headers["X-XSRF-TOKEN"]).toBe("token=1");
    expect(init.body).toBe('{"email":"a@b.c"}');
  });

  it("bootstraps /sanctum/csrf-cookie first when the cookie is missing", async () => {
    fetchMock.mockImplementation(async (url: string) => {
      if (url.endsWith("/sanctum/csrf-cookie")) {
        document.cookie = `${CSRF_COOKIE}=fresh%3D2`;
        return reply(204);
      }
      return reply(200, { data: 1 });
    });

    await apiFetch("/api/v1/auth/login", { method: "POST", body: {} });

    expect(fetchMock.mock.calls[0][0]).toBe("http://localhost/sanctum/csrf-cookie");
    expect(fetchMock.mock.calls[1][0]).toBe("http://localhost/api/v1/auth/login");
    expect(fetchMock.mock.calls[1][1].headers["X-XSRF-TOKEN"]).toBe("fresh=2");
  });

  it("shares one bootstrap call across concurrent requests", async () => {
    fetchMock.mockImplementation(async (url: string) => {
      if (url.endsWith("/sanctum/csrf-cookie")) {
        document.cookie = `${CSRF_COOKIE}=shared`;
        return reply(204);
      }
      return reply(200, { data: 1 });
    });

    await Promise.all([
      apiFetch("/api/v1/a", { method: "POST", body: {} }),
      apiFetch("/api/v1/b", { method: "POST", body: {} }),
    ]);

    const bootstraps = fetchMock.mock.calls.filter(([url]) =>
      String(url).endsWith("/sanctum/csrf-cookie"),
    );

    expect(bootstraps).toHaveLength(1);
  });

  it("throws an ApiError carrying the status and Laravel field errors", async () => {
    fetchMock.mockResolvedValueOnce(
      reply(422, {
        message: "The provided credentials are incorrect.",
        errors: { email: ["The provided credentials are incorrect."] },
      }),
    );

    let caught: unknown;
    try {
      await apiFetch("/api/v1/auth/login", { method: "GET" });
    } catch (error) {
      caught = error;
    }

    expect(caught).toBeInstanceOf(ApiError);

    const apiError = caught as ApiError;
    expect(apiError.status).toBe(422);
    expect(apiError.message).toBe("The provided credentials are incorrect.");
    expect(apiError.errors()?.email?.[0]).toBe("The provided credentials are incorrect.");
  });

  it("reports an unreachable API as status 0 rather than throwing a network error", async () => {
    fetchMock.mockRejectedValueOnce(new TypeError("fetch failed"));

    let caught: unknown;
    try {
      await apiFetch("/api/v1/health/live");
    } catch (error) {
      caught = error;
    }

    expect(caught).toBeInstanceOf(ApiError);
    expect((caught as ApiError).status).toBe(0);
  });
});
