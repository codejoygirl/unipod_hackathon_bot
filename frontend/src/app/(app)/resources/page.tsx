"use client";

import { ChatHeader } from "@/components/chat/chat-header";
import { apiFetch } from "@/lib/api/client";
import type { CommunityResource, CommunityResourcesResponse } from "@/lib/api/types";
import { useWebChat } from "@/lib/web-chat/web-chat-context";
import { useCallback, useEffect, useMemo, useState } from "react";

// Default curated fallback resources for UniPods community in case DB is still empty
const FALLBACK_RESOURCES: CommunityResource[] = [
  {
    id: "unipod-community-resources-folder",
    name: "UniPod Community Resources",
    kind: "folder",
    url: "https://drive.google.com/drive/folders/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs",
    description:
      "Official UniPod Google Drive repository containing core programme toolkits, templates, guidelines, and cohort learning materials.",
    authority_tier: "verified_resource",
    source_type: "google_drive",
    is_asset: true,
  },
  {
    id: "unipod-handbook",
    name: "UniPods Programme Handbook",
    kind: "handbook",
    url: "https://drive.google.com/file/d/1YZvsMxcbq_EvWZk-Zx3IBHYxwdhXRs5O/view",
    description:
      "Comprehensive UniPods programme handbook with cohort guidelines, expectations, innovation milestones, and mentor directory.",
    authority_tier: "verified_resource",
    source_type: "google_drive",
    is_asset: true,
  },
  {
    id: "wadhwani-ignite-slides",
    name: "Wadhwani Ignite Curriculum Slides",
    kind: "slides",
    url: "https://drive.google.com/file/d/1jkYKc8xmP1Msh7jPGaC0lPsZceG_GkFh/view",
    description:
      "Wadhwani Ignite entrepreneurship training decks, module slides, frameworks, and workshop presentation materials.",
    authority_tier: "verified_resource",
    source_type: "google_drive",
    is_asset: true,
  },
];

function normalizeResource(res: CommunityResource): CommunityResource {
  let name = res.name;
  if (name.trim() === "UNIPOD COMMUNITY RESOURCES") {
    name = "UniPod Community Resources";
  } else if (name.length > 3 && name === name.toUpperCase()) {
    name = name
      .toLowerCase()
      .split(" ")
      .map((w) => (w.length > 0 ? w[0].toUpperCase() + w.slice(1) : ""))
      .join(" ")
      .replace(/\bUnipod\b/g, "UniPod")
      .replace(/\bUnipods\b/g, "UniPods")
      .replace(/\bAi\b/g, "AI");
  }

  let description = res.description;
  if (
    description &&
    (description.includes("folder containing programme assets, toolkits, templates, guidelines, and cohort materials") ||
      description.includes("resource folder containing programme assets, toolkits"))
  ) {
    description =
      "Official UniPod Google Drive repository containing core programme toolkits, templates, guidelines, and cohort learning materials.";
  }

  return {
    ...res,
    name,
    description,
  };
}

function isRealResource(res: CommunityResource): boolean {
  if (!res.url || !res.url.startsWith("http")) {
    return false;
  }
  const nameLower = res.name.toLowerCase();
  if (nameLower.startsWith("admin update") || nameLower.startsWith("chat update")) {
    return false;
  }
  return true;
}

type CategoryFilter = "all" | "folder" | "handbook" | "slides" | "form" | "document";

