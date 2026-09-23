"use client";

import { FileText, Globe, MessagesSquare } from "lucide-react";
import { useState } from "react";

import { cn } from "@/lib/utils";
import type { EvidenceCitation } from "@/types/api";

/**
 * Where a citation came from.
 *
 * The origin scheme is the useful part — `whatsapp://…/part-19` tells a member it came
 * from the group chat — so it is shown as a mark rather than spelled out. The raw URI is
 * an internal scheme with no browser target, so printing it would only add noise.
 */
function SourceIcon({ uri, className }: { uri: string; className?: string }) {
  const scheme = uri.split(":")[0]?.toLowerCase() ?? "";

  if (scheme === "whatsapp") return <WhatsAppGlyph className={className} />;
  if (uri.startsWith("http")) return <Globe aria-hidden="true" className={className} />;
  if (scheme === "community") return <MessagesSquare aria-hidden="true" className={className} />;

  return <FileText aria-hidden="true" className={className} />;
}

function WhatsAppGlyph({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 510 512.459" fill="currentColor" aria-hidden="true" className={className}>
      <path d="M435.689 74.468C387.754 26.471 324 .025 256.071 0 116.098 0 2.18 113.906 2.131 253.916c-.024 44.758 11.677 88.445 33.898 126.946L0 512.459l134.617-35.311c37.087 20.238 78.85 30.891 121.345 30.903h.109c139.949 0 253.88-113.917 253.928-253.928.024-67.855-26.361-131.645-74.31-179.643v-.012zm-179.618 390.7h-.085c-37.868-.011-75.016-10.192-107.428-29.417l-7.707-4.577-79.886 20.953 21.32-77.889-5.017-7.987c-21.125-33.605-32.29-72.447-32.266-112.322.049-116.366 94.729-211.046 211.155-211.046 56.373.025 109.364 22.003 149.214 61.903 39.853 39.888 61.781 92.927 61.757 149.313-.05 116.377-94.728 211.058-211.057 211.058v.011zm115.768-158.067c-6.344-3.178-37.537-18.52-43.358-20.639-5.82-2.119-10.044-3.177-14.27 3.178-4.225 6.357-16.388 20.651-20.09 24.875-3.702 4.238-7.403 4.762-13.747 1.583-6.343-3.178-26.787-9.874-51.029-31.487-18.86-16.827-31.597-37.598-35.297-43.955-3.702-6.355-.39-9.789 2.775-12.943 2.849-2.848 6.344-7.414 9.522-11.116s4.225-6.355 6.343-10.581c2.12-4.238 1.06-7.937-.522-11.117-1.584-3.177-14.271-34.409-19.568-47.108-5.151-12.37-10.385-10.69-14.269-10.897-3.703-.183-7.927-.219-12.164-.219s-11.105 1.582-16.925 7.939c-5.82 6.354-22.209 21.709-22.209 52.927 0 31.22 22.733 61.405 25.911 65.642 3.177 4.237 44.745 68.318 108.389 95.812 15.135 6.538 26.957 10.446 36.175 13.368 15.196 4.834 29.027 4.153 39.96 2.52 12.19-1.825 37.54-15.353 42.824-30.172 5.283-14.818 5.283-27.529 3.701-30.172-1.582-2.641-5.819-4.237-12.163-7.414l.011-.024z" />
    </svg>
  );
}

function locationOf(citation: EvidenceCitation): string | null {
  if (citation.page !== null) return `page ${citation.page}`;
  if (citation.timestamp !== null) return `${citation.timestamp}s`;
  return null;
}

/**
 * A pill has to identify its source on its own. The chunked-ingest suffix ("(part 16/19)")
 * is noise at this size, so it is dropped and the rest is truncated by CSS.
 */
function labelOf(citation: EvidenceCitation): string {
  return citation.source_name.replace(/\s*\(part \d+\/\d+\)$/i, "").trim();
}

/**
 * The sources behind an answer, as a row of small pills — one per citation.
 *
 * Sources are evidence, not the answer. Showing every quote inline buried the reply a
 * member actually asked for, so each pill opens on tap and only one is open at a time.
 *
 * Deliberately neutral: the accent colour is reserved for the answer state and calls to
 * action, not for supporting material.
 */
export function EvidenceList({ citations }: { citations: EvidenceCitation[] }) {
  const [openId, setOpenId] = useState<string | null>(null);

  if (citations.length === 0) return null;

  const open = citations.find((citation) => citation.evidence_id === openId) ?? null;

  return (
    <section aria-label="Sources">
      <h3 className="zak-label text-ink-soft">
        Sources
        <span className="ml-2">{citations.length}</span>
      </h3>

      <ul className="mt-2 flex flex-wrap items-center gap-1.5">
        {citations.map((citation) => {
          const isOpen = citation.evidence_id === openId;

          return (
            <li key={citation.evidence_id}>
              <button
                type="button"
                onClick={() => setOpenId(isOpen ? null : citation.evidence_id)}
                aria-expanded={isOpen}
                className={cn(
                  "inline-flex max-w-[14rem] items-center gap-1.5 rounded-full border px-2 py-0.5 text-[0.6875rem] leading-4 transition-colors duration-200",
                  isOpen
                    ? "border-rule-strong bg-paper-sunk text-ink"
                    : "border-rule text-ink-soft hover:border-rule-strong hover:text-ink",
                )}
              >
                <SourceIcon uri={citation.source_uri} className="size-2.5 shrink-0" />
                <span className="shrink-0 font-medium">{citation.evidence_id}</span>
                <span className="truncate">{labelOf(citation)}</span>
              </button>
            </li>
          );
        })}
      </ul>

      {open ? (
        <div className="mt-2.5 flex gap-3">
          <SourceIcon uri={open.source_uri} className="mt-0.5 size-4 shrink-0 text-ink-soft" />

          <div className="min-w-0 flex-1">
            <p className="text-[0.9375rem] leading-6 text-ink">{open.source_name}</p>

            <blockquote className="mt-1.5 border-l-2 border-rule pl-3 text-sm leading-6 text-ink-soft">
              {open.exact_quote}
            </blockquote>

            <p className="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs leading-5 text-ink-soft">
              <span className="font-mono">{open.authority}</span>
              {locationOf(open) ? (
                <>
                  <span aria-hidden="true">·</span>
                  <span>{locationOf(open)}</span>
                </>
              ) : null}
            </p>
          </div>
        </div>
      ) : null}
    </section>
  );
}
