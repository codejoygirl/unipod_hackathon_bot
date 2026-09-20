"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useForm, useWatch } from "react-hook-form";

import { Button } from "@/components/ui/button";
import { Field, fieldControlProps } from "@/components/ui/field";
import { Textarea } from "@/components/ui/textarea";
import { askSchema, type AskValues } from "@/lib/validation/chat";

const MAX = 2000;

type ChatComposerProps = {
  onAsk: (query: string) => void;
  isPending: boolean;
  /** Set when there is no community to scope the question to. */
  blockedReason?: string | null;
};

export function ChatComposer({ onAsk, isPending, blockedReason }: ChatComposerProps) {
  const form = useForm<AskValues>({
    resolver: zodResolver(askSchema),
    defaultValues: { query: "" },
  });

  // `useWatch` rather than `form.watch`: the React Compiler can memoize around it.
  const query = useWatch({ control: form.control, name: "query" });
  const error = form.formState.errors.query?.message;
  const unavailable = Boolean(blockedReason);

  function submit(values: AskValues) {
    onAsk(values.query);
    form.reset();
  }

  return (
    <form onSubmit={form.handleSubmit(submit)} noValidate className="zak-card rounded-sm p-5">
      <Field controlId="ask-query" label="Your question" error={error}>
        <Textarea
          rows={3}
          placeholder="What do you need to know?"
          {...fieldControlProps("ask-query", error)}
          {...form.register("query")}
          disabled={unavailable || isPending}
        />
      </Field>

      <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
        {/* The submit stays enabled while the form is invalid: hiding it hides the fix. */}
        <p className="text-xs leading-5 text-ink-soft">
          {blockedReason ?? `${query.length}/${MAX}`}
        </p>

        <Button type="submit" variant="primary" disabled={unavailable || isPending}>
          {isPending ? "Asking" : "Ask"}
        </Button>
      </div>
    </form>
  );
}
