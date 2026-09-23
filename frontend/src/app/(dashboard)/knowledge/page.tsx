"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";

import { AuthorityBadge, LifecycleBadge } from "@/components/knowledge/lifecycle-badge";
import { Button } from "@/components/ui/button";
import { ErrorNotice } from "@/components/ui/error-notice";
import { Skeleton } from "@/components/ui/skeleton";
import { useActiveCommunity } from "@/features/communities/hooks/active-community";
import {
  getKnowledgeSources,
  publishKnowledgeSource,
  rejectKnowledgeSource,
  submitForReview,
} from "@/lib/api/knowledge";
import { useSession } from "@/lib/auth/session";
import { queryKeys } from "@/lib/query/keys";
import type { KnowledgeSource, UserRole } from "@/types/api";

/** Roles the backend's policies accept for knowledge lifecycle changes. */
const MANAGER_ROLES: UserRole[] = ["tenant_owner", "community_admin", "trusted_organiser"];

function KnowledgeRow({ source, canManage }: { source: KnowledgeSource; canManage: boolean }) {
  const queryClient = useQueryClient();

  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: queryKeys.knowledgeSourcesRoot });

  const submit = useMutation({
    mutationFn: () => submitForReview(source.id),
    onSuccess: invalidate,
  });
  const publish = useMutation({
    mutationFn: () => publishKnowledgeSource(source.id),
    onSuccess: invalidate,
  });
  const reject = useMutation({
    mutationFn: () => rejectKnowledgeSource(source.id),
    onSuccess: invalidate,
  });

  const busy = submit.isPending || publish.isPending || reject.isPending;
  const failure = submit.error ?? publish.error ?? reject.error;

  return (
    <li className="zak-card rounded-sm px-5 py-4">
      <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div className="min-w-0">
          <p className="text-[0.9375rem] leading-6 font-medium text-ink">{source.name}</p>
          <p className="mt-1 font-mono text-xs leading-5 break-all text-ink-soft">{source.uri}</p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <LifecycleBadge status={source.lifecycle_status} />
          <AuthorityBadge tier={source.authority_tier} />
        </div>
      </div>

      <p className="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs leading-5 text-ink-soft">
        <span className="font-mono">{source.source_type}</span>
        <span aria-hidden="true">·</span>
        <span className="font-mono">{source.language}</span>
        {source.published_at ? (
          <>
            <span aria-hidden="true">·</span>
            <span>published {new Date(source.published_at).toLocaleDateString()}</span>
          </>
        ) : null}
      </p>

      {failure ? (
        <div className="mt-3">
          <ErrorNotice error={failure} title="That change was not applied" />
        </div>
      ) : null}

      {canManage ? (
        <div className="mt-4 flex flex-wrap gap-2">
          {source.lifecycle_status === "draft" ? (
            <Button size="sm" disabled={busy} onClick={() => submit.mutate()}>
              {submit.isPending ? "Submitting" : "Submit for review"}
            </Button>
          ) : null}

          {source.lifecycle_status === "pending_review" ? (
            <>
              <Button
                size="sm"
                variant="primary"
                disabled={busy}
                onClick={() => publish.mutate()}
              >
                {publish.isPending ? "Publishing" : "Publish"}
              </Button>
              <Button
                size="sm"
                variant="danger"
                disabled={busy}
                onClick={() => reject.mutate()}
              >
                {reject.isPending ? "Rejecting" : "Reject"}
              </Button>
            </>
          ) : null}

          {source.lifecycle_status === "published" ? (
            <p className="text-xs leading-5 text-ink-soft">
              Published knowledge is retrievable. Editing is not available yet.
            </p>
          ) : null}
        </div>
      ) : null}
    </li>
  );
}

export default function KnowledgePage() {
  const [page, setPage] = useState(1);
  const { user } = useSession();
  const { tenantId } = useActiveCommunity();

  const query = useQuery({
    queryKey: queryKeys.knowledgeSources(page),
    queryFn: ({ signal }) => getKnowledgeSources(page, signal),
  });

  // `tenants` is absent from the register response, so it is optional on User.
  const roles = user?.tenants?.find((tenant) => tenant.id === tenantId)?.roles ?? [];
  const canManage = roles.some((role) => MANAGER_ROLES.includes(role));

  const sources = query.data?.data ?? [];
  const meta = query.data?.meta;

  return (
    <div className="space-y-6">
      <header>
        <h1 className="font-display text-[1.75rem] leading-tight tracking-[-0.015em]">Knowledge</h1>
        <p className="mt-2 max-w-[64ch] text-sm leading-6 text-ink-soft">
          Sources across every community you can reach. Only published sources are retrievable when
          someone asks a question.
        </p>
      </header>

      {query.error ? <ErrorNotice error={query.error} title="Could not load knowledge" /> : null}

      {query.isPending ? (
        <div className="space-y-3">
          <Skeleton className="h-24 w-full" />
          <Skeleton className="h-24 w-full" />
        </div>
      ) : null}

      {query.isSuccess && sources.length === 0 ? (
        <div className="rounded-sm border border-dashed border-rule-strong px-5 py-8">
          <p className="text-[0.9375rem] leading-6 font-medium text-ink">No sources yet</p>
          <p className="mt-2 max-w-[60ch] text-[0.9375rem] leading-6 text-ink-soft">
            Nothing has been added to your communities. Once someone uploads a document or forwards a
            WhatsApp export, it appears here as a draft to review.
          </p>
        </div>
      ) : null}

      {sources.length > 0 ? (
        <ul className="space-y-3">
          {sources.map((source) => (
            <KnowledgeRow key={source.id} source={source} canManage={canManage} />
          ))}
        </ul>
      ) : null}

      {meta && meta.last_page > 1 ? (
        <nav aria-label="Pages" className="flex items-center justify-between gap-4 border-t border-rule pt-5">
          <Button
            size="sm"
            disabled={page <= 1}
            onClick={() => setPage((current) => Math.max(1, current - 1))}
          >
            Previous
          </Button>

          <p className="text-xs leading-5 text-ink-soft">
            Page {meta.current_page} of {meta.last_page} · {meta.total} sources
          </p>

          <Button
            size="sm"
            disabled={page >= meta.last_page}
            onClick={() => setPage((current) => current + 1)}
          >
            Next
          </Button>
        </nav>
      ) : null}
    </div>
  );
}
