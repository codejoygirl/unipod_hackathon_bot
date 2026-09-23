import type { ComponentPropsWithRef } from "react";

import { cn } from "@/lib/utils";

/**
 * `text-base` below the `sm` breakpoint: iOS Safari zooms the viewport when a focused
 * input renders under 16px.
 */
export function Input({ className, ...props }: ComponentPropsWithRef<"input">) {
  return (
    <input
      className={cn(
        "min-h-11 w-full rounded-sm border border-rule-strong bg-paper-raised px-3 py-2",
        "text-base text-ink placeholder:text-ink-soft/70 sm:text-sm",
        "aria-invalid:border-clay",
        "disabled:opacity-55",
        className,
      )}
      {...props}
    />
  );
}
