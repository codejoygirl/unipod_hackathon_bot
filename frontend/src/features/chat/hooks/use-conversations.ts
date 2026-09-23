"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  createConversation,
  deleteConversation,
  listConversations,
} from "@/lib/api/conversations";
import { queryKeys } from "@/lib/query/keys";

export function useConversations() {
  return useQuery({
    queryKey: queryKeys.conversations(),
    queryFn: async ({ signal }) => {
      const response = await listConversations(signal);
      return response.data;
    },
  });
}

export function useCreateConversation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (tenantId?: string | null) =>
      createConversation(tenantId).then((response) => response.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.conversationsRoot });
    },
  });
}

export function useDeleteConversation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => deleteConversation(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.conversationsRoot });
    },
  });
}
