import { Badge } from "@/components/ui/badge";
import { answerPresentation } from "./answer-state";
import { cn } from "@/lib/utils";
import type { AnswerState } from "@/types/api";

export function AnswerStatus({ state, className }: { state: AnswerState; className?: string }) {
  const presentation = answerPresentation(state);

  return (
    <Badge tone={presentation.tone} className={cn("font-mono", className)}>
      {presentation.label}
    </Badge>
  );
}
