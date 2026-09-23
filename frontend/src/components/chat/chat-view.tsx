"use client";

import { apiFetch, ApiError } from "@/lib/api/client";
import type { AssistantAskResponse } from "@/lib/api/types";
import {
  loadChatHistory,
  saveChatHistory,
  type QuotedMessage,
  type StoredChatEntry,
} from "@/lib/web-chat/storage";
import { useWebChat } from "@/lib/web-chat/web-chat-context";
import { useCallback, useEffect, useRef, useState } from "react";
import { AssistantMessage } from "./assistant-message";
import { ChatComposer } from "./chat-composer";
import { ChatHeader } from "./chat-header";
import { GrokThinkingLoader } from "./grok-thinking-loader";
import { UserMessage } from "./user-message";

// Formats timestamp in user's browser local timezone (e.g. 5:39 AM)
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

// Grounded in the UniPods METI AI Program & community knowledge base
const STARTER_PROMPTS = [
  "What is the schedule for the UniPods METI AI Programme?",
  "How will the training in Ethiopia and online courses work?",
  "Where can I find the Wadhwani resource pack and documents?",
  "Who are the programme coordinators from METI and UNDP?",
];

export function ChatView() {
  const { sessionId, memberPhone, community, adminToken } = useWebChat();
  const [entries, setEntries] = useState<StoredChatEntry[]>([]);
  const [pending, setPending] = useState(false);
  const [replyingTo, setReplyingTo] = useState<QuotedMessage | null>(null);
  const scrollRef = useRef<HTMLDivElement>(null);
  const bottomRef = useRef<HTMLDivElement>(null);
  const loadingRef = useRef<HTMLDivElement>(null);
  const hydratedFor = useRef<string | null>(null);

  // Reliable scroll-to-bottom that handles layout shifts and loader appearance
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
    setTimeout(performScroll, 50);
    setTimeout(performScroll, 160);
  }, []);

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

  useEffect(() => {
    if (!community || !sessionId) {
      return;
    }
    saveChatHistory(community.id, sessionId, entries);
  }, [community, sessionId, entries]);

  // Scroll to latest message or loading state whenever feed or pending changes
  useEffect(() => {
    scrollToBottom();
  }, [entries, pending, scrollToBottom]);

  // When AI starts thinking, ensure the loading indicator is smoothly scrolled into view
  useEffect(() => {
    if (pending) {
      const scrollDown = () => {
        if (loadingRef.current) {
          loadingRef.current.scrollIntoView({ behavior: "smooth", block: "end" });
        } else {
          scrollToBottom(true);
        }
      };
      requestAnimationFrame(scrollDown);
      setTimeout(scrollDown, 50);
      setTimeout(scrollDown, 160);
    }
  }, [pending, scrollToBottom]);

  const sendMessage = useCallback(
    async (text: string, quote?: QuotedMessage) => {
      if (!community || !sessionId) {
        return;
      }

      const now = new Date();
      const userId = crypto.randomUUID();
      setEntries((prev) => [
        ...prev,
        { id: userId, role: "user", text, sentAt: formatTime(now), quote },
      ]);
      setPending(true);
      setReplyingTo(null);

      // Instantly scroll down so the loading indicator is immediately in view
      scrollToBottom();

      try {
        const userTimezone =
          typeof Intl !== "undefined"
            ? Intl.DateTimeFormat().resolvedOptions().timeZone || "Africa/Lagos"
            : "Africa/Lagos";

        const body: Record<string, string> = {
          query: text,
          phone: memberPhone ?? "",
          timezone: userTimezone,
        };
        const headers: Record<string, string> = {};
        if (adminToken) {
          headers["X-Admin-Token"] = adminToken;
        }

        const response = await apiFetch<AssistantAskResponse>("/api/v1/web-chat/ask", {
          method: "POST",
          headers,
          body: JSON.stringify(body),
        });
        setEntries((prev) => [
          ...prev,
          {
            id: crypto.randomUUID(),
            role: "assistant",
            response,
            sentAt: formatTime(new Date()),
          },
        ]);
      } catch (err) {
        const message =
          err instanceof ApiError
            ? err.message
            : "We're having trouble connecting right now. Please try again in a moment.";
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
      }
    },
    [community, memberPhone, sessionId, scrollToBottom],
  );

  return (
    <div className="flex min-h-dvh flex-col bg-zinc-50/50">
      <ChatHeader />

      <div
        ref={scrollRef}
        className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-3.5 overflow-y-auto px-4 pb-28 pt-4"
      >
        {/* Welcome Empty State */}
        {entries.length === 0 ? (
          <div className="my-auto flex flex-col items-center justify-center py-8 text-center">
            <div className="rounded-3xl border border-zinc-200/80 bg-white p-6 sm:p-8 shadow-xs max-w-md w-full">
              <h2 className="text-base font-semibold text-zinc-900 tracking-tight">
                UniPod Community Assistant
              </h2>
              <p className="mt-1.5 text-xs leading-relaxed text-zinc-500">
                Ask anything about the programme, sessions, resources, or community updates.
              </p>

              <div className="mt-6 space-y-2 text-left">
                <p className="text-[11px] font-semibold uppercase tracking-wider text-zinc-400">
                  Suggested topics:
                </p>
                {STARTER_PROMPTS.map((prompt) => (
                  <button
                    key={prompt}
                    type="button"
                    onClick={() => void sendMessage(prompt)}
                    className="w-full text-left rounded-xl border border-zinc-100 bg-zinc-50/70 px-3.5 py-2.5 text-xs text-zinc-700 hover:border-zinc-300 hover:bg-zinc-100/80 transition-all cursor-pointer"
                  >
                    &ldquo;{prompt}&rdquo;
                  </button>
                ))}
              </div>
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
                onQuote={(q) => setReplyingTo(q)}
              />
            );
          }
          return (
            <div
              key={entry.id}
              className="self-center my-1 rounded-xl border border-rose-200 bg-rose-50/90 px-4 py-2.5 text-xs text-rose-800 shadow-xs max-w-md text-center"
            >
              {entry.text}
            </div>
          );
        })}

        {/* Grok Thinking / Retrieving Loader */}
        {pending ? (
          <div ref={loadingRef}>
            <GrokThinkingLoader />
          </div>
        ) : null}

        {/* Invisible Scroll Sentinel to guarantee auto-scrolling to the latest item */}
        <div ref={bottomRef} className="h-2 w-full shrink-0" aria-hidden="true" />
      </div>

      {/* Docked Composer with Voice & Reply Support (native WhatsApp/Telegram mobile dock) */}
      <div className="fixed inset-x-0 bottom-0 z-30 pb-[env(safe-area-inset-bottom)] bg-white/95 backdrop-blur-md">
        <ChatComposer
          disabled={pending || !community}
          onSend={(text, quote) => void sendMessage(text, quote)}
          quotedMessage={replyingTo}
          onClearQuote={() => setReplyingTo(null)}
          onFocus={() => scrollToBottom(true)}
        />
      </div>
    </div>
  );
}
