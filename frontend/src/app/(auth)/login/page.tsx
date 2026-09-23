import type { Metadata } from "next";

import { LoginForm } from "@/features/auth/components/login-form";

export const metadata: Metadata = {
  title: "Sign in · Zak",
};

export default function LoginPage() {
  return (
    <div>
      <h1 className="font-display text-[1.75rem] leading-tight tracking-[-0.015em] text-balance">
        Sign in
      </h1>
      <p className="mt-2 text-sm leading-6 text-ink-soft">
        Use the account your community gave you.
      </p>

      <div className="mt-8">
        <LoginForm />
      </div>
    </div>
  );
}
