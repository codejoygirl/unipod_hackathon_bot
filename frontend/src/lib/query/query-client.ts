import { QueryClient } from "@tanstack/react-query";

/**
 * PRD §12.3 asks for polling on some surfaces and requires it to pause when the tab is
 * hidden. The default here is therefore no polling at all, with
 * `refetchIntervalInBackground: false` so any interval added later inherits that pause.
 *
 * `assistant/ask` is a synchronous request, so nothing polls today.
 */
export function makeQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        retry: 1,
        refetchOnWindowFocus: false,
        refetchIntervalInBackground: false,
      },
      mutations: {
        retry: 0,
      },
    },
  });
}
