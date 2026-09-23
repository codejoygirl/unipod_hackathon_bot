"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";

import { PromptInputBox } from "@/components/ui/ai-prompt-box";
import { useAsk } from "@/features/chat/hooks/use-ask";
import { useSession } from "@/lib/auth/session";

/** Drawn from what the cohort group actually asks, so the examples are recognisable. */
const EXAMPLES = [
  "What is the hackathon deadline?",
  "Send me the session recordings",
  "When is the next live session?",
];

/**
 * Ask box on the landing page: type a question and land in the thread with the answer.
 *
 * It mounts `useSession` deliberately. A visitor with no session would otherwise get a 401,
 * and the session hook is what establishes the demo session automatically.
 */
export function LandingAsk() {
  const router = useRouter();
  const { isLoading: sessionLoading } = useSession();
  const [value, setValue] = useState("");
  const ask = useAsk();

  const busy = sessionLoading || ask.isPending;

  function submit(query: string) {
    const trimmed = query.trim();
    if (trimmed.length === 0 || busy) return;

    ask.mutate(
      { query: trimmed, conversation_id: null },
      {
        onSuccess: (response) =>
          router.push(`/conversations/${response.meta.conversation_id}`),
      },
    );
  }

  return (
    <div className="w-full max-w-xl">
      <form
        onSubmit={(event) => {
          event.preventDefault();
          submit(value);
        }}
        noValidate
      >
        <PromptInputBox
          value={value}
          onValueChange={setValue}
          onSubmit={() => submit(value)}
          isLoading={busy}
          disabled={busy}
          placeholder="Ask about deadlines, meetings or recordings…"
          controlId="landing-ask"
        />
      </form>

      {ask.error ? (
        <p role="alert" className="mt-2.5 text-center text-xs leading-5 text-clay">
          {ask.error instanceof Error ? ask.error.message : "The question could not be sent."}
        </p>
      ) : null}

      <ul className="mt-3 flex flex-wrap justify-center gap-2">
        {EXAMPLES.map((example) => (
          <li key={example}>
            <button
              type="button"
              onClick={() => submit(example)}
              disabled={busy}
              className="rounded-full border border-rule px-3 py-1.5 text-xs leading-5 text-ink-soft transition-colors duration-200 hover:border-accent hover:text-ink disabled:opacity-55"
            >
              {example}
            </button>
          </li>
        ))}
      </ul>
    </div>
  );
}
