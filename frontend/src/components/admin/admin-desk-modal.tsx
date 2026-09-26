"use client";

import React, { useCallback, useEffect, useState } from "react";
import { useWebChat } from "@/lib/web-chat/web-chat-context";
import { apiFetch } from "@/lib/api/client";
import { ThemeSelect } from "@/components/ui/theme-select";
import { DateTimePicker } from "@/components/ui/date-time-picker";

type DeskTab = "update" | "asset" | "meeting" | "import";

const TABS: { id: DeskTab; label: string; hint: string }[] = [
  { id: "update", label: "Update", hint: "Announce to the cohort" },
  { id: "asset", label: "Asset", hint: "Handbook, form, slides" },
  { id: "meeting", label: "Meeting", hint: "Teams / Meet / Zoom link" },
  { id: "import", label: "Knowledge", hint: "Paste notes for the assistant" },
];

export function openAdminDesk(tab: DeskTab = "update") {
  if (typeof window !== "undefined") {
    window.dispatchEvent(new CustomEvent("open-admin-desk", { detail: { tab } }));
  }
}

export function AdminDeskModal() {
  const { memberPhone, adminToken, isAdmin, phase } = useWebChat();
  const [isOpen, setIsOpen] = useState(false);
  const [isClosing, setIsClosing] = useState(false);
  const [tab, setTab] = useState<DeskTab>("update");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  // Update form
  const [title, setTitle] = useState("");
  const [message, setMessage] = useState("");
  const [category, setCategory] = useState("Announcement");
  const [actionQuery, setActionQuery] = useState("");

  // Asset form
  const [assetKind, setAssetKind] = useState("handbook");
  const [assetTitle, setAssetTitle] = useState("");
  const [assetUrl, setAssetUrl] = useState("");

  // Meeting form
  const [meetingTitle, setMeetingTitle] = useState("");
  const [meetingUrl, setMeetingUrl] = useState("");
  const [meetingPlatform, setMeetingPlatform] = useState("teams");
  const [meetingCategory, setMeetingCategory] = useState("office_hours");
  const [meetingSchedule, setMeetingSchedule] = useState("");
  const [meetingHost, setMeetingHost] = useState("");
  const [meetingDescription, setMeetingDescription] = useState("");

  // Import form
  const [importName, setImportName] = useState("");
  const [importContent, setImportContent] = useState("");

  const handleClose = useCallback(() => {
    setIsClosing(true);
    setTimeout(() => {
      setIsOpen(false);
      setIsClosing(false);
      setError(null);
      setSuccess(null);
    }, 180);
  }, []);

  useEffect(() => {
    const onOpen = (e: Event) => {
      const detail = (e as CustomEvent<{ tab?: DeskTab }>).detail;
      setTab(detail?.tab ?? "update");
      setError(null);
      setSuccess(null);
      setIsOpen(true);
    };
    window.addEventListener("open-admin-desk", onOpen);
    return () => window.removeEventListener("open-admin-desk", onOpen);
  }, []);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape" && isOpen) handleClose();
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [isOpen, handleClose]);

  const adminHeaders = (): HeadersInit => {
    const headers: Record<string, string> = {};
    if (adminToken) {
      headers.Authorization = `Bearer ${adminToken}`;
      headers["X-Admin-Token"] = adminToken;
    }
    return headers;
  };

  const resetSuccessForms = () => {
    if (tab === "update") {
      setTitle("");
      setMessage("");
      setActionQuery("");
    } else if (tab === "asset") {
      setAssetTitle("");
      setAssetUrl("");
    } else if (tab === "meeting") {
      setMeetingTitle("");
      setMeetingUrl("");
      setMeetingSchedule("");
      setMeetingHost("");
      setMeetingDescription("");
    } else {
      setImportName("");
      setImportContent("");
    }
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!memberPhone || !adminToken) {
      setError("Admin session required. Sign in again with your admin password.");
      return;
    }

    setBusy(true);
    setError(null);
    setSuccess(null);

    try {
      if (tab === "update") {
        if (!title.trim() || !message.trim()) {
          setError("Title and message are required.");
          setBusy(false);
          return;
        }
        await apiFetch("/api/v1/web-chat/notifications", {
          method: "POST",
          headers: adminHeaders(),
          body: JSON.stringify({
            phone: memberPhone,
            admin_token: adminToken,
            title: title.trim(),
            message: message.trim(),
            category,
            action_query: actionQuery.trim() || undefined,
          }),
        });
        setSuccess("Update published. Members will see it in the bell, and push subscribers get an alert.");
      } else if (tab === "asset") {
        if (!assetTitle.trim() || !assetUrl.trim()) {
          setError("Title and link are required.");
          setBusy(false);
          return;
        }
        const res = await apiFetch<{ data: { reply?: string } }>("/api/v1/web-chat/admin/assets", {
          method: "POST",
          headers: adminHeaders(),
          body: JSON.stringify({
            phone: memberPhone,
            admin_token: adminToken,
            kind: assetKind,
            title: assetTitle.trim(),
            url: assetUrl.trim(),
          }),
        });
        setSuccess(res.data.reply || "Resource published to the community catalog.");
      } else if (tab === "meeting") {
        if (!meetingTitle.trim() || !meetingUrl.trim()) {
          setError("Meeting title and join URL are required.");
          setBusy(false);
          return;
        }
        await apiFetch("/api/v1/web-chat/admin/meetings", {
          method: "POST",
          headers: adminHeaders(),
          body: JSON.stringify({
            phone: memberPhone,
            admin_token: adminToken,
            title: meetingTitle.trim(),
            url: meetingUrl.trim(),
            platform: meetingPlatform,
            category: meetingCategory,
            schedule: meetingSchedule.trim() || undefined,
            host: meetingHost.trim() || undefined,
            description: meetingDescription.trim() || undefined,
          }),
        });
        setSuccess("Meeting published. It appears on the Meetings hub for members.");
      } else {
        if (!importContent.trim()) {
          setError("Paste the knowledge text to import.");
          setBusy(false);
          return;
        }
        const res = await apiFetch<{ data: { reply?: string; hint?: string } }>("/api/v1/web-chat/admin/import", {
          method: "POST",
          headers: adminHeaders(),
          body: JSON.stringify({
            phone: memberPhone,
            admin_token: adminToken,
            name: importName.trim() || undefined,
            content: importContent.trim(),
          }),
        });
        setSuccess(
          [res.data.reply, res.data.hint].filter(Boolean).join(" ") ||
            "Draft imported. Publish it from chat when ready.",
        );
      }
      resetSuccessForms();
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Something went wrong. Try again.");
    } finally {
      setBusy(false);
    }
  };

  if (!isOpen || !isAdmin || phase !== "ready") return null;

  const activeHint = TABS.find((t) => t.id === tab)?.hint;

  return (
    <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4">
      <div
        className={`fixed inset-0 bg-black/55 backdrop-blur-xs transition-opacity duration-200 ${
          isClosing ? "opacity-0" : "opacity-100"
        }`}
        onClick={handleClose}
        aria-hidden
      />

      <div
        className={`relative z-10 flex max-h-[92vh] w-full max-w-xl flex-col overflow-hidden rounded-t-2xl sm:rounded-2xl border border-zinc-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-[#181818] ${
          isClosing ? "animate-modal-out" : "animate-modal-in"
        }`}
        role="dialog"
        aria-modal="true"
        aria-label="Admin desk"
      >
        <div className="flex items-start justify-between gap-3 border-b border-zinc-100 px-5 py-4 dark:border-zinc-800">
          <div className="min-w-0 flex-1">
            <h3 className="text-[10px] font-semibold uppercase tracking-wider text-blue-700 dark:text-blue-400">
              Admin desk
            </h3>
            <div className="mt-2 w-full">
              <ThemeSelect
                value={tab}
                onChange={(v) => {
                  setTab(v as DeskTab);
                  setError(null);
                  setSuccess(null);
                }}
                aria-label="Publish type"
                options={TABS.map((t) => ({ value: t.id, label: t.label }))}
              />
            </div>
            {activeHint ? (
              <p className="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{activeHint}</p>
            ) : null}
          </div>
          <button
            type="button"
            onClick={handleClose}
            className="rounded-lg p-1.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200 cursor-pointer shrink-0"
            aria-label="Close"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <line x1="18" y1="6" x2="6" y2="18" />
              <line x1="6" y1="6" x2="18" y2="18" />
            </svg>
          </button>
        </div>

        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
          <div className="flex-1 overflow-y-auto p-5 space-y-3.5 no-scrollbar">
            {tab === "update" && (
              <>
                <Field label="Title" required>
                  <input
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    placeholder="e.g. Office hour moved to Friday"
                    className={inputClass}
                  />
                </Field>
                <Field label="Message" required>
                  <textarea
                    rows={4}
                    value={message}
                    onChange={(e) => setMessage(e.target.value)}
                    placeholder="What should members know?"
                    className={textareaClass}
                  />
                </Field>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <Field label="Category">
                    <ThemeSelect
                      value={category}
                      onChange={setCategory}
                      aria-label="Category"
                      options={[
                        { value: "Announcement", label: "Announcement" },
                        { value: "Live Session", label: "Live Session" },
                        { value: "Deadline", label: "Deadline" },
                        { value: "System", label: "System" },
                      ]}
                    />
                  </Field>
                  <Field label="Ask-assistant prompt">
                    <input
                      value={actionQuery}
                      onChange={(e) => setActionQuery(e.target.value)}
                      placeholder="Optional follow-up question"
                      className={inputClass}
                    />
                  </Field>
                </div>
              </>
            )}

            {tab === "asset" && (
              <>
                <Field label="Type">
                  <ThemeSelect
                    value={assetKind}
                    onChange={setAssetKind}
                    aria-label="Asset type"
                    options={[
                      { value: "handbook", label: "Handbook" },
                      { value: "form", label: "Form" },
                      { value: "slides", label: "Slides" },
                      { value: "other", label: "Other" },
                    ]}
                  />
                </Field>
                <Field label="Title" required>
                  <input
                    value={assetTitle}
                    onChange={(e) => setAssetTitle(e.target.value)}
                    placeholder="e.g. UniPods Handbook"
                    className={inputClass}
                  />
                </Field>
                <Field label="Share link" required>
                  <input
                    value={assetUrl}
                    onChange={(e) => setAssetUrl(e.target.value)}
                    placeholder="https://drive.google.com/..."
                    className={inputClass}
                  />
                </Field>
                <p className="text-[11px] text-zinc-500 dark:text-zinc-400 leading-relaxed">
                  Same as <code className="font-mono text-[10px]">/asset handbook Title URL</code> on WhatsApp or Telegram.
                </p>
              </>
            )}

            {tab === "meeting" && (
              <>
                <Field label="Title" required>
                  <input
                    value={meetingTitle}
                    onChange={(e) => setMeetingTitle(e.target.value)}
                    placeholder="e.g. Weekly cohort sync"
                    className={inputClass}
                  />
                </Field>
                <Field label="Join URL" required>
                  <input
                    value={meetingUrl}
                    onChange={(e) => setMeetingUrl(e.target.value)}
                    placeholder="https://teams.microsoft.com/... or Meet / Zoom"
                    className={inputClass}
                  />
                </Field>
                <div className="grid grid-cols-2 gap-3">
                  <Field label="Platform">
                    <ThemeSelect
                      value={meetingPlatform}
                      onChange={setMeetingPlatform}
                      aria-label="Meeting platform"
                      options={[
                        { value: "teams", label: "Microsoft Teams" },
                        { value: "meet", label: "Google Meet" },
                        { value: "zoom", label: "Zoom" },
                      ]}
                    />
                  </Field>
                  <Field label="Category">
                    <ThemeSelect
                      value={meetingCategory}
                      onChange={setMeetingCategory}
                      aria-label="Meeting category"
                      options={[
                        { value: "office_hours", label: "Office hours" },
                        { value: "weekly_sync", label: "Weekly sync" },
                        { value: "workshop", label: "Workshop" },
                        { value: "hackathon", label: "Hackathon" },
                        { value: "recap", label: "Recap" },
                      ]}
                    />
                  </Field>
                </div>
                <Field label="Schedule">
                  <DateTimePicker
                    value={meetingSchedule}
                    onChange={(display) => setMeetingSchedule(display)}
                    placeholder="Pick date and time"
                  />
                </Field>
                <Field label="Host">
                  <input
                    value={meetingHost}
                    onChange={(e) => setMeetingHost(e.target.value)}
                    placeholder="Optional host name"
                    className={inputClass}
                  />
                </Field>
                <Field label="Description">
                  <textarea
                    rows={3}
                    value={meetingDescription}
                    onChange={(e) => setMeetingDescription(e.target.value)}
                    placeholder="What this session is for"
                    className={textareaClass}
                  />
                </Field>
              </>
            )}

            {tab === "import" && (
              <>
                <Field label="Draft title">
                  <input
                    value={importName}
                    onChange={(e) => setImportName(e.target.value)}
                    placeholder="Optional — we can suggest one"
                    className={inputClass}
                  />
                </Field>
                <Field label="Paste knowledge" required>
                  <textarea
                    rows={8}
                    value={importContent}
                    onChange={(e) => setImportContent(e.target.value)}
                    placeholder="Paste programme notes, FAQs, or updates…"
                    className={textareaClass}
                  />
                </Field>
                <p className="text-[11px] text-zinc-500 dark:text-zinc-400 leading-relaxed">
                  Creates a draft (same as chat import). Publish from chat with{" "}
                  <code className="font-mono text-[10px]">/publish</code> when ready for members.
                </p>
              </>
            )}

            {error && (
              <div className="rounded-xl border border-rose-200 bg-rose-50 p-2.5 text-xs text-rose-700 dark:border-rose-900/60 dark:bg-rose-950/40 dark:text-rose-300">
                {error}
              </div>
            )}
            {success && (
              <div className="rounded-xl border border-blue-200 bg-blue-50 p-2.5 text-xs text-blue-800 dark:border-blue-900/60 dark:bg-blue-950/40 dark:text-blue-300">
                {success}
              </div>
            )}
          </div>

          <div className="flex shrink-0 items-center justify-end gap-2 border-t border-zinc-100 bg-zinc-50/70 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-900/40">
            <button
              type="button"
              onClick={handleClose}
              disabled={busy}
              className="rounded-xl px-4 py-2.5 text-xs font-medium text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800 cursor-pointer"
            >
              Close
            </button>
            <button
              type="submit"
              disabled={busy}
              className="inline-flex items-center gap-1.5 rounded-xl bg-blue-600 px-5 py-2.5 text-xs font-semibold text-white shadow-xs hover:bg-blue-500 disabled:opacity-50 cursor-pointer"
            >
              {busy ? (
                <>
                  <span className="h-3 w-3 animate-spin rounded-full border-2 border-white/30 border-t-white" />
                  Publishing…
                </>
              ) : tab === "import" ? (
                "Import draft"
              ) : (
                "Publish"
              )}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function Field({
  label,
  required,
  children,
}: {
  label: string;
  required?: boolean;
  children: React.ReactNode;
}) {
  return (
    <div>
      <label className="mb-1.5 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">
        {label}
        {required ? <span className="text-blue-600 dark:text-blue-400"> *</span> : null}
      </label>
      {children}
    </div>
  );
}

const inputClass =
  "w-full rounded-xl border border-zinc-300 bg-white px-3.5 py-2.5 text-xs text-zinc-900 placeholder:text-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder:text-zinc-500";

const textareaClass = `${inputClass} resize-none`;
