"use client";

import { QueryClientProvider } from "@tanstack/react-query";
import { useState } from "react";

import { makeQueryClient } from "@/lib/query/query-client";

export function Providers({ children }: { children: React.ReactNode }) {
  // Created once per browser session, never shared across requests on the server.
  const [queryClient] = useState(makeQueryClient);

  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
}
