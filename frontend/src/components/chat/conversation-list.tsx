"use client";

import { Link2, Search, SquarePen, X } from "lucide-react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useMemo, useState } from "react";

import { ThemeToggle } from "@/components/landing/theme-toggle";
import { Brand } from "@/components/layout/brand";
import { ErrorNotice } from "@/components/ui/error-notice";
import { Skeleton } from "@/components/ui/skeleton";
import {
  useConversations,
  useDeleteConversation,
} from "@/features/chat/hooks/use-conversations";
import { useSession } from "@/lib/auth/session";
import { cn } from "@/lib/utils";
import type { ConversationSummary } from "@/types/api";

const ICON_BUTTON =
  "flex size-8 shrink-0 items-center justify-center rounded-lg text-ink-soft transition-colors duration-200 hover:bg-paper-sunk hover:text-ink";

const ROW =
  "flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm transition-colors duration-200";

function labelOf(conversation: ConversationSummary): string {
  const title = conversation.title?.trim();
  return title && title.length > 0 ? title : "New chat";
}

function initialsOf(name: string): string {
  const parts = name.trim().split(/\s+/).slice(0, 2);
  const initials = parts.map((part) => part.charAt(0).toUpperCase()).join("");
  return initials.length > 0 ? initials : "?";
}

/**
 * The chat history rail. Follows the ChatGPT sidebar shape — brand and icon actions in the
 * header, a "Recents" group, one icon per thread, and the signed-in member pinned at the
 * bottom — rendered with this app's tokens so it holds up in both themes.
 *
 * `onNavigate` closes the mobile drawer when a thread is opened from it.
 */
export function ConversationList({ onNavigate }: { onNavigate?: () => void }) {
  const conversations = useConversations();
  const remove = useDeleteConversation();
  const { user } = useSession();
  const router = useRouter();
  const pathname = usePathname();
  const [searchOpen, setSearchOpen] = useState(false);
  const [filter, setFilter] = useState("");

  const rows = useMemo(() => {
    const all = conversations.data ?? [];
    const needle = filter.trim().toLowerCase();
    if (needle.length === 0) return all;

    return all.filter((conversation) => labelOf(conversation).toLowerCase().includes(needle));
  }, [conversations.data, filter]);

  function handleDelete(id: string) {
    remove.mutate(id, {
      onSuccess: () => {
        // Leaving a deleted thread open would 404 on the next refetch.
        if (pathname === `/conversations/${id}`) router.push("/conversations");
      },
    });
  }

  const isNewChat = pathname === "/conversations";

  return (
    <aside aria-label="Chat history" className="flex h-full min-h-0 flex-col">
      <div className="flex shrink-0 items-center justify-between gap-2 px-3 pt-3 pb-2">
        <Brand />

        <div className="flex items-center gap-0.5">
          <Link href="/resources" onClick={onNavigate} aria-label="Resources" className={ICON_BUTTON}>
            <Link2 aria-hidden="true" className="size-4" />
          </Link>

          <button
            type="button"
            aria-label={searchOpen ? "Close search" : "Search chats"}
            aria-expanded={searchOpen}
            onClick={() => {
              setSearchOpen((open) => !open);
              if (searchOpen) setFilter("");
            }}
            className={ICON_BUTTON}
          >
            <Search aria-hidden="true" className="size-4" />
          </button>

          <Link href="/conversations" onClick={onNavigate} aria-label="New chat" className={ICON_BUTTON}>
            <SquarePen aria-hidden="true" className="size-4" />
          </Link>
        </div>
      </div>

      {searchOpen ? (
        <div className="shrink-0 px-3 pb-2">
          <input
            type="search"
            value={filter}
            onChange={(event) => setFilter(event.target.value)}
            placeholder="Search chats"
            aria-label="Search chats"
            className="min-h-9 w-full rounded-lg border border-rule bg-paper-sunk px-2.5 text-sm text-ink placeholder:text-ink-soft/70 focus:border-accent focus:outline-none"
          />
        </div>
      ) : null}

      <div className="shrink-0 px-2">
        <Link
          href="/conversations"
          onClick={onNavigate}
          aria-current={isNewChat ? "page" : undefined}
          className={cn(
            ROW,
            isNewChat ? "bg-paper-sunk font-medium text-ink" : "text-ink hover:bg-paper-sunk",
          )}
        >
          <SquarePen aria-hidden="true" className="size-4 shrink-0" />
          New chat
        </Link>
      </div>

      <div className="zak-scroll min-h-0 flex-1 overflow-y-auto px-2 pt-4 pb-3">
        <p className="zak-label px-2.5 pb-1 text-ink-soft">Recents</p>

        {conversations.isPending ? (
          <div className="space-y-2 px-2.5 py-1">
            <Skeleton className="h-4 w-4/5" />
            <Skeleton className="h-4 w-3/5" />
            <Skeleton className="h-4 w-2/3" />
          </div>
        ) : null}

        {conversations.error ? (
          <div className="px-1 py-1">
            <ErrorNotice error={conversations.error} title="Could not load your chats" />
          </div>
        ) : null}

        {!conversations.isPending && !conversations.error && rows.length === 0 ? (
          <p className="px-2.5 py-1 text-xs leading-5 text-ink-soft">
            {filter.trim().length > 0 ? "No chats match." : "No chats yet."}
          </p>
        ) : null}

        <ul className="space-y-0.5">
          {rows.map((conversation) => {
            const href = `/conversations/${conversation.id}`;
            const active = pathname === href;
            const label = labelOf(conversation);

            return (
              <li key={conversation.id} className="group relative">
                <Link
                  href={href}
                  onClick={onNavigate}
                  aria-current={active ? "page" : undefined}
                  className={cn(
                    ROW,
                    "pr-9",
                    active
                      ? "bg-paper-sunk font-medium text-ink"
                      : "text-ink-soft hover:bg-paper-sunk hover:text-ink",
                  )}
                >
                  <span className="truncate">{label}</span>
                </Link>

                <button
                  type="button"
                  onClick={() => handleDelete(conversation.id)}
                  aria-label={`Delete chat: ${label}`}
                  className={cn(
                    "absolute top-1/2 right-1.5 -translate-y-1/2 rounded-md p-1 text-ink-soft transition-opacity duration-200 hover:text-clay focus-visible:opacity-100",
                    active ? "opacity-70" : "opacity-0 group-hover:opacity-70",
                  )}
                >
                  <X aria-hidden="true" className="size-3.5" />
                </button>
              </li>
            );
          })}
        </ul>
      </div>

      <div className="shrink-0 p-2">
        <div className="flex items-center gap-2.5 rounded-lg px-2 py-1.5">
          <span
            aria-hidden="true"
            className="flex size-8 shrink-0 items-center justify-center rounded-full bg-accent text-xs font-semibold text-paper"
          >
            {initialsOf(user?.name ?? "")}
          </span>

          <div className="min-w-0 flex-1">
            <p className="truncate text-sm leading-5 text-ink">{user?.name ?? "Signed in"}</p>
            <p className="truncate text-xs leading-4 text-ink-soft">{user?.email ?? ""}</p>
          </div>

          <ThemeToggle className={ICON_BUTTON} />
        </div>
      </div>
    </aside>
  );
}
