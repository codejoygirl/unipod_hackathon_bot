"use client";

import { PlatformReachRow } from "@/components/branding/platform-reach";
import { CircularLoader } from "@/components/ui/circular-loader";
import { APP_LOGO_SRC, KENYA_PHONE_PLACEHOLDER } from "@/lib/branding";
import { usePwa } from "@/lib/pwa/pwa-context";
import { INSTALL_APP_BUTTON_CLASS } from "@/components/pwa/install-prompt";
import { normalizePhone } from "@/lib/web-chat/phone";
import { useWebChat } from "@/lib/web-chat/web-chat-context";
import Image from "next/image";
import { FormEvent, useState } from "react";

export function PhoneEntryScreen() {
  const {
    submitMemberPhone,
    submitAdminPassword,
    backToPhoneEntry,
    error,
    phase,
    adminName,
  } = useWebChat();

  const { isInstallable, isInstalled, promptInstall } = usePwa();

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
      setLocalError("Please enter your coordinator password.");
      return;
    }
    setLocalError(null);
    submitAdminPassword(passwordValue.trim());
  }

  const activeError = localError || error;

  return (
    <div className="flex min-h-dvh flex-col justify-between items-center bg-white dark:bg-[#0d0d0d] text-zinc-900 dark:text-zinc-100 px-4 py-8 sm:py-12 transition-colors duration-200">
      {/* Top action bar */}
      <div className="w-full flex justify-end max-w-5xl px-4 sm:px-6">
        {isInstallable && !isInstalled ? (
          <button
            type="button"
            onClick={() => void promptInstall()}
            className={INSTALL_APP_BUTTON_CLASS}
            title="Install UniPod on your device"
          >
            <svg
              width="13"
              height="13"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2.5"
              strokeLinecap="round"
              strokeLinejoin="round"
              className="shrink-0 text-blue-300 dark:text-blue-600"
            >
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
              <polyline points="7 10 12 15 17 10" />
              <line x1="12" y1="15" x2="12" y2="3" />
            </svg>
            <span>Install app</span>
          </button>
        ) : null}
      </div>

      {/* Main ChatGPT-style centered card container */}
      <main className="w-full max-w-[380px] sm:max-w-[440px] flex flex-col items-center my-auto animate-fade-in">
        {/* Brand Icon */}
        <div className="flex h-14 w-14 items-center justify-center rounded-2xl overflow-hidden shadow-sm mb-6">
          <Image
            src={APP_LOGO_SRC}
            alt="UniPod Logo"
            width={56}
            height={56}
            className="h-full w-full object-contain rounded-2xl"
            priority
          />
        </div>

        {isPasswordPrompt ? (
          /* Coordinator Password Prompt */
          <>
            <h1 className="text-2xl sm:text-[28px] font-semibold tracking-tight text-zinc-900 dark:text-zinc-100 text-center">
              Welcome, {adminName || "Admin"}
            </h1>
            <p className="mt-2 text-sm text-zinc-500 dark:text-zinc-400 text-center leading-relaxed max-w-xs">
              Enter your coordinator password to access management tools.
            </p>

            <form onSubmit={handlePasswordSubmit} className="mt-6 w-full space-y-3.5">
              <div>
                <label
                  htmlFor="admin-password"
                  className="block text-left text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1"
                >
                  Password
                </label>
                <div className="relative">
                  <input
                    id="admin-password"
                    type={showPassword ? "text" : "password"}
                    placeholder="Enter coordinator password"
                    autoComplete="current-password"
                    autoFocus
                    value={passwordValue}
                    onChange={(e) => setPasswordValue(e.target.value)}
                    disabled={submitting}
                    className="w-full rounded-xl border border-zinc-300 bg-white px-3.5 py-2.5 pr-10 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-zinc-900 focus:outline-hidden focus:ring-1 focus:ring-zinc-900 shadow-2xs transition-all disabled:opacity-60 dark:border-zinc-800 dark:bg-[#181818] dark:text-zinc-100 dark:placeholder:text-zinc-500 dark:focus:border-zinc-100 dark:focus:ring-zinc-100"
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword((s) => !s)}
                    className="absolute right-3 top-1/2 -translate-y-1/2 rounded p-1 text-zinc-400 hover:text-zinc-700 dark:text-zinc-500 dark:hover:text-zinc-300 cursor-pointer transition"
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

              {activeError && (
                <div className="rounded-xl bg-rose-50 border border-rose-200/80 px-3 py-2 text-xs text-rose-700 font-medium text-left dark:bg-rose-950/40 dark:border-rose-900/60 dark:text-rose-300">
                  {activeError}
                </div>
              )}

              <button
                type="submit"
                disabled={submitting}
                className="w-full cursor-pointer rounded-xl bg-zinc-900 py-2.5 text-sm font-semibold text-white transition-all hover:bg-zinc-800 active:scale-[0.99] shadow-xs disabled:opacity-60 flex items-center justify-center gap-2 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-200"
              >
                {submitting ? (
                  <>
                    <CircularLoader size="xs" className="border-t-white dark:border-t-zinc-900" />
                    <span>Signing in…</span>
                  </>
                ) : (
                  "Continue"
                )}
              </button>

              <button
                type="button"
                onClick={backToPhoneEntry}
                disabled={submitting}
                className="w-full text-center text-xs text-zinc-500 hover:text-zinc-900 transition pt-1 cursor-pointer font-medium dark:text-zinc-400 dark:hover:text-zinc-200"
              >
                ← Use a different phone number
              </button>
            </form>
          </>
        ) : (
          /* Member Phone Number Entry */
          <>
            <h1 className="text-2xl sm:text-[28px] font-semibold tracking-tight text-zinc-900 dark:text-zinc-100 text-center">
              Sign in to UniPod
            </h1>
            <p className="mt-2 text-sm text-zinc-500 dark:text-zinc-400 text-center leading-relaxed max-w-xs">
              Enter your phone number to access your community assistant, schedules, and programme updates.
            </p>

            <form onSubmit={handlePhoneSubmit} className="mt-6 w-full space-y-3.5">
              <div>
                <label
                  htmlFor="member-phone"
                  className="block text-left text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1"
                >
                  Phone number
                </label>
                <input
                  id="member-phone"
                  type="tel"
                  inputMode="tel"
                  autoComplete="tel"
                  autoFocus
                  placeholder={`e.g. ${KENYA_PHONE_PLACEHOLDER}`}
                  value={phoneValue}
                  onChange={(e) => setPhoneValue(e.target.value)}
                  disabled={submitting}
                  className="w-full rounded-xl border border-zinc-300 bg-white px-3.5 py-2.5 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-zinc-900 focus:outline-hidden focus:ring-1 focus:ring-zinc-900 shadow-2xs transition-all disabled:opacity-60 dark:border-zinc-800 dark:bg-[#181818] dark:text-zinc-100 dark:placeholder:text-zinc-500 dark:focus:border-zinc-100 dark:focus:ring-zinc-100"
                />
                <p className="mt-1.5 text-left text-[11px] text-zinc-500 dark:text-zinc-400">
                  Include country code (e.g. 254 for Kenya or 234 for Nigeria)
                </p>
              </div>

              {activeError && (
                <div className="rounded-xl bg-rose-50 border border-rose-200/80 px-3 py-2 text-xs text-rose-700 font-medium text-left dark:bg-rose-950/40 dark:border-rose-900/60 dark:text-rose-300">
                  {activeError}
                </div>
              )}

              <button
                type="submit"
                disabled={submitting}
                className="w-full cursor-pointer rounded-xl bg-zinc-900 py-2.5 text-sm font-semibold text-white transition-all hover:bg-zinc-800 active:scale-[0.99] shadow-xs disabled:opacity-60 flex items-center justify-center gap-2 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-200"
              >
                {submitting ? (
                  <>
                    <CircularLoader size="xs" className="border-t-white dark:border-t-zinc-900" />
                    <span>Continuing…</span>
                  </>
                ) : (
                  "Continue"
                )}
              </button>
            </form>
          </>
        )}

        {/* Platform channels: WhatsApp / Telegram */}
        <PlatformReachRow />
      </main>

      {/* Subtle footer */}
      <footer className="mt-8 text-center text-xs text-zinc-400 dark:text-zinc-500 select-none">
        UniPod Community Assistant · Grounded community knowledge
      </footer>
    </div>
  );
}
