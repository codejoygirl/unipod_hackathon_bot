import type { ComponentPropsWithRef } from "react";

import { cn } from "@/lib/utils";

export function Skeleton({ className, ...props }: ComponentPropsWithRef<"div">) {
  return (
    <div
      aria-hidden="true"
      className={cn("animate-pulse rounded-sm bg-rule motion-reduce:animate-none", className)}
      {...props}
    />
  );
}
