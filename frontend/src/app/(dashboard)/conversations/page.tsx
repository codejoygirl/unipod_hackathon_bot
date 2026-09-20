"use client";

import { ChatThread } from "@/components/chat/chat-thread";
import { ErrorNotice } from "@/components/ui/error-notice";
import { useActiveCommunity } from "@/features/communities/hooks/active-community";

export default function ConversationsPage() {
  const { communityId, communityName, blockedReason, error } = useActiveCommunity();

  return (
    <div className="space-y-6">
      <header>
        <h1 className="font-display text-[1.75rem] leading-tight tracking-[-0.015em]">
          Conversations
        </h1>
        <p className="mt-2 max-w-[64ch] text-sm leading-6 text-ink-soft">
          {communityName
            ? `Answers are drawn only from knowledge published to ${communityName}.`
            : "Choose a community before asking a question."}
        </p>
      </header>

      {error ? <ErrorNotice error={error} title="Could not load your communities" /> : null}

      <ChatThread
        communityIds={communityId ? [communityId] : []}
        scope={communityName ?? "your communities"}
        blockedReason={blockedReason}
      />
    </div>
  );
}
