"use client";

import Link from "next/link";
import { Suspense } from "react";

import { AppNav } from "@/components/layout/app-nav";
import { CommunitySelector } from "@/components/layout/community-selector";
import { SessionMenu } from "@/components/layout/session-menu";
import { Skeleton } from "@/components/ui/skeleton";
import { ActiveCommunityProvider } from "@/features/communities/hooks/active-community";
import { RequireSession } from "@/lib/auth/guard";

/** Shown while the active-community provider resolves its search params. */
function ShellFallback() {
  return (
    <div className="mx-auto w-full max-w-6xl space-y-4 px-6 py-10 sm:px-9">
      <Skeleton className="h-6 w-52" />
      <Skeleton className="h-11 w-72" />
      <Skeleton className="h-48 w-full" />
    </div>
  );
}

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  return (
    <RequireSession>
      <Suspense fallback={<ShellFallback />}>
        <ActiveCommunityProvider>
          <div className="flex min-h-full flex-1 flex-col">
            <header className="border-b border-rule bg-paper">
              <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-x-6 gap-y-3 px-6 py-3 sm:px-9">
                <Link href="/" className="inline-flex items-baseline gap-0.5">
                  <span className="font-display text-[1.25rem] leading-none font-medium tracking-[-0.015em]">
                    Zak
                  </span>
                  <span
                    aria-hidden="true"
                    className="font-mono text-[0.5625rem] leading-none text-accent"
                  >
                    [1]
                  </span>
                  <span className="sr-only">Community assistant</span>
                </Link>

                <AppNav />
                <SessionMenu />
              </div>

              <div className="mx-auto max-w-6xl border-t border-rule px-6 py-3 sm:px-9">
                <CommunitySelector />
              </div>
            </header>

            <main className="mx-auto w-full max-w-6xl flex-1 px-6 py-8 sm:px-9">{children}</main>
          </div>
        </ActiveCommunityProvider>
      </Suspense>
    </RequireSession>
  );
}
