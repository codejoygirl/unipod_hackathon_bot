"use client";

import { useMutation } from "@tanstack/react-query";

import { ask } from "@/lib/api/assistant";
import type { AskPayload } from "@/types/api";

export function useAsk() {
  return useMutation({
    mutationFn: (payload: AskPayload) => ask(payload),
  });
}
