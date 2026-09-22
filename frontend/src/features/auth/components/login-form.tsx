"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";

import { Button } from "@/components/ui/button";
import { Field, fieldControlProps } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { ApiError } from "@/lib/api/client";
import { useLogin } from "@/lib/auth/session";
import { loginSchema, type LoginValues } from "@/lib/validation/auth";

const FIELDS = ["email", "password"] as const;

export function LoginForm() {
  const router = useRouter();
  const login = useLogin();

  const form = useForm<LoginValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: "", password: "" },
  });

  function onSubmit(values: LoginValues) {
    form.clearErrors("root");

    login.mutate(values, {
      onSuccess: () => router.replace("/conversations"),
      onError: (error) => {
        if (!(error instanceof ApiError)) {
          form.setError("root", { message: "Something went wrong. Try again." });
          return;
        }

        // Laravel answers bad credentials with 422 and field-keyed errors, not 401.
        const fieldErrors = error.errors() ?? {};
        let mapped = false;

        for (const field of FIELDS) {
          const message = fieldErrors[field]?.[0];
          if (message) {
            form.setError(field, { message });
            mapped = true;
          }
        }

        if (!mapped) {
          form.setError("root", {
            message:
              error.status === 0
                ? "The API is not reachable. Start the backend, then try again."
                : error.message,
          });
        }
      },
    });
  }

  const rootError = form.formState.errors.root?.message;
  const emailError = form.formState.errors.email?.message;
  const passwordError = form.formState.errors.password?.message;

  return (
    <form onSubmit={form.handleSubmit(onSubmit)} noValidate className="space-y-5">
      {rootError ? (
        <p role="alert" className="rounded-sm border border-clay bg-clay-wash px-3 py-2.5 text-sm leading-5 text-clay">
          {rootError}
        </p>
      ) : null}

      <Field controlId="login-email" label="Email" error={emailError}>
        <Input
          type="email"
          autoComplete="email"
          autoFocus
          {...fieldControlProps("login-email", emailError)}
          {...form.register("email")}
        />
      </Field>

      <Field controlId="login-password" label="Password" error={passwordError}>
        <Input
          type="password"
          autoComplete="current-password"
          {...fieldControlProps("login-password", passwordError)}
          {...form.register("password")}
        />
      </Field>

      <Button type="submit" variant="primary" size="lg" className="w-full" disabled={login.isPending}>
        {login.isPending ? "Signing in" : "Sign in"}
      </Button>

      <p className="text-sm leading-5 text-ink-soft">
        No account yet?{" "}
        <Link href="/register" className="text-accent underline underline-offset-4">
          Create one
        </Link>
      </p>
    </form>
  );
}
