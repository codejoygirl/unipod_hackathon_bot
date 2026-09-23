import type { EvidenceCitation } from "@/types/api";

/**
 * Every citation the answer used, shown inline rather than behind a control: quoting the
 * source is the product's whole claim, so it should not need a click.
 *
 * `source_uri` is rendered as text, never a link. Real values use internal schemes
 * (`doc://`, `mock://`) with no browser-resolvable target, so linking them would promise
 * navigation that cannot happen.
 */
function locationOf(citation: EvidenceCitation): string | null {
  if (citation.page !== null) return `page ${citation.page}`;
  if (citation.timestamp !== null) return `${citation.timestamp}s`;
  return null;
}

export function EvidenceList({ citations }: { citations: EvidenceCitation[] }) {
  if (citations.length === 0) return null;

  return (
    <section className="border-t border-rule pt-5">
      <h3 className="zak-label text-ink-soft">
        Sources
        <span className="ml-2 text-ink-soft">{citations.length}</span>
      </h3>

      <ol className="mt-4 space-y-4">
        {citations.map((citation) => {
          const location = locationOf(citation);

          return (
            <li key={citation.evidence_id} className="flex gap-3">
              <span aria-hidden="true" className="zak-label mt-0.5 shrink-0 text-accent">
                {citation.evidence_id}
              </span>

              <div className="min-w-0 flex-1">
                <p className="text-[0.9375rem] leading-6 text-ink">{citation.source_name}</p>

                <blockquote className="mt-2 border-l-2 border-rule-strong pl-3 text-[0.9375rem] leading-6 text-ink-soft">
                  {citation.exact_quote}
                </blockquote>

                <p className="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs leading-5 text-ink-soft">
                  <span className="font-mono">{citation.authority}</span>
                  {location ? <span aria-hidden="true">·</span> : null}
                  {location ? <span>{location}</span> : null}
                  <span aria-hidden="true">·</span>
                  <span className="font-mono break-all">{citation.source_uri}</span>
                </p>
              </div>
            </li>
          );
        })}
      </ol>
    </section>
  );
}
