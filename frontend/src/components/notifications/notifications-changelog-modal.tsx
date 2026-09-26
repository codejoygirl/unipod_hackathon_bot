"use client";

import React, { useState, useEffect, useLayoutEffect, useRef, useCallback } from "react";
import { createPortal } from "react-dom";
import { Tooltip } from "@/components/ui/tooltip";
import { apiFetch } from "@/lib/api/client";
import type { CommunityNotificationItem, CommunityNotificationsResponse } from "@/lib/api/types";
import { syncAppBadge } from "@/lib/pwa/app-badge";
import { CHANGELOG_ENTRIES, LATEST_CHANGELOG, type ChangelogEntry } from "@/lib/changelog";
import { useWebChat } from "@/lib/web-chat/web-chat-context";

export type CommunityNotification = CommunityNotificationItem;
export type { ChangelogEntry };

interface NotificationsChangelogModalProps {
  onInsertQuery?: (query: string) => void;
}

export function openNotificationsModal(tab: "notifications" | "changelog" = "notifications") {
  if (typeof window !== "undefined") {
    window.dispatchEvent(new CustomEvent("open-notifications", { detail: { tab } }));
  }
}

export function NotificationsChangelogModal({ onInsertQuery }: NotificationsChangelogModalProps) {
  const { memberPhone, adminToken } = useWebChat();
  const [isOpen, setIsOpen] = useState(false);
  const [isClosing, setIsClosing] = useState(false);
  const [activeTab, setActiveTab] = useState<"notifications" | "changelog">("notifications");
  const popoverRef = useRef<HTMLDivElement>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const [portalReady, setPortalReady] = useState(false);
  const [panelStyle, setPanelStyle] = useState<React.CSSProperties>({});
  const [showMobileOverlay, setShowMobileOverlay] = useState(false);
  const [notifications, setNotifications] = useState<CommunityNotification[]>([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [loadingNotifications, setLoadingNotifications] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);

  useEffect(() => {
    setPortalReady(true);
  }, []);

  const applyNotificationPayload = useCallback((payload: CommunityNotificationsResponse["data"]) => {
    setNotifications(payload.notifications);
    setUnreadCount(payload.unread_count);
    void syncAppBadge(payload.unread_count);
  }, []);

  const fetchNotifications = useCallback(async () => {
    if (!memberPhone) {
      setNotifications([]);
      setUnreadCount(0);
      void syncAppBadge(0);
      return;
    }

    setLoadingNotifications(true);
    setLoadError(null);
    try {
      const qs = new URLSearchParams({ phone: memberPhone });
      const headers: Record<string, string> = {};
      if (adminToken) {
        headers.Authorization = `Bearer ${adminToken}`;
        headers["X-Admin-Token"] = adminToken;
      }
      const res = await apiFetch<CommunityNotificationsResponse>(
        `/api/v1/web-chat/notifications?${qs.toString()}`,
        { headers },
      );
      applyNotificationPayload(res.data);
    } catch {
      setLoadError("Could not load updates right now.");
    } finally {
      setLoadingNotifications(false);
    }
  }, [memberPhone, adminToken, applyNotificationPayload]);

  // Load on mount / phone change, and when panel opens; light poll while open.
  useEffect(() => {
    void fetchNotifications();
  }, [fetchNotifications]);

  useEffect(() => {
    if (!isOpen || activeTab !== "notifications") {
      return;
    }
    void fetchNotifications();
    const id = window.setInterval(() => {
      void fetchNotifications();
    }, 60_000);
    return () => window.clearInterval(id);
  }, [isOpen, activeTab, fetchNotifications]);

  useEffect(() => {
    const onFocus = () => {
      void fetchNotifications();
    };
    window.addEventListener("focus", onFocus);
    return () => window.removeEventListener("focus", onFocus);
  }, [fetchNotifications]);

  useLayoutEffect(() => {
    if (!isOpen || !buttonRef.current) {
      return;
    }

    const updatePosition = () => {
      const trigger = buttonRef.current?.getBoundingClientRect();
      if (!trigger) {
        return;
      }

      const isMobile = window.matchMedia("(max-width: 639px)").matches;
      setShowMobileOverlay(isMobile);

      if (isMobile) {
        setPanelStyle({});
        return;
      }

      const width = Math.min(410, window.innerWidth - 24);
      let right = window.innerWidth - trigger.right;
      if (window.innerWidth - right - width < 12) {
        right = Math.max(12, window.innerWidth - width - 12);
      }

      setPanelStyle({
        position: "fixed",
        top: trigger.bottom + 8,
        right,
        width,
        maxHeight: "min(480px, 72vh)",
      });
    };

    updatePosition();
    window.addEventListener("resize", updatePosition);
    window.addEventListener("scroll", updatePosition, true);

    return () => {
      window.removeEventListener("resize", updatePosition);
      window.removeEventListener("scroll", updatePosition, true);
    };
  }, [isOpen, activeTab]);

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

  const markAllAsRead = async () => {
    if (!memberPhone) return;
    try {
      const qs = new URLSearchParams({ phone: memberPhone });
      const res = await apiFetch<CommunityNotificationsResponse>(
        `/api/v1/web-chat/notifications/read-all?${qs.toString()}`,
        { method: "POST" },
      );
      applyNotificationPayload(res.data);
    } catch {
      setLoadError("Could not mark updates as read.");
    }
  };

  const markAsRead = async (id: string) => {
    if (!memberPhone) return;
    try {
      const qs = new URLSearchParams({ phone: memberPhone });
      const res = await apiFetch<CommunityNotificationsResponse>(
        `/api/v1/web-chat/notifications/${id}/read?${qs.toString()}`,
        { method: "POST" },
      );
      applyNotificationPayload(res.data);
    } catch {
      // keep list; user can still ask Zak
    }
  };

  const handleActionClick = (query?: string | null) => {
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
    <div className="relative flex h-8 w-8 items-center justify-center">
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
              setShowMobileOverlay(
                typeof window !== "undefined" && window.matchMedia("(max-width: 639px)").matches,
              );
              setIsOpen(true);
            }
          }}
          className={`relative flex h-8 w-8 items-center justify-center rounded-lg transition active:scale-95 cursor-pointer ${
            isOpen
              ? "bg-zinc-200/80 text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100"
              : "text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
          }`}
          aria-label="View notifications and what's new"
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

      {isOpen &&
        portalReady &&
        createPortal(
          <>
            {showMobileOverlay && (
              <div
                className={`fixed inset-0 z-[55] bg-black/40 backdrop-blur-[2px] transition-opacity duration-200 ease-out ${
                  isClosing ? "opacity-0" : "opacity-100"
                }`}
                onClick={handleClose}
                aria-hidden="true"
              />
            )}

            <div
              ref={popoverRef}
              role="dialog"
              aria-modal="true"
              aria-labelledby="updates-panel-title"
              style={showMobileOverlay ? undefined : panelStyle}
              className={`z-[60] box-border flex max-w-[100vw] flex-col overflow-hidden border border-zinc-200/90 bg-white shadow-2xl select-none dark:border-zinc-800 dark:bg-[#181818] ${
                showMobileOverlay
                  ? `fixed inset-x-0 bottom-0 w-full max-h-[min(88dvh,100dvh)] rounded-t-2xl rounded-b-none border-x-0 border-b-0 pb-[env(safe-area-inset-bottom,0px)] pl-[max(0px,env(safe-area-inset-left))] pr-[max(0px,env(safe-area-inset-right))] ${
                      isClosing ? "animate-mobile-sheet-out" : "animate-mobile-sheet-in"
                    }`
                  : `fixed rounded-2xl ${isClosing ? "animate-popover-out" : "animate-popover-in"}`
              }`}
            >
            {/* Popover Header */}
            <div className="flex items-center justify-between border-b border-zinc-100 px-4 py-3 max-sm:px-4 dark:border-zinc-800/80">
              <div className="flex items-center gap-2">
                <div className="flex h-6 w-6 items-center justify-center rounded-md bg-blue-500/10 text-blue-600 dark:text-blue-400">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2">
                    <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
                    <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
                  </svg>
                </div>
                <h3
                  id="updates-panel-title"
                  className="text-sm font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                >
                  Updates &amp; community
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
            <div className="flex flex-col gap-2 border-b border-zinc-100 px-4 py-2 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800/80">
              <div className="flex min-w-0 flex-wrap items-center gap-1.5">
                <button
                  type="button"
                  onClick={() => setActiveTab("notifications")}
                  className={`flex max-w-full items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-semibold transition cursor-pointer ${
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
                  className={`flex max-w-full items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-semibold transition cursor-pointer ${
                    activeTab === "changelog"
                      ? "bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900"
                      : "text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800"
                  }`}
                >
                  <span>What&apos;s new</span>
                  {LATEST_CHANGELOG.isLatest && (
                    <span className="shrink-0 whitespace-nowrap rounded-full bg-blue-100 px-1.5 py-0.5 text-[9px] font-semibold text-blue-800 dark:bg-blue-950 dark:text-blue-300">
                      Latest
                    </span>
                  )}
                </button>
              </div>

              {activeTab === "notifications" && unreadCount > 0 && (
                <button
                  type="button"
                  onClick={() => void markAllAsRead()}
                  className="self-start text-[11px] font-medium text-blue-600 hover:underline sm:self-auto dark:text-blue-400 cursor-pointer"
                >
                  Mark all as read
                </button>
              )}
            </div>

            {/* Popover Body */}
            <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain no-scrollbar px-4 py-3.5 sm:max-h-[380px]">
              {activeTab === "notifications" ? (
                <div className="space-y-2.5">
                  {loadingNotifications && notifications.length === 0 ? (
                    <p className="py-6 text-center text-xs text-zinc-500 dark:text-zinc-400">
                      Loading updates…
                    </p>
                  ) : null}
                  {loadError && notifications.length === 0 ? (
                    <div className="rounded-xl border border-rose-200 bg-rose-50/80 px-3 py-4 text-center dark:border-rose-900/50 dark:bg-rose-950/30">
                      <p className="text-xs text-rose-700 dark:text-rose-300">{loadError}</p>
                      <button
                        type="button"
                        onClick={() => void fetchNotifications()}
                        className="mt-2 text-[11px] font-medium text-blue-700 underline dark:text-blue-400 cursor-pointer"
                      >
                        Try again
                      </button>
                    </div>
                  ) : null}
                  {!loadingNotifications && !loadError && notifications.length === 0 ? (
                    <p className="py-6 text-center text-xs text-zinc-500 dark:text-zinc-400">
                      No community updates yet.
                    </p>
                  ) : null}
                  {notifications.map((item) => (
                    <div
                      key={item.id}
                      onClick={() => void markAsRead(item.id)}
                      className={`group relative rounded-xl border p-3 transition-all cursor-pointer ${
                        item.read
                          ? "border-zinc-200/80 bg-zinc-50/50 hover:bg-zinc-50 dark:border-zinc-800/80 dark:bg-zinc-900/30 dark:hover:bg-zinc-900/50"
                          : "border-blue-200/90 bg-blue-50/30 hover:bg-blue-50/50 shadow-2xs dark:border-blue-800/50 dark:bg-blue-950/20"
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
                                ? "bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300"
                                : "bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                            }`}
                          >
                            {item.category}
                          </span>
                          {!item.read && (
                            <span className="h-1.5 w-1.5 rounded-full bg-blue-500" title="Unread" />
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

                      {item.action_query && (
                        <div className="mt-2.5 flex items-center gap-2">
                          <button
                            type="button"
                            onClick={(e) => {
                              e.stopPropagation();
                              void markAsRead(item.id);
                              handleActionClick(item.action_query);
                            }}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 bg-white px-2 py-1 text-[11px] font-medium text-zinc-800 shadow-2xs hover:bg-zinc-50 active:scale-95 transition dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700 cursor-pointer"
                          >
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                              <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                            </svg>
                            <span>Ask Zak about this</span>
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
                      Recent improvements
                    </p>
                    <p className="mt-0.5 leading-relaxed">
                      New features and fixes in UniPod Assistant—projects, your library, chat, meetings, and programme answers.
                    </p>
                  </div>

                  {CHANGELOG_ENTRIES.map((entry) => (
                    <div
                      key={entry.version}
                      className="border-l-2 border-zinc-200 pl-3 relative dark:border-zinc-800"
                    >
                      <div className="absolute -left-[5px] top-1 h-2 w-2 rounded-full border border-white bg-blue-500 dark:border-zinc-900" />
                      
                      <div className="flex items-center gap-1.5">
                        <span className="font-mono text-xs font-bold text-zinc-900 dark:text-zinc-100">
                          {entry.version}
                        </span>
                        {entry.isLatest && (
                          <span className="shrink-0 whitespace-nowrap rounded-full bg-blue-100 px-1.5 py-0.5 text-[9px] font-bold text-blue-800 dark:bg-blue-950 dark:text-blue-300">
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
                                  ? "bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300"
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
            <div className="flex shrink-0 items-center justify-between gap-2 border-t border-zinc-100 bg-zinc-50/60 px-4 py-2.5 dark:border-zinc-800/80 dark:bg-zinc-900/40 text-[11px] text-zinc-500 dark:text-zinc-400">
              <span className="min-w-0 truncate">UniPod community assistant</span>
              <button
                type="button"
                onClick={handleClose}
                className="font-medium text-zinc-700 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-zinc-100 cursor-pointer"
              >
                Close
              </button>
            </div>
            </div>
          </>,
          document.body,
        )}
    </div>
  );
}
