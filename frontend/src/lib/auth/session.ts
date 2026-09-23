"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { getSession, isAnonymousError, login, logout, register } from "@/lib/api/auth";
import { queryKeys } from "@/lib/query/keys";
import type { LoginPayload, RegisterPayload, User } from "@/types/api";

/**
 * `GET /auth/me` answers 401 for a visitor with no session. That is a state, not a
 * failure, so it resolves to `null` rather than erroring.
 */
export function useSession() {
  const query = useQuery({
    queryKey: queryKeys.session,
    queryFn: async ({ signal }): Promise<User | null> => {
      try {
        const response = await getSession(signal);
        return response.data;
      } catch (error) {
        if (isAnonymousError(error)) return null;
        throw error;
      }
    },
  });

  return {
    user: query.data ?? null,
    /** True until the first answer arrives, anonymous or not. */
    isLoading: query.isPending,
    /** True only once we know there is no session. */
    isAnonymous: query.isSuccess && query.data === null,
    error: query.error,
  };
}

export function useLogin() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: LoginPayload) => login(payload).then((response) => response.data),
    onSuccess: (user) => {
      // The login payload is a complete session, including `tenants`.
      queryClient.setQueryData(queryKeys.session, user);
    },
  });
}

export function useRegister() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: RegisterPayload) =>
      register(payload).then((response) => response.data),
    onSuccess: async () => {
      // Register does not return `tenants`, so its payload is not a complete session.
      await queryClient.invalidateQueries({ queryKey: queryKeys.session });
    },
  });
}

export function useLogout() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => logout(),
    onSuccess: () => {
      // Drop every cached resource: it all belonged to the session that just ended.
      queryClient.clear();
      queryClient.invalidateQueries({ queryKey: queryKeys.session });
    },
  });
}
