import type { AssistantAskResponse } from "@/lib/api/types";

export type QuotedMessage = {
  id: string;
  sender: string;
  text: string;
};

export type StoredChatEntry =
  | {
      id: string;
      role: "user";
      text: string;
      sentAt: string;
      quote?: QuotedMessage;
      imagePreview?: string;
      imagePreviews?: string[];
    }
  | {
      id: string;
      role: "assistant";
      response: AssistantAskResponse;
      sentAt: string;
      reaction?: "up" | "down" | null;
      quote?: QuotedMessage;
    }
  | { id: string; role: "error"; text: string; sentAt: string };

function storageKey(communityId: string, sessionId: string): string {
  return `zak-web-chat:${communityId}:${sessionId}`;
}

export function loadChatHistory(
  communityId: string,
  sessionId: string,
): StoredChatEntry[] {
  if (typeof window === "undefined") {
    return [];
  }
  try {
    const raw = window.localStorage.getItem(storageKey(communityId, sessionId));
    if (!raw) {
      return [];
    }
    const parsed = JSON.parse(raw) as unknown;
    return Array.isArray(parsed) ? (parsed as StoredChatEntry[]) : [];
  } catch {
    return [];
  }
}

export function saveChatHistory(
  communityId: string,
  sessionId: string,
  entries: StoredChatEntry[],
): void {
  if (typeof window === "undefined") {
    return;
  }
  try {
    window.localStorage.setItem(storageKey(communityId, sessionId), JSON.stringify(entries));
  } catch {
    /* quota or private mode */
  }
}
