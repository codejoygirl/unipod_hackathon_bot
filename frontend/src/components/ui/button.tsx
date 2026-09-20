import { cva, type VariantProps } from "class-variance-authority";
import type { ComponentPropsWithRef } from "react";

import { cn } from "@/lib/utils";

/**
 * Product register: sentence case in the sans face, never uppercase mono. The mono
 * treatment is reserved for metadata labels so buttons stay scannable.
 * `min-h-11` on the default size keeps the hit area at the 44px touch minimum.
 */
export const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 rounded-sm text-sm font-medium whitespace-nowrap transition-colors duration-200 disabled:pointer-events-none disabled:opacity-55",
  {
    variants: {
      variant: {
        primary: "bg-accent text-paper hover:bg-accent-bright",
        secondary: "border border-rule-strong text-ink hover:border-accent hover:text-accent",
        ghost: "text-ink-soft hover:bg-paper-sunk hover:text-ink",
        danger: "border border-clay text-clay hover:bg-clay-wash",
      },
      size: {
        sm: "min-h-9 px-3",
        md: "min-h-11 px-4",
        lg: "min-h-12 px-5",
      },
    },
    defaultVariants: { variant: "secondary", size: "md" },
  },
);

export type ButtonProps = ComponentPropsWithRef<"button"> &
  VariantProps<typeof buttonVariants>;

export function Button({ className, variant, size, type = "button", ...props }: ButtonProps) {
  return (
    <button
      type={type}
      className={cn(buttonVariants({ variant, size }), className)}
      {...props}
    />
  );
}
