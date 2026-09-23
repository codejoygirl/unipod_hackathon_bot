"use client";

import { AppBrand } from "@/components/branding/app-brand";
import { CircularLoader } from "@/components/ui/circular-loader";
import { PlatformReachRow } from "@/components/branding/platform-reach";
import {
  APP_SIGN_IN_DESCRIPTION,
  KENYA_PHONE_PLACEHOLDER,
} from "@/lib/branding";
import { normalizePhone } from "@/lib/web-chat/phone";
import { useWebChat } from "@/lib/web-chat/web-chat-context";
import { FormEvent, useState } from "react";

export function PhoneEntryScreen() {
  const {
    submitMemberPhone,
    submitAdminPassword,
    backToPhoneEntry,
    error,
    phase,
    adminName,
    memberLabel,
    memberPhone,
  } = useWebChat();

  const [phoneValue, setPhoneValue] = useState("");
  const [passwordValue, setPasswordValue] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [localError, setLocalError] = useState<string | null>(null);

  const submitting = phase === "loading";
  const isPasswordPrompt = phase === "needs-password";

  function handlePhoneSubmit(e: FormEvent) {
    e.preventDefault();
    const digits = normalizePhone(phoneValue);
    if (!digits) {
      setLocalError(
        "Enter your phone number with country code (e.g. 254712345678 or 2347041131371).",
      );
      return;
    }
    setLocalError(null);
    submitMemberPhone(digits);
  }

  function handlePasswordSubmit(e: FormEvent) {
    e.preventDefault();
    if (!passwordValue.trim()) {
      setLocalError("Please enter your admin password.");
      return;
    }
    setLocalError(null);
    submitAdminPassword(passwordValue.trim());
  }

  return (
    <div className="flex min-h-dvh flex-col items-center justify-center bg-zinc-50 px-4 py-12 sm:px-6">
      <div className="w-full max-w-sm rounded-3xl border border-zinc-200/80 bg-white p-8 shadow-sm">
        <AppBrand size="lg" />

        {isPasswordPrompt ? (
          // Admin password entry card
          <div className="mt-8 text-center">
            <div className="mx-auto inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-900 shadow-2xs">
              <svg
                width="12"
                height="12"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2.5"
                strokeLinecap="round"
                strokeLinejoin="round"
                className="shrink-0 text-emerald-600"
              >
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
              </svg>
              <span>Coordinator sign-in</span>
            </div>

            <h1 className="mt-3 text-lg font-semibold tracking-tight text-zinc-900">
              Welcome, {adminName || "Admin"}
            </h1>
            <p className="mt-1 text-xs leading-relaxed text-zinc-500">
              Coordinators use a web-only password (same admin tools as WhatsApp and Telegram).
            </p>
          </div>
        ) : (
          // Member phone entry card
          <div className="mt-8 text-center">
            <h1 className="text-lg font-semibold tracking-tight text-zinc-900">
              Sign in with your phone
            </h1>
            <p className="mt-2 text-xs leading-relaxed text-zinc-500">
              {APP_SIGN_IN_DESCRIPTION}
            </p>
          </div>
        )}

        {submitting ? (
          <div className="mt-8 flex justify-center py-4">
            <CircularLoader size="md" />
          </div>
        ) : isPasswordPrompt ? (
          <form onSubmit={handlePasswordSubmit} className="mt-6 space-y-4">
            <div>
              <label htmlFor="admin-password" className="sr-only">
                Admin Password
              </label>
              <div className="relative">
                <input
                  id="admin-password"
                  type={showPassword ? "text" : "password"}
                  placeholder="Admin password"
                  autoComplete="current-password"
                  autoFocus
                  value={passwordValue}
                  onChange={(e) => setPasswordValue(e.target.value)}
                  className="w-full rounded-xl border border-zinc-200 bg-zinc-50/50 px-4 py-3 pr-11 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-zinc-900 focus:bg-white focus:outline-none focus:ring-1 focus:ring-zinc-900 transition-colors"
                />
                <button
                  type="button"
                  onClick={() => setShowPassword((s) => !s)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 rounded p-1 text-zinc-400 hover:text-zinc-700 cursor-pointer"
                  title={showPassword ? "Hide password" : "Show password"}
                >
                  {showPassword ? (
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                      <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" />
                      <line x1="1" y1="1" x2="23" y2="23" />
                    </svg>
                  ) : (
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                      <circle cx="12" cy="12" r="3" />
                    </svg>
                  )}
                </button>
              </div>
            </div>

            {(localError || error) && (
              <p className="text-xs text-rose-600 font-medium">{localError || error}</p>
            )}

            <button
              type="submit"
              className="w-full cursor-pointer rounded-xl bg-zinc-900 py-3 text-sm font-medium text-white transition hover:bg-zinc-800 active:scale-[0.99] shadow-xs"
            >
              Continue as coordinator
            </button>

            <button
              type="button"
              onClick={backToPhoneEntry}
              className="w-full text-center text-xs text-zinc-500 hover:text-zinc-900 transition pt-1 cursor-pointer"
            >
              ← Use a different phone number
            </button>
          </form>
        ) : (
          <form onSubmit={handlePhoneSubmit} className="mt-6 space-y-4">
            <div>
              <label htmlFor="member-phone" className="sr-only">
                Phone number
              </label>
              <input
                id="member-phone"
                type="tel"
                inputMode="tel"
                autoComplete="tel"
                placeholder={`e.g. ${KENYA_PHONE_PLACEHOLDER}`}
                value={phoneValue}
                onChange={(e) => setPhoneValue(e.target.value)}
                className="w-full rounded-xl border border-zinc-200 bg-zinc-50/50 px-4 py-3 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-zinc-900 focus:bg-white focus:outline-none focus:ring-1 focus:ring-zinc-900 transition-colors"
              />
            </div>
            {(localError || error) && (
              <p className="text-xs text-rose-600 font-medium">{localError || error}</p>
            )}
            <button
              type="submit"
              className="w-full cursor-pointer rounded-xl bg-zinc-900 py-3 text-sm font-medium text-white transition hover:bg-zinc-800 active:scale-[0.99]"
            >
              Continue
            </button>
          </form>
        )}

        {!submitting ? <PlatformReachRow /> : null}
      </div>
    </div>
  );
}
