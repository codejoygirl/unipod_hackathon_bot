"use client";

import { useQuery } from "@tanstack/react-query";

import { getSession, isAnonymousError, login } from "@/lib/api/auth";
import { queryKeys } from "@/lib/query/keys";
import type { User } from "@/types/api";

/**
 * The app has no sign-in screen: it opens straight into chat. The API still requires a
 * session, so one is established automatically with the seeded demo account
 * (`php artisan zak:seed-assistant-demo`).
 *
 * DEV SHIM — restore a real sign-in flow before this is exposed to anyone else.
 */
const DEMO_EMAIL = "demo@zak.test";
const DEMO_PASSWORD = "password123";

/**
 * `GET /auth/me` answers 401 for a visitor with no session. That is the trigger to start
 * one, not a failure worth showing.
 */
export function useSession() {
  const query = useQuery({
    queryKey: queryKeys.session,
    queryFn: async ({ signal }): Promise<User> => {
      try {
        const response = await getSession(signal);
        return response.data;
      } catch (error) {
        if (!isAnonymousError(error)) throw error;

        try {
          const started = await login({ email: DEMO_EMAIL, password: DEMO_PASSWORD });
          return started.data;
        } catch {
          throw new Error(
            "Could not start a session. Seed the demo account first: php artisan zak:seed-assistant-demo",
          );
        }
      }
    },
  });

  return {
    user: query.data ?? null,
    isLoading: query.isPending,
    error: query.error,
  };
}
