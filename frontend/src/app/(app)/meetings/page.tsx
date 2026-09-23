"use client";

import { ChatHeader } from "@/components/chat/chat-header";
import { useWebChat } from "@/lib/web-chat/web-chat-context";
import { useMemo, useState, useEffect } from "react";

export interface CommunityMeeting {
  id: string;
  title: string;
  category: "weekly_sync" | "office_hours" | "workshop" | "hackathon" | "recap";
  platform: "teams" | "meet" | "zoom";
  platformName: string;
  url: string;
  schedule: string;
  timeContext: string;
  host: string;
  description: string;
  meetingId?: string;
  passcode?: string;
  isLiveNow?: boolean;
}

const COMMUNITY_MEETINGS: CommunityMeeting[] = [
  {
    id: "meti-teams-open-hour",
    title: "METI Open Hour: 'Ask Us Anything'",
    category: "office_hours",
    platform: "teams",
    platformName: "Microsoft Teams",
    url: "https://teams.microsoft.com/l/meetup-join/19%3ameeting_MjlkNWYyMjYtMGNhMi00NDM1LTlkNmYtOTZhYTU2MDU4MDc2%40thread.v2/0?context=%7B%22Tid%22%3A%22b3e5db5e-2944-4837-99f5-7488ace54319%22%2C%22Oid%22%3A%2225f213f2-0e2f-4763-83fa-0d909a0e9701%22%7D",
    schedule: "Every Friday at 1:00 PM GMT",
    timeContext: "2:00 PM WAT / 3:00 PM CAT / 4:00 PM EAT",
    host: "Diane & METI Programme Team",
    description: "Weekly open office hour to ask anything about your venture, technical bottlenecks, prototype feedback, and cohort progress.",
    isLiveNow: false,
  },
  {
    id: "weekly-cohort-sync",
    title: "Weekly Cohort Sync & Demo Standup",
    category: "weekly_sync",
    platform: "meet",
    platformName: "Google Meet",
    url: "https://meet.google.com/unipod-cohort-sync",
    schedule: "Tuesdays & Thursdays at 2:00 PM GMT",
    timeContext: "3:00 PM WAT / 4:00 PM CAT / 5:00 PM EAT",
    host: "Programme Coordinators & Leads",
    description: "Live demonstration of sprint deliverables, peer feedback, milestone tracking, and cross-team collaboration.",
    isLiveNow: false,
  },
  {
    id: "mentor-tech-office-hours",
    title: "Mentor Technical Office Hours: AI Architecture & Systems",
    category: "office_hours",
    platform: "zoom",
    platformName: "Zoom",
    url: "https://zoom.us/j/92485710294",
    schedule: "Wednesdays at 11:00 AM GMT",
    timeContext: "12:00 PM WAT / 1:00 PM CAT / 2:00 PM EAT",
    host: "Technical Lead Mentors",
    description: "One-on-one and breakout architectural reviews: LLM integration, RAG pipelines, API scalability, and cloud deployments.",
    meetingId: "924 8571 0294",
    passcode: "UNIPOD2026",
    isLiveNow: false,
  },
  {
    id: "wadhwani-masterclass",
    title: "Wadhwani AI & Entrepreneurship Masterclass",
    category: "workshop",
    platform: "teams",
    platformName: "Microsoft Teams",
    url: "https://teams.microsoft.com/l/meetup-join/19%3ameeting_ZjI1YWZjNGMtNmFmNy00NTFlLWE4MGYtNTBjOGU4NmYyMmZi%40thread.v2/0?context=%7b%22Tid%22%3a%22b3e5db5e-2944-4837-99f5-7488ace54319%22%2c%22Oid%22%3a%22d853f98a-ba60-4bfb-9368-8960661d364c%22%7d",
    schedule: "Bi-weekly Mondays at 12:00 PM GMT",
    timeContext: "1:00 PM WAT / 2:00 PM CAT / 3:00 PM EAT",
    host: "Wadhwani Ignite Faculty",
    description: "Deep-dive workshops into customer discovery, value proposition design, unit economics, and market validation.",
    isLiveNow: false,
  },
  {
    id: "hackathon-pitch-clinic",
    title: "Hackathon Pitch Clinic & Rehearsal",
    category: "hackathon",
    platform: "meet",
    platformName: "Google Meet",
    url: "https://meet.google.com/unipod-pitch-clinic",
    schedule: "Fridays before deadlines at 4:00 PM GMT",
    timeContext: "5:00 PM WAT / 6:00 PM CAT / 7:00 PM EAT",
    host: "Pitch Coaches & Venture Leads",
    description: "3-minute pitch rehearsals, judge rubrics, slide deck feedback, and live Q&A preparation.",
    isLiveNow: false,
  },
  {
    id: "onboarding-call-recap",
    title: "Cohort Onboarding & Orientation Call (Recording & Recap)",
    category: "recap",
    platform: "teams",
    platformName: "Microsoft Teams Recording",
    url: "https://teams.microsoft.com/l/meetingrecap?driveId=b!iplkOk-dvkG7vyuOBdx8vLVOz3cMsFpEnyRP0GRcQyvzewveGMTPRKYE4O9F5hn3&driveItemId=01TOJP4VA2L5YYQHQVMREZCT27V3WYIGJ2&sitePath=https://undp-my.sharepoint.com/:v:/g/personal/munira_umugwaneza_undp_org/IQAaX3GIHhVkSZFPX67thBk6AUp7P_OnFnwhNrWzl9Q0Efs&fileUrl=https://undp-my.sharepoint.com/personal/munira_umugwaneza_undp_org/Documents/Recordings/MIT+Universal+AI+Welcome+and+onboarding+Call-20260916_140218-Meeting+Recording.mp4?web=1",
    schedule: "On-demand Recording",
    timeContext: "Available 24/7 for all cohort members",
    host: "Munira Umugwaneza (UNDP / UniPod)",
    description: "Official welcome, programme roadmap, expectations, and mentorship guidelines for Cohort 4.",
    isLiveNow: false,
  },
  {
    id: "design-sprint-workshop",
    title: "Design Sprint & Rapid Prototyping Workshop",
    category: "workshop",
    platform: "meet",
    platformName: "Google Meet",
    url: "https://meet.google.com/unipod-prototyping",
    schedule: "Thursdays at 11:00 AM GMT",
    timeContext: "12:00 PM WAT / 1:00 PM CAT / 2:00 PM EAT",
    host: "UniPod Technical Innovation Leads",
    description: "Hands-on engineering workshops covering Figma to code, hardware-software integration, and rapid MVP testing.",
    isLiveNow: false,
  },
  {
    id: "founder-fireside-scaling",
    title: "Founder Fireside: Scaling AI Ventures in Africa",
    category: "office_hours",
    platform: "zoom",
    platformName: "Zoom",
    url: "https://zoom.us/j/93821094821",
    schedule: "First Tuesday of the Month at 3:00 PM GMT",
    timeContext: "4:00 PM WAT / 5:00 PM CAT / 6:00 PM EAT",
    host: "Guest Venture Capitalists & AI Founders",
    description: "Candid founder conversations on navigating regional regulation, enterprise sales, fundraising, and talent acquisition.",
    meetingId: "938 2109 4821",
    passcode: "UNIPOD2026",
    isLiveNow: false,
  },
];

