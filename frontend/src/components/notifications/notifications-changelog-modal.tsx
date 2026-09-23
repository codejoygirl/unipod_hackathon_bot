"use client";

import React, { useState, useEffect, useRef, useCallback } from "react";
import { Tooltip } from "@/components/ui/tooltip";

export interface CommunityNotification {
  id: string;
  title: string;
  message: string;
  timestamp: string;
  category: "Announcement" | "Live Session" | "Deadline" | "System";
  read: boolean;
  actionQuery?: string;
}

export interface ChangelogEntry {
  version: string;
  date: string;
  isLatest?: boolean;
  title: string;
  changes: {
    tag: "Feature" | "Improvement" | "Fix" | "UI";
    description: string;
  }[];
}

const INITIAL_NOTIFICATIONS: CommunityNotification[] = [
  {
    id: "notif-0",
    title: "New Meetings Hub & Live Sessions Active",
    message: "Browse weekly cohort syncs, mentor office hours, and open sessions with direct Teams, Zoom & Meet links.",
    timestamp: "Just now",
    category: "Live Session",
    read: false,
    actionQuery: "What meetings and live sessions are scheduled for this week?",
  },
  {
    id: "notif-1",
    title: "UniPods METI AI Hackathon Kickoff",
    message: "Initial project registration and team roster submission closes this Friday. Review the criteria in the handbook.",
    timestamp: "2 hours ago",
    category: "Deadline",
    read: false,
    actionQuery: "When is the hackathon deadline and submission requirements?",
  },
  {
    id: "notif-2",
    title: "Live Mentorship Q&A on Microsoft Teams",
    message: "Technical mentors will host a live office hour tomorrow at 4:00 PM to review project architectures.",
    timestamp: "Yesterday",
    category: "Live Session",
    read: false,
    actionQuery: "When is the next live session and how do I join?",
  },
  {
    id: "notif-3",
    title: "Wadhwani Resource Pack Updated",
    message: "New reference materials on generative models and training notebooks have been added to the knowledge hub.",
    timestamp: "3 days ago",
    category: "Announcement",
    read: true,
    actionQuery: "Where can I find the UniPods handbook and Wadhwani resource pack?",
  },
  {
    id: "notif-4",
    title: "Community Share Approved",
    message: "Your submitted tip regarding prompt optimization has been approved and published to member drafts.",
    timestamp: "5 days ago",
    category: "System",
    read: true,
  },
];

