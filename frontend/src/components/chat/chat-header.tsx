"use client";

import { APP_DISPLAY_NAME, APP_LOGO_SRC } from "@/lib/branding";
import { useWebChat } from "@/lib/web-chat/web-chat-context";
import Image from "next/image";

export function ChatHeader() {
  const { community, memberLabel, sessionId, isAdmin, adminName } = useWebChat();

  const formattedPhone = memberLabel
    ? (memberLabel.startsWith("+") || memberLabel.includes("(") ? memberLabel : `+${memberLabel}`)
    : "";

  return (
    <header className="sticky top-0 z-30 border-b border-zinc-200/80 bg-white/95 px-4 py-2.5 backdrop-blur-md">
      <div className="mx-auto flex max-w-2xl items-center justify-between gap-3">
        <div className="flex items-center gap-3 min-w-0">
          <div className="relative shrink-0">
            <Image
              src={APP_LOGO_SRC}
              alt="UniPod Logo"
              width={38}
              height={38}
              className="rounded-full object-contain border border-zinc-200 bg-white p-0.5"
            />
            <span
              className="absolute bottom-0 right-0 h-2.5 w-2.5 rounded-full bg-emerald-500 ring-2 ring-white"
              title="Online"
            />
          </div>
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-1.5">
              <h1 className="truncate text-sm font-semibold tracking-tight text-zinc-900">
                {community?.name ?? APP_DISPLAY_NAME}
              </h1>
            </div>
            <p className="truncate text-xs text-zinc-500 font-normal">
              {formattedPhone ? (
                <span>{formattedPhone}</span>
              ) : sessionId ? (
                <span>Session {sessionId.slice(0, 10)}</span>
              ) : (
                <span className="text-emerald-600 font-medium">online</span>
              )}
            </p>
          </div>
        </div>

        {/* Role badge (Admin vs Member) & Online indicator */}
        <div className="flex items-center gap-2 shrink-0">
          {isAdmin ? (
            <span
              className="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-800 shadow-2xs"
              title={`Community coordinator (${adminName || "Admin"})`}
            >
              <svg
                width="11"
                height="11"
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
              <span>Admin</span>
            </span>
          ) : (
            <span
              className="inline-flex items-center rounded-full border border-zinc-200 bg-zinc-100/90 px-2 py-0.5 text-[11px] font-medium text-zinc-600"
              title="Logged in as Community Member"
            >
              Member
            </span>
          )}

          <div className="flex items-center gap-1.5 text-xs text-zinc-400">
            <span className="inline-block h-1.5 w-1.5 rounded-full bg-emerald-500" />
            <span className="text-[11px] text-zinc-500 hidden sm:inline">Active</span>
          </div>
        </div>
      </div>
    </header>
  );
}
