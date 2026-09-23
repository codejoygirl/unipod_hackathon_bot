"use client";

import { ExternalLink } from "lucide-react";
import { useMemo, useState } from "react";

import { ErrorNotice } from "@/components/ui/error-notice";
import { Skeleton } from "@/components/ui/skeleton";
import { useResources } from "@/features/resources/hooks/use-resources";
import { cn } from "@/lib/utils";
import type { ResourceCategory, ResourceLink } from "@/types/api";

const LABELS: Record<ResourceCategory, string> = {
  recordings: "Recordings",
  meetings: "Meetings",
  documents: "Documents",
  code: "Code",
  other: "Shared by members",
};

const ORDER: ResourceCategory[] = ["recordings", "meetings", "documents", "code", "other"];

/**
 * Links mined from published community knowledge.
 *
 * These come from the *content*, not from citations: citation URIs use internal schemes
 * (`whatsapp://…`, `doc://…`) that a browser cannot open, so they are text-only elsewhere.
 * Everything here is a real `https://` target, opened in a new tab.
 */
export default function ResourcesPage() {
  const resources = useResources();
  const [filter, setFilter] = useState<ResourceCategory | "all">("all");

  const links = resources.data?.data ?? [];

  const counts = useMemo(() => {
    const out: Partial<Record<ResourceCategory, number>> = {};

    for (const link of links) {
      out[link.category] = (out[link.category] ?? 0) + 1;
    }

    return out;
  }, [links]);

  const groups = useMemo(
    () =>
      ORDER.map((category) => ({
        category,
        items: links.filter((link) => link.category === category),
      })).filter(
        (group) => group.items.length > 0 && (filter === "all" || filter === group.category),
      ),
    [links, filter],
  );

  return (
    <div className="space-y-6">
      <header>
        <h1 className="font-display text-[1.75rem] leading-tight tracking-[-0.015em]">
          Resources
        </h1>
        <p className="mt-2 max-w-[64ch] text-sm leading-6 text-ink-soft">
          Links shared in the knowledge published to your communities. Each opens in a new tab.
        </p>
      </header>

      {resources.error ? (
        <ErrorNotice error={resources.error} title="Could not load resources" />
      ) : null}

      {resources.isPending ? (
        <div className="space-y-2">
          <Skeleton className="h-14 w-full" />
          <Skeleton className="h-14 w-full" />
          <Skeleton className="h-14 w-full" />
        </div>
      ) : null}

      {links.length > 0 ? (
        <div className="flex flex-wrap gap-2">
          <Chip active={filter === "all"} label={`All ${links.length}`} onClick={() => setFilter("all")} />

          {ORDER.filter((category) => counts[category]).map((category) => (
            <Chip
              key={category}
              active={filter === category}
              label={`${LABELS[category]} ${counts[category]}`}
              onClick={() => setFilter(category)}
            />
          ))}
        </div>
      ) : null}

      {groups.map((group) => (
        <section key={group.category} className="space-y-2">
          <h2 className="zak-label text-ink-soft">{LABELS[group.category]}</h2>

          <ul className="grid gap-2 sm:grid-cols-2">
            {group.items.map((link) => (
              <ResourceCard key={link.url} link={link} />
            ))}
          </ul>
        </section>
      ))}

      {!resources.isPending && !resources.error && links.length === 0 ? (
        <p className="rounded-xl border border-dashed border-rule-strong px-5 py-8 text-sm leading-6 text-ink-soft">
          No links yet. Publish knowledge that contains URLs and they will show up here.
        </p>
      ) : null}
    </div>
  );
}

function ResourceCard({ link }: { link: ResourceLink }) {
  return (
    <li>
      <a
        href={link.url}
        target="_blank"
        // Untrusted content supplied these URLs, so never hand the opener over.
        rel="noopener noreferrer"
        className="group flex items-start gap-3 rounded-xl border border-rule bg-paper-raised px-4 py-3 transition-colors duration-200 hover:border-accent"
      >
        <span className="min-w-0 flex-1">
          <span className="block truncate text-sm leading-5 text-ink">{link.domain}</span>
          <span className="mt-0.5 block truncate font-mono text-xs leading-5 text-ink-soft">
            {link.url}
          </span>
          <span className="mt-0.5 block truncate text-xs leading-5 text-ink-soft">
            from {link.source_name}
          </span>
        </span>

        <ExternalLink
          aria-hidden="true"
          className="mt-0.5 size-4 shrink-0 text-ink-soft transition-colors duration-200 group-hover:text-accent"
        />
      </a>
    </li>
  );
}

function Chip({
  active,
  label,
  onClick,
}: {
  active: boolean;
  label: string;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={cn(
        "rounded-full border px-3 py-1.5 text-xs leading-5 transition-colors duration-200",
        active
          ? "border-accent bg-accent-wash text-accent"
          : "border-rule text-ink-soft hover:border-accent hover:text-ink",
      )}
    >
      {label}
    </button>
  );
}
