"use client";

import { ChatHeader } from "@/components/chat/chat-header";
import { usePageSize } from "@/lib/ui/use-page-size";
import { useEffect, useMemo, useState } from "react";

type IntegrationStatus = "connected" | "soon";

type Integration = {
  id: string;
  name: string;
  description: string;
  status: IntegrationStatus;
};

/** Relevant connectors for a cohort assistant — one scrollable page, not a marketplace. */
const INTEGRATIONS: Integration[] = [
  {
    id: "whatsapp",
    name: "WhatsApp",
    description: "Live channel for members and admin commands.",
    status: "connected",
  },
  {
    id: "telegram",
    name: "Telegram",
    description: "Live bot channel with the same community knowledge.",
    status: "connected",
  },
  { id: "gmail", name: "Gmail", description: "Surface deadlines and programme mail in chat.", status: "soon" },
  { id: "calendar", name: "Google Calendar", description: "Sync cohort events and session reminders.", status: "soon" },
  { id: "drive", name: "Google Drive", description: "Auto-index shared folders and docs.", status: "soon" },
  { id: "meet", name: "Google Meet", description: "Import Meet links into the Meetings hub.", status: "soon" },
  { id: "teams", name: "Microsoft Teams", description: "Pull Teams rooms and channel updates.", status: "soon" },
  { id: "outlook", name: "Outlook Calendar", description: "Sync office hours and mentor bookings.", status: "soon" },
  { id: "onedrive", name: "OneDrive / SharePoint", description: "Index UNDP / UniPod shared libraries.", status: "soon" },
  { id: "zoom", name: "Zoom", description: "Import recurring Zoom rooms and recordings.", status: "soon" },
  { id: "slack", name: "Slack", description: "Post community updates to workspace channels.", status: "soon" },
  { id: "discord", name: "Discord", description: "Mirror announcements into cohort servers.", status: "soon" },
  { id: "notion", name: "Notion", description: "Ground answers in programme wikis and SOPs.", status: "soon" },
  { id: "airtable", name: "Airtable", description: "Read cohort rosters and submission tables.", status: "soon" },
  { id: "typeform", name: "Typeform / Forms", description: "Track signup and feedback forms.", status: "soon" },
  { id: "youtube", name: "YouTube", description: "Surface recorded sessions and playlists.", status: "soon" },
  { id: "github", name: "GitHub", description: "Link venture repos and hackathon projects.", status: "soon" },
  { id: "figma", name: "Figma", description: "Share design files from sprint workshops.", status: "soon" },
];

function IntegrationGlyph({ id }: { id: string }) {
  const common = "stroke-current";
  switch (id) {
    case "gmail":
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden className={common}>
          <path d="M4 6h16v12H4V6Zm0 0 8 6 8-6" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      );
    case "calendar":
    case "outlook":
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden className={common}>
          <rect x="3" y="5" width="18" height="16" rx="2" strokeWidth="1.75" />
          <path d="M3 9h18M8 3v4M16 3v4" strokeWidth="1.75" strokeLinecap="round" />
        </svg>
      );
    case "drive":
    case "onedrive":
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden className={common}>
          <path d="M9 3h6l6 10H15L9 3Zm-2 4 6 10H1l6-10Zm8 10 3 5H4l3-5h8Z" strokeWidth="1.5" strokeLinejoin="round" />
        </svg>
      );
    case "meet":
    case "zoom":
    case "teams":
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden className={common}>
          <rect x="2" y="7" width="14" height="10" rx="2" strokeWidth="1.75" />
          <path d="M16 10l5-2v8l-5-2v-4Z" strokeWidth="1.75" strokeLinejoin="round" />
        </svg>
      );
    case "whatsapp":
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden className={common}>
          <path d="M21 11.5a8.5 8.5 0 0 1-12.8 7.3L3 21l2.2-5.1A8.5 8.5 0 1 1 21 11.5Z" strokeWidth="1.75" strokeLinejoin="round" />
        </svg>
      );
    case "telegram":
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden className={common}>
          <path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7Z" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      );
    case "slack":
    case "discord":
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden className={common}>
          <path d="M8 12V6a2 2 0 1 1 4 0v6a2 2 0 1 1-4 0Zm8 0v6a2 2 0 1 1-4 0v-6a2 2 0 1 1 4 0Z" strokeWidth="1.5" />
        </svg>
      );
    case "github":
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden className={common}>
          <path d="M9 19c-4.3 1.4-4.3-2.1-6-2.5M15 22v-3.9a3.4 3.4 0 0 0-1-2.6c3.2-.4 6.5-1.6 6.5-7A5.4 5.4 0 0 0 19 4.6 5 5 0 0 0 18.8 1S17.5.7 15 2.5a11 11 0 0 0-6 0C6.5.7 5.2 1 5.2 1A5 5 0 0 0 5 4.6 5.4 5.4 0 0 0 3.5 8.5c0 5.4 3.3 6.6 6.5 7a3.4 3.4 0 0 0-1 2.6V22" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      );
    case "youtube":
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden className={common}>
          <rect x="2" y="6" width="20" height="12" rx="3" strokeWidth="1.75" />
          <path d="M10 9.5v5l5-2.5-5-2.5Z" strokeWidth="1.5" strokeLinejoin="round" />
        </svg>
      );
    case "figma":
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden className={common}>
          <path d="M8 3h4v6H8a3 3 0 0 1 0-6Zm4 0h4a3 3 0 0 1 0 6h-4V3Zm0 6H8a3 3 0 0 0 0 6h4V9Zm0 6H8a3 3 0 1 0 3 3v-3Zm4-6a3 3 0 1 1 0 6 3 3 0 0 1 0-6Z" strokeWidth="1.35" strokeLinejoin="round" />
        </svg>
      );
    default:
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden className={common}>
          <path d="M12 22v-5M9 8V2M15 8V2M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      );
  }
}

