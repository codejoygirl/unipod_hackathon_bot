import type { CSSProperties } from "react";

import { AnswerStatus } from "./answer-status";
import { answerPresentation } from "./answer-state";
import { ConflictList } from "./conflict-list";
import { EvidenceList } from "./evidence-list";
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
 * The product's output: the question, the answer, the sources behind it, and how much the
 * system trusted them. Reused on the landing page and in the member chat.
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
    <article className={cn("zak-card rounded-sm p-5 sm:p-6", className)} style={style}>
      {channel || scope ? (
        <header className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
          {channel ? <span className="zak-label text-ink-soft">{channel}</span> : null}
          {scope ? <span className="zak-label text-ink-soft">{scope}</span> : null}
        </header>
      ) : null}

      <div className={cn("flex gap-3", channel || scope ? "mt-5" : undefined)}>
        <span aria-hidden="true" className="zak-label mt-1 shrink-0 text-ink-soft">
          Q
        </span>
        <p className="zak-question text-balance">{question}</p>
      </div>

      <div className="mt-5 border-t border-rule pt-5">
        <div className="flex gap-3">
          <span aria-hidden="true" className="zak-label mt-1 shrink-0 text-ink-soft">
            A
          </span>
          <p
            lang={detectedLanguage}
            dir={detectedLanguage ? "auto" : undefined}
            className={cn("zak-answer", hasProse ? "text-ink" : "text-ink-soft italic")}
          >
            {body}
          </p>
        </div>
      </div>

      {conflicts && conflicts.length > 0 ? (
        <div className="mt-5">
          <ConflictList conflicts={conflicts} />
        </div>
      ) : null}

      {evidence && evidence.length > 0 ? (
        <div className="mt-5">
          <EvidenceList citations={evidence} />
        </div>
      ) : null}

      <footer className="mt-5 flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-rule pt-5">
        <AnswerStatus state={state} />

        {typeof confidence === "number" ? (
          <span className="zak-label text-ink-soft">
            confidence {Math.round(confidence * 100)}%
          </span>
        ) : null}

        {needsEscalation ? (
          <span className="text-xs leading-5 text-ink-soft">
            {escalationReason ?? "Sent to an administrator for a human answer."}
          </span>
        ) : null}
      </footer>
    </article>
  );
}
