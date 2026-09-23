"use client";

import { useRouter } from "next/navigation";

import { ChatThread } from "@/components/chat/chat-thread";

export default function ConversationsPage() {
  const router = useRouter();

  return (
    <ChatThread
      conversationId={null}
      onConversationCreated={(id) => router.replace(`/conversations/${id}`)}
    />
  );
}
