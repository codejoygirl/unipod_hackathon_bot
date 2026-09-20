"use client";

import { ErrorNotice } from "@/components/ui/error-notice";
import { Skeleton } from "@/components/ui/skeleton";
import { useActiveCommunity } from "@/features/communities/hooks/active-community";
import { useSession } from "@/lib/auth/session";

export default function CommunitiesPage() {
  const { user } = useSession();
  const { tenants, communities, tenantId, communityId, isLoading, error } = useActiveCommunity();

  const activeTenant = tenants.find((tenant) => tenant.id === tenantId) ?? null;

  return (
    <div className="space-y-6">
      <header>
        <h1 className="font-display text-[1.75rem] leading-tight tracking-[-0.015em]">
          Communities
        </h1>
        <p className="mt-2 max-w-[64ch] text-sm leading-6 text-ink-soft">
          {activeTenant
            ? `Communities you can reach in ${activeTenant.name ?? activeTenant.slug ?? "this organisation"}.`
            : "Communities you belong to."}
        </p>
      </header>

      {error ? <ErrorNotice error={error} title="Could not load communities" /> : null}

      {isLoading ? (
        <div className="space-y-3">
          <Skeleton className="h-20 w-full" />
          <Skeleton className="h-20 w-full" />
        </div>
      ) : null}

      {!isLoading && tenants.length === 0 ? (
        <div className="rounded-sm border border-dashed border-rule-strong px-5 py-8">
          <p className="text-[0.9375rem] leading-6 font-medium text-ink">
            You are not in a community yet
          </p>
          <p className="mt-2 max-w-[60ch] text-[0.9375rem] leading-6 text-ink-soft">
            A community administrator adds members. Until then there is no knowledge to search and no
            one to ask.
          </p>
        </div>
      ) : null}

      {!isLoading && tenants.length > 0 && communities.length === 0 ? (
        <div className="rounded-sm border border-dashed border-rule-strong px-5 py-8">
          <p className="text-[0.9375rem] leading-6 font-medium text-ink">
            No communities to show here
          </p>
          <p className="mt-2 max-w-[60ch] text-[0.9375rem] leading-6 text-ink-soft">
            This organisation has no communities you can reach, or they are still being set up.
          </p>
        </div>
      ) : null}

      {communities.length > 0 ? (
        <ul className="space-y-3">
          {communities.map((community) => {
            const isActive = community.id === communityId;

            return (
              <li
                key={community.id}
                className="zak-card flex flex-wrap items-center justify-between gap-x-6 gap-y-2 rounded-sm px-5 py-4"
              >
                <div className="min-w-0">
                  <p className="text-[0.9375rem] leading-6 font-medium text-ink">
                    {community.name}
                  </p>
                  <p className="mt-1 font-mono text-xs leading-5 break-all text-ink-soft">
                    {community.slug}
                  </p>
                </div>

                <div className="flex items-center gap-3">
                  <span className="text-xs leading-5 text-ink-soft">
                    Created {new Date(community.created_at).toLocaleDateString()}
                  </span>
                  {isActive ? <span className="zak-label text-accent">active</span> : null}
                </div>
              </li>
            );
          })}
        </ul>
      ) : null}

      {activeTenant ? (
        <section className="border-t border-rule pt-5">
          <h2 className="zak-label text-ink-soft">Your role in this organisation</h2>
          <p className="mt-2 font-mono text-[0.8125rem] leading-6 text-ink">
            {activeTenant.roles.length > 0 ? activeTenant.roles.join(", ") : "member"}
          </p>
          <p className="mt-2 text-xs leading-5 text-ink-soft">
            Signed in as {user?.email}.
          </p>
        </section>
      ) : null}
    </div>
  );
}
