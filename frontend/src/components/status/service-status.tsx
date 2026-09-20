"use client";

import { useEffect, useState } from "react";

import { getLiveness } from "@/lib/api/health";
import { cn } from "@/lib/utils";

type Probe =
  | { state: "checking" }
  | { state: "online"; service: string }
  | { state: "unreachable"; detail: string };

const DOT: Record<Probe["state"], string> = {
  checking: "bg-ink-soft zak-live",
  online: "bg-accent",
  unreachable: "bg-clay",
};

const LABEL: Record<Probe["state"], string> = {
  checking: "Checking the API",
  online: "API reachable",
  unreachable: "API not reachable",
};

/** Module scope on purpose: it touches no React state, so it is safe to await anywhere. */
async function readLiveness(signal?: AbortSignal): Promise<Probe> {
  try {
    const liveness = await getLiveness(signal);
    return { state: "online", service: liveness.service };
  } catch (error) {
    return {
      state: "unreachable",
      detail: error instanceof Error ? error.message : "Unknown error.",
    };
  }
}

/**
 * Probes the one product route the backend serves outside auth.
 * It reports "not reachable" honestly rather than pretending the stack is up.
 */
export function ServiceStatus({ className }: { className?: string }) {
  const [probe, setProbe] = useState<Probe>({ state: "checking" });

  useEffect(() => {
    const controller = new AbortController();

    void readLiveness(controller.signal).then((next) => {
      if (!controller.signal.aborted) setProbe(next);
    });

    return () => controller.abort();
  }, []);

  function recheck() {
    setProbe({ state: "checking" });
    void readLiveness().then(setProbe);
  }

  return (
    <div
      className={cn(
        "zak-card flex flex-wrap items-center justify-between gap-x-6 gap-y-4 rounded-sm px-4 py-4",
        className,
      )}
      role="status"
      aria-live="polite"
    >
      <div className="flex min-w-0 items-center gap-3">
        <span
          aria-hidden="true"
          className={cn("size-2.5 shrink-0 rounded-full", DOT[probe.state])}
        />
        <span className="min-w-0">
          <span className="zak-label block text-ink">{LABEL[probe.state]}</span>
          <span className="mt-1 block truncate text-xs leading-5 text-ink-soft">
            {probe.state === "checking" ? "GET /api/v1/health/live" : null}
            {probe.state === "online" ? `Service identifies as ${probe.service}.` : null}
            {probe.state === "unreachable" ? probe.detail : null}
          </span>
        </span>
      </div>

      <button
        type="button"
        onClick={recheck}
        className="zak-label shrink-0 rounded-sm border border-rule-strong px-3 py-2 text-ink-soft transition-colors duration-200 hover:border-accent hover:text-accent active:translate-y-px"
      >
        Check again
      </button>
    </div>
  );
}