const CHANGELOG_ENTRIES: ChangelogEntry[] = [
  {
    version: "v1.4.0",
    date: "September 2026",
    isLatest: true,
    title: "Live Meetings Hub & Resilient Assistant Experience",
    changes: [
      {
        tag: "Feature",
        description: "Live Meetings & Sessions Hub: Direct access to cohort syncs, mentor office hours, and masterclasses across Microsoft Teams, Google Meet, and Zoom.",
      },
      {
        tag: "Feature",
        description: "In-App Feature Suggestions: Submit tool improvements with instant escalation to community coordinators via Telegram & WhatsApp bots.",
      },
      {
        tag: "UI",
        description: "Smooth Modal & Dropdown Animations: Fluid ease-out transitions on opening and closing all dialogs, popovers, and drawers.",
      },
      {
        tag: "Improvement",
        description: "Sanitized Error Shield: Replaced technical backend exception details with clean, reassuring, user-friendly guidance.",
      },
      {
        tag: "UI",
        description: "Compact Modern Controls: Unified input and button heights, edge-aligned header actions, and visible Install App header pill.",
      },
      {
        tag: "Improvement",
        description: "Animated Skeleton Loading: Instant visual feedback and shimmer placeholders during session discovery and resource lookups.",
      },
    ],
  },
  {
    version: "v1.3.0",
    date: "September 2026",
    isLatest: false,
    title: "ChatGPT Experience & Interactive Command Palette",
    changes: [
      {
        tag: "UI",
        description: "Full ChatGPT aesthetic overhaul with dark/light themes, collapsible sidebar, and conversation history.",
      },
      {
        tag: "Feature",
        description: "Clickable Quick Commands & Slash Menu: Auto-populate /ask, /share, /feature, /help directly into your composer.",
      },
      {
        tag: "UI",
        description: "Refined notification dropdown anchored directly to header with zero scrollbar clutter.",
      },
      {
        tag: "Improvement",
        description: "Guaranteed Smart Auto-Scroll: Sticks to bottom during live bot typing, streaming, and input focus.",
      },
      {
        tag: "Feature",
        description: "Unified Notification & Changelog Dropdown: In-app release notes and real-time community announcements.",
      },
    ],
  },
  {
    version: "v1.2.0",
    date: "August 2026",
    title: "Voice Dictation & Standalone PWA",
    changes: [
      {
        tag: "Feature",
        description: "Hands-free voice recognition with live audio visualizer and speech-to-text querying.",
      },
      {
        tag: "Improvement",
        description: "Progressive Web App support: Install as standalone mobile or desktop app with offline capability.",
      },
      {
        tag: "Feature",
        description: "Rich interactive meeting cards with 1-click launch for Microsoft Teams, Zoom, and Google Meet.",
      },
    ],
  },
  {
    version: "v1.1.0",
    date: "July 2026",
    title: "Knowledge Grounding & Admin Controls",
    changes: [
      {
        tag: "Feature",
        description: "Direct Drive asset registration via /asset and WhatsApp/Telegram export ingestion via /import.",
      },
      {
        tag: "Improvement",
        description: "Dual-layer permission validation ensuring community isolation.",
      },
      {
        tag: "Fix",
        description: "Resolved citation revalidation timeouts on complex technical queries.",
      },
    ],
  },
];

const STORAGE_KEY_NOTIFS = "unipod_notifications_state_v1";

interface NotificationsChangelogModalProps {
  onInsertQuery?: (query: string) => void;
}

export function openNotificationsModal(tab: "notifications" | "changelog" = "notifications") {
  if (typeof window !== "undefined") {
    window.dispatchEvent(new CustomEvent("open-notifications", { detail: { tab } }));
  }
}

