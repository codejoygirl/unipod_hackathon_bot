"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Controller, useForm } from "react-hook-form";

import { PromptInputBox } from "@/components/ui/ai-prompt-box";
import { fieldMessageId } from "@/components/ui/field";
import { askSchema, type AskValues } from "@/lib/validation/chat";

const MAX = 2000;
const CONTROL_ID = "ask-query";

type ChatComposerProps = {
  onAsk: (query: string) => void;
  isPending: boolean;
  /**
   * Set only when the member has no communities at all, so the API would refuse the
   * question. Picking a community is not required, so this is null in the normal case.
   */
  blockedReason?: string | null;
};

/**
 * The pinned bottom input. Presentation lives in `PromptInputBox`; this owns the form and
 * its validation, and hands the finished question to the thread.
 */
export function ChatComposer({ onAsk, isPending, blockedReason }: ChatComposerProps) {
  const form = useForm<AskValues>({
    resolver: zodResolver(askSchema),
    defaultValues: { query: "" },
  });

  const handleSubmit = form.handleSubmit((values) => {
    onAsk(values.query);
    form.reset();
  });

  return (
    <form onSubmit={handleSubmit} noValidate>
      <Controller
        control={form.control}
        name="query"
        render={({ field, fieldState }) => (
          <PromptInputBox
            value={field.value}
            onValueChange={field.onChange}
            onSubmit={handleSubmit}
            isLoading={isPending}
            disabled={Boolean(blockedReason)}
            placeholder="Ask Zak anything…"
            error={fieldState.error?.message ?? blockedReason ?? undefined}
            controlId={CONTROL_ID}
            errorId={fieldMessageId(CONTROL_ID)}
            maxLength={MAX}
          />
        )}
      />
    </form>
  );
}
