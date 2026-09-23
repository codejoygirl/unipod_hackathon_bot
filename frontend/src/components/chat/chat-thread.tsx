"use client";

import { CalendarClock, Clock, Megaphone } from "lucide-react";
import Image from "next/image";
import { useMemo, useState } from "react";

import { ThinkingOrb } from "thinking-orbs";

import { Skeleton } from "@/components/ui/skeleton";
import { useAsk } from "@/features/chat/hooks/use-ask";
import { useConversation } from "@/features/chat/hooks/use-conversation";
import { ApiError } from "@/lib/api/client";
import type { GroundedAnswer } from "@/types/api";
import { ChatComposer } from "./chat-composer";
import { CitedAnswer } from "./cited-answer";
import { AssistantTurn, UserTurn } from "./turn";

/** Diagnostics trace at the API boundary too; see `lib/api/assistant.ts`. Dev only. */
const DEV = process.env.NODE_ENV !== "production";

/** Turns the API's status codes into something a member can act on. */
function describeFailure(error: unknown): string {
  if (error instanceof ApiError) {
    switch (error.status) {
      case 401:
        return "Your session has ended. Sign in again to keep asking.";
      case 403:
        return "Your account is not in any community yet, so there is nothing to answer from.";
      case 404:
        return "That conversation no longer exists. Start a new chat.";
      case 422:
        return "The selected communities must all belong to the same tenant.";
      case 0:
        return "The API is not reachable. Start the backend, then try again.";
      default:
        return error.message;
    }
  }

  return "The question could not be answered.";
}

const SUGGESTIONS = [
  { label: "When does the community clinic open?", Icon: Clock },
  { label: "What deadlines are coming up?", Icon: CalendarClock },
  { label: "Summarize the latest announcements", Icon: Megaphone },
];

type ChatThreadProps = {
  /** Continue this thread. Null starts a new one on the first question. */
  conversationId: string | null;
  /** Called with the id the API assigned, so the URL can catch up. */
  onConversationCreated?: (id: string) => void;
};

/**
 * The full-height chat column: a scrollable message area with the composer pinned at the
 * bottom. Messages come from the server record, never component state.
 */
