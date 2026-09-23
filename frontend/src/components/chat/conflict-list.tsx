import type { AnswerConflict } from "@/types/api";

/** Rendered only for the CONFLICT state, where the API returns each side of a disagreement. */
export function ConflictList({ conflicts }: { conflicts: AnswerConflict[] }) {
  if (conflicts.length === 0) return null;

  return (
    <section>
      <h3 className="zak-label text-amber">
        Sources disagree
        <span className="ml-2">{conflicts.length}</span>
      </h3>

      <ul className="mt-3 space-y-4">
        {conflicts.map((conflict) => (
          <li key={conflict.topic}>
            <p className="text-[0.9375rem] leading-6 font-medium text-ink">{conflict.topic}</p>

            <ul className="mt-2 space-y-1.5">
              {conflict.claims.map((claim) => (
                <li
                  key={claim}
                  className="border-l-2 border-amber pl-3 text-[0.9375rem] leading-6 text-ink-soft"
                >
                  {claim}
                </li>
              ))}
            </ul>

            <p className="mt-1.5 text-xs leading-5 text-ink-soft">
              Recommended action: {conflict.action}
            </p>
          </li>
        ))}
      </ul>
    </section>
  );
}
