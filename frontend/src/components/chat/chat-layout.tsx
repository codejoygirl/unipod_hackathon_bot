"use client";

import { useState, type ReactNode } from "react";

import { ThemeToggle } from "@/components/landing/theme-toggle";
import { Brand } from "@/components/layout/brand";
import { cn } from "@/lib/utils";
import { ConversationList } from "./conversation-list";

/**
 * The ChatGPT shell: a fixed history rail on the left and a full-height main column.
 * On small screens the rail becomes a slide-in drawer toggled from the top bar.
 */
export function ChatLayout({ children }: { children: ReactNode }) {
  const [sidebarOpen, setSidebarOpen] = useState(false);

  function close() {
    setSidebarOpen(false);
  }

  return (
    <div className="flex h-dvh w-full overflow-hidden bg-paper">
      {sidebarOpen ? (
        <button
          type="button"
          aria-label="Close chat history"
          onClick={close}
          className="fixed inset-0 z-40 bg-ink/40 backdrop-blur-sm lg:hidden"
        />
      ) : null}

      <aside
        className={cn(
          // White in light mode, raised in dark: the rail leads, the transcript recedes.
          "z-50 flex w-[17.5rem] shrink-0 flex-col bg-paper-raised transition-transform duration-200",
          "max-lg:fixed max-lg:inset-y-0 max-lg:left-0",
          sidebarOpen
            ? "max-lg:translate-x-0"
            : "max-lg:pointer-events-none max-lg:-translate-x-full",
        )}
      >
        <ConversationList onNavigate={close} />
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex h-12 shrink-0 items-center gap-3 px-3 lg:hidden">
          <button
            type="button"
            onClick={() => setSidebarOpen(true)}
            aria-label="Open chat history"
            className="flex size-9 items-center justify-center rounded-lg border border-rule-strong text-ink-soft transition-colors duration-200 hover:border-accent hover:text-accent"
          >
            <MenuIcon />
          </button>
          <Brand />
          <div className="ml-auto">
            <ThemeToggle className="flex size-9 items-center justify-center rounded-full border border-rule-strong text-ink-soft transition-colors duration-200 hover:border-accent hover:text-accent" />
          </div>
        </header>

        <div className="flex min-h-0 flex-1 flex-col">{children}</div>
      </div>
    </div>
  );
}

function MenuIcon() {
  return (
    <svg
      viewBox="0 0 16 16"
      width="16"
      height="16"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.5"
      strokeLinecap="round"
      aria-hidden="true"
    >
      <path d="M2.5 4h11M2.5 8h11M2.5 12h11" />
    </svg>
  );
}
