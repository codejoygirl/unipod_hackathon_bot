export interface BotCommandItem {
  command: string;
  category: "member" | "admin";
  description: string;
  example: string;
  badge?: string;
}

export const MEMBER_COMMANDS: BotCommandItem[] = [
  {
    command: "/ask",
    category: "member",
    description: "Ask about schedules, links, or updates",
    example: "/ask When is the next session?",
    badge: "Popular",
  },
  {
    command: "/share",
    category: "member",
    description: "Share a tip with the community (admin reviews first)",
    example: "/share Clinic moved to 3pm tomorrow",
  },
  {
    command: "/feature",
    category: "member",
    description: "Request a feature or suggest an improvement",
    example: "/feature Remind me a day before deadlines",
  },
  {
    command: "/help",
    category: "member",
    description: "Show this interactive command guide again",
    example: "/help",
  },
];

export const ADMIN_COMMANDS: BotCommandItem[] = [
  {
    command: "/import",
    category: "admin",
    description: "Paste a chat export to create a draft",
    example: "/import [paste the WhatsApp/Telegram export text]",
  },
  {
    command: "/publish",
    category: "admin",
    description: "Make a draft live for members",
    example: "/publish ABC123",
  },
  {
    command: "/knowledge",
    category: "admin",
    description: "See drafts and published knowledge",
    example: "/knowledge",
  },
  {
    command: "/asset",
    category: "admin",
    description: "Add a Drive file link for members",
    example: "/asset handbook UniPods Handbook drive.google.com/file/d/YOUR_FILE_ID/view",
  },
  {
    command: "/features",
    category: "admin",
    description: "List open or decided feature requests",
    example: "/features open",
  },
  {
    command: "/approve",
    category: "admin",
    description: "Approve a share or feature by Request ID",
    example: "/approve H7G74Y",
  },
  {
    command: "/decline",
    category: "admin",
    description: "Decline a share or feature by Request ID",
    example: "/decline H7G74Y",
  },
  {
    command: "/reply",
    category: "admin",
    description: "Answer an escalated member question",
    example: "/reply H7G74Y The session is at 4pm",
  },
  {
    command: "/logins",
    category: "admin",
    description: "View admin phones and web login passwords",
    example: "/logins",
  },
];

export const ALL_COMMANDS: BotCommandItem[] = [
  ...MEMBER_COMMANDS,
  ...ADMIN_COMMANDS,
];

/**
 * Dispatches an event across the window to insert and auto-populate text
 * into the chat composer input, immediately focusing it for editing.
 */
export function insertChatCommand(text: string) {
  if (typeof window !== "undefined") {
    window.dispatchEvent(
      new CustomEvent("insert-chat-command", { detail: { command: text } })
    );
  }
}
