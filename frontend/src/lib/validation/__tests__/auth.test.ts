import { describe, expect, it } from "vitest";

import { loginSchema, registerSchema } from "../auth";

const VALID = {
  name: "Ada Lovelace",
  email: "ada@example.com",
  password: "password123",
  password_confirmation: "password123",
};

describe("loginSchema", () => {
  it("accepts a well-formed pair", () => {
    expect(loginSchema.safeParse({ email: "ada@example.com", password: "x" }).success).toBe(true);
  });

  it("rejects a malformed email", () => {
    expect(loginSchema.safeParse({ email: "not-an-email", password: "x" }).success).toBe(false);
  });

  it("rejects an empty password", () => {
    expect(loginSchema.safeParse({ email: "ada@example.com", password: "" }).success).toBe(false);
  });
});

describe("registerSchema", () => {
  it("accepts a valid registration", () => {
    expect(registerSchema.safeParse(VALID).success).toBe(true);
  });

  it("requires at least 8 characters, matching the server rule", () => {
    const result = registerSchema.safeParse({ ...VALID, password: "short", password_confirmation: "short" });
    expect(result.success).toBe(false);
  });

  it("reports a mismatched confirmation on the confirmation field", () => {
    const result = registerSchema.safeParse({ ...VALID, password_confirmation: "password124" });

    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0].path).toEqual(["password_confirmation"]);
    }
  });
});
