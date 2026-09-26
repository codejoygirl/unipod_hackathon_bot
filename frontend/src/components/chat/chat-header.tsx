"use client";

import React from "react";
import { useTheme } from "@/lib/theme/theme-context";
import { useSidebar } from "@/lib/sidebar/sidebar-context";
import { InstallHeaderButton } from "@/components/pwa/install-prompt";
import { HeaderMoreMenu } from "@/components/tour/header-more-menu";
import { Tooltip } from "@/components/ui/tooltip";
import { NotificationsChangelogModal } from "@/components/notifications/notifications-changelog-modal";
import { PlatformReach } from "@/components/branding/platform-reach";

interface ChatHeaderProps {
  onToggleSidebar?: () => void;
  onInsertQuery?: (text: string) => void;
}

export function ChatHeader({ onToggleSidebar, onInsertQuery }: ChatHeaderProps) {
  const { resolvedTheme, toggleTheme } = useTheme();
  const sidebar = useSidebar();

  const toggleAction = onToggleSidebar || sidebar.toggleSidebar;

  return (
    <header className="sticky top-0 z-30 bg-white/95 backdrop-blur-md dark:bg-[#0d0d0d]/95 select-none w-full">
      <div className="flex h-13 w-full items-center justify-between px-3 sm:px-5">
        {/* Left: Mobile-only drawer toggle (hidden on desktop since sidebar has its own collapse controls) */}
        <div className="flex items-center md:hidden min-w-0">
          <button
            type="button"
            onClick={toggleAction}
            className="flex h-8 w-8 items-center justify-center rounded-lg text-zinc-600 transition hover:bg-zinc-100 hover:text-zinc-900 active:scale-95 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-zinc-100 cursor-pointer"
            aria-label="Toggle navigation menu"
          >
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
              <line x1="3" y1="6" x2="21" y2="6" />
              <line x1="3" y1="12" x2="21" y2="12" />
              <line x1="3" y1="18" x2="21" y2="18" />
            </svg>
          </button>
        </div>

        {/* Rightmost Action Controls: Install App, then a tight icon cluster */}
        <div className="ml-auto flex items-center gap-2">
          <InstallHeaderButton />

          <div className="flex h-8 items-center gap-0.5">
            <HeaderMoreMenu />

            <div className="flex h-8 w-8 items-center justify-center" data-tour="notifications">
              <NotificationsChangelogModal onInsertQuery={onInsertQuery} />
            </div>

            <div className="flex h-8 w-8 items-center justify-center">
              <Tooltip
                content={resolvedTheme === "dark" ? "Switch to light mode" : "Switch to dark mode"}
                position="bottom"
              >
                <button
                  type="button"
                  data-tour="theme"
                  onClick={toggleTheme}
                  className="flex h-8 w-8 items-center justify-center rounded-lg text-zinc-600 transition hover:bg-zinc-100 hover:text-zinc-900 active:scale-95 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-zinc-100 cursor-pointer"
                  aria-label="Toggle theme"
                >
                  {resolvedTheme === "dark" ? (
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="text-amber-400">
                      <circle cx="12" cy="12" r="5" />
                      <line x1="12" y1="1" x2="12" y2="3" />
                      <line x1="12" y1="21" x2="12" y2="23" />
                      <line x1="4.22" y1="4.22" x2="5.64" y2="5.64" />
                      <line x1="18.36" y1="18.36" x2="19.78" y2="19.78" />
                      <line x1="1" y1="12" x2="3" y2="12" />
                      <line x1="21" y1="12" x2="23" y2="12" />
                      <line x1="4.22" y1="19.78" x2="5.64" y2="18.36" />
                      <line x1="18.36" y1="5.64" x2="19.78" y2="4.22" />
                    </svg>
                  ) : (
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="text-zinc-700">
                      <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
                    </svg>
                  )}
                </button>
              </Tooltip>
            </div>
          </div>
        </div>
      </div>
      <PlatformReach variant="strip" />
    </header>
  );
}