export default function IntegrationsPage() {
  const [currentPage, setCurrentPage] = useState(1);
  const pageSize = usePageSize();
  const totalPages = Math.max(1, Math.ceil(INTEGRATIONS.length / pageSize));
  const paginated = useMemo(() => {
    const start = (currentPage - 1) * pageSize;
    return INTEGRATIONS.slice(start, start + pageSize);
  }, [currentPage, pageSize]);

  useEffect(() => {
    if (currentPage > totalPages) setCurrentPage(totalPages);
  }, [currentPage, totalPages]);

  return (
    <div className="flex flex-1 flex-col h-full min-w-0 overflow-hidden relative bg-white dark:bg-[#0d0d0d] text-zinc-900 dark:text-zinc-100">
      <ChatHeader />

      <div className="flex-1 overflow-y-auto px-4 py-5 sm:px-6 md:px-8">
        <div className="mx-auto max-w-xl space-y-5">
          <div className="border-b border-zinc-200/80 pb-4 dark:border-zinc-800/80">
            <h1 className="text-xl sm:text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">
              Integrations
            </h1>
            <p className="mt-1 text-xs sm:text-sm text-zinc-500 dark:text-zinc-400">
              WhatsApp and Telegram are live for the community. Other connectors are queued for later.
            </p>
          </div>

          <ul className="flex flex-col gap-2.5 max-w-xl">
            {paginated.map((item) => {
              const connected = item.status === "connected";
              return (
                <li
                  key={item.id}
                  className={`flex items-center gap-3 rounded-xl border px-3 py-2.5 ${
                    connected
                      ? "border-blue-200/80 bg-blue-50/40 dark:border-blue-900/50 dark:bg-blue-950/20"
                      : "border-zinc-200/80 bg-zinc-50/40 opacity-70 dark:border-zinc-800 dark:bg-zinc-900/40"
                  }`}
                  aria-disabled="true"
                >
                  <div
                    className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg shadow-2xs ${
                      connected
                        ? item.id === "whatsapp"
                          ? "bg-white text-[#25D366] dark:bg-zinc-900"
                          : "bg-white text-sky-500 dark:bg-zinc-900"
                        : "bg-white text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400"
                    }`}
                  >
                    <IntegrationGlyph id={item.id} />
                  </div>
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-1.5 min-w-0">
                      <h2 className="truncate text-xs font-semibold text-zinc-800 dark:text-zinc-200">{item.name}</h2>
                      {connected ? (
                        <span className="shrink-0 rounded-full bg-blue-100 px-1.5 py-0.5 text-[8px] font-semibold text-blue-800 dark:bg-blue-950 dark:text-blue-300">
                          Connected
                        </span>
                      ) : (
                        <span className="shrink-0 rounded border border-zinc-200/80 px-1 py-0.5 text-[8px] font-medium text-zinc-400 dark:border-zinc-700 dark:text-zinc-500">
                          Soon
                        </span>
                      )}
                    </div>
                    <p className="mt-0.5 text-[11px] leading-snug text-zinc-500 dark:text-zinc-400 line-clamp-1">
                      {item.description}
                    </p>
                  </div>
                  <button
                    type="button"
                    disabled
                    className="shrink-0 rounded-md border border-zinc-200 bg-white px-2 py-1 text-[10px] font-medium text-zinc-400 cursor-not-allowed dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-500"
                  >
                    {connected ? "Manage" : "Connect"}
                  </button>
                </li>
              );
            })}
          </ul>

          {totalPages > 1 ? (
            <div className="flex flex-col items-center justify-between gap-3 border-t border-zinc-200/80 pt-3 sm:flex-row dark:border-zinc-800/80">
              <span className="text-xs font-medium text-zinc-500 dark:text-zinc-400">
                Showing {(currentPage - 1) * pageSize + 1}–
                {Math.min(currentPage * pageSize, INTEGRATIONS.length)} of {INTEGRATIONS.length} integrations
              </span>
              <div className="flex items-center gap-1.5">
                <button
                  type="button"
                  disabled={currentPage <= 1}
                  onClick={() => setCurrentPage((page) => Math.max(1, page - 1))}
                  className="inline-flex items-center gap-1 rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-xs font-medium text-zinc-700 shadow-2xs transition hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-zinc-800 dark:bg-[#1a1a1a] dark:text-zinc-300 dark:hover:bg-zinc-800 cursor-pointer"
                >
                  ‹ Previous
                </button>
                {Array.from({ length: totalPages }, (_, i) => i + 1).map((pageNum) => (
                  <button
                    key={pageNum}
                    type="button"
                    onClick={() => setCurrentPage(pageNum)}
                    className={`h-7 w-7 rounded-lg text-xs font-semibold transition cursor-pointer ${
                      currentPage === pageNum
                        ? "bg-zinc-900 text-white shadow-2xs dark:bg-zinc-100 dark:text-zinc-900"
                        : "border border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-[#1a1a1a] dark:text-zinc-400 dark:hover:bg-zinc-800"
                    }`}
                  >
                    {pageNum}
                  </button>
                ))}
                <button
                  type="button"
                  disabled={currentPage >= totalPages}
                  onClick={() => setCurrentPage((page) => Math.min(totalPages, page + 1))}
                  className="inline-flex items-center gap-1 rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-xs font-medium text-zinc-700 shadow-2xs transition hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-zinc-800 dark:bg-[#1a1a1a] dark:text-zinc-300 dark:hover:bg-zinc-800 cursor-pointer"
                >
                  Next ›
                </button>
              </div>
            </div>
          ) : null}
        </div>
      </div>
    </div>
  );
}
