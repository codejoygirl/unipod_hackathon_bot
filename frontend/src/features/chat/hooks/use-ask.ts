"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";

import { ask } from "@/lib/api/assistant";
import { queryKeys } from "@/lib/query/keys";
import type { AskPayload } from "@/types/api";

export function useAsk() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: AskPayload) => ask(payload),
    onSuccess: (_response, payload) => {
      // A new thread has to appear in the sidebar; an existing one moves to the top.
      queryClient.invalidateQueries({ queryKey: queryKeys.conversationsRoot });
      if (payload.conversation_id) {
        queryClient.invalidateQueries({
          queryKey: queryKeys.conversation(payload.conversation_id),
        });
      }
    },
  });
}
