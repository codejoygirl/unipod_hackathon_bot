import { cva, type VariantProps } from "class-variance-authority";
import type { ComponentPropsWithRef } from "react";

import { cn } from "@/lib/utils";

const badgeVariants = cva(
  "inline-flex items-center rounded-sm border px-2 py-0.5 text-xs leading-5 whitespace-nowrap",
  {
    variants: {
      tone: {
        neutral: "border-rule-strong bg-paper-sunk text-ink-soft",
        accent: "border-accent bg-accent-wash text-accent",
        amber: "border-amber bg-amber-wash text-amber",
        clay: "border-clay bg-clay-wash text-clay",
      },
    },
    defaultVariants: { tone: "neutral" },
  },
);

export type BadgeProps = ComponentPropsWithRef<"span"> & VariantProps<typeof badgeVariants>;

export function Badge({ className, tone, ...props }: BadgeProps) {
  return <span className={cn(badgeVariants({ tone }), className)} {...props} />;
}
