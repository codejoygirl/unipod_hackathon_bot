import type { ComponentPropsWithRef } from "react";

import { cn } from "@/lib/utils";

export function Textarea({ className, ...props }: ComponentPropsWithRef<"textarea">) {
  return (
    <textarea
      className={cn(
        "w-full resize-y rounded-sm border border-rule-strong bg-paper-raised px-3 py-2.5",
        "text-base leading-6 text-ink placeholder:text-ink-soft/70 sm:text-sm",
        "aria-invalid:border-clay",
        "disabled:opacity-55",
        className,
      )}
      {...props}
    />
  );
}
