import type { AnswerState } from "@/types/api";

export type AnswerTone = "accent" | "amber" | "clay" | "neutral";

export type AnswerPresentation = {
  /** Shown to the member. Sentence case, never uppercase. */
  label: string;
  tone: AnswerTone;
  /** What the state means in plain terms, used when the API returns no prose. */
  description: string;
  /** Whether a human is expected to follow up. Mirrors `AnswerState::requiresAdminEscalation()`. */
  escalates: boolean;
};

/**
 * All six values of `App\Enums\AnswerState`. The PRD lists five; the API also returns
 * `INSUFFICIENT_EVIDENCE`, and dropping it would render an unstyled answer.
 *
 * Colour is never the only signal: every state carries its own label.
 */
export const ANSWER_STATES: Record<AnswerState, AnswerPresentation> = {
  VERIFIED: {
    label: "verified",
    tone: "accent",
    description: "Sources agree, and at least one is authoritative for this community.",
    escalates: false,
  },
  POSSIBLE: {
    label: "possible",
    tone: "amber",
    description: "Supported, but the sources are partial or informal.",
    escalates: false,
  },
  CONFLICT: {
    label: "conflict",
    tone: "amber",
    description: "Credible sources disagree. Both sides are shown below.",
    escalates: true,
  },
  INSUFFICIENT_EVIDENCE: {
    label: "insufficient evidence",
    tone: "neutral",
    description: "Nothing in scope covered this well enough to answer.",
    escalates: true,
  },
  UNKNOWN: {
    label: "unknown",
    tone: "neutral",
    description: "No knowledge in scope answers this. It has been sent to an administrator.",
    escalates: true,
  },
  BLOCKED: {
    label: "blocked",
    tone: "clay",
    description:
      "This falls outside the communities you belong to. Nothing was retrieved or generated.",
    escalates: false,
  },
};

export const ANSWER_STATE_VALUES = Object.keys(ANSWER_STATES) as AnswerState[];

export function isAnswerState(value: string): value is AnswerState {
  return Object.prototype.hasOwnProperty.call(ANSWER_STATES, value);
}

export function answerPresentation(state: AnswerState): AnswerPresentation {
  return ANSWER_STATES[state];
}
