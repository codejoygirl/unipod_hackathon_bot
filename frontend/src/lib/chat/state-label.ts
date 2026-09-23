import type { AnswerState } from "@/lib/api/types";

export function stateLabel(state: AnswerState): string {
  switch (state) {
    case "VERIFIED":
      return "Verified";
    case "POSSIBLE":
      return "Possible";
    case "CONFLICT":
      return "Conflict";
    case "INSUFFICIENT_EVIDENCE":
      return "Insufficient evidence";
    case "UNKNOWN":
      return "Unknown";
    case "BLOCKED":
      return "Blocked";
    default:
      return state;
  }
}

export function stateTone(state: AnswerState): string {
  switch (state) {
    case "VERIFIED":
      return "text-emerald-700 bg-emerald-50 ring-emerald-200";
    case "POSSIBLE":
      return "text-amber-800 bg-amber-50 ring-amber-200";
    case "CONFLICT":
      return "text-orange-800 bg-orange-50 ring-orange-200";
    default:
      return "text-zinc-600 bg-zinc-100 ring-zinc-200";
  }
}
