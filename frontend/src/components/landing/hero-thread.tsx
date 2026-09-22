import { CitedAnswer } from "@/components/chat/cited-answer";
import type { EvidenceCitation } from "@/types/api";

/**
 * The hero's product visual: one member, in scope for one question and outside it for the
 * next. The second turn is the point. An assistant that only ever answers looks the same
 * as one that guesses, and the refusal is what separates them.
 *
 * Rendered on a dark inset so the product reads as a live surface rather than more page copy.
 */
const BUDGET_QUESTION = "What did the design pod decide about the studio budget?";

const BUDGET_EVIDENCE: EvidenceCitation[] = [
  {
    evidence_id: "E1",
    source_name: "Design pod handbook, v4",
    source_uri: "doc://design-pod-handbook",
    exact_quote: "The pod approves a studio budget at the start of each term.",
    context: "Budget and spending",
    page: 12,
    timestamp: null,
    authority: "official_announcement",
  },
  {
    evidence_id: "E2",
    source_name: "Design pod minutes, 4 March",
    source_uri: "doc://design-pod-minutes-2026-03-04",
    exact_quote: "Approved: materials and equipment maintenance for the term.",
    context: "Minutes, item 3",
    page: null,
    timestamp: null,
    authority: "community_discussion",
  },
];

const KIT_QUESTION = "Who approved the robotics pod's kit supplier?";

export function HeroThread() {
  return (
    <div className="zak-dark rounded-2xl p-2.5 sm:p-3">
      <div className="rounded-xl border border-rule bg-paper-sunk/60 p-3 sm:p-4">
        <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-1 pt-0.5 pb-4">
          <span className="zak-label text-ink-soft">Web chat</span>
          <span className="zak-label text-ink-soft">UniPods · Design pod</span>
        </div>

        <div className="space-y-3">
          <CitedAnswer
            question={BUDGET_QUESTION}
            state="VERIFIED"
            answer="The design pod approved a studio budget for this term, split between materials and equipment maintenance. It was minuted on 4 March and holds for this term only."
            evidence={BUDGET_EVIDENCE}
            confidence={0.84}
          />

          <CitedAnswer question={KIT_QUESTION} state="BLOCKED" evidence={[]} />
        </div>
      </div>
    </div>
  );
}
