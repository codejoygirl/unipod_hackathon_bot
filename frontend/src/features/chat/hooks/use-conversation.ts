"use client";

import { useQuery } from "@tanstack/react-query";

import { getConversation } from "@/lib/api/conversations";
import { queryKeys } from "@/lib/query/keys";

/** One thread with its messages, or nothing when no thread is selected. */
export function useConversation(id: string | null) {
  return useQuery({
    queryKey: queryKeys.conversation(id ?? "none"),
    enabled: Boolean(id),
    queryFn: async ({ signal }) => {
      if (!id) return null;
      const response = await getConversation(id, signal);
      return response.data;
    },
  });
}
