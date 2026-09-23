"use client";

import { WebChatProvider } from "@/lib/web-chat/web-chat-context";

/**
 * App-wide client providers (TanStack Query, etc.) are added in Phase 4.
 */
export function Providers({ children }: { children: React.ReactNode }) {
  return <WebChatProvider>{children}</WebChatProvider>;
}