export function NotificationsChangelogModal({ onInsertQuery }: NotificationsChangelogModalProps) {
  const [isOpen, setIsOpen] = useState(false);
  const [isClosing, setIsClosing] = useState(false);
  const [activeTab, setActiveTab] = useState<"notifications" | "changelog">("notifications");
  const popoverRef = useRef<HTMLDivElement>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);

  const handleClose = () => {
    setIsClosing(true);
    setTimeout(() => {
      setIsOpen(false);
      setIsClosing(false);
    }, 180);
  };

  const openPanel = useCallback((tab: "notifications" | "changelog" = "notifications") => {
    setIsClosing(false);
    setIsOpen(true);
    setActiveTab(tab);
  }, []);

  // Listen for global open events (sidebar, composer, deep links)
  useEffect(() => {
    const handleOpen = (e: Event) => {
      const customEvent = e as CustomEvent<{ tab?: "notifications" | "changelog" }>;
      openPanel(customEvent.detail?.tab ?? "notifications");
    };
    const handleUpdates = () => openPanel("changelog");

    window.addEventListener("open-notifications", handleOpen);
    window.addEventListener("open-updates-modal", handleUpdates);
    return () => {
      window.removeEventListener("open-notifications", handleOpen);
      window.removeEventListener("open-updates-modal", handleUpdates);
    };
  }, [openPanel]);

  // Deep link: ?changelog=1 | ?updates=1 | ?notifications=1
  useEffect(() => {
    if (typeof window === "undefined") return;
    const params = new URLSearchParams(window.location.search);
    let tab: "notifications" | "changelog" | null = null;
    if (params.get("changelog") === "1" || params.get("updates") === "1") {
      tab = "changelog";
    } else if (params.get("notifications") === "1") {
      tab = "notifications";
    }
    if (!tab) return;

    const t = window.setTimeout(() => openPanel(tab), 400);

    params.delete("changelog");
    params.delete("updates");
    params.delete("notifications");
    const qs = params.toString();
    const next = `${window.location.pathname}${qs ? `?${qs}` : ""}${window.location.hash}`;
    window.history.replaceState(null, "", next);

    return () => window.clearTimeout(t);
  }, [openPanel]);

  const [notifications, setNotifications] = useState<CommunityNotification[]>(() => {
    if (typeof window === "undefined") return INITIAL_NOTIFICATIONS;
    try {
      const stored = localStorage.getItem(STORAGE_KEY_NOTIFS);
      if (stored) {
        return JSON.parse(stored);
      }
    } catch {
      // ignore
    }
    return INITIAL_NOTIFICATIONS;
  });

  const saveNotifications = (updated: CommunityNotification[]) => {
    setNotifications(updated);
    try {
      localStorage.setItem(STORAGE_KEY_NOTIFS, JSON.stringify(updated));
    } catch {
      // ignore
    }
  };

  const unreadCount = notifications.filter((n) => !n.read).length;

  const markAllAsRead = () => {
    const updated = notifications.map((n) => ({ ...n, read: true }));
    saveNotifications(updated);
  };

  const markAsRead = (id: string) => {
    const updated = notifications.map((n) => (n.id === id ? { ...n, read: true } : n));
    saveNotifications(updated);
  };

  const handleActionClick = (query?: string) => {
    if (query && onInsertQuery) {
      onInsertQuery(query);
      handleClose();
    }
  };

  // Close when clicking outside
  useEffect(() => {
    if (!isOpen) return;
    const handleClickOutside = (e: MouseEvent) => {
      const target = e.target as Node;
      if (
        popoverRef.current &&
        !popoverRef.current.contains(target) &&
        buttonRef.current &&
        !buttonRef.current.contains(target)
      ) {
        handleClose();
      }
    };
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, [isOpen]);

  // Close on Escape key
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape" && isOpen) {
        handleClose();
      }
    };
    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [isOpen]);

  return (
    <div className="relative">
      {/* Bell Trigger Button */}
      <Tooltip content="Notifications & Updates" position="bottom">
        <button
          ref={buttonRef}
          type="button"
          onClick={() => {
            if (isOpen) {
              handleClose();
            } else {
              setIsClosing(false);
              setIsOpen(true);
            }
          }}
          className={`relative flex h-8 w-8 items-center justify-center rounded-lg transition active:scale-95 cursor-pointer ${
            isOpen
              ? "bg-zinc-200/80 text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100"
              : "text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
          }`}
          aria-label="View notifications and product changelog"
          aria-expanded={isOpen}
        >
          <svg
            width="17"
            height="17"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
            <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
          </svg>

          {/* Unread Indicator Badge */}
          {unreadCount > 0 && (
            <span className="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white shadow-xs">
              {unreadCount}
              <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-rose-400 opacity-60" />
            </span>
          )}
        </button>
      </Tooltip>

      {/* Downward Anchored Dropdown Popover (Standard UI/UX, No Scrollbar Clutter) */}
      {isOpen && (
        <>
          {/* Subtle Mobile Overlay only */}
          <div
            className={`fixed inset-0 z-40 bg-black/30 backdrop-blur-2xs transition-opacity duration-200 ease-out sm:hidden ${
              isClosing ? "opacity-0" : "opacity-100"
            }`}
            onClick={handleClose}
            aria-hidden="true"
          />

          <div
            ref={popoverRef}
            className={`absolute right-0 top-full mt-2 z-50 w-[360px] sm:w-[410px] max-w-[calc(100vw-1.5rem)] rounded-2xl border border-zinc-200/90 bg-white shadow-2xl dark:border-zinc-800 dark:bg-[#181818] overflow-hidden select-none ${
              isClosing ? "animate-popover-out" : "animate-popover-in"
            }`}
          >
            {/* Popover Header */}
            <div className="flex items-center justify-between border-b border-zinc-100 px-4 py-3 dark:border-zinc-800/80">
              <div className="flex items-center gap-2">
                <div className="flex h-6 w-6 items-center justify-center rounded-md bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2">
                    <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
                    <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
                  </svg>
                </div>
                <h3 className="text-sm font-semibold tracking-tight text-zinc-900 dark:text-zinc-100">
                  Updates & Community
                </h3>
              </div>

              {/* Close Button */}
              <button
                type="button"
                onClick={handleClose}
                className="rounded-md p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200 cursor-pointer"
                aria-label="Close"
              >
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <line x1="18" y1="6" x2="6" y2="18" />
                  <line x1="6" y1="6" x2="18" y2="18" />
                </svg>
              </button>
            </div>

            {/* Navigation Tabs */}
            <div className="flex items-center justify-between border-b border-zinc-100 px-3.5 pt-2 pb-1.5 dark:border-zinc-800/80">
              <div className="flex items-center gap-1.5">
                <button
                  type="button"
                  onClick={() => setActiveTab("notifications")}
                  className={`flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-semibold transition cursor-pointer ${
                    activeTab === "notifications"
                      ? "bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900"
                      : "text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800"
                  }`}
                >
                  <span>Notifications</span>
                  {unreadCount > 0 && (
                    <span
                      className={`rounded-full px-1.5 py-0.5 text-[10px] font-bold ${
                        activeTab === "notifications"
                          ? "bg-rose-500 text-white"
                          : "bg-rose-100 text-rose-700 dark:bg-rose-900/60 dark:text-rose-300"
                      }`}
                    >
                      {unreadCount}
                    </span>
                  )}
                </button>

                <button
                  type="button"
                  onClick={() => setActiveTab("changelog")}
                  className={`flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-semibold transition cursor-pointer ${
                    activeTab === "changelog"
                      ? "bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900"
                      : "text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800"
                  }`}
                >
                  <span>Changelog</span>
                  <span className="shrink-0 whitespace-nowrap rounded-full bg-emerald-100 px-1.5 py-0.5 text-[9px] font-semibold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                    v1.3.0
                  </span>
                </button>
              </div>

              {activeTab === "notifications" && unreadCount > 0 && (
                <button
                  type="button"
                  onClick={markAllAsRead}
                  className="text-[11px] font-medium text-emerald-600 hover:underline dark:text-emerald-400 cursor-pointer"
                >
                  Mark all read
                </button>
              )}
            </div>

            {/* Popover Body - Clean and NO clunky scrollbar */}
            <div className="max-h-[380px] overflow-y-auto no-scrollbar p-3.5">
              {activeTab === "notifications" ? (
                <div className="space-y-2.5">
                  {notifications.map((item) => (
                    <div
                      key={item.id}
                      onClick={() => markAsRead(item.id)}
                      className={`group relative rounded-xl border p-3 transition-all cursor-pointer ${
                        item.read
                          ? "border-zinc-200/80 bg-zinc-50/50 hover:bg-zinc-50 dark:border-zinc-800/80 dark:bg-zinc-900/30 dark:hover:bg-zinc-900/50"
                          : "border-emerald-200/90 bg-emerald-50/30 hover:bg-emerald-50/50 shadow-2xs dark:border-emerald-800/50 dark:bg-emerald-950/20"
                      }`}
                    >
                      <div className="flex items-start justify-between gap-2">
                        <div className="flex items-center gap-1.5">
                          <span
                            className={`rounded px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wider ${
                              item.category === "Deadline"
                                ? "bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300"
                                : item.category === "Live Session"
                                ? "bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300"
                                : item.category === "Announcement"
                                ? "bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300"
                                : "bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                            }`}
                          >
                            {item.category}
                          </span>
                          {!item.read && (
                            <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" title="Unread" />
                          )}
                        </div>
                        <time className="text-[10px] text-zinc-400">{item.timestamp}</time>
                      </div>

                      <h4 className="mt-1.5 text-xs font-semibold text-zinc-900 dark:text-zinc-100">
                        {item.title}
                      </h4>
                      <p className="mt-1 text-[11px] leading-relaxed text-zinc-600 dark:text-zinc-300">
                        {item.message}
                      </p>

                      {item.actionQuery && (
                        <div className="mt-2.5 flex items-center gap-2">
                          <button
                            type="button"
                            onClick={(e) => {
                              e.stopPropagation();
                              handleActionClick(item.actionQuery);
                            }}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 bg-white px-2 py-1 text-[11px] font-medium text-zinc-800 shadow-2xs hover:bg-zinc-50 active:scale-95 transition dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700 cursor-pointer"
                          >
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                              <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                            </svg>
                            <span>Ask Assistant about this</span>
                          </button>
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              ) : (
                /* Changelog Tab */
                <div className="space-y-4">
                  <div className="rounded-xl border border-zinc-200 bg-zinc-50/80 p-2.5 text-[11px] text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-400">
                    <p className="font-semibold text-zinc-900 dark:text-zinc-200">
                      Platform Releases & Updates
                    </p>
                    <p className="mt-0.5 leading-relaxed">
                      Continuous updates, quick commands, and knowledge improvements deployed directly to web chat.
                    </p>
                  </div>

                  {CHANGELOG_ENTRIES.map((entry) => (
                    <div
                      key={entry.version}
                      className="border-l-2 border-zinc-200 pl-3 relative dark:border-zinc-800"
                    >
                      <div className="absolute -left-[5px] top-1 h-2 w-2 rounded-full border border-white bg-emerald-500 dark:border-zinc-900" />
                      
                      <div className="flex items-center gap-1.5">
                        <span className="font-mono text-xs font-bold text-zinc-900 dark:text-zinc-100">
                          {entry.version}
                        </span>
                        {entry.isLatest && (
                          <span className="shrink-0 whitespace-nowrap rounded-full bg-emerald-100 px-1.5 py-0.5 text-[9px] font-bold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                            Latest
                          </span>
                        )}
                        <span className="text-[10px] text-zinc-400">· {entry.date}</span>
                      </div>

                      <h4 className="mt-0.5 text-xs font-semibold text-zinc-800 dark:text-zinc-200">
                        {entry.title}
                      </h4>

                      <div className="mt-2 space-y-1.5">
                        {entry.changes.map((change, idx) => (
                          <div key={idx} className="flex items-start gap-1.5 text-[11px]">
                            <span
                              className={`mt-0.5 rounded px-1.5 py-0.5 font-mono text-[8px] font-bold uppercase tracking-wider shrink-0 whitespace-nowrap ${
                                change.tag === "Feature"
                                  ? "bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"
                                  : change.tag === "UI"
                                  ? "bg-purple-100 text-purple-800 dark:bg-purple-950 dark:text-purple-300"
                                  : change.tag === "Improvement"
                                  ? "bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300"
                                  : "bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300"
                              }`}
                            >
                              {change.tag}
                            </span>
                            <span className="text-zinc-600 dark:text-zinc-300 leading-relaxed">
                              {change.description}
                            </span>
                          </div>
                        ))}
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>

            {/* Popover Footer */}
            <div className="flex items-center justify-between border-t border-zinc-100 bg-zinc-50/60 px-4 py-2.5 dark:border-zinc-800/80 dark:bg-zinc-900/40 text-[11px] text-zinc-500 dark:text-zinc-400">
              <span>UniPods METI AI Assistant</span>
              <button
                type="button"
                onClick={handleClose}
                className="font-medium text-zinc-700 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-zinc-100 cursor-pointer"
              >
                Close
              </button>
            </div>
          </div>
        </>
      )}
    </div>
  );
}