export default function ResourcesPage() {
  const { memberPhone, community, isAdmin } = useWebChat();
  const [resources, setResources] = useState<CommunityResource[]>([]);
  const [loading, setLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState("");
  const [activeCategory, setActiveCategory] = useState<CategoryFilter>("all");
  const [copiedId, setCopiedId] = useState<string | null>(null);
  const [currentPage, setCurrentPage] = useState(1);
  const [viewMode, setViewMode] = useState<"grid" | "table">("grid");
  const ITEMS_PER_PAGE = 8;

  const handleSearchChange = (val: string) => {
    setSearchQuery(val);
    setCurrentPage(1);
  };

  const handleCategoryChange = (cat: CategoryFilter) => {
    setActiveCategory(cat);
    setCurrentPage(1);
  };

  // Admin Quick Add state
  const [showAdminAdd, setShowAdminAdd] = useState(false);
  const [addKind, setAddKind] = useState("other");
  const [addTitle, setAddTitle] = useState("");
  const [addUrl, setAddUrl] = useState("");
  const [addSubmitting, setAddSubmitting] = useState(false);
  const [addFeedback, setAddFeedback] = useState<string | null>(null);

  const fetchResources = useCallback(async () => {
    if (!memberPhone) return;
    setLoading(true);
    try {
      const res = await apiFetch<CommunityResourcesResponse>(
        `/api/v1/web-chat/resources?phone=${encodeURIComponent(memberPhone)}`,
      );
      if (res.data?.resources && res.data.resources.length > 0) {
        const clean = res.data.resources.filter(isRealResource).map(normalizeResource);
        setResources(clean.length > 0 ? clean : FALLBACK_RESOURCES);
      } else {
        // Use curated UniPod community starter resources if DB has none yet
        setResources(FALLBACK_RESOURCES);
      }
    } catch {
      // In case of network or offline, provide fallback resources
      setResources(FALLBACK_RESOURCES);
    } finally {
      setLoading(false);
    }
  }, [memberPhone]);

  useEffect(() => {
    let ignore = false;
    const load = async () => {
      if (!memberPhone) return;
      setLoading(true);
      try {
        const res = await apiFetch<CommunityResourcesResponse>(
          `/api/v1/web-chat/resources?phone=${encodeURIComponent(memberPhone)}`,
        );
        if (!ignore) {
          if (res.data?.resources && res.data.resources.length > 0) {
            const clean = res.data.resources.filter(isRealResource).map(normalizeResource);
            setResources(clean.length > 0 ? clean : FALLBACK_RESOURCES);
          } else {
            setResources(FALLBACK_RESOURCES);
          }
        }
      } catch {
        if (!ignore) {
          setResources(FALLBACK_RESOURCES);
        }
      } finally {
        if (!ignore) {
          setLoading(false);
        }
      }
    };
    const t = setTimeout(() => {
      void load();
    }, 0);
    return () => {
      ignore = true;
      clearTimeout(t);
    };
  }, [memberPhone]);

  const copyToClipboard = async (id: string, url: string) => {
    try {
      await navigator.clipboard.writeText(url);
      setCopiedId(id);
      setTimeout(() => setCopiedId(null), 2200);
    } catch {
      // Fallback
    }
  };

  const handleAdminRegister = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!memberPhone || !addTitle.trim() || !addUrl.trim()) return;
    setAddSubmitting(true);
    setAddFeedback(null);
    try {
      const command = `/asset ${addKind} ${addTitle.trim()} ${addUrl.trim()}`;
      await apiFetch<{ data: { answer: string } }>("/api/v1/web-chat/ask", {
        method: "POST",
        body: JSON.stringify({
          phone: memberPhone,
          query: command,
        }),
      });
      setAddFeedback("Resource registered and published successfully!");
      setAddTitle("");
      setAddUrl("");
      await fetchResources();
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Failed to register resource.";
      setAddFeedback(`Error: ${msg}`);
    } finally {
      setAddSubmitting(false);
    }
  };

  // Filter & Search logic
  const filteredResources = useMemo(() => {
    return resources.filter((item) => {
      const matchesCategory =
        activeCategory === "all"
          ? true
          : activeCategory === "folder"
            ? item.kind === "folder" || (item.url && item.url.includes("/folders"))
            : item.kind === activeCategory;

      if (!matchesCategory) return false;

      if (!searchQuery.trim()) return true;
      const q = searchQuery.toLowerCase();
      return (
        item.name.toLowerCase().includes(q) ||
        item.description.toLowerCase().includes(q) ||
        (item.url && item.url.toLowerCase().includes(q))
      );
    });
  }, [resources, activeCategory, searchQuery]);

  // Find the primary resource hub folder for spotlight
  const primaryHub = useMemo(() => {
    // 1. Look for the dedicated community root Drive folder
    const officialHub = resources.find(
      (r) =>
        r.url &&
        r.url.includes("drive.google.com/drive/folders") &&
        (r.name.toLowerCase().includes("community resource") ||
          r.name.toLowerCase().includes("unipod") ||
          r.is_asset) &&
        !r.name.toLowerCase().startsWith("admin update"),
    );
    if (officialHub) return officialHub;

    // 2. Look for any genuine Google Drive folder resource
    const anyFolder = resources.find(
      (r) =>
        r.kind === "folder" &&
        r.url &&
        r.url.includes("drive.google.com/drive/folders") &&
        !r.name.toLowerCase().startsWith("admin update"),
    );
    if (anyFolder) return anyFolder;

    // 3. Fallback safely to the official UniPod community root Drive folder (never an arbitrary doc or chat post)
    return FALLBACK_RESOURCES[0];
  }, [resources]);

  const totalPages = Math.max(1, Math.ceil(filteredResources.length / ITEMS_PER_PAGE));
  const paginatedResources = useMemo(() => {
    const start = (currentPage - 1) * ITEMS_PER_PAGE;
    return filteredResources.slice(start, start + ITEMS_PER_PAGE);
  }, [filteredResources, currentPage]);

  return (
    <div className="flex flex-1 flex-col h-full min-w-0 overflow-y-auto no-scrollbar bg-white dark:bg-[#0d0d0d] text-zinc-900 dark:text-zinc-100">
      <ChatHeader />

      <main className="mx-auto flex w-full max-w-5xl flex-1 flex-col px-4 py-6 pb-28 sm:px-6">
        {/* Banner Section */}
        <div className="mb-6 flex flex-col gap-1.5">
          <h1 className="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100 sm:text-3xl">
            Community Resources
          </h1>
          <p className="text-sm text-zinc-600 dark:text-zinc-400 max-w-3xl leading-relaxed">
            Official Google Drive links, programme handbooks, slide decks, and documents for{" "}
            <span className="font-semibold text-zinc-900 dark:text-zinc-100">{community?.name ?? "UniPod Community"}</span>.
          </p>
        </div>

        {/* Spotlight Hero Card (Drive Folder) */}
        {primaryHub && primaryHub.url && (
          <div className="mb-6 relative overflow-hidden rounded-2xl border border-emerald-200/90 bg-gradient-to-br from-emerald-50/90 via-emerald-50/40 to-white dark:border-emerald-800/60 dark:bg-gradient-to-br dark:from-[#1b2a22] dark:via-[#161f1a] dark:to-[#121212] p-5 shadow-xs transition-all hover:shadow-sm">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              <div className="flex items-start gap-3.5 min-w-0">
                <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-emerald-600 text-white shadow-xs">
                  <FolderIcon />
                </div>
                <div className="min-w-0">
                  <div className="flex items-center gap-2">
                    <span className="rounded bg-emerald-100 dark:bg-emerald-900/60 px-1.5 py-0.5 text-[10px] font-bold text-emerald-800 dark:text-emerald-300 uppercase tracking-wider">
                      Primary Drive Hub
                    </span>
                  </div>
                  <h2 className="mt-1 text-base font-bold text-zinc-900 dark:text-zinc-100 truncate">
                    {primaryHub.name}
                  </h2>
                  <p className="text-xs text-zinc-600 dark:text-zinc-400 line-clamp-2 mt-0.5">
                    {primaryHub.description}
                  </p>
                </div>
              </div>

              <div className="flex items-center gap-2 shrink-0">
                <a
                  href={primaryHub.url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex items-center justify-center gap-1.5 rounded-xl bg-emerald-600 px-3.5 py-2 text-xs font-semibold text-white shadow-xs transition-all hover:bg-emerald-500 active:scale-98 cursor-pointer"
                >
                  <span>Open Drive</span>
                  <ExternalLinkIcon />
                </a>
                <button
                  type="button"
                  onClick={() => copyToClipboard(primaryHub.id, primaryHub.url!)}
                  className="inline-flex items-center justify-center rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-3 py-2 text-xs font-medium text-zinc-700 dark:text-zinc-200 shadow-2xs transition-all hover:bg-zinc-50 dark:hover:bg-zinc-700 active:scale-98 cursor-pointer"
                  title="Copy link to clipboard"
                >
                  {copiedId === primaryHub.id ? (
                    <span className="text-emerald-600 dark:text-emerald-400 font-semibold flex items-center gap-1">
                      <CheckIcon /> Copied
                    </span>
                  ) : (
                    <span className="flex items-center gap-1">
                      <CopyIcon /> Copy
                    </span>
                  )}
                </button>
              </div>
            </div>
          </div>
        )}

        {/* Search, Filter & View Controls */}
        <div className="mb-6 flex flex-col gap-3">
          <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div className="relative flex-1">
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => handleSearchChange(e.target.value)}
                placeholder="Search resources, folders, guides, slides..."
                className="w-full rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-[#181818] px-4 py-2.5 pl-10 text-sm text-zinc-900 dark:text-zinc-100 placeholder-zinc-400 dark:placeholder-zinc-500 shadow-2xs focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20"
              />
              <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-zinc-400">
                <SearchIcon />
              </div>
              {searchQuery && (
                <button
                  type="button"
                  onClick={() => handleSearchChange("")}
                  className="absolute inset-y-0 right-0 flex items-center pr-3 text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 cursor-pointer"
                >
                  Clear
                </button>
              )}
            </div>

            {/* View Mode Switcher */}
            <div className="flex items-center self-end sm:self-auto shrink-0 rounded-xl border border-zinc-200/80 dark:border-zinc-800 bg-white dark:bg-[#181818] p-1 shadow-2xs">
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

          {/* Filter Pills */}
          <div className="flex items-center gap-1.5 overflow-x-auto no-scrollbar py-0.5">
            <FilterPill
              active={activeCategory === "all"}
              onClick={() => handleCategoryChange("all")}
              label="All"
              count={resources.length}
            />
            <FilterPill
              active={activeCategory === "folder"}
              onClick={() => handleCategoryChange("folder")}
              label="Folders & Drive"
              count={resources.filter((r) => r.kind === "folder" || (r.url && r.url.includes("/folders"))).length}
            />
            <FilterPill
              active={activeCategory === "handbook"}
              onClick={() => handleCategoryChange("handbook")}
              label="Handbooks & Guides"
              count={resources.filter((r) => r.kind === "handbook").length}
            />
            <FilterPill
              active={activeCategory === "slides"}
              onClick={() => handleCategoryChange("slides")}
              label="Slides & Decks"
              count={resources.filter((r) => r.kind === "slides").length}
            />
            <FilterPill
              active={activeCategory === "form"}
              onClick={() => handleCategoryChange("form")}
              label="Forms & Signups"
              count={resources.filter((r) => r.kind === "form").length}
            />
            <FilterPill
              active={activeCategory === "document"}
              onClick={() => handleCategoryChange("document")}
              label="Documents"
              count={resources.filter((r) => r.kind === "document").length}
            />
          </div>
        </div>

        {/* Resources Grid / Cards List */}
        {loading ? (
          <div className="grid gap-3.5 sm:grid-cols-2">
            {[1, 2, 3, 4].map((n) => (
              <div
                key={n}
                className="animate-pulse rounded-2xl border border-zinc-200/80 dark:border-zinc-800/80 bg-white dark:bg-[#181818] p-4.5 shadow-2xs"
              >
                <div className="flex items-center gap-3">
                  <div className="h-10 w-10 rounded-xl bg-zinc-200 dark:bg-zinc-800" />
                  <div className="flex-1 space-y-1.5">
                    <div className="h-4 w-3/4 rounded bg-zinc-200 dark:bg-zinc-800" />
                    <div className="h-3 w-1/2 rounded bg-zinc-100 dark:bg-zinc-800/60" />
                  </div>
                </div>
                <div className="mt-3.5 space-y-1">
                  <div className="h-3 w-full rounded bg-zinc-100 dark:bg-zinc-800/60" />
                  <div className="h-3 w-4/5 rounded bg-zinc-100 dark:bg-zinc-800/60" />
                </div>
              </div>
            ))}
          </div>
        ) : filteredResources.length === 0 ? (
          <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-zinc-200 dark:border-zinc-800 bg-white dark:bg-[#181818] p-8 text-center shadow-2xs">
            <div className="flex h-12 w-12 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-400 mb-3">
              <SearchIcon />
            </div>
            <h3 className="text-sm font-semibold text-zinc-900 dark:text-zinc-100">No resources found</h3>
            <p className="mt-1 text-xs text-zinc-500 dark:text-zinc-400 max-w-sm">
              {searchQuery
                ? `No community resources match "${searchQuery}". Try a different keyword.`
                : "No resources published in this category yet."}
            </p>
            {searchQuery && (
              <button
                type="button"
                onClick={() => setSearchQuery("")}
                className="mt-3 rounded-lg bg-zinc-100 dark:bg-zinc-800 px-3 py-1.5 text-xs font-medium text-zinc-700 dark:text-zinc-300 hover:bg-zinc-200 dark:hover:bg-zinc-700 cursor-pointer"
              >
                Clear search
              </button>
            )}
          </div>
        ) : (
          <div className="space-y-5">
            {viewMode === "grid" ? (
              <div className="grid gap-3.5 sm:grid-cols-2">
                {paginatedResources.map((item) => (
                  <ResourceCard
                    key={item.id}
                    resource={item}
                    copied={copiedId === item.id}
                    onCopy={() => item.url && copyToClipboard(item.id, item.url)}
                  />
                ))}
              </div>
            ) : (
              <ResourceTable
                resources={paginatedResources}
                copiedId={copiedId}
                onCopy={(id, url) => copyToClipboard(id, url)}
              />
            )}

            {/* Pagination Controls */}
            {totalPages > 1 && (
              <div className="flex flex-col sm:flex-row items-center justify-between gap-3 pt-3 border-t border-zinc-200/80 dark:border-zinc-800/80">
                <span className="text-xs text-zinc-500 dark:text-zinc-400 font-medium">
                  Showing {(currentPage - 1) * ITEMS_PER_PAGE + 1}–
                  {Math.min(currentPage * ITEMS_PER_PAGE, filteredResources.length)} of{" "}
                  {filteredResources.length} resources
                </span>

                <div className="flex items-center gap-1.5">
                  <button
                    type="button"
                    disabled={currentPage <= 1}
                    onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
                    className="inline-flex items-center gap-1 rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-2.5 py-1.5 text-xs font-medium text-zinc-700 dark:text-zinc-300 shadow-2xs transition hover:bg-zinc-50 dark:hover:bg-zinc-800 disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer"
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
                          : "border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-50 dark:hover:bg-zinc-800"
                      }`}
                    >
                      {pageNum}
                    </button>
                  ))}

                  <button
                    type="button"
                    disabled={currentPage >= totalPages}
                    onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
                    className="inline-flex items-center gap-1 rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-2.5 py-1.5 text-xs font-medium text-zinc-700 dark:text-zinc-300 shadow-2xs transition hover:bg-zinc-50 dark:hover:bg-zinc-800 disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer"
                  >
                    Next ›
                  </button>
                </div>
              </div>
            )}
          </div>
        )}

        {/* Coordinator Tools: Add Resource (Admin only) */}
        {isAdmin && (
          <div className="mt-8 rounded-2xl border border-zinc-200/90 dark:border-zinc-800 bg-white dark:bg-[#181818] p-5 shadow-2xs">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300 text-xs font-bold">
                  ★
                </span>
                <h3 className="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                  Coordinator Tool: Add Resource
                </h3>
              </div>
              <button
                type="button"
                onClick={() => setShowAdminAdd(!showAdminAdd)}
                className="text-xs font-medium text-emerald-700 dark:text-emerald-400 hover:text-emerald-800 cursor-pointer"
              >
                {showAdminAdd ? "Hide form" : "+ Register link"}
              </button>
            </div>

            <p className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
              Register a Google Drive folder, document, or slide deck directly into the knowledge base.
            </p>

            {showAdminAdd && (
              <form onSubmit={handleAdminRegister} className="mt-4 space-y-3 pt-3 border-t border-zinc-100 dark:border-zinc-800">
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                  <div>
                    <label className="block text-[11px] font-semibold text-zinc-600 dark:text-zinc-400 uppercase mb-1">
                      Resource Type
                    </label>
                    <select
                      value={addKind}
                      onChange={(e) => setAddKind(e.target.value)}
                      className="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-[#202020] px-3 py-2 text-xs text-zinc-900 dark:text-zinc-100 focus:border-emerald-500 focus:outline-none"
                    >
                      <option value="other">Folder / Resource Hub</option>
                      <option value="handbook">Handbook / Guide</option>
                      <option value="slides">Slides / Presentation</option>
                      <option value="form">Form / Signup</option>
                    </select>
                  </div>
                  <div className="sm:col-span-2">
                    <label className="block text-[11px] font-semibold text-zinc-600 dark:text-zinc-400 uppercase mb-1">
                      Resource Title
                    </label>
                    <input
                      type="text"
                      required
                      placeholder="e.g. UniPod Community Resources"
                      value={addTitle}
                      onChange={(e) => setAddTitle(e.target.value)}
                      className="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-[#202020] px-3 py-2 text-xs text-zinc-900 dark:text-zinc-100 placeholder-zinc-400 dark:placeholder-zinc-500 focus:border-emerald-500 focus:outline-none"
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-[11px] font-semibold text-zinc-600 dark:text-zinc-400 uppercase mb-1">
                    Google Drive or Web URL
                  </label>
                  <input
                    type="url"
                    required
                    placeholder="https://drive.google.com/drive/folders/..."
                    value={addUrl}
                    onChange={(e) => setAddUrl(e.target.value)}
                    className="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-[#202020] px-3 py-2 text-xs text-zinc-900 dark:text-zinc-100 placeholder-zinc-400 dark:placeholder-zinc-500 focus:border-emerald-500 focus:outline-none"
                  />
                </div>

                {addFeedback && (
                  <p
                    className={`text-xs ${
                      addFeedback.startsWith("Error") ? "text-rose-600 dark:text-rose-400" : "text-emerald-600 dark:text-emerald-400"
                    }`}
                  >
                    {addFeedback}
                  </p>
                )}

                <div className="flex justify-end gap-2 pt-1">
                  <button
                    type="submit"
                    disabled={addSubmitting}
                    className="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2 text-xs font-semibold text-white shadow-xs hover:bg-emerald-500 disabled:opacity-50 cursor-pointer"
                  >
                    {addSubmitting ? "Registering & Indexing..." : "Publish to Knowledge Base"}
                  </button>
                </div>
              </form>
            )}
          </div>
        )}
      </main>
    </div>
  );
}

function FilterPill({
  active,
  onClick,
  label,
  count,
}: {
  active: boolean;
  onClick: () => void;
  label: string;
  count: number;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium transition-all shrink-0 cursor-pointer ${
        active
          ? "bg-zinc-900 text-white shadow-2xs font-semibold dark:bg-zinc-100 dark:text-zinc-900"
          : "bg-white dark:bg-[#181818] border border-zinc-200 dark:border-zinc-800 text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-100 hover:bg-zinc-50 dark:hover:bg-zinc-800/60"
      }`}
    >
      <span>{label}</span>
      <span
        className={`shrink-0 whitespace-nowrap rounded-full px-1.5 py-0.5 text-[10px] font-semibold ${
          active ? "bg-white/20 text-white dark:bg-zinc-900/20 dark:text-zinc-900" : "bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400"
        }`}
      >
        {count}
      </span>
    </button>
  );
}

function ResourceTable({
  resources,
  copiedId,
  onCopy,
}: {
  resources: CommunityResource[];
  copiedId: string | null;
  onCopy: (id: string, url: string) => void;
}) {
  return (
    <div className="overflow-hidden rounded-2xl border border-zinc-200/80 dark:border-zinc-800/80 bg-white dark:bg-[#181818] shadow-2xs">
      <div className="overflow-x-auto no-scrollbar">
        <table className="w-full text-left text-xs border-collapse">
          <thead>
            <tr className="border-b border-zinc-200/80 dark:border-zinc-800/80 bg-zinc-50/70 dark:bg-zinc-800/40 text-zinc-500 dark:text-zinc-400 font-semibold">
              <th className="py-3 px-4">Resource</th>
              <th className="py-3 px-3">Type</th>
              <th className="py-3 px-3 hidden md:table-cell">Description</th>
              <th className="py-3 px-4 text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-200/60 dark:divide-zinc-800/60 text-zinc-700 dark:text-zinc-300">
            {resources.map((item) => {
              const isFolder =
                item.kind === "folder" || (item.url && item.url.includes("/folders"));
              const isHandbook = item.kind === "handbook";
              const isSlides = item.kind === "slides";
              const isForm = item.kind === "form";

              const typeBadge = isFolder
                ? { label: "Folder", color: "bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-300" }
                : isHandbook
                  ? { label: "Handbook", color: "bg-blue-100 text-blue-800 dark:bg-blue-900/60 dark:text-blue-300" }
                  : isSlides
                    ? { label: "Slides", color: "bg-purple-100 text-purple-800 dark:bg-purple-900/60 dark:text-purple-300" }
                    : isForm
                      ? { label: "Form", color: "bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-300" }
                      : { label: "Document", color: "bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300" };

              return (
                <tr
                  key={item.id}
                  className="transition hover:bg-zinc-50/80 dark:hover:bg-zinc-800/30"
                >
                  <td className="py-3.5 px-4 font-medium text-zinc-900 dark:text-zinc-100">
                    <div className="flex items-center gap-3">
                      <div className="shrink-0 flex items-center justify-center h-8 w-8 rounded-lg bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300">
                        {isFolder ? (
                          <FolderIcon />
                        ) : isHandbook ? (
                          <DocumentIcon />
                        ) : isSlides ? (
                          <SlidesIcon />
                        ) : isForm ? (
                          <FormIcon />
                        ) : (
                          <DocumentIcon />
                        )}
                      </div>
                      <div className="min-w-0">
                        <div className="flex items-center gap-1.5">
                          <span className="font-semibold text-xs sm:text-sm text-zinc-900 dark:text-zinc-100 truncate">
                            {item.name}
                          </span>
                          {item.authority_tier === "verified_resource" && (
                            <span
                              className="text-[10px] text-emerald-600 dark:text-emerald-400 font-semibold flex items-center gap-0.5 shrink-0"
                              title="Official Verified Resource"
                            >
                              <CheckBadgeIcon />
                            </span>
                          )}
                        </div>
                        <div className="text-[11px] text-zinc-400 dark:text-zinc-500 line-clamp-1 md:hidden mt-0.5">
                          {item.description}
                        </div>
                      </div>
                    </div>
                  </td>
                  <td className="py-3.5 px-3">
                    <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-[10px] font-semibold ${typeBadge.color}`}>
                      {typeBadge.label}
                    </span>
                  </td>
                  <td className="py-3.5 px-3 hidden md:table-cell text-zinc-500 dark:text-zinc-400 max-w-xs truncate">
                    {item.description || "—"}
                  </td>
                  <td className="py-3.5 px-4 text-right">
                    <div className="inline-flex items-center gap-1.5 justify-end">
                      {item.url && (
                        <>
                          <a
                            href={item.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center gap-1 rounded-lg bg-zinc-900 dark:bg-zinc-100 px-2.5 py-1.5 text-xs font-semibold text-white dark:text-zinc-900 shadow-2xs hover:bg-zinc-800 dark:hover:bg-white active:scale-98 cursor-pointer"
                            title="Open Resource"
                          >
                            <span>Open</span>
                            <ExternalLinkIcon />
                          </a>
                          <button
                            type="button"
                            onClick={() => onCopy(item.id, item.url!)}
                            className="inline-flex items-center rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-2 py-1.5 text-xs font-medium text-zinc-600 dark:text-zinc-300 shadow-2xs hover:bg-zinc-50 dark:hover:bg-zinc-700 active:scale-98 cursor-pointer"
                            title="Copy link"
                          >
                            {copiedId === item.id ? (
                              <span className="text-emerald-600 dark:text-emerald-400 font-semibold flex items-center gap-0.5">
                                <CheckIcon />
                              </span>
                            ) : (
                              <CopyIcon />
                            )}
                          </button>
                        </>
                      )}
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

function ResourceCard({
  resource,
  copied,
  onCopy,
}: {
  resource: CommunityResource;
  copied: boolean;
  onCopy: () => void;
}) {
  const isFolder =
    resource.kind === "folder" || (resource.url && resource.url.includes("/folders"));
  const isHandbook = resource.kind === "handbook";
  const isSlides = resource.kind === "slides";
  const isForm = resource.kind === "form";

  const colorStyles = isFolder
    ? {
        bg: "bg-emerald-50 text-emerald-700 border-emerald-200/80 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-800/60",
        badge: "bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-300",
        label: "Drive Folder",
      }
    : isHandbook
      ? {
          bg: "bg-blue-50 text-blue-700 border-blue-200/80 dark:bg-blue-950/60 dark:text-blue-300 dark:border-blue-800/60",
          badge: "bg-blue-100 text-blue-800 dark:bg-blue-900/60 dark:text-blue-300",
          label: "Handbook",
        }
      : isSlides
        ? {
            bg: "bg-purple-50 text-purple-700 border-purple-200/80 dark:bg-purple-950/60 dark:text-purple-300 dark:border-purple-800/60",
            badge: "bg-purple-100 text-purple-800 dark:bg-purple-900/60 dark:text-purple-300",
            label: "Presentation Deck",
          }
        : isForm
          ? {
              bg: "bg-amber-50 text-amber-700 border-amber-200/80 dark:bg-amber-950/60 dark:text-amber-300 dark:border-amber-800/60",
              badge: "bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-300",
              label: "Form & Signup",
            }
          : {
              bg: "bg-zinc-50 text-zinc-700 border-zinc-200/80 dark:bg-zinc-800 dark:text-zinc-300 dark:border-zinc-700",
              badge: "bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-300",
              label: "Document",
            };

  return (
    <div className="flex flex-col justify-between rounded-2xl border border-zinc-200/80 dark:border-zinc-800/80 bg-white dark:bg-[#181818] p-4.5 shadow-2xs transition-all hover:border-zinc-300 dark:hover:border-zinc-700 hover:shadow-xs">
      <div>
        <div className="flex items-start justify-between gap-2.5">
          <div className="flex items-center gap-2.5">
            <div
              className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border ${colorStyles.bg}`}
            >
              {isFolder ? (
                <FolderIcon />
              ) : isHandbook ? (
                <BookIcon />
              ) : isSlides ? (
                <SlidesIcon />
              ) : isForm ? (
                <FormIcon />
              ) : (
                <DocumentIcon />
              )}
            </div>
            <div>
              <span
                className={`inline-block shrink-0 whitespace-nowrap rounded px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider ${colorStyles.badge}`}
              >
                {colorStyles.label}
              </span>
            </div>
          </div>

          {resource.authority_tier === "verified_resource" && (
            <span
              className="text-[10px] text-emerald-600 dark:text-emerald-400 font-semibold flex items-center gap-0.5 shrink-0"
              title="Official Verified Resource"
            >
              <CheckBadgeIcon />
              <span>Verified</span>
            </span>
          )}
        </div>

        <h3 className="mt-3 text-sm font-bold text-zinc-900 dark:text-zinc-100 leading-snug">
          {resource.name}
        </h3>

        <p className="mt-1 text-xs text-zinc-500 dark:text-zinc-400 line-clamp-2 leading-relaxed">
          {resource.description || "Official community resource."}
        </p>
      </div>

      <div className="mt-4 flex items-center justify-between gap-2 pt-3 border-t border-zinc-100/90 dark:border-zinc-800/80">
        <div className="flex items-center gap-1.5">
          {resource.url && (
            <a
              href={resource.url}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-1 rounded-lg bg-zinc-900 dark:bg-zinc-100 px-3 py-1.5 text-xs font-semibold text-white dark:text-zinc-900 shadow-2xs hover:bg-zinc-800 dark:hover:bg-white active:scale-98 cursor-pointer"
            >
              <span>Open</span>
              <ExternalLinkIcon />
            </a>
          )}

          {resource.url && (
            <button
              type="button"
              onClick={onCopy}
              className="inline-flex items-center gap-1 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-2.5 py-1.5 text-xs font-medium text-zinc-600 dark:text-zinc-300 shadow-2xs hover:bg-zinc-50 dark:hover:bg-zinc-700 active:scale-98 cursor-pointer"
              title="Copy share link"
            >
              {copied ? (
                <span className="text-emerald-600 dark:text-emerald-400 font-semibold flex items-center gap-0.5">
                  <CheckIcon /> Copied
                </span>
              ) : (
                <span className="flex items-center gap-0.5">
                  <CopyIcon /> Copy
                </span>
              )}
            </button>
          )}
        </div>

        {/* Ask Zak button disabled for now per user instruction */}
      </div>
    </div>
  );
}

// Icons
function FolderIcon() {
  return (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden>
      <path
        d="M3 7v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-6l-2-2H5a2 2 0 0 0-2 2z"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

function BookIcon() {
  return (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden>
      <path
        d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"
        stroke="currentColor"
        strokeWidth="2"
      />
      <path d="M6 6h10M6 10h10" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
    </svg>
  );
}

function SlidesIcon() {
  return (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden>
      <rect x="2" y="3" width="20" height="14" rx="2" stroke="currentColor" strokeWidth="2" />
      <path d="M8 21h8M12 17v4" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
      <path d="m10 8 5 3-5 3V8z" fill="currentColor" />
    </svg>
  );
}

function FormIcon() {
  return (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden>
      <path
        d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2M9 5h6"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
      />
      <path d="m9 14 2 2 4-4" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
    </svg>
  );
}

function DocumentIcon() {
  return (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden>
      <path
        d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"
        stroke="currentColor"
        strokeWidth="2"
      />
      <polyline points="14 2 14 8 20 8" stroke="currentColor" strokeWidth="2" />
      <line x1="16" y1="13" x2="8" y2="13" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
      <line x1="16" y1="17" x2="8" y2="17" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
    </svg>
  );
}

function ExternalLinkIcon() {
  return (
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden>
      <path
        d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3"
        stroke="currentColor"
        strokeWidth="2.5"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

function CopyIcon() {
  return (
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden>
      <rect x="9" y="9" width="13" height="13" rx="2" stroke="currentColor" strokeWidth="2" />
      <path
        d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"
        stroke="currentColor"
        strokeWidth="2"
      />
    </svg>
  );
}

function CheckIcon() {
  return (
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden>
      <path
        d="M20 6L9 17l-5-5"
        stroke="currentColor"
        strokeWidth="2.5"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

function CheckBadgeIcon() {
  return (
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" aria-hidden>
      <path
        d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"
        fill="currentColor"
      />
    </svg>
  );
}

function SearchIcon() {
  return (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden>
      <circle cx="11" cy="11" r="8" stroke="currentColor" strokeWidth="2" />
      <path d="m21 21-4.35-4.35" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
    </svg>
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
