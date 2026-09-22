import { z } from "zod";

/**
 * Mirrors the Laravel rules in `LoginRequest` / `RegisterRequest` so the same failure is
 * caught in the browser first. The server remains the authority: its 422 messages are
 * mapped back onto these fields.
 */

export const loginSchema = z.object({
  email: z
    .string()
    .min(1, "Enter your email address.")
    .email("Enter a valid email address."),
  password: z.string().min(1, "Enter your password."),
});

export type LoginValues = z.infer<typeof loginSchema>;

export const registerSchema = z
  .object({
    name: z.string().min(1, "Enter your name.").max(255, "Keep this under 255 characters."),
    email: z
      .string()
      .min(1, "Enter your email address.")
      .email("Enter a valid email address.")
      .max(255, "Keep this under 255 characters."),
    password: z.string().min(8, "Use at least 8 characters."),
    password_confirmation: z.string().min(1, "Repeat your password."),
  })
  .refine((values) => values.password === values.password_confirmation, {
    path: ["password_confirmation"],
    message: "Passwords do not match.",
  });

export type RegisterValues = z.infer<typeof registerSchema>;