export function ChatThread({ conversationId, onConversationCreated }: ChatThreadProps) {
  const conversation = useConversation(conversationId);
  const [pending, setPending] = useState<string | null>(null);
  const [failure, setFailure] = useState<{ question: string; message: string } | null>(null);
  const ask = useAsk();

  const messages = conversation.data?.messages;

  // The API stores a question and its answer as adjacent rows, so render them paired.
  const pairs = useMemo(() => {
    const out: { key: string; question: string; answer: GroundedAnswer | null }[] = [];
    const rows = messages ?? [];

    for (let i = 0; i < rows.length; i += 1) {
      const message = rows[i];
      if (message.role !== "user") continue;

      const next = rows[i + 1];
      out.push({
        key: message.id,
        question: message.content,
        answer: next && next.role === "assistant" ? next.answer : null,
      });
    }

    return out;
  }, [messages]);

  function handleAsk(query: string) {
    setFailure(null);
    setPending(query);

    if (DEV) {
      console.log("[zak:thread] submit", {
        query,
        chars: query.length,
        conversationId: conversationId ?? "(new thread)",
      });
    }

    ask.mutate(
      // No scope is sent: the server answers from everything the member can read.
      { query, conversation_id: conversationId },
      {
        onSuccess: (response) => {
          setPending(null);

          const created = response.meta.conversation_id;

          if (DEV) {
            console.log("[zak:thread] settled", {
              state: response.data.state,
              citations: response.data.evidence_drawer.length,
              conversationId: created,
              startedNewThread: !conversationId,
            });
          }

          if (!conversationId && created) onConversationCreated?.(created);
        },
        onError: (error) => {
          setPending(null);
          setFailure({ question: query, message: describeFailure(error) });

          if (DEV) {
            console.error("[zak:thread] failed", {
              message: describeFailure(error),
              error,
            });
          }
        },
      },
    );
  }

  // A fresh thread has no id, so a disabled query still counts as resolved.
  const isThreadResolved = conversationId === null || !conversation.isPending;
  const isEmpty = isThreadResolved && pairs.length === 0 && pending === null && failure === null;

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      <div className="zak-scroll min-h-0 flex-1 overflow-y-auto">
        <div className="mx-auto w-full max-w-3xl px-4 py-8 sm:px-6">
          {conversation.isPending && conversationId ? (
            <div className="space-y-3">
              <Skeleton className="h-4 w-2/3" />
              <Skeleton className="h-4 w-1/2" />
              <Skeleton className="h-4 w-3/4" />
            </div>
          ) : null}

          {isEmpty ? <EmptyState onAsk={handleAsk} /> : null}

          <ol className="space-y-6">
            {pairs.map((pair) => (
              <li key={pair.key}>
                {pair.answer ? (
                  <CitedAnswer
                    question={pair.question}
                    state={pair.answer.state}
                    answer={pair.answer.answer}
                    evidence={pair.answer.evidence_drawer}
                    conflicts={pair.answer.conflicts}
                    confidence={pair.answer.confidence}
                    detectedLanguage={pair.answer.detected_language}
                    needsEscalation={pair.answer.needs_escalation}
                    escalationReason={pair.answer.escalation_reason}
                  />
                ) : (
                  <UserTurn>{pair.question}</UserTurn>
                )}
              </li>
            ))}
          </ol>

          {pending !== null ? (
            <div className="mt-6 flex flex-col gap-6">
              <UserTurn>{pending}</UserTurn>
              <AssistantTurn>
                <div
                  className="flex items-center gap-2.5"
                  role="status"
                  aria-label="Zak is searching your communities"
                >
                  <ThinkingOrb state="searching" size={20} />
                  <span aria-hidden="true" className="text-[0.9375rem] leading-6 text-ink-soft">
                    Searching your communities
                  </span>
                </div>
              </AssistantTurn>
            </div>
          ) : null}

          {failure !== null ? (
            <div className="mt-6 flex flex-col gap-6">
              <UserTurn>{failure.question}</UserTurn>
              <AssistantTurn>
                <p role="alert" className="text-[0.9375rem] leading-6 text-clay">
                  {failure.message}
                </p>
              </AssistantTurn>
            </div>
          ) : null}
        </div>
      </div>

      <div className="shrink-0 bg-paper">
        <div className="mx-auto w-full max-w-3xl px-4 py-3 sm:px-6 sm:py-4">
          <ChatComposer onAsk={handleAsk} isPending={ask.isPending} />
        </div>
      </div>
    </div>
  );
}

function EmptyState({ onAsk }: { onAsk: (query: string) => void }) {
  return (
    <div className="flex flex-col items-center px-2 pt-10 pb-8 text-center sm:pt-14">
      <Image
        src="/zak-mascot.png"
        alt=""
        aria-hidden="true"
        width={44}
        height={44}
        className="size-11 rounded-2xl object-cover"
      />

      <h1 className="mt-5 font-display text-[1.5rem] leading-tight font-bold tracking-[-0.02em] text-ink sm:text-[1.75rem]">
        What do you need to know?
      </h1>

      <p className="mt-2 max-w-[46ch] text-[0.9375rem] leading-6 text-ink-soft">
        Ask anything. Zak answers in plain language and shows the sources behind every reply.
      </p>

      <div className="mt-6 flex flex-wrap justify-center gap-2">
        {SUGGESTIONS.map(({ label, Icon }) => (
          <button
            key={label}
            type="button"
            onClick={() => onAsk(label)}
            className="inline-flex items-center gap-1.5 rounded-full border border-rule bg-paper-raised px-3 py-1.5 text-xs leading-5 text-ink-soft transition-colors duration-200 hover:border-accent hover:text-ink"
          >
            <Icon aria-hidden="true" className="size-3.5 shrink-0" />
            {label}
          </button>
        ))}
      </div>
    </div>
  );
}
