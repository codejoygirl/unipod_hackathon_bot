import type { Metadata } from "next";

import { RegisterForm } from "@/features/auth/components/register-form";

export const metadata: Metadata = {
  title: "Create an account · Zak",
};

export default function RegisterPage() {
  return (
    <div>
      <h1 className="font-display text-[1.75rem] leading-tight tracking-[-0.015em] text-balance">
        Create an account
      </h1>
      <p className="mt-2 text-sm leading-6 text-ink-soft">
        Creating an account does not add you to a community. An administrator does that.
      </p>

      <div className="mt-8">
        <RegisterForm />
      </div>
    </div>
  );
}
