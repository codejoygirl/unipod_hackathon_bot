"use client";

import { normalizePhone } from "@/lib/web-chat/phone";
import { useWebChat } from "@/lib/web-chat/web-chat-context";
import { FormEvent, useState } from "react";

const APP_TITLE =
  process.env.NEXT_PUBLIC_APP_NAME?.trim() || "UniPod Community Assistant";

export function PhoneEntryScreen() {
  const { submitMemberPhone, accessKey, error } = useWebChat();
  const [value, setValue] = useState("");
  const [localError, setLocalError] = useState<string | null>(null);

  function handleSubmit(e: FormEvent) {
    e.preventDefault();
    const digits = normalizePhone(value);
    if (!digits) {
      setLocalError("Enter your phone number with country code (digits only, 8–15 numbers).");
      return;
    }
    setLocalError(null);
    submitMemberPhone(digits);
  }

  return (
    <div className="flex min-h-dvh flex-col justify-center bg-[#f6f4fa] px-6 py-12">
      <div className="mx-auto w-full max-w-md">
        <p className="text-xs font-semibold uppercase tracking-wide text-zinc-400">{APP_TITLE}</p>
        <h1 className="mt-2 text-2xl font-semibold text-zinc-900">Enter your phone number</h1>
        <p className="mt-2 text-sm leading-relaxed text-zinc-600">
          We use it to load your existing web chat or start a new session linked to the same history
          as your private messages on WhatsApp or Telegram.
        </p>

        <form onSubmit={handleSubmit} className="mt-8 space-y-4">
          <div>
            <label htmlFor="member-phone" className="sr-only">
              Phone number
            </label>
            <input
              id="member-phone"
              type="tel"
              inputMode="tel"
              autoComplete="tel"
              placeholder="e.g. 2347041131371"
              value={value}
              onChange={(e) => setValue(e.target.value)}
              className="w-full rounded-2xl border border-zinc-200 bg-white px-4 py-3.5 text-base text-zinc-900 placeholder:text-zinc-400 focus:border-zinc-400 focus:outline-none focus:ring-2 focus:ring-zinc-200"
            />
          </div>
          {(localError || error) && (
            <p className="text-sm text-red-600">{localError || error}</p>
          )}
          {!accessKey ? (
            <p className="text-xs text-amber-800">
              This install is missing a community link key (<code>?k=</code>). Open the full invite
              URL from your admin or bot.
            </p>
          ) : null}
          <button
            type="submit"
            disabled={!accessKey}
            className="w-full rounded-2xl bg-zinc-900 py-3.5 text-sm font-semibold text-white disabled:opacity-50"
          >
            Continue to chat
          </button>
        </form>
      </div>
    </div>
  );
}
