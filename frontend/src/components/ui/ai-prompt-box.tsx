"use client";

import { ArrowUp, Mic, Square } from "lucide-react";
import { useEffect, useRef, type KeyboardEvent } from "react";

import { cn } from "@/lib/utils";

const MAX_TEXTAREA_HEIGHT = 160;

type PromptInputBoxProps = {
  value: string;
  onValueChange: (value: string) => void;
  /**
   * Called on Enter (Shift+Enter keeps the newline) or the send button. Points at the
   * surrounding form's submit handler, so validation stays with the form.
   */
  onSubmit: () => void;
  isLoading?: boolean;
  disabled?: boolean;
  placeholder?: string;
  error?: string;
  controlId?: string;
  errorId?: string;
  maxLength?: number;
  label?: string;
  className?: string;
};

/**
 * The chat input, mirroring the shadcn `PromptInputBox` reference: a rounded surface with a
 * borderless auto-growing textarea and a circular send button that morphs between a
 * microphone (empty), an up-arrow (has content) and a stop square (busy).
 *
 * Two deliberate departures from the reference:
 *
 * - **Themed with this app's tokens** (`--paper-raised`, `--ink`) instead of hardcoded
 *   `#1F2023` greys, so the same surface reads correctly in light and dark mode.
 * - **No module-scope `document` access.** The reference appends a `<style>` tag while the
 *   module is evaluated, which throws during the server render.
 */
export function PromptInputBox({
  value,
  onValueChange,
  onSubmit,
  isLoading = false,
  disabled = false,
  placeholder = "Ask anything…",
  error,
  controlId,
  errorId,
  maxLength,
  label = "Your question",
  className,
}: PromptInputBoxProps) {
  const textareaRef = useRef<HTMLTextAreaElement | null>(null);

  const busy = disabled || isLoading;
  const hasContent = value.trim().length > 0;
  const nearLimit = typeof maxLength === "number" && value.length > maxLength * 0.75;

  // Grow with the content. Runs after paint, so the server render is untouched.
  useEffect(() => {
    const el = textareaRef.current;
    if (!el) return;
    el.style.height = "0px";
    el.style.height = `${Math.min(el.scrollHeight, MAX_TEXTAREA_HEIGHT)}px`;
  }, [value]);

  function handleKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
    if (event.key !== "Enter" || event.shiftKey) return;

    event.preventDefault();
    if (hasContent && !busy) onSubmit();
  }

  function handleSendClick() {
    if (busy) return;
    if (hasContent) onSubmit();
    else textareaRef.current?.focus();
  }

  const sendLabel = isLoading ? "Answering" : hasContent ? "Send message" : "Type a question";

  return (
    <div
      className={cn(
        "rounded-3xl bg-paper-raised p-2 transition-colors duration-200",
        "shadow-[0_1px_2px_oklch(0.2_0.01_255/0.05),0_8px_30px_-12px_oklch(0.2_0.01_255/0.18)]",
        className,
      )}
    >
      <label htmlFor={controlId} className="sr-only">
        {label}
      </label>

      <textarea
        ref={textareaRef}
        id={controlId}
        rows={1}
        value={value}
        onChange={(event) => onValueChange(event.target.value)}
        onKeyDown={handleKeyDown}
        placeholder={placeholder}
        disabled={busy}
        aria-invalid={error ? true : undefined}
        aria-describedby={error && errorId ? errorId : undefined}
        className="min-h-11 w-full resize-none overflow-y-auto bg-transparent px-2.5 py-2 text-[0.9375rem] leading-6 text-ink placeholder:text-ink-soft/70 focus:outline-none disabled:opacity-55"
      />

      <div className="flex items-center justify-end gap-2 px-1 pt-1.5 pb-1">
        <button
          type="button"
          onClick={handleSendClick}
          disabled={busy}
          aria-label={sendLabel}
          title={sendLabel}
          className={cn(
            "flex size-9 shrink-0 items-center justify-center rounded-full transition-colors duration-200",
            busy
              ? "bg-paper-sunk text-ink-soft"
              : hasContent
                ? "bg-ink text-paper hover:bg-ink/90"
                : "bg-transparent text-ink-soft hover:bg-paper-sunk hover:text-ink",
          )}
        >
          {isLoading ? (
            <Square aria-hidden="true" className="size-3.5 fill-current" />
          ) : hasContent ? (
            <ArrowUp aria-hidden="true" className="size-4" />
          ) : (
            <Mic aria-hidden="true" className="size-4" />
          )}
        </button>
      </div>

      {error ? (
        <p id={errorId} className="px-2.5 pb-1 text-xs leading-5 text-clay">
          {error}
        </p>
      ) : null}

      {nearLimit ? (
        <p className="px-2.5 pb-1 text-xs leading-5 text-ink-soft tabular-nums">
          {value.length}/{maxLength}
        </p>
      ) : null}
    </div>
  );
}
