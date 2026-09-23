"use client";

import React, { useCallback, useEffect, useRef, useState } from "react";
import Image from "next/image";
import { apiFetch, ApiError } from "@/lib/api/client";
import type { AssistantAskResponse } from "@/lib/api/types";
import { APP_LOGO_SRC, APP_DISPLAY_NAME } from "@/lib/branding";
import {
  loadChatHistory,
  saveChatHistory,
  type QuotedMessage,
  type StoredChatEntry,
} from "@/lib/web-chat/storage";
import { useWebChat } from "@/lib/web-chat/web-chat-context";
import { useSidebar } from "@/lib/sidebar/sidebar-context";
import { insertChatCommand } from "@/lib/chat/commands-data";
import { AssistantMessage } from "./assistant-message";
import { ChatComposer } from "./chat-composer";
import { ChatHeader } from "./chat-header";
import { GrokThinkingLoader } from "./grok-thinking-loader";
import { UserMessage } from "./user-message";
import { Tooltip } from "@/components/ui/tooltip";
import { chatSendingLabel } from "@/lib/ui/outbound-status";

function formatTime(date: Date = new Date()): string {
  try {
    const userTz = Intl.DateTimeFormat().resolvedOptions().timeZone;
    return new Intl.DateTimeFormat(undefined, {
      hour: "numeric",
      minute: "2-digit",
      hour12: true,
      timeZone: userTz,
    }).format(date);
  } catch {
    return date.toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" });
  }
}

// Starter prompt cards grounded in UniPods METI AI Programme
const STARTER_PROMPTS = [
  {
    title: "Hackathon deadline",
    prompt: "When is the hackathon deadline and submission requirements?",
    icon: "🏆",
  },
  {
    title: "Programme schedule",
    prompt: "What is the schedule for the UniPods METI AI Programme?",
    icon: "📅",
  },
  {
    title: "Training & sessions",
    prompt: "How will the training in Ethiopia and online courses work?",
    icon: "🎓",
  },
  {
    title: "Resources & handbook",
    prompt: "Where can I find the UniPods handbook and Wadhwani resource pack?",
    icon: "📚",
  },
];

