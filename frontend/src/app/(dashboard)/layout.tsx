"use client";

import { Suspense, type ReactNode } from "react";

import { Skeleton } from "@/components/ui/skeleton";
import { ActiveCommunityProvider } from "@/features/communities/hooks/active-community";
import { RequireSession } from "@/lib/auth/guard";

/** Shown while the active-community provider resolves its search params. */
function ShellFallback() {
  return (
    <div className="mx-auto w-full max-w-3xl space-y-4 px-6 py-10">
      <Skeleton className="h-6 w-48" />
      <Skeleton className="h-4 w-2/3" />
      <Skeleton className="h-32 w-full" />
    </div>
  );
}

/**
 * The chat app is the whole surface now: no nav, community selector or session menu.
 * Auth and community providers stay as wiring; only the visible chrome is gone.
 */
export default function DashboardLayout({ children }: { children: ReactNode }) {
  return (
    <RequireSession>
      <Suspense fallback={<ShellFallback />}>
        <ActiveCommunityProvider>
          <div className="min-h-dvh">{children}</div>
        </ActiveCommunityProvider>
      </Suspense>
    </RequireSession>
  );
}
