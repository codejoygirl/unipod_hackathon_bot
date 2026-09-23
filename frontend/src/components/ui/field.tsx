import type { ReactNode } from "react";

import { Label } from "./label";
import { cn } from "@/lib/utils";

/** Stable id for the message element so the control can point at it with `aria-describedby`. */
export function fieldMessageId(controlId: string): string {
  return `${controlId}-message`;
}

/**
 * The message element only gets an id when it actually renders, so a control must not
 * reference a missing node. These keep the label, error and hint wiring in one place.
 */
export function fieldControlProps(controlId: string, error?: string, hint?: string) {
  const message = error ?? hint;

  return {
    id: controlId,
    "aria-invalid": error ? true : undefined,
    "aria-describedby": message ? fieldMessageId(controlId) : undefined,
  };
}

type FieldProps = {
  controlId: string;
  label: string;
  error?: string;
  hint?: string;
  children: ReactNode;
  className?: string;
};

export function Field({ controlId, label, error, hint, children, className }: FieldProps) {
  return (
    <div className={cn("space-y-2", className)}>
      <Label htmlFor={controlId}>{label}</Label>
      {children}
      {error ? (
        <p id={fieldMessageId(controlId)} className="text-sm leading-5 text-clay">
          {error}
        </p>
      ) : hint ? (
        <p id={fieldMessageId(controlId)} className="text-xs leading-5 text-ink-soft">
          {hint}
        </p>
      ) : null}
    </div>
  );
}
