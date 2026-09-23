"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";

import { Button } from "@/components/ui/button";
import { Field, fieldControlProps } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { ApiError } from "@/lib/api/client";
import { useRegister } from "@/lib/auth/session";
import { registerSchema, type RegisterValues } from "@/lib/validation/auth";

const FIELDS = ["name", "email", "password", "password_confirmation"] as const;

export function RegisterForm() {
  const router = useRouter();
  const registerMutation = useRegister();

  const form = useForm<RegisterValues>({
    resolver: zodResolver(registerSchema),
    defaultValues: { name: "", email: "", password: "", password_confirmation: "" },
  });

  function onSubmit(values: RegisterValues) {
    form.clearErrors("root");

    registerMutation.mutate(values, {
      onSuccess: () => router.replace("/communities"),
      onError: (error) => {
        if (!(error instanceof ApiError)) {
          form.setError("root", { message: "Something went wrong. Try again." });
          return;
        }

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

  const errors = form.formState.errors;

  return (
    <form onSubmit={form.handleSubmit(onSubmit)} noValidate className="space-y-5">
      {errors.root?.message ? (
        <p role="alert" className="rounded-sm border border-clay bg-clay-wash px-3 py-2.5 text-sm leading-5 text-clay">
          {errors.root.message}
        </p>
      ) : null}

      <Field controlId="register-name" label="Name" error={errors.name?.message}>
        <Input
          autoComplete="name"
          autoFocus
          {...fieldControlProps("register-name", errors.name?.message)}
          {...form.register("name")}
        />
      </Field>

      <Field controlId="register-email" label="Email" error={errors.email?.message}>
        <Input
          type="email"
          autoComplete="email"
          {...fieldControlProps("register-email", errors.email?.message)}
          {...form.register("email")}
        />
      </Field>

      <Field
        controlId="register-password"
        label="Password"
        hint="At least 8 characters."
        error={errors.password?.message}
      >
        <Input
          type="password"
          autoComplete="new-password"
          {...fieldControlProps("register-password", errors.password?.message, "At least 8 characters.")}
          {...form.register("password")}
        />
      </Field>

      <Field
        controlId="register-password-confirmation"
        label="Repeat password"
        error={errors.password_confirmation?.message}
      >
        <Input
          type="password"
          autoComplete="new-password"
          {...fieldControlProps(
            "register-password-confirmation",
            errors.password_confirmation?.message,
          )}
          {...form.register("password_confirmation")}
        />
      </Field>

      <Button
        type="submit"
        variant="primary"
        size="lg"
        className="w-full"
        disabled={registerMutation.isPending}
      >
        {registerMutation.isPending ? "Creating account" : "Create account"}
      </Button>

      <p className="text-sm leading-5 text-ink-soft">
        Already have an account?{" "}
        <Link href="/login" className="text-accent underline underline-offset-4">
          Sign in
        </Link>
      </p>
    </form>
  );
}