type MeetingCategoryFilter = "all" | "weekly_sync" | "office_hours" | "workshop" | "hackathon" | "recap";

export default function MeetingsPage() {
  const { community } = useWebChat();
  const [loading, setLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState("");
  const [activeCategory, setActiveCategory] = useState<MeetingCategoryFilter>("all");
  const [copiedId, setCopiedId] = useState<string | null>(null);
  const [currentPage, setCurrentPage] = useState(1);
  const [viewMode, setViewMode] = useState<"grid" | "table">("grid");
  const ITEMS_PER_PAGE = 4;

  useEffect(() => {
    const timer = setTimeout(() => {
      setLoading(false);
    }, 280);
    return () => clearTimeout(timer);
  }, []);

  const handleSearchChange = (val: string) => {
    setSearchQuery(val);
    setCurrentPage(1);
  };

  const handleCategoryChange = (cat: MeetingCategoryFilter) => {
    setActiveCategory(cat);
    setCurrentPage(1);
  };

  const filteredMeetings = useMemo(() => {
    return COMMUNITY_MEETINGS.filter((m) => {
      const matchesCategory = activeCategory === "all" || m.category === activeCategory;
      if (!matchesCategory) return false;

      if (!searchQuery.trim()) return true;
      const q = searchQuery.toLowerCase();
      return (
        m.title.toLowerCase().includes(q) ||
        m.description.toLowerCase().includes(q) ||
        m.host.toLowerCase().includes(q) ||
        m.platformName.toLowerCase().includes(q) ||
        m.schedule.toLowerCase().includes(q)
      );
    });
  }, [activeCategory, searchQuery]);

  const totalPages = Math.max(1, Math.ceil(filteredMeetings.length / ITEMS_PER_PAGE));
  const paginatedMeetings = useMemo(() => {
    const start = (currentPage - 1) * ITEMS_PER_PAGE;
    return filteredMeetings.slice(start, start + ITEMS_PER_PAGE);
  }, [filteredMeetings, currentPage]);

  const handleCopyLink = async (meeting: CommunityMeeting) => {
    try {
      await navigator.clipboard.writeText(meeting.url);
      setCopiedId(meeting.id);
      setTimeout(() => setCopiedId(null), 2000);
    } catch {
      // fallback
    }
  };

  const categories: { label: string; value: MeetingCategoryFilter; count: number }[] = [
    { label: "All Sessions", value: "all", count: COMMUNITY_MEETINGS.length },
    {
      label: "Weekly Syncs",
      value: "weekly_sync",
      count: COMMUNITY_MEETINGS.filter((m) => m.category === "weekly_sync").length,
    },
    {
      label: "Office Hours",
      value: "office_hours",
      count: COMMUNITY_MEETINGS.filter((m) => m.category === "office_hours").length,
    },
    {
      label: "Workshops",
      value: "workshop",
      count: COMMUNITY_MEETINGS.filter((m) => m.category === "workshop").length,
    },
    {
      label: "Hackathon",
      value: "hackathon",
      count: COMMUNITY_MEETINGS.filter((m) => m.category === "hackathon").length,
    },
    {
      label: "Replays & Recaps",
      value: "recap",
      count: COMMUNITY_MEETINGS.filter((m) => m.category === "recap").length,
    },
  ];

  return (
    <div className="flex flex-1 flex-col h-full min-w-0 overflow-hidden relative bg-white dark:bg-[#0d0d0d] text-zinc-900 dark:text-zinc-100">
      {/* Top Header */}
      <ChatHeader />

      {/* Main Content Area */}
      <div className="flex-1 overflow-y-auto px-4 py-5 sm:px-6 md:px-8">
        <div className="mx-auto max-w-5xl space-y-6">
          {/* Page Banner & Headline */}
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-b border-zinc-200/80 pb-5 dark:border-zinc-800/80">
            <div>
              <div className="flex items-center gap-2">
                <h1 className="text-xl sm:text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">
                  {community?.name ? `${community.name} Meetings` : "Meetings & Live Sessions"}
                </h1>
                <span className="inline-flex items-center rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300">
                  Active
                </span>
              </div>
              <p className="mt-1 text-xs sm:text-sm text-zinc-500 dark:text-zinc-400">
                Join live cohort standups, mentor office hours, masterclasses, and catch up on recorded sessions.
              </p>
            </div>

            {/* Quick timezone reminder pill */}
            <div className="flex items-center gap-1.5 self-start sm:self-auto rounded-lg border border-zinc-200/70 bg-white px-3 py-1.5 text-xs text-zinc-600 shadow-2xs dark:border-zinc-800 dark:bg-[#1a1a1a] dark:text-zinc-300">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <circle cx="12" cy="12" r="10" />
                <polyline points="12 6 12 12 16 14" />
              </svg>
              <span>Times listed in GMT (WAT: +1h, CAT: +2h, EAT: +3h)</span>
            </div>
          </div>

          {/* Search, Filter & View Controls */}
          <div className="flex flex-col gap-3">
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
              {/* Search Input */}
              <div className="relative flex-1 max-w-md">
                <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-zinc-400">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <circle cx="11" cy="11" r="8" />
                    <line x1="21" y1="21" x2="16.65" y2="16.65" />
                  </svg>
                </div>
                <input
                  type="text"
                  placeholder="Search sessions, topics, mentors..."
                  value={searchQuery}
                  onChange={(e) => handleSearchChange(e.target.value)}
                  className="w-full rounded-xl border border-zinc-200/80 bg-white py-2 pl-9 pr-3 text-xs sm:text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-zinc-400 focus:outline-hidden dark:border-zinc-800 dark:bg-[#1a1a1a] dark:text-zinc-100 dark:placeholder:text-zinc-500 dark:focus:border-zinc-600 shadow-2xs"
                />
                {searchQuery && (
                  <button
                    type="button"
                    onClick={() => handleSearchChange("")}
                    className="absolute inset-y-0 right-0 flex items-center pr-3 text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 cursor-pointer"
                  >
                    Clear
                  </button>
                )}
              </div>

              {/* View Mode Switcher */}
              <div className="flex items-center self-end sm:self-auto shrink-0 rounded-xl border border-zinc-200/80 dark:border-zinc-800 bg-white dark:bg-[#1a1a1a] p-1 shadow-2xs">
                <button
                  type="button"
                  onClick={() => setViewMode("grid")}
                  className={`inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition cursor-pointer ${
                    viewMode === "grid"
                      ? "bg-zinc-900 dark:bg-zinc-100 text-white dark:text-zinc-900 shadow-2xs"
                      : "text-zinc-500 dark:text-zinc-400 hover:text-zinc-800 dark:hover:text-zinc-200"
                  }`}
                  title="Grid View"
                  aria-label="Grid view"
                >
                  <GridIcon />
                  <span>Grid</span>
                </button>
                <button
                  type="button"
                  onClick={() => setViewMode("table")}
                  className={`inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition cursor-pointer ${
                    viewMode === "table"
                      ? "bg-zinc-900 dark:bg-zinc-100 text-white dark:text-zinc-900 shadow-2xs"
                      : "text-zinc-500 dark:text-zinc-400 hover:text-zinc-800 dark:hover:text-zinc-200"
                  }`}
                  title="Table View"
                  aria-label="Table view"
                >
                  <TableIcon />
                  <span>Table</span>
                </button>
              </div>
            </div>

            {/* Category Filter Pills */}
            <div className="flex items-center gap-1.5 overflow-x-auto no-scrollbar py-0.5 sm:flex-wrap">
              {categories.map((c) => (
                <button
                  key={c.value}
                  type="button"
                  onClick={() => handleCategoryChange(c.value)}
                  className={`inline-flex shrink-0 items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium transition cursor-pointer ${
                    activeCategory === c.value
                      ? "bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900 shadow-2xs"
                      : "border border-zinc-200/80 bg-white text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:border-zinc-800 dark:bg-[#1a1a1a] dark:text-zinc-400 dark:hover:bg-zinc-800/80 dark:hover:text-zinc-200"
                  }`}
                >
                  <span>{c.label}</span>
                  <span
                    className={`rounded-full px-1.5 py-0.2 text-[10px] font-semibold ${
                      activeCategory === c.value
                        ? "bg-zinc-800 text-zinc-200 dark:bg-zinc-200 dark:text-zinc-800"
                        : "bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400"
                    }`}
                  >
                    {c.count}
                  </span>
                </button>
              ))}
            </div>
          </div>

          {/* Skeleton Loader or Meeting Cards Grid or Table */}
          {loading ? (
            viewMode === "grid" ? (
              <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                {[1, 2, 3, 4].map((i) => (
                  <div
                    key={i}
                    className="flex flex-col justify-between rounded-xl border border-zinc-200/80 bg-white p-4 shadow-2xs dark:border-zinc-800 dark:bg-[#1a1a1a] animate-pulse"
                  >
                    <div>
                      {/* Top Badges */}
                      <div className="flex items-center justify-between gap-2">
                        <div className="h-5 w-20 rounded bg-zinc-200 dark:bg-zinc-800" />
                        <div className="h-4 w-24 rounded bg-zinc-100 dark:bg-zinc-800/60" />
                      </div>

                      {/* Title */}
                      <div className="mt-3 space-y-1.5">
                        <div className="h-4.5 w-4/5 rounded bg-zinc-200 dark:bg-zinc-800" />
                        <div className="h-4.5 w-1/2 rounded bg-zinc-200 dark:bg-zinc-800" />
                      </div>

                      {/* Description */}
                      <div className="mt-2.5 space-y-1.5">
                        <div className="h-3 w-full rounded bg-zinc-100 dark:bg-zinc-800/60" />
                        <div className="h-3 w-5/6 rounded bg-zinc-100 dark:bg-zinc-800/60" />
                      </div>

                      {/* Schedule / Time */}
                      <div className="mt-3.5 space-y-2 border-t border-zinc-100 pt-3 dark:border-zinc-800/60">
                        <div className="h-3 w-3/5 rounded bg-zinc-200/70 dark:bg-zinc-800" />
                        <div className="h-3 w-1/2 rounded bg-zinc-100 dark:bg-zinc-800/60" />
                        <div className="h-3 w-2/5 rounded bg-zinc-100 dark:bg-zinc-800/60" />
                      </div>
                    </div>

                    {/* Action Buttons */}
                    <div className="mt-4 flex items-center justify-between gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800/60">
                      <div className="h-7 w-20 rounded-lg bg-zinc-100 dark:bg-zinc-800" />
                      <div className="h-7 w-24 rounded-lg bg-zinc-200 dark:bg-zinc-800" />
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <div className="overflow-hidden rounded-xl border border-zinc-200/80 bg-white shadow-2xs dark:border-zinc-800 dark:bg-[#1a1a1a] animate-pulse p-4 space-y-3">
                {[1, 2, 3, 4].map((i) => (
                  <div key={i} className="flex items-center justify-between gap-4 py-2 border-b border-zinc-100 dark:border-zinc-800 last:border-0">
                    <div className="h-4 w-1/3 rounded bg-zinc-200 dark:bg-zinc-800" />
                    <div className="h-4 w-1/4 rounded bg-zinc-100 dark:bg-zinc-800/60" />
                    <div className="h-4 w-1/6 rounded bg-zinc-100 dark:bg-zinc-800/60" />
                    <div className="h-7 w-20 rounded-lg bg-zinc-200 dark:bg-zinc-800" />
                  </div>
                ))}
              </div>
            )
          ) : filteredMeetings.length === 0 ? (
            <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-zinc-300 py-12 text-center dark:border-zinc-800">
              <svg
                width="36"
                height="36"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.5"
                className="text-zinc-400"
              >
                <circle cx="12" cy="12" r="10" />
                <line x1="12" y1="8" x2="12" y2="12" />
                <line x1="12" y1="16" x2="12.01" y2="16" />
              </svg>
              <h3 className="mt-3 text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                No matching sessions found
              </h3>
              <p className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                Try refining your search keyword or switching the category filter.
              </p>
            </div>
          ) : (
            <div className="space-y-5">
              {viewMode === "grid" ? (
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                  {paginatedMeetings.map((meeting) => (
                    <MeetingCard
                      key={meeting.id}
                      meeting={meeting}
                      copied={copiedId === meeting.id}
                      onCopy={() => handleCopyLink(meeting)}
                    />
                  ))}
                </div>
              ) : (
                <MeetingTable
                  meetings={paginatedMeetings}
                  copiedId={copiedId}
                  onCopy={handleCopyLink}
                />
              )}

              {/* Pagination Controls */}
              {totalPages > 1 && (
                <div className="flex flex-col sm:flex-row items-center justify-between gap-3 pt-3 border-t border-zinc-200/80 dark:border-zinc-800/80">
                  <span className="text-xs text-zinc-500 dark:text-zinc-400 font-medium">
                    Showing {(currentPage - 1) * ITEMS_PER_PAGE + 1}–
                    {Math.min(currentPage * ITEMS_PER_PAGE, filteredMeetings.length)} of{" "}
                    {filteredMeetings.length} sessions
                  </span>

                  <div className="flex items-center gap-1.5">
                    <button
                      type="button"
                      disabled={currentPage <= 1}
                      onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
                      className="inline-flex items-center gap-1 rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-[#1a1a1a] px-2.5 py-1.5 text-xs font-medium text-zinc-700 dark:text-zinc-300 shadow-2xs transition hover:bg-zinc-50 dark:hover:bg-zinc-800 disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer"
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
                            ? "bg-zinc-900 dark:bg-zinc-100 text-white dark:text-zinc-900 shadow-2xs"
                            : "border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-[#1a1a1a] text-zinc-600 dark:text-zinc-400 hover:bg-zinc-50 dark:hover:bg-zinc-800"
                        }`}
                      >
                        {pageNum}
                      </button>
                    ))}

                    <button
                      type="button"
                      disabled={currentPage >= totalPages}
                      onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
                      className="inline-flex items-center gap-1 rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-[#1a1a1a] px-2.5 py-1.5 text-xs font-medium text-zinc-700 dark:text-zinc-300 shadow-2xs transition hover:bg-zinc-50 dark:hover:bg-zinc-800 disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer"
                    >
                      Next ›
                    </button>
                  </div>
                </div>
              )}
            </div>
          )}

          {/* Footer Guide & Guidelines */}
          <div className="rounded-xl border border-zinc-200/80 bg-zinc-50/60 p-4 text-xs text-zinc-500 dark:border-zinc-800/80 dark:bg-[#161616] dark:text-zinc-400">
            <h4 className="font-semibold text-zinc-800 dark:text-zinc-200 mb-1">
              Live Session Etiquette & Tips
            </h4>
            <ul className="list-disc pl-4 space-y-1">
              <li>Join meetings 3–5 minutes early to test your microphone and camera setup.</li>
              <li>Keep your microphone muted upon entry unless speaking during open Q&A.</li>
              <li>If your internet connection drops, session links remain open for rejoining.</li>
              <li>Recordings are usually processed and made available within 24 hours of session end.</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  );
}

function PlatformBadge({ platform }: { platform: "teams" | "meet" | "zoom" }) {
  switch (platform) {
    case "teams":
      return (
        <span className="inline-flex items-center gap-1 rounded bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor">
            <path d="M16 10h4a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2h-4v-10zm-6-2h4a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-4a2 2 0 0 1-2-2V10a2 2 0 0 1 2-2zm-6 4h4v8H4a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2z" />
          </svg>
          Teams
        </span>
      );
    case "meet":
      return (
        <span className="inline-flex items-center gap-1 rounded bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor">
            <path d="M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z" />
          </svg>
          Google Meet
        </span>
      );
    case "zoom":
      return (
        <span className="inline-flex items-center gap-1 rounded bg-sky-50 px-2 py-0.5 text-[11px] font-semibold text-sky-700 dark:bg-sky-950/60 dark:text-sky-300">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor">
            <path d="M4.5 5A2.5 2.5 0 0 0 2 7.5v9A2.5 2.5 0 0 0 4.5 19h10a2.5 2.5 0 0 0 2.5-2.5v-2.17l3.72 2.48A1 1 0 0 0 22 16V8a1 1 0 0 0-1.28-.96L17 9.67V7.5A2.5 2.5 0 0 0 14.5 5h-10z" />
          </svg>
          Zoom
        </span>
      );
    default:
      return null;
  }
}

function MeetingTable({
  meetings,
  copiedId,
  onCopy,
}: {
  meetings: CommunityMeeting[];
  copiedId: string | null;
  onCopy: (meeting: CommunityMeeting) => void;
}) {
  return (
    <div className="overflow-hidden rounded-2xl border border-zinc-200/80 dark:border-zinc-800/80 bg-white dark:bg-[#1a1a1a] shadow-2xs">
      <div className="overflow-x-auto no-scrollbar">
        <table className="w-full text-left text-xs border-collapse">
          <thead>
            <tr className="border-b border-zinc-200/80 dark:border-zinc-800/80 bg-zinc-50/70 dark:bg-zinc-800/40 text-zinc-500 dark:text-zinc-400 font-semibold">
              <th className="py-3 px-4">Session & Host</th>
              <th className="py-3 px-3">Platform</th>
              <th className="py-3 px-3 hidden md:table-cell">Category</th>
              <th className="py-3 px-3 hidden sm:table-cell">Schedule</th>
              <th className="py-3 px-4 text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-200/60 dark:divide-zinc-800/60 text-zinc-700 dark:text-zinc-300">
            {meetings.map((meeting) => {
              const isRecap = meeting.category === "recap";
              return (
                <tr
                  key={meeting.id}
                  className="transition hover:bg-zinc-50/80 dark:hover:bg-zinc-800/30"
                >
                  <td className="py-3.5 px-4 font-medium text-zinc-900 dark:text-zinc-100">
                    <div className="min-w-0">
                      <div className="flex items-center gap-2">
                        {meeting.isLiveNow && (
                          <span className="inline-flex items-center gap-1 rounded-full bg-rose-500/15 px-2 py-0.5 text-[10px] font-bold text-rose-600 dark:text-rose-400">
                            <span className="h-1.5 w-1.5 rounded-full bg-rose-500 animate-pulse" />
                            LIVE NOW
                          </span>
                        )}
                        <span className="font-semibold text-xs sm:text-sm text-zinc-900 dark:text-zinc-100 truncate">
                          {meeting.title}
                        </span>
                      </div>
                      <div className="mt-0.5 text-[11px] text-zinc-500 dark:text-zinc-400 truncate">
                        Host: {meeting.host}
                      </div>
                    </div>
                  </td>
                  <td className="py-3.5 px-3">
                    <PlatformBadge platform={meeting.platform} />
                  </td>
                  <td className="py-3.5 px-3 hidden md:table-cell">
                    <span className="rounded bg-zinc-100 px-2 py-0.5 text-[10px] font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400 capitalize">
                      {meeting.category.replace("_", " ")}
                    </span>
                  </td>
                  <td className="py-3.5 px-3 hidden sm:table-cell text-zinc-500 dark:text-zinc-400">
                    <div className="font-medium text-zinc-700 dark:text-zinc-300">{meeting.schedule}</div>
                    <div className="text-[10px] text-zinc-400 dark:text-zinc-500">{meeting.timeContext}</div>
                  </td>
                  <td className="py-3.5 px-4 text-right">
                    <div className="inline-flex items-center gap-1.5 justify-end">
                      <a
                        href={meeting.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center gap-1 rounded-lg bg-zinc-900 dark:bg-zinc-100 px-3 py-1.5 text-xs font-semibold text-white dark:text-zinc-900 shadow-2xs hover:bg-zinc-800 dark:hover:bg-white active:scale-98 cursor-pointer"
                      >
                        <span>{isRecap ? "Watch" : "Join"}</span>
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                          <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
                          <polyline points="15 3 21 3 21 9" />
                          <line x1="10" y1="14" x2="21" y2="3" />
                        </svg>
                      </a>
                      <button
                        type="button"
                        onClick={() => onCopy(meeting)}
                        className="inline-flex items-center rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-2.5 py-1.5 text-xs font-medium text-zinc-600 dark:text-zinc-300 shadow-2xs hover:bg-zinc-50 dark:hover:bg-zinc-700 active:scale-98 cursor-pointer"
                        title="Copy session link"
                      >
                        {copiedId === meeting.id ? (
                          <span className="text-emerald-600 dark:text-emerald-400 font-semibold flex items-center gap-1">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                              <polyline points="20 6 9 17 4 12" />
                            </svg>
                          </span>
                        ) : (
                          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                            <rect x="9" y="9" width="13" height="13" rx="2" />
                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
                          </svg>
                        )}
                      </button>
                    </div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function MeetingCard({
  meeting,
  copied,
  onCopy,
}: {
  meeting: CommunityMeeting;
  copied: boolean;
  onCopy: () => void;
}) {
  const isRecap = meeting.category === "recap";

  return (
    <div className="flex flex-col justify-between rounded-xl border border-zinc-200/80 bg-white p-4 shadow-2xs transition hover:border-zinc-300 dark:border-zinc-800 dark:bg-[#1a1a1a] dark:hover:border-zinc-700">
      <div>
        {/* Top Badges & Platform */}
        <div className="flex items-center justify-between gap-2">
          <PlatformBadge platform={meeting.platform} />
          <span className="rounded bg-zinc-100 px-2 py-0.5 text-[10px] font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400 capitalize">
            {meeting.category.replace("_", " ")}
          </span>
        </div>

        {/* Title */}
        <h3 className="mt-2.5 text-sm sm:text-base font-semibold text-zinc-900 dark:text-zinc-100 leading-snug">
          {meeting.title}
        </h3>

        {/* Description */}
        <p className="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400 line-clamp-2 leading-relaxed">
          {meeting.description}
        </p>

        {/* Schedule & Time */}
        <div className="mt-3 space-y-1 border-t border-zinc-100 pt-3 dark:border-zinc-800/60">
          <div className="flex items-center gap-1.5 text-xs font-medium text-zinc-700 dark:text-zinc-300">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
              <line x1="16" y1="2" x2="16" y2="6" />
              <line x1="8" y1="2" x2="8" y2="6" />
              <line x1="3" y1="10" x2="21" y2="10" />
            </svg>
            <span>{meeting.schedule}</span>
          </div>
          <div className="flex items-center gap-1.5 text-[11px] text-zinc-500 dark:text-zinc-400">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <circle cx="12" cy="12" r="10" />
              <polyline points="12 6 12 12 16 14" />
            </svg>
            <span>{meeting.timeContext}</span>
          </div>
          <div className="flex items-center gap-1.5 text-[11px] text-zinc-500 dark:text-zinc-400">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
              <circle cx="12" cy="7" r="4" />
            </svg>
            <span>Host: {meeting.host}</span>
          </div>
        </div>

        {/* Meeting ID & Passcode if applicable */}
        {(meeting.meetingId || meeting.passcode) && (
          <div className="mt-2.5 flex flex-wrap items-center gap-3 rounded-lg bg-zinc-50 px-2.5 py-1.5 text-[11px] text-zinc-600 dark:bg-zinc-900/60 dark:text-zinc-300 border border-zinc-200/60 dark:border-zinc-800">
            {meeting.meetingId && (
              <div>
                <span className="text-zinc-400 dark:text-zinc-500">ID: </span>
                <span className="font-mono font-medium">{meeting.meetingId}</span>
              </div>
            )}
            {meeting.passcode && (
              <div>
                <span className="text-zinc-400 dark:text-zinc-500">Passcode: </span>
                <span className="font-mono font-medium">{meeting.passcode}</span>
              </div>
            )}
          </div>
        )}
      </div>

      {/* Action Buttons */}
      <div className="mt-4 flex items-center justify-between gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800/60">
        <button
          type="button"
          onClick={onCopy}
          className="inline-flex items-center gap-1 rounded-lg border border-zinc-200 px-2.5 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-100 hover:text-zinc-900 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-zinc-100 transition cursor-pointer"
        >
          {copied ? (
            <>
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <polyline points="20 6 9 17 4 12" />
              </svg>
              <span>Copied!</span>
            </>
          ) : (
            <>
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <rect x="9" y="9" width="13" height="13" rx="2" ry="2" />
                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
              </svg>
              <span>Copy link</span>
            </>
          )}
        </button>

        <a
          href={meeting.url}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-white shadow-2xs hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200 transition cursor-pointer"
        >
          <span>{isRecap ? "Watch Recap" : "Join Session"}</span>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
            <line x1="5" y1="12" x2="19" y2="12" />
            <polyline points="12 5 19 12 12 19" />
          </svg>
        </a>
      </div>
    </div>
  );
}

function GridIcon() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden>
      <rect x="3" y="3" width="7" height="7" rx="1.5" />
      <rect x="14" y="3" width="7" height="7" rx="1.5" />
      <rect x="14" y="14" width="7" height="7" rx="1.5" />
      <rect x="3" y="14" width="7" height="7" rx="1.5" />
    </svg>
  );
}

function TableIcon() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden>
      <rect x="3" y="3" width="18" height="18" rx="2" />
      <path d="M3 9h18M3 15h18M9 9v12" />
    </svg>
  );
}
