"use client";

import { apiFetch, ApiError } from "@/lib/api/client";
import type { AssistantAskResponse } from "@/lib/api/types";
import { loadChatHistory, saveChatHistory, type StoredChatEntry } from "@/lib/web-chat/storage";
import { useWebChat } from "@/lib/web-chat/web-chat-context";
import { useCallback, useEffect, useRef, useState } from "react";
import { AssistantMessage } from "./assistant-message";
import { ChatComposer } from "./chat-composer";
import { ChatHeader } from "./chat-header";
import { UserMessage } from "./user-message";

function formatTime(date: Date): string {
  return date.toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" });
}

export function ChatView() {
  const { accessKey, sessionId, memberPhone, community } = useWebChat();
  const [entries, setEntries] = useState<StoredChatEntry[]>([]);
  const [pending, setPending] = useState(false);
  const scrollRef = useRef<HTMLDivElement>(null);
  const hydratedFor = useRef<string | null>(null);

  const scrollToBottom = useCallback(() => {
    requestAnimationFrame(() => {
      scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: "smooth" });
    });
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

  useEffect(() => {
    scrollToBottom();
  }, [entries, scrollToBottom]);

  const sendMessage = useCallback(
    async (text: string) => {
      if (!community || !sessionId) {
        return;
      }

      const now = new Date();
      const userId = crypto.randomUUID();
      setEntries((prev) => [
        ...prev,
        { id: userId, role: "user", text, sentAt: formatTime(now) },
      ]);
      setPending(true);

      try {
        const body: Record<string, string> = {
          query: text,
          s: sessionId,
        };
        if (accessKey) {
          body.k = accessKey;
        }
        if (memberPhone) {
          body.p = memberPhone;
        }
        const response = await apiFetch<AssistantAskResponse>("/api/v1/web-chat/ask", {
          method: "POST",
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
            : "Something went wrong. Check that Laravel and the AI service are running.";
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
    [accessKey, community, memberPhone, sessionId],
  );

  return (
    <div className="flex min-h-dvh flex-col bg-[#f6f4fa]">
      <ChatHeader />
      <div
        ref={scrollRef}
        className="mx-auto flex w-full max-w-lg flex-1 flex-col gap-4 overflow-y-auto px-4 pb-36 pt-4"
      >
        <div className="flex justify-center">
          <span className="inline-flex items-center gap-1.5 rounded-full bg-white/90 px-3 py-1 text-[11px] font-medium text-zinc-600 shadow-sm ring-1 ring-zinc-200/80">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden>
              <path
                d="M12 3 4 7v6c0 5 3.5 8 8 8s8-3 8-8V7l-8-4Z"
                stroke="currentColor"
                strokeWidth="1.75"
              />
            </svg>
            Grounded answers with citation checks
          </span>
        </div>

        {entries.length === 0 ? (
          <p className="text-center text-sm text-zinc-500">
            Private chat with Zak — same grounded Q&amp;A as WhatsApp or Telegram DM for this
            community.
          </p>
        ) : null}

        {entries.map((entry) => {
          if (entry.role === "user") {
            return <UserMessage key={entry.id} text={entry.text} sentAt={entry.sentAt} />;
          }
          if (entry.role === "assistant") {
            return (
              <AssistantMessage key={entry.id} response={entry.response} sentAt={entry.sentAt} />
            );
          }
          return (
            <div
              key={entry.id}
              className="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
            >
              {entry.text}
            </div>
          );
        })}

        {pending ? (
          <p className="text-center text-xs text-zinc-400" aria-live="polite">
            Searching community knowledge…
          </p>
        ) : null}
      </div>

      <div className="fixed inset-x-0 bottom-[calc(3.25rem+env(safe-area-inset-bottom))] z-30">
        <ChatComposer
          disabled={pending || !community}
          onSend={(text) => void sendMessage(text)}
        />
      </div>
    </div>
  );
}