export function ChatView() {
  const { sessionId, memberPhone, community, adminToken, isAdmin } = useWebChat();
  const { toggleSidebar } = useSidebar();
  const [entries, setEntries] = useState<StoredChatEntry[]>([]);
  const [pending, setPending] = useState(false);
  const [pendingStatusLabel, setPendingStatusLabel] = useState("Asking Zak…");
  const [replyingTo, setReplyingTo] = useState<QuotedMessage | null>(null);
  const [showScrollBottomButton, setShowScrollBottomButton] = useState(false);

  const scrollRef = useRef<HTMLDivElement>(null);
  const bottomRef = useRef<HTMLDivElement>(null);
  const loadingRef = useRef<HTMLDivElement>(null);
  const hydratedFor = useRef<string | null>(null);

  // Multi-frame robust scroll-to-bottom
  const scrollToBottom = useCallback((smooth = true) => {
    const performScroll = () => {
      if (bottomRef.current) {
        bottomRef.current.scrollIntoView({
          behavior: smooth ? "smooth" : "auto",
          block: "end",
        });
      } else if (scrollRef.current) {
        scrollRef.current.scrollTop = scrollRef.current.scrollHeight;
      }
    };

    requestAnimationFrame(performScroll);
    setTimeout(performScroll, 40);
    setTimeout(performScroll, 150);
    setTimeout(performScroll, 300);
  }, []);

  const handleNewChat = useCallback(() => {
    setEntries([]);
    if (community && sessionId) {
      saveChatHistory(community.id, sessionId, []);
    }
    scrollToBottom(false);
  }, [community, sessionId, scrollToBottom]);

  // Listen for "new-chat" event dispatched from Sidebar, Header, or hotkeys
  useEffect(() => {
    const onNewChatEvent = () => {
      handleNewChat();
    };
    window.addEventListener("new-chat", onNewChatEvent);
    return () => window.removeEventListener("new-chat", onNewChatEvent);
  }, [handleNewChat]);

  // Keyboard shortcut Ctrl+K to start new chat
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "k") {
        e.preventDefault();
        handleNewChat();
      }
    };
    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [handleNewChat]);

  // Check URL query parameters for ?prompt= or ?q=
  useEffect(() => {
    if (typeof window === "undefined") return;
    const params = new URLSearchParams(window.location.search);
    const initialPrompt = params.get("prompt") || params.get("q");
    if (initialPrompt) {
      insertChatCommand(initialPrompt);
      // Clean query string
      const url = new URL(window.location.href);
      url.searchParams.delete("prompt");
      url.searchParams.delete("q");
      window.history.replaceState({}, "", url.pathname + (url.search ? url.search : ""));
    }
  }, []);

  // Detect scroll position to show/hide "Scroll to bottom" button
  const handleScroll = () => {
    if (!scrollRef.current) return;
    const { scrollTop, scrollHeight, clientHeight } = scrollRef.current;
    const distanceFromBottom = scrollHeight - scrollTop - clientHeight;
    setShowScrollBottomButton(distanceFromBottom > 160);
  };

  // Hydrate chat entries for community & session
  useEffect(() => {
    if (!community || !sessionId) {
      return;
    }
    const key = `${community.id}:${sessionId}`;
    if (hydratedFor.current === key) {
      return;
    }
    hydratedFor.current = key;
    setEntries(loadChatHistory(community.id, sessionId));
  }, [community, sessionId]);

  // Save chat entries
  useEffect(() => {
    if (!community || !sessionId) {
      return;
    }
    if (hydratedFor.current !== `${community.id}:${sessionId}`) {
      return;
    }
    saveChatHistory(community.id, sessionId, entries);
  }, [community, sessionId, entries]);

  const handlePopulateQuery = useCallback(
    (promptText: string) => {
      insertChatCommand(promptText);
      scrollToBottom(true);
    },
    [scrollToBottom]
  );

  const handleReaction = useCallback(
    (entryId: string, reaction: "up" | "down") => {
      setEntries((prev) =>
        prev.map((e) => {
          if (e.id !== entryId || e.role !== "assistant") return e;
          return {
            ...e,
            reaction: e.reaction === reaction ? null : reaction,
          };
        })
      );
    },
    []
  );

  const sendMessage = useCallback(
    async (text: string, quote?: QuotedMessage) => {
      if (!community) return;

      const userEntry: StoredChatEntry = {
        id: crypto.randomUUID(),
        role: "user",
        text,
        sentAt: formatTime(new Date()),
        quote,
      };

      setEntries((prev) => [...prev, userEntry]);
      setPendingStatusLabel(chatSendingLabel(isAdmin, text));
      setPending(true);

      // Auto-scroll immediately when user sends message
      scrollToBottom(true);

      try {
        const queryText = quote?.text
          ? `[Replying to: "${quote.text}"]\n${text}`
          : text;

        const payload = {
          query: queryText,
          phone: memberPhone ?? undefined,
          session_id: sessionId ?? undefined,
        };

        const headers: Record<string, string> = {};
        if (adminToken) {
          headers.Authorization = `Bearer ${adminToken}`;
        }

        const res = await apiFetch<AssistantAskResponse>(
          "/api/v1/web-chat/ask",
          {
            method: "POST",
            headers,
            body: JSON.stringify(payload),
          }
        );

        const assistantEntry: StoredChatEntry = {
          id: crypto.randomUUID(),
          role: "assistant",
          response: res,
          sentAt: formatTime(new Date()),
          quote,
        };

        setEntries((prev) => [...prev, assistantEntry]);
      } catch (err) {
        const message =
          err instanceof ApiError
            ? err.message
            : "Could not reach the assistant. Check your connection or try again in a moment.";
        setEntries((prev) => [
          ...prev,
          {
            id: crypto.randomUUID(),
            role: "error",
            text: message,
            sentAt: formatTime(new Date()),
          },
        ]);
      } finally {
        setPending(false);
        // Scroll once response arrives
        scrollToBottom(true);
      }
    },
    [community, memberPhone, sessionId, adminToken, scrollToBottom, isAdmin]
  );

  return (
    <div className="flex flex-1 flex-col h-full min-w-0 overflow-hidden relative bg-white dark:bg-[#0d0d0d] text-zinc-900 dark:text-zinc-100">
      {/* Top Header */}
      <ChatHeader
        onToggleSidebar={toggleSidebar}
        onInsertQuery={handlePopulateQuery}
      />

      {/* Scrollable Message Feed */}
      <div
        ref={scrollRef}
        onScroll={handleScroll}
        className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 overflow-y-auto no-scrollbar px-4 pt-4 pb-36"
      >
        {/* Welcome Screen / Empty State (ChatGPT Style) */}
        {entries.length === 0 ? (
          <div className="my-auto flex flex-col items-center justify-center py-10 text-center animate-fade-in select-none">
            <div className="flex h-14 w-14 items-center justify-center rounded-2xl overflow-hidden shadow-sm mb-4">
              <Image
                src={APP_LOGO_SRC}
                alt="UniPod Logo"
                width={56}
                height={56}
                className="h-full w-full object-contain rounded-2xl"
              />
            </div>

            <h2 className="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
              What would you like to know?
            </h2>
            <p className="mt-1.5 text-xs text-zinc-500 max-w-md dark:text-zinc-400 leading-relaxed">
              Ask anything about the {community?.name ?? APP_DISPLAY_NAME}, schedules, hackathons, sessions, or resources.
            </p>

            {/* Starter Prompt Cards */}
            <div className="mt-8 grid w-full max-w-lg grid-cols-1 gap-2.5 sm:grid-cols-2 text-left">
              {STARTER_PROMPTS.map((item) => (
                <button
                  key={item.title}
                  type="button"
                  onClick={() => handlePopulateQuery(item.prompt)}
                  className="group flex flex-col justify-between rounded-2xl border border-zinc-200/80 bg-white p-3.5 text-xs text-zinc-700 shadow-2xs hover:border-zinc-300 hover:bg-zinc-50 hover:shadow-xs transition-all cursor-pointer dark:border-zinc-800 dark:bg-[#171717] dark:text-zinc-300 dark:hover:border-zinc-700 dark:hover:bg-zinc-800/80"
                >
                  <div className="flex items-center justify-between">
                    <span className="font-semibold text-zinc-900 group-hover:text-zinc-950 dark:text-zinc-100 dark:group-hover:text-white">
                      {item.title}
                    </span>
                    <span className="text-sm">{item.icon}</span>
                  </div>
                  <span className="mt-1.5 text-zinc-500 line-clamp-2 dark:text-zinc-400 text-[11px] leading-relaxed">
                    {item.prompt}
                  </span>
                </button>
              ))}
            </div>

            {/* Quick Command Hint Chip */}
            <div className="mt-6 flex items-center gap-2 text-xs text-zinc-400 dark:text-zinc-500">
              <span>💡 Click on any card or command to populate it into your composer.</span>
            </div>
          </div>
        ) : null}

        {/* Message Feed */}
        {entries.map((entry) => {
          if (entry.role === "user") {
            return (
              <UserMessage
                key={entry.id}
                id={entry.id}
                text={entry.text}
                sentAt={entry.sentAt}
                quote={entry.quote}
                onQuote={(q) => setReplyingTo(q)}
              />
            );
          }
          if (entry.role === "assistant") {
            return (
              <AssistantMessage
                key={entry.id}
                id={entry.id}
                response={entry.response}
                sentAt={entry.sentAt}
                reaction={entry.reaction}
                quote={entry.quote}
                onQuote={(q) => setReplyingTo(q)}
                onReact={handleReaction}
                onSuggestionClick={handlePopulateQuery}
              />
            );
          }
          return (
            <div
              key={entry.id}
              className="self-center my-2 rounded-xl border border-rose-200 bg-rose-50/90 px-4 py-2.5 text-xs text-rose-800 shadow-xs max-w-md text-center dark:border-rose-900/60 dark:bg-rose-950/40 dark:text-rose-300"
            >
              {entry.text}
            </div>
          );
        })}

        {/* Thinking / Retrieving Loader */}
        {pending && (
          <div ref={loadingRef} className="my-2">
            <GrokThinkingLoader statusText={pendingStatusLabel} />
          </div>
        )}

        {/* Bottom Sentinel */}
        <div ref={bottomRef} className="h-6 w-full shrink-0" aria-hidden="true" />
      </div>

      {/* Floating "Scroll to Bottom" Button (ChatGPT Style) */}
      {showScrollBottomButton && (
        <div className="absolute bottom-24 right-6 z-20 animate-fade-in">
          <Tooltip content="Scroll to bottom" position="left">
            <button
              type="button"
              onClick={() => scrollToBottom(true)}
              className="flex h-9 w-9 items-center justify-center rounded-full border border-zinc-300 bg-white text-zinc-700 shadow-md transition hover:bg-zinc-50 hover:text-zinc-950 active:scale-95 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700 cursor-pointer"
              aria-label="Scroll to latest message"
            >
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                <line x1="12" y1="5" x2="12" y2="19" />
                <polyline points="19 12 12 19 5 12" />
              </svg>
            </button>
          </Tooltip>
        </div>
      )}

      {/* Docked Composer - ChatGPT Style Floating Container */}
      <div className="absolute inset-x-0 bottom-0 z-30 pb-[env(safe-area-inset-bottom)]">
        <ChatComposer
          disabled={pending || !community}
          sending={pending}
          sendingLabel={pendingStatusLabel}
          isAdmin={isAdmin}
          onSend={(text, quote) => void sendMessage(text, quote)}
          quotedMessage={replyingTo}
          onClearQuote={() => setReplyingTo(null)}
          onFocus={() => scrollToBottom(true)}
          onTyping={() => scrollToBottom(true)}
        />
      </div>
    </div>
  );
}
