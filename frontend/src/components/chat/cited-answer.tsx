import type { CSSProperties } from "react";

import { AnswerStatus } from "./answer-status";
import { answerPresentation } from "./answer-state";
import { ConflictList } from "./conflict-list";
import { EvidenceList } from "./evidence-list";
import { AssistantTurn, UserTurn } from "./turn";
import { cn } from "@/lib/utils";
import type { AnswerConflict, AnswerState, EvidenceCitation } from "@/types/api";

type CitedAnswerProps = {
  question: string;
  state: AnswerState;
  /** Prose from the API. Empty for non-actionable states, which is normal. */
  answer?: string;
  evidence?: EvidenceCitation[];
  conflicts?: AnswerConflict[];
  confidence?: number;
  /** BCP-47 tag from `detected_language`. Drives `lang` and right-to-left layout. */
  detectedLanguage?: string;
  needsEscalation?: boolean;
  escalationReason?: string | null;
  /** Where the question was asked, and the community it was scoped to. */
  channel?: string;
  scope?: string;
  className?: string;
  style?: CSSProperties;
};

/**
 * The product's output as a chat turn: the member's question as a right-aligned bubble and
 * the answer as plain left-aligned prose — no bubble — followed by its verdict and sources.
 * Reused on the landing page and in the member chat.
 */
export function CitedAnswer({
  question,
  state,
  answer,
  evidence,
  conflicts,
  confidence,
  detectedLanguage,
  needsEscalation,
  escalationReason,
  channel,
  scope,
  className,
  style,
}: CitedAnswerProps) {
  const presentation = answerPresentation(state);
  const hasProse = typeof answer === "string" && answer.trim().length > 0;

  // A state with no prose is a valid outcome, not a rendering bug: say what it means.
  const body = hasProse ? answer : presentation.description;

  return (
    <article className={cn("flex flex-col gap-4", className)} style={style}>
      {channel || scope ? (
        <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
          {channel ? <span className="zak-label text-ink-soft">{channel}</span> : null}
          {scope ? <span className="zak-label text-ink-soft">{scope}</span> : null}
        </div>
      ) : null}

      <UserTurn>{question}</UserTurn>

      <AssistantTurn>
        <p
          lang={detectedLanguage}
          dir={detectedLanguage ? "auto" : undefined}
          className={cn("zak-answer", hasProse ? "text-ink" : "text-ink-soft")}
        >
          {body}
        </p>

        <div className="flex flex-wrap items-center gap-x-2.5 gap-y-2">
          <AnswerStatus state={state} />

          {typeof confidence === "number" ? (
            <span className="zak-label text-ink-soft">
              {Math.round(confidence * 100)}% confident
            </span>
          ) : null}

          {needsEscalation ? (
            <span className="text-xs leading-5 text-ink-soft">
              {escalationReason ?? "Sent to an administrator for a human answer."}
            </span>
          ) : null}
        </div>

        {conflicts && conflicts.length > 0 ? <ConflictList conflicts={conflicts} /> : null}
        {evidence && evidence.length > 0 ? <EvidenceList citations={evidence} /> : null}
      </AssistantTurn>
    </article>
  );
}
