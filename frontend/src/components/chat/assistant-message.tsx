import type { AssistantAskResponse } from "@/lib/api/types";
import { stateLabel, stateTone } from "@/lib/chat/state-label";
import { EscalationNotice } from "./escalation-notice";

type AssistantMessageProps = {
  response: AssistantAskResponse;
  sentAt: string;
};

export function AssistantMessage({ response, sentAt }: AssistantMessageProps) {
  const { data, meta } = response;
  const sources = data.evidence_drawer ?? [];
  const uniqueSourceNames = [...new Set(sources.map((s) => s.source_name).filter(Boolean))];

  return (
    <div className="flex gap-2">
      <div
        className="mt-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-zinc-900 text-white"
        aria-hidden
      >
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
          <path
            d="M12 3v3M8 7h8M7 11h10v7a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2v-7Z"
            stroke="currentColor"
            strokeWidth="1.75"
            strokeLinecap="round"
          />
        </svg>
      </div>
      <div className="min-w-0 max-w-[min(100%,20rem)] space-y-2 sm:max-w-md">
        <article className="rounded-2xl border border-zinc-200/80 bg-white p-4 shadow-sm">
          <div className="mb-3 flex flex-wrap items-center gap-2 text-xs text-zinc-500">
            <span
              className={`inline-flex items-center rounded-full px-2 py-0.5 font-medium ring-1 ring-inset ${stateTone(data.state)}`}
            >
              {stateLabel(data.state)}
            </span>
            <span>{meta.chunks_evaluated} sources scanned</span>
            {data.confidence != null ? (
              <span>{Math.round(data.confidence * 100)}% confidence</span>
            ) : null}
          </div>
          <p className="whitespace-pre-wrap text-sm leading-relaxed text-zinc-800">{data.answer}</p>
          {uniqueSourceNames.length > 0 ? (
            <ul className="mt-3 flex flex-wrap gap-1.5">
              {uniqueSourceNames.map((name) => (
                <li
                  key={name}
                  className="rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-medium text-zinc-600"
                >
                  #{name.replace(/\s+/g, "-").toLowerCase()}
                </li>
              ))}
            </ul>
          ) : null}
          {data.conflicts.length > 0 ? (
            <div className="mt-3 rounded-xl bg-orange-50 p-3 text-xs text-orange-900">
              <p className="font-semibold">Conflicting sources</p>
              <ul className="mt-1 list-disc pl-4">
                {data.conflicts.map((c) => (
                  <li key={c.topic}>{c.topic}</li>
                ))}
              </ul>
            </div>
          ) : null}
        </article>
        {data.needs_escalation ? <EscalationNotice reason={data.escalation_reason} /> : null}
        <time className="block text-[11px] text-zinc-400">{sentAt}</time>
      </div>
    </div>
  );
}
