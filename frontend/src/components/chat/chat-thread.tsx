"use client";

import { useRef, useState } from "react";

import { ApiError } from "@/lib/api/client";
import { ChatComposer } from "./chat-composer";
import { CitedAnswer } from "./cited-answer";
import { Skeleton } from "@/components/ui/skeleton";
import { useAsk } from "@/features/chat/hooks/use-ask";
import type { GroundedAnswer } from "@/types/api";

type Turn =
  | { id: number; question: string; status: "pending" }
  | { id: number; question: string; status: "answered"; answer: GroundedAnswer }
  | { id: number; question: string; status: "failed"; message: string };

/** Turns the API's status codes into something a member can act on. */
function describeFailure(error: unknown): string {
  if (error instanceof ApiError) {
    switch (error.status) {
      case 401:
        return "Your session has ended. Sign in again to keep asking.";
      case 403:
        return "None of the selected communities are ones you can read. Pick another community.";
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

type ChatThreadProps = {
  /** ULIDs, all from one tenant: the API rejects a cross-tenant request with 422. */
  communityIds: string[];
  scope: string;
  blockedReason?: string | null;
};

export function ChatThread({ communityIds, scope, blockedReason }: ChatThreadProps) {
  const [turns, setTurns] = useState<Turn[]>([]);
  const nextId = useRef(1);
  const ask = useAsk();

  function handleAsk(query: string) {
    const id = nextId.current++;

    setTurns((prev) => [...prev, { id, question: query, status: "pending" }]);

    ask.mutate(
      { query, community_ids: communityIds },
      {
        onSuccess: (response) => {
          setTurns((prev) =>
            prev.map((turn) =>
              turn.id === id
                ? { id, question: query, status: "answered", answer: response.data }
                : turn,
            ),
          );
        },
        onError: (error) => {
          setTurns((prev) =>
            prev.map((turn) =>
              turn.id === id
                ? { id, question: query, status: "failed", message: describeFailure(error) }
                : turn,
            ),
          );
        },
      },
    );
  }

  return (
    <div className="space-y-6">
      <ChatComposer onAsk={handleAsk} isPending={ask.isPending} blockedReason={blockedReason} />

      {turns.length === 0 ? (
        <div className="rounded-sm border border-dashed border-rule-strong px-5 py-8">
          <p className="text-[0.9375rem] leading-6 font-medium text-ink">No questions yet</p>
          <p className="mt-2 max-w-[60ch] text-[0.9375rem] leading-6 text-ink-soft">
            Ask about anything documented in {scope}. Every answer shows the sources it used, and
            anything outside {scope} is refused rather than guessed.
          </p>
        </div>
      ) : null}

      <ol className="space-y-6">
        {turns.map((turn) => (
          <li key={turn.id}>
            {turn.status === "answered" ? (
              <CitedAnswer
                question={turn.question}
                state={turn.answer.state}
                answer={turn.answer.answer}
                evidence={turn.answer.evidence_drawer}
                conflicts={turn.answer.conflicts}
                confidence={turn.answer.confidence}
                detectedLanguage={turn.answer.detected_language}
                needsEscalation={turn.answer.needs_escalation}
                escalationReason={turn.answer.escalation_reason}
                scope={scope}
              />
            ) : (
              <div className="zak-card rounded-sm p-5 sm:p-6">
                <div className="flex gap-3">
                  <span aria-hidden="true" className="zak-label mt-1 shrink-0 text-ink-soft">
                    Q
                  </span>
                  <p className="zak-question text-balance">{turn.question}</p>
                </div>

                {turn.status === "pending" ? (
                  <div className="mt-5 border-t border-rule pt-5">
                    <p className="zak-label text-ink-soft">Searching your communities</p>
                    <div className="mt-3 space-y-2">
                      <Skeleton className="h-4 w-3/4" />
                      <Skeleton className="h-4 w-1/2" />
                    </div>
                  </div>
                ) : (
                  <p
                    role="alert"
                    className="mt-5 border-t border-rule pt-5 text-[0.9375rem] leading-6 text-clay"
                  >
                    {turn.message}
                  </p>
                )}
              </div>
            )}
          </li>
        ))}
      </ol>
    </div>
  );
}
