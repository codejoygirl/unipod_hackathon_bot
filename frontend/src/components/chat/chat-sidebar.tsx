"use client";

import React from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import Image from "next/image";
import { APP_LOGO_SRC } from "@/lib/branding";
import { useWebChat } from "@/lib/web-chat/web-chat-context";
import { useSidebar } from "@/lib/sidebar/sidebar-context";
import { withPhoneQuery } from "@/lib/web-chat/url-params";
import { Tooltip } from "@/components/ui/tooltip";

interface ChatSidebarProps {
  isOpen: boolean;
  onToggle: () => void;
  onNewChat?: () => void;
  onSelectPrompt?: (prompt: string) => void;
  onOpenCommands?: () => void;
}

export function ChatSidebar({
  isOpen,
  onToggle,
  onNewChat,
}: ChatSidebarProps) {
  const { community, memberLabel, memberPhone, isAdmin, adminName, logOut } = useWebChat();
  const { openFeatureModal } = useSidebar();
  const pathname = usePathname();

  const handleNavClick = () => {
    if (typeof window !== "undefined" && window.innerWidth < 768 && isOpen) {
      onToggle();
    }
  };

  const formattedPhone = memberLabel
    ? memberLabel.startsWith("+") || memberLabel.includes("(")
      ? memberLabel
      : `+${memberLabel}`
    : "";

  // Last 2 digits or initials for avatar circle (e.g. "42")
  const avatarBadge = formattedPhone
    ? formattedPhone.slice(-2)
    : isAdmin
    ? "AD"
    : "42";

  const displayName = isAdmin
    ? adminName || "Coordinator"
    : formattedPhone || "+2348117084642";

  const activeCommunityName = community?.name ?? "Demo Community";

  const isAssistantHome = pathname === "/" || pathname === "";
  const isResourcesActive = pathname.startsWith("/resources");
  const isMeetingsActive = pathname.startsWith("/meetings");

  const navLinkClass = (active: boolean) =>
    `flex items-center justify-between rounded-xl px-2.5 py-2 text-xs font-medium transition cursor-pointer ${
      active
        ? "bg-zinc-200/90 text-zinc-950 font-semibold shadow-2xs ring-1 ring-inset ring-zinc-300/70 dark:bg-[#252525] dark:text-white dark:ring-zinc-600/60"
        : "text-zinc-600 hover:bg-zinc-200/50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-zinc-200"
    }`;

  const navIconRailClass = (active: boolean) =>
    `flex h-9 w-9 items-center justify-center rounded-xl transition cursor-pointer ${
      active
        ? "bg-zinc-200/90 text-zinc-950 shadow-2xs ring-1 ring-inset ring-zinc-300/70 dark:bg-[#252525] dark:text-white dark:ring-zinc-600/60"
        : "text-zinc-600 hover:bg-zinc-200/60 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
    }`;

  return (
    <>
      {/* Mobile Backdrop with smooth opacity fade */}
      <div
        className={`fixed inset-0 z-40 bg-black/60 backdrop-blur-xs transition-opacity duration-300 ease-[cubic-bezier(0.16,1,0.3,1)] md:hidden ${
          isOpen ? "opacity-100 pointer-events-auto" : "opacity-0 pointer-events-none"
        }`}
        onClick={onToggle}
        aria-hidden="true"
      />

      {/* Collapsible Sidebar Container */}
      <aside
        className={`fixed inset-y-0 left-0 z-50 flex flex-col border-r border-zinc-200/90 bg-[#f9f9fb] transition-[width,transform] duration-300 ease-[cubic-bezier(0.16,1,0.3,1)] md:static md:translate-x-0 md:shadow-none dark:border-zinc-800/80 dark:bg-[#171717] overflow-hidden will-change-[width,transform] ${
          isOpen
            ? "w-72 max-w-[85vw] translate-x-0 md:w-64 shadow-2xl"
            : "-translate-x-full w-72 max-w-[85vw] md:w-16 shadow-none"
        }`}
      >
        {/* ========================================================
           EXPANDED FULL SIDEBAR VIEW (Mobile full drawer & Desktop md:w-64)
           ======================================================== */}
        <div
          className={`h-full w-72 max-w-[85vw] md:w-64 flex flex-col justify-between px-3 py-3.5 sm:py-4 select-none shrink-0 transition-opacity duration-200 ${
            isOpen
              ? "opacity-100 pointer-events-auto md:delay-75"
              : "max-md:opacity-100 max-md:pointer-events-auto md:opacity-0 md:pointer-events-none md:absolute md:inset-y-0 md:left-0"
          }`}
        >
          {/* Top Section: Community Switcher, New Chat, & Navigation */}
          <div className="flex flex-col min-h-0 flex-1">
            {/* 1. Community Header + Collapse Button (Full-width edge-to-edge border) */}
            <div className="relative -mx-3 px-3 pb-3 mb-3 border-b border-zinc-200/80 dark:border-zinc-800/80">
              <div className="flex items-center justify-between gap-1">
                <div className="flex flex-1 items-center justify-between rounded-xl px-1.5 py-1 text-left min-w-0 select-none hover:bg-zinc-200/50 dark:hover:bg-zinc-800/50 transition">
                  <Link
                    href={withPhoneQuery("/", memberPhone)}
                    onClick={handleNavClick}
                    className="flex items-center gap-1.5 min-w-0 flex-1 cursor-pointer"
                    title="Community assistant home"
                  >
                    {/* Natural App Logo */}
                    <div className="relative flex h-7 w-7 shrink-0 items-center justify-center rounded-lg overflow-hidden shadow-2xs">
                      <Image
                        src={APP_LOGO_SRC}
                        alt="Logo"
                        width={28}
                        height={28}
                        className="h-full w-full object-contain rounded-lg"
                      />
                    </div>

                    <div className="min-w-0 flex-1 flex items-center gap-1.5">
                      <span className="truncate text-xs font-semibold text-zinc-900 dark:text-zinc-100">
                        {activeCommunityName}
                      </span>
                      <span className="shrink-0 whitespace-nowrap rounded bg-emerald-100 px-1 py-0.5 text-[8px] font-bold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                        METI AI
                      </span>
                    </div>
                  </Link>

                  {/* Disabled Dropdown Icon (Shows switching exists, but disabled for now) */}
                  <Tooltip content="Switch community (Coming soon)" position="bottom">
                    <div
                      className="flex h-5 w-5 shrink-0 items-center justify-center text-zinc-400 dark:text-zinc-500 opacity-60 cursor-not-allowed select-none ml-1"
                      aria-disabled="true"
                      aria-label="Community switcher disabled"
                    >
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                        <polyline points="6 9 12 15 18 9" />
                      </svg>
                    </div>
                  </Tooltip>
                </div>

                {/* Sidebar Collapse / Close Toggle */}
                <Tooltip content={isOpen ? "Collapse sidebar" : "Expand sidebar"} position="bottom" shortcut="Ctrl+S">
                  <button
                    type="button"
                    onClick={onToggle}
                    className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-zinc-500 hover:bg-zinc-200/70 hover:text-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200 cursor-pointer active:scale-95 transition"
                    aria-label="Close sidebar"
                  >
                    {/* Mobile: close X icon */}
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="md:hidden">
                      <line x1="18" y1="6" x2="6" y2="18" />
                      <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                    {/* Desktop: sidebar collapse icon */}
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="hidden md:block">
                      <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
                      <line x1="9" y1="3" x2="9" y2="21" />
                    </svg>
                  </button>
                </Tooltip>
              </div>
            </div>

            {/* Clear thread / New chat */}
            <Tooltip
              content="Start a new chat conversation"
              position="bottom"
            >
              <button
                type="button"
                onClick={() => {
                  handleNavClick();
                  onNewChat?.();
                }}
                className="mb-2 flex w-full items-center gap-2.5 rounded-full bg-zinc-100/95 px-3 py-2 text-xs font-semibold text-zinc-800 shadow-2xs hover:bg-zinc-200/90 hover:text-zinc-950 dark:bg-zinc-800/90 dark:text-zinc-200 dark:hover:bg-zinc-800 transition active:scale-[0.99] cursor-pointer"
                aria-label="New chat"
              >
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" className="text-emerald-600 dark:text-emerald-400 shrink-0">
                  <line x1="12" y1="5" x2="12" y2="19" />
                  <line x1="5" y1="12" x2="19" y2="12" />
                </svg>
                <span>New chat</span>
              </button>
            </Tooltip>

            {/* Navigation Links - Shifted comfortably down from top */}
            <nav className="mt-1 space-y-1.5 flex-1 overflow-y-auto no-scrollbar" aria-label="Sidebar navigation">
              {/* Chat (home) */}
              <Link
                href={withPhoneQuery("/", memberPhone)}
                onClick={handleNavClick}
                className={navLinkClass(isAssistantHome)}
                aria-current={isAssistantHome ? "page" : undefined}
              >
                <div className="flex items-center gap-2.5 min-w-0">
                  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden className="shrink-0">
                    <path
                      d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"
                      stroke="currentColor"
                      strokeWidth={isAssistantHome ? "2" : "1.75"}
                      strokeLinecap="round"
                      strokeLinejoin="round"
                    />
                  </svg>
                  <span className="truncate">Chat</span>
                </div>
                {isAssistantHome ? (
                  <span className="shrink-0 whitespace-nowrap rounded bg-zinc-300/80 px-1.5 py-0.5 text-[9px] font-semibold text-zinc-800 dark:bg-zinc-600 dark:text-zinc-100">
                    Active
                  </span>
                ) : null}
              </Link>

              {/* Resources (Enabled) */}
              <Link
                href={withPhoneQuery("/resources", memberPhone)}
                onClick={handleNavClick}
                className={navLinkClass(isResourcesActive)}
                aria-current={isResourcesActive ? "page" : undefined}
              >
                <div className="flex items-center gap-2.5 min-w-0">
                  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden className="shrink-0">
                    <path
                      d="M3 7v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-6l-2-2H5a2 2 0 0 0-2 2z"
                      stroke="currentColor"
                      strokeWidth={isResourcesActive ? "2" : "1.75"}
                      strokeLinecap="round"
                      strokeLinejoin="round"
                    />
                  </svg>
                  <span className="truncate">Resources</span>
                </div>
                {isResourcesActive ? (
                  <span className="shrink-0 whitespace-nowrap rounded bg-zinc-300/80 px-1.5 py-0.5 text-[9px] font-semibold text-zinc-800 dark:bg-zinc-600 dark:text-zinc-100">
                    Active
                  </span>
                ) : (
                  <span className="shrink-0 whitespace-nowrap rounded bg-emerald-100 px-1.5 py-0.5 text-[9px] font-semibold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                    Hub
                  </span>
                )}
              </Link>

              {/* Meetings (Enabled) */}
              <Link
                href={withPhoneQuery("/meetings", memberPhone)}
                onClick={handleNavClick}
                className={navLinkClass(isMeetingsActive)}
                aria-current={isMeetingsActive ? "page" : undefined}
              >
                <div className="flex items-center gap-2.5 min-w-0">
                  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden className="shrink-0">
                    <path
                      d="M4 8V6a2 2 0 0 1 2-2h2M16 4h2a2 2 0 0 1 2 2v2M20 16v2a2 2 0 0 1-2 2h-2M8 20H6a2 2 0 0 1-2-2v-2M9 12h6v4H9v-4Z"
                      stroke="currentColor"
                      strokeWidth={isMeetingsActive ? "2" : "1.75"}
                      strokeLinecap="round"
                    />
                  </svg>
                  <span className="truncate">Meetings</span>
                </div>
                {isMeetingsActive ? (
                  <span className="shrink-0 whitespace-nowrap rounded bg-zinc-300/80 px-1.5 py-0.5 text-[9px] font-semibold text-zinc-800 dark:bg-zinc-600 dark:text-zinc-100">
                    Active
                  </span>
                ) : (
                  <span className="shrink-0 whitespace-nowrap rounded bg-sky-100 px-1.5 py-0.5 text-[9px] font-semibold text-sky-800 dark:bg-sky-950 dark:text-sky-300">
                    Live
                  </span>
                )}
              </Link>

              {/* Catch up (Disabled for now) */}
              <div
                className="flex items-center justify-between rounded-xl px-2.5 py-2 text-xs font-medium text-zinc-400 dark:text-zinc-600 opacity-40 cursor-not-allowed select-none"
                title="Catch up (Coming soon)"
                aria-disabled="true"
              >
                <div className="flex items-center gap-2.5 min-w-0">
                  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden className="shrink-0">
                    <path
                      d="M7 4h10v16H7V4ZM9 8h6M9 12h6M9 16h4"
                      stroke="currentColor"
                      strokeWidth="1.75"
                      strokeLinecap="round"
                    />
                  </svg>
                  <span className="truncate">Catch up</span>
                </div>
                <span className="shrink-0 rounded border border-zinc-200/80 px-1.5 py-0.2 text-[9px] font-medium text-zinc-400 dark:border-zinc-800 dark:text-zinc-600">
                  Soon
                </span>
              </div>

              {/* Tasks (Disabled for now) */}
              <div
                className="flex items-center justify-between rounded-xl px-2.5 py-2 text-xs font-medium text-zinc-400 dark:text-zinc-600 opacity-40 cursor-not-allowed select-none"
                title="Tasks (Coming soon)"
                aria-disabled="true"
              >
                <div className="flex items-center gap-2.5 min-w-0">
                  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden className="shrink-0">
                    <path
                      d="M9 11l2 2 4-4M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"
                      stroke="currentColor"
                      strokeWidth="1.75"
                      strokeLinecap="round"
                      strokeLinejoin="round"
                    />
                  </svg>
                  <span className="truncate">Tasks</span>
                </div>
                <span className="shrink-0 rounded border border-zinc-200/80 px-1.5 py-0.2 text-[9px] font-medium text-zinc-400 dark:border-zinc-800 dark:text-zinc-600">
                  Soon
                </span>
              </div>

              <div className="pt-2 pb-1">
                <div className="h-px w-full bg-zinc-200/80 dark:bg-zinc-800/80" />
              </div>

              {/* Request Feature */}
              <button
                type="button"
                onClick={() => {
                  handleNavClick();
                  openFeatureModal();
                }}
                className="flex w-full items-center justify-between rounded-xl px-2.5 py-2 text-xs font-medium text-zinc-700 hover:bg-emerald-500/10 hover:text-emerald-700 dark:text-zinc-300 dark:hover:bg-emerald-950/40 dark:hover:text-emerald-300 transition cursor-pointer text-left group"
              >
                <div className="flex items-center gap-2.5 min-w-0">
                  <svg
                    width="17"
                    height="17"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.75"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    className="shrink-0 text-emerald-600 dark:text-emerald-400 group-hover:scale-110 transition-transform"
                  >
                    <path d="M12 2a7 7 0 0 0-7 7c0 2.38 1.19 4.47 3 5.74V17a2 2 0 0 0 2 2h4a2 2 0 0 0 2-2v-2.26c1.81-1.27 3-3.36 3-5.74a7 7 0 0 0-7-7M9 21a1 1 0 0 0 1 1h4a1 1 0 0 0 1-1v-1H9v1Z" />
                  </svg>
                  <span className="truncate font-medium">Request feature</span>
                </div>
                <span className="shrink-0 rounded-full bg-emerald-100 px-1.5 py-0.5 text-[9px] font-semibold text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-300">
                  New
                </span>
              </button>

              {/* Updates & Info */}
              <button
                type="button"
                onClick={() => {
                  handleNavClick();
                  window.dispatchEvent(new CustomEvent("open-updates-modal"));
                }}
                className="flex w-full items-center justify-between rounded-xl px-2.5 py-2 text-xs font-medium text-zinc-600 hover:bg-zinc-200/50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-zinc-200 transition cursor-pointer text-left"
              >
                <div className="flex items-center gap-2.5 min-w-0">
                  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden className="shrink-0">
                    <path
                      d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0"
                      stroke="currentColor"
                      strokeWidth="1.75"
                      strokeLinecap="round"
                      strokeLinejoin="round"
                    />
                  </svg>
                  <span className="truncate">Updates & info</span>
                </div>
              </button>
            </nav>
          </div>

          {/* Bottom Section: User Profile */}
          <div className="border-t border-zinc-200/80 pt-2.5 dark:border-zinc-800/80">
            {/* User Profile Card */}
            <div className="flex items-center justify-between rounded-xl p-1.5 hover:bg-zinc-200/50 dark:hover:bg-zinc-800/50 transition">
              <div className="flex items-center gap-2.5 min-w-0">
                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-600 font-bold text-xs text-white shadow-2xs">
                  {avatarBadge}
                </div>
                <div className="min-w-0">
                  <p className="truncate text-xs font-semibold text-zinc-900 dark:text-zinc-100">
                    {displayName}
                  </p>
                  <p className="text-[10px] text-zinc-500 dark:text-zinc-400">
                    {isAdmin ? "Admin" : "Member"}
                  </p>
                </div>
              </div>

              <Tooltip content="Sign out" position="top">
                <button
                  type="button"
                  onClick={() => {
                    handleNavClick();
                    logOut();
                  }}
                  className="rounded-lg p-1.5 text-zinc-400 hover:bg-zinc-200 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200 cursor-pointer"
                  aria-label="Sign out"
                >
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                    <polyline points="16 17 21 12 16 7" />
                    <line x1="21" y1="12" x2="9" y2="12" />
                  </svg>
                </button>
              </Tooltip>
            </div>
          </div>
        </div>

        {/* ========================================================
           COLLAPSED ICON-RAIL VIEW (Desktop md:w-16)
           ======================================================== */}
        <div
          className={`hidden md:flex h-full w-16 flex-col justify-between py-3.5 sm:py-4 px-2 select-none items-center shrink-0 transition-opacity duration-200 ${
            !isOpen
              ? "opacity-100 pointer-events-auto delay-100"
              : "opacity-0 pointer-events-none absolute inset-y-0 left-0"
          }`}
        >
          {/* Top Icons: Expand button, Logo, New Chat */}
          <div className="flex flex-col items-center gap-3">
            {/* Uncollapse / Expand Sidebar Toggle */}
            <Tooltip content="Expand sidebar" position="right" shortcut="Ctrl+S">
              <button
                type="button"
                onClick={onToggle}
                className="flex h-9 w-9 items-center justify-center rounded-xl text-zinc-600 transition hover:bg-zinc-200/70 hover:text-zinc-900 active:scale-95 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-zinc-100 cursor-pointer"
                aria-label="Expand sidebar"
              >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
                  <line x1="9" y1="3" x2="9" y2="21" />
                </svg>
              </button>
            </Tooltip>

            {/* Natural App Logo - Links back to Chat */}
            <Tooltip content="Community assistant" position="right">
              <Link
                href={withPhoneQuery("/", memberPhone)}
                className="flex h-9 w-9 items-center justify-center rounded-xl overflow-hidden shadow-2xs select-none hover:opacity-90 active:scale-95 transition cursor-pointer"
              >
                <Image
                  src={APP_LOGO_SRC}
                  alt="UniPod Logo"
                  width={36}
                  height={36}
                  className="h-full w-full object-contain rounded-xl"
                />
              </Link>
            </Tooltip>

            {/* New chat */}
            <Tooltip content="New chat" position="right">
              <button
                type="button"
                onClick={onNewChat}
                className="flex h-9 w-9 items-center justify-center rounded-xl text-zinc-600 hover:bg-zinc-200/60 hover:text-zinc-900 active:scale-95 transition dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200 cursor-pointer"
                aria-label="New chat"
              >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                  <line x1="12" y1="5" x2="12" y2="19" />
                  <line x1="5" y1="12" x2="19" y2="12" />
                </svg>
              </button>
            </Tooltip>

            <div className="w-8 h-px bg-zinc-200 dark:bg-zinc-800 my-0.5" />

            {/* Navigation Icons */}
            <Tooltip content="Chat" position="right">
              <Link
                href={withPhoneQuery("/", memberPhone)}
                className={navIconRailClass(isAssistantHome)}
                aria-current={isAssistantHome ? "page" : undefined}
              >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden>
                  <path
                    d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"
                    stroke="currentColor"
                    strokeWidth={isAssistantHome ? "2" : "1.75"}
                    strokeLinecap="round"
                    strokeLinejoin="round"
                  />
                </svg>
              </Link>
            </Tooltip>

            {/* Resources (Enabled) */}
            <Tooltip content="Resources (Hub)" position="right">
              <Link
                href={withPhoneQuery("/resources", memberPhone)}
                className={navIconRailClass(isResourcesActive)}
                aria-current={isResourcesActive ? "page" : undefined}
              >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden>
                  <path
                    d="M3 7v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-6l-2-2H5a2 2 0 0 0-2 2z"
                    stroke="currentColor"
                    strokeWidth={isResourcesActive ? "2" : "1.75"}
                    strokeLinecap="round"
                    strokeLinejoin="round"
                  />
                </svg>
              </Link>
            </Tooltip>

            {/* Meetings (Live) */}
            <Tooltip content="Meetings (Live)" position="right">
              <Link
                href={withPhoneQuery("/meetings", memberPhone)}
                className={navIconRailClass(isMeetingsActive)}
                aria-current={isMeetingsActive ? "page" : undefined}
              >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden>
                  <path
                    d="M4 8V6a2 2 0 0 1 2-2h2M16 4h2a2 2 0 0 1 2 2v2M20 16v2a2 2 0 0 1-2 2h-2M8 20H6a2 2 0 0 1-2-2v-2M9 12h6v4H9v-4Z"
                    stroke="currentColor"
                    strokeWidth={isMeetingsActive ? "2" : "1.75"}
                    strokeLinecap="round"
                  />
                </svg>
              </Link>
            </Tooltip>

            {/* Catch up (Disabled) */}
            <Tooltip content="Catch up (Coming soon)" position="right">
              <div
                className="flex h-9 w-9 items-center justify-center rounded-xl text-zinc-400 dark:text-zinc-600 opacity-40 cursor-not-allowed select-none"
                aria-disabled="true"
              >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden>
                  <path
                    d="M7 4h10v16H7V4ZM9 8h6M9 12h6M9 16h4"
                    stroke="currentColor"
                    strokeWidth="1.75"
                    strokeLinecap="round"
                  />
                </svg>
              </div>
            </Tooltip>

            {/* Tasks (Disabled) */}
            <Tooltip content="Tasks (Coming soon)" position="right">
              <div
                className="flex h-9 w-9 items-center justify-center rounded-xl text-zinc-400 dark:text-zinc-600 opacity-40 cursor-not-allowed select-none"
                aria-disabled="true"
              >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden>
                  <path
                    d="M9 11l2 2 4-4M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"
                    stroke="currentColor"
                    strokeWidth="1.75"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                  />
                </svg>
              </div>
            </Tooltip>

            <div className="w-8 h-px bg-zinc-200 dark:bg-zinc-800 my-0.5" />

            {/* Request Feature */}
            <Tooltip content="Request feature" position="right">
              <button
                type="button"
                onClick={openFeatureModal}
                className="flex h-9 w-9 items-center justify-center rounded-xl text-emerald-600 hover:bg-emerald-500/10 active:scale-95 transition dark:text-emerald-400 dark:hover:bg-emerald-950/40 cursor-pointer"
                aria-label="Request feature"
              >
                <svg
                  width="18"
                  height="18"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="1.75"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                >
                  <path d="M12 2a7 7 0 0 0-7 7c0 2.38 1.19 4.47 3 5.74V17a2 2 0 0 0 2 2h4a2 2 0 0 0 2-2v-2.26c1.81-1.27 3-3.36 3-5.74a7 7 0 0 0-7-7M9 21a1 1 0 0 0 1 1h4a1 1 0 0 0 1-1v-1H9v1Z" />
                </svg>
              </button>
            </Tooltip>

            {/* Updates & Info */}
            <Tooltip content="Notifications & what's new" position="right">
              <button
                type="button"
                onClick={() => window.dispatchEvent(new CustomEvent("open-updates-modal"))}
                className="flex h-9 w-9 items-center justify-center rounded-xl text-zinc-600 transition hover:bg-zinc-200/60 hover:text-zinc-900 active:scale-95 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200 cursor-pointer"
                aria-label="Updates & info"
              >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden>
                  <path
                    d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0"
                    stroke="currentColor"
                    strokeWidth="1.75"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                  />
                </svg>
              </button>
            </Tooltip>
          </div>

          {/* Bottom Icons: Avatar (Sign out) */}
          <div className="flex flex-col items-center gap-2.5 pt-2 border-t border-zinc-200/80 dark:border-zinc-800/80 w-full">
            {/* User Avatar Circle */}
            <Tooltip content={`${displayName} · Click to sign out`} position="right">
              <button
                type="button"
                onClick={logOut}
                className="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-600 font-bold text-xs text-white shadow-2xs hover:opacity-90 active:scale-95 transition cursor-pointer"
                aria-label="Sign out"
              >
                {avatarBadge}
              </button>
            </Tooltip>
          </div>
        </div>
      </aside>
    </>
  );
}
