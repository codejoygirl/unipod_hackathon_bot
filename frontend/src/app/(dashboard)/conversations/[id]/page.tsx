"use client";

import { useParams } from "next/navigation";

import { ChatThread } from "@/components/chat/chat-thread";

export default function ConversationThreadPage() {
  const params = useParams<{ id: string }>();
  const conversationId = typeof params?.id === "string" ? params.id : null;

  return <ChatThread conversationId={conversationId} />;
}
