import type { ReactNode } from "react";

import { ChatLayout } from "@/components/chat/chat-layout";

export default function ConversationsLayout({ children }: { children: ReactNode }) {
  return <ChatLayout>{children}</ChatLayout>;
}
