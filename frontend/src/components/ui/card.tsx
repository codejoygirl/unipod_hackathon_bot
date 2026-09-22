import type { ComponentPropsWithRef } from "react";

import { cn } from "@/lib/utils";

export function Card({ className, ...props }: ComponentPropsWithRef<"div">) {
  return <div className={cn("zak-card rounded-sm", className)} {...props} />;
}

export function CardHeader({ className, ...props }: ComponentPropsWithRef<"div">) {
  return (
    <div
      className={cn("flex flex-wrap items-center justify-between gap-3 px-5 py-4", className)}
      {...props}
    />
  );
}

export function CardTitle({ className, ...props }: ComponentPropsWithRef<"h2">) {
  return (
    <h2
      className={cn("text-[0.9375rem] leading-6 font-medium text-ink", className)}
      {...props}
    />
  );
}

export function CardBody({ className, ...props }: ComponentPropsWithRef<"div">) {
  return <div className={cn("border-t border-rule px-5 py-4", className)} {...props} />;
}
