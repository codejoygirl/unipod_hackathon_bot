import type { ComponentPropsWithRef } from "react";

import { cn } from "@/lib/utils";

export function Label({ className, ...props }: ComponentPropsWithRef<"label">) {
  return (
    <label
      className={cn("block text-sm leading-5 font-medium text-ink", className)}
      {...props}
    />
  );
}
