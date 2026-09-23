"use client";

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
 * Waits for the automatic session before rendering a surface that needs one.
 *
 * There is no sign-in route to redirect to any more, so a failure is reported in place
 * rather than bounced to a form that cannot help.
 */
export function RequireSession({ children }: { children: React.ReactNode }) {
  const { isLoading, error } = useSession();

  if (error) {
    return (
      <div className="mx-auto w-full max-w-2xl px-6 py-16">
        <div className="zak-card rounded-sm p-6">
          <h1 className="text-[0.9375rem] leading-6 font-medium text-ink">
            Cannot start a session
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

  if (isLoading) {
    return (
      <div role="status" aria-live="polite">
        <span className="sr-only">Starting your session</span>
        <LoadingShell />
      </div>
    );
  }

  return <>{children}</>;
}
