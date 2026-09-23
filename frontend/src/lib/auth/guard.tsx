"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

import { Skeleton } from "@/components/ui/skeleton";
import { useSession } from "./session";

function LoadingShell() {
  return (
    <div className="mx-auto w-full max-w-5xl space-y-4 px-6 py-10">
      <Skeleton className="h-6 w-48" />
      <Skeleton className="h-44 w-full" />
    </div>
  );
}

/**
 * Guards authenticated routes on the client.
 *
 * The session is a first-party cookie owned by Laravel on another origin, so a Next
 * server component cannot read it. Redirecting on the client is the trade-off; the
 * loading shell keeps a signed-in member from seeing a login flash.
 */
export function RequireSession({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const { isLoading, isAnonymous, error } = useSession();

  useEffect(() => {
    if (isAnonymous) router.replace("/login");
  }, [isAnonymous, router]);

  if (error) {
    // An unreachable API is not "signed out". Sending someone to a login form that cannot
    // submit would be a lie about what went wrong.
    return (
      <div className="mx-auto w-full max-w-2xl px-6 py-16">
        <div className="zak-card rounded-sm p-6">
          <h1 className="text-[0.9375rem] leading-6 font-medium text-ink">
            Cannot reach the API
          </h1>
          <p className="mt-2 text-[0.9375rem] leading-6 text-ink-soft">
            {error instanceof Error ? error.message : "Unknown error."}
          </p>
          <p className="mt-4 text-xs leading-5 text-ink-soft">
            The Laravel backend serves this app. Start it, then reload.
          </p>
        </div>
      </div>
    );
  }

  if (isLoading || isAnonymous) {
    return (
      <div role="status" aria-live="polite">
        <span className="sr-only">Checking your session</span>
        <LoadingShell />
      </div>
    );
  }

  return <>{children}</>;
}
