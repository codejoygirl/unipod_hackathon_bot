"use client";

import React, { useState, useEffect, useCallback } from "react";
import { useWebChat } from "@/lib/web-chat/web-chat-context";
import { apiFetch } from "@/lib/api/client";
import { featureRequestSendingLabel } from "@/lib/ui/outbound-status";

interface RequestFeatureModalProps {
  isOpen: boolean;
  onClose: () => void;
}

interface FeatureResponse {
  data: {
    id: string;
    ref?: string;
    status: string;
    message: string;
  };
}

export function RequestFeatureModal({ isOpen, onClose }: RequestFeatureModalProps) {
  const { community, memberPhone, isAdmin, adminName } = useWebChat();

  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [contactName, setContactName] = useState(() => adminName || "");
  const [contactPhone, setContactPhone] = useState(() => memberPhone || "");
  const [fieldErrors, setFieldErrors] = useState<{ title?: string; description?: string }>({});
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isClosing, setIsClosing] = useState(false);
  const [submittedRef, setSubmittedRef] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const handleClose = useCallback(() => {
    setIsClosing(true);
    setTimeout(() => {
      onClose();
      setIsClosing(false);
      setSubmittedRef(null);
      setError(null);
      setFieldErrors({});
    }, 180);
  }, [onClose]);

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape" && isOpen) {
        handleClose();
      }
    };
    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [isOpen, handleClose]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    const newErrors: { title?: string; description?: string } = {};
    if (!title.trim()) {
      newErrors.title = "Feature title is required.";
    }
    if (!description.trim()) {
      newErrors.description = "Please describe what problem this solves.";
    }

    if (Object.keys(newErrors).length > 0) {
      setFieldErrors(newErrors);
      return;
    }

    setFieldErrors({});
    setIsSubmitting(true);
    setError(null);

    // Automatically determine role without requiring manual selection
    const userType = isAdmin ? "admin" : "member";

    try {
      const res = await apiFetch<FeatureResponse>("/api/v1/web-chat/feature-request", {
        method: "POST",
        body: JSON.stringify({
          title: title.trim(),
          description: description.trim(),
          user_type: userType,
          phone: contactPhone.trim() || undefined,
          name: contactName.trim() || undefined,
          community_id: community?.id,
        }),
      });

      setSubmittedRef(res.data.ref || "LOGGED");
      setTitle("");
      setDescription("");
      setFieldErrors({});
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Failed to submit request. Please try again.";
      setError(msg);
    } finally {
      setIsSubmitting(false);
    }
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4">
      {/* Backdrop */}
      <div
        className={`fixed inset-0 bg-black/60 backdrop-blur-xs transition-opacity duration-200 ease-out ${
          isClosing ? "opacity-0" : "opacity-100"
        }`}
        onClick={handleClose}
        aria-hidden="true"
      />

      {/* Modal Dialog */}
      <div
        className={`relative flex max-h-[90vh] w-full max-w-lg flex-col rounded-2xl border border-zinc-200 bg-white shadow-2xl transition-all dark:border-zinc-800 dark:bg-[#181818] z-10 overflow-hidden select-none ${
          isClosing ? "animate-modal-out" : "animate-modal-in"
        }`}
      >
        {/* Header */}
        <div className="flex items-center justify-between border-b border-zinc-100 px-5 py-4 dark:border-zinc-800">
          <div className="flex items-center gap-2.5">
            <div className="flex h-8 w-8 items-center justify-center rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M12 2v1M12 21v1M4.22 4.22l.71.71M19.07 19.07l.71.71M2 12h1M21 12h1M4.22 19.78l.71-.71M19.07 4.93l.71-.71" />
                <path d="M9 18h6M10 22h4" />
                <path d="M12 6a6 6 0 0 0-6 6c0 2.2 1.2 4.1 3 5.1V18h6v-.9c1.8-1 3-2.9 3-5.1a6 6 0 0 0-6-6z" />
              </svg>
            </div>
            <div>
              <h3 className="text-base font-semibold text-zinc-900 dark:text-zinc-100">
                Request a Feature
              </h3>
              <p className="text-xs text-zinc-500 dark:text-zinc-400">
                Share an idea or tool improvement for the UniPods assistant
              </p>
            </div>
          </div>

          <button
            type="button"
            onClick={handleClose}
            className="rounded-lg p-1.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200 cursor-pointer"
            aria-label="Close"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <line x1="18" y1="6" x2="6" y2="18" />
              <line x1="6" y1="6" x2="18" y2="18" />
            </svg>
          </button>
        </div>

        {/* Content Body */}
        <div className="p-5 overflow-y-auto no-scrollbar">
          {submittedRef ? (
            <div className="flex flex-col items-center py-6 text-center animate-fade-in">
              <div className="flex h-12 w-12 items-center justify-center rounded-full bg-blue-100 text-blue-600 dark:bg-blue-950/80 dark:text-blue-400 mb-3">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                  <polyline points="20 6 9 17 4 12" />
                </svg>
              </div>

              <h4 className="text-base font-bold text-zinc-900 dark:text-zinc-100">
                Feature Request Received!
              </h4>

              <div className="mt-2 inline-flex items-center gap-1.5 rounded-lg border border-blue-300/80 bg-blue-50 px-3 py-1 font-mono text-xs font-bold text-blue-800 dark:border-blue-800 dark:bg-blue-950/50 dark:text-blue-300">
                <span>Ref Code:</span>
                <span>{submittedRef}</span>
              </div>

              <p className="mt-3 text-xs leading-relaxed text-zinc-600 dark:text-zinc-300 max-w-sm">
                Your suggestion has been logged to the database and dispatched directly to the programme coordinators via Telegram and WhatsApp.
              </p>

              <button
                type="button"
                onClick={handleClose}
                className="mt-6 inline-flex items-center justify-center rounded-xl bg-zinc-900 px-5 py-2.5 text-xs font-semibold text-white shadow-xs hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-200 cursor-pointer"
              >
                Done
              </button>
            </div>
          ) : (
            <form onSubmit={handleSubmit} noValidate className="space-y-4">
              {/* Feature Title */}
              <div>
                <label htmlFor="feature-title" className="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1.5">
                  Feature Title <span className="text-blue-600 dark:text-blue-400">*</span>
                </label>
                <input
                  id="feature-title"
                  type="text"
                  placeholder="e.g. Add calendar sync for mentoring sessions"
                  value={title}
                  onChange={(e) => {
                    setTitle(e.target.value);
                    if (fieldErrors.title) {
                      setFieldErrors((prev) => ({ ...prev, title: undefined }));
                    }
                  }}
                  className={`w-full rounded-xl border bg-white px-3.5 py-2.5 text-xs text-zinc-900 placeholder:text-zinc-400 focus:outline-none focus:ring-1 shadow-2xs transition dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder:text-zinc-500 ${
                    fieldErrors.title
                      ? "border-rose-400 focus:border-rose-500 focus:ring-rose-500/20"
                      : "border-zinc-300 focus:border-blue-500 focus:ring-blue-500 dark:border-zinc-700"
                  }`}
                />
                {fieldErrors.title && (
                  <p className="mt-1 text-[11px] font-medium text-rose-600 dark:text-rose-400 animate-fade-in">
                    {fieldErrors.title}
                  </p>
                )}
              </div>

              {/* Feature Description */}
              <div>
                <label htmlFor="feature-desc" className="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1.5">
                  Description & Impact <span className="text-blue-600 dark:text-blue-400">*</span>
                </label>
                <textarea
                  id="feature-desc"
                  rows={4}
                  placeholder="Describe what problem this solves and how you envision it working..."
                  value={description}
                  onChange={(e) => {
                    setDescription(e.target.value);
                    if (fieldErrors.description) {
                      setFieldErrors((prev) => ({ ...prev, description: undefined }));
                    }
                  }}
                  className={`w-full rounded-xl border bg-white p-3.5 text-xs text-zinc-900 placeholder:text-zinc-400 focus:outline-none focus:ring-1 shadow-2xs resize-none transition dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder:text-zinc-500 ${
                    fieldErrors.description
                      ? "border-rose-400 focus:border-rose-500 focus:ring-rose-500/20"
                      : "border-zinc-300 focus:border-blue-500 focus:ring-blue-500 dark:border-zinc-700"
                  }`}
                />
                {fieldErrors.description && (
                  <p className="mt-1 text-[11px] font-medium text-rose-600 dark:text-rose-400 animate-fade-in">
                    {fieldErrors.description}
                  </p>
                )}
              </div>

              {/* Name & Phone in 2 columns (No "(Optional)" in labels) */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label htmlFor="contact-name" className="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                    Your Name
                  </label>
                  <input
                    id="contact-name"
                    type="text"
                    placeholder="e.g. Jane Doe"
                    value={contactName}
                    onChange={(e) => setContactName(e.target.value)}
                    className="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-xs text-zinc-900 placeholder:text-zinc-400 focus:border-blue-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder:text-zinc-500"
                  />
                </div>

                <div>
                  <label htmlFor="contact-phone" className="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                    Phone / WhatsApp
                  </label>
                  <input
                    id="contact-phone"
                    type="tel"
                    placeholder="e.g. +254..."
                    value={contactPhone}
                    onChange={(e) => setContactPhone(e.target.value)}
                    className="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-xs text-zinc-900 placeholder:text-zinc-400 focus:border-blue-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder:text-zinc-500"
                  />
                </div>
              </div>

              {error && (
                <div className="rounded-xl border border-rose-200 bg-rose-50 p-2.5 text-xs text-rose-700 dark:border-rose-900/60 dark:bg-rose-950/40 dark:text-rose-300">
                  {error}
                </div>
              )}

              {/* Submit Button */}
              <div className="pt-2 flex items-center justify-end gap-2">
                <button
                  type="button"
                  onClick={handleClose}
                  disabled={isSubmitting}
                  className="rounded-xl px-4 py-2.5 text-xs font-medium text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800 cursor-pointer"
                >
                  Cancel
                </button>

                <button
                  type="submit"
                  disabled={isSubmitting}
                  className="inline-flex items-center gap-1.5 rounded-xl bg-zinc-900 px-5 py-2.5 text-xs font-semibold text-white shadow-xs hover:bg-zinc-800 disabled:opacity-50 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-200 cursor-pointer"
                >
                  {isSubmitting ? (
                    <>
                      <span className="h-3 w-3 animate-spin rounded-full border-2 border-white/20 border-t-white dark:border-zinc-900/20 dark:border-t-zinc-900" />
                      <span>{featureRequestSendingLabel()}</span>
                    </>
                  ) : (
                    <>
                      <span>Submit Request</span>
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                        <line x1="5" y1="12" x2="19" y2="12" />
                        <polyline points="12 5 19 12 12 19" />
                      </svg>
                    </>
                  )}
                </button>
              </div>
            </form>
          )}
        </div>
      </div>
    </div>
  );
}
