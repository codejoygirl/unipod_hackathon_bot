"use client";

import React, { useState, useEffect } from "react";
import {
  MEMBER_COMMANDS,
  ADMIN_COMMANDS,
  ALL_COMMANDS,
  insertChatCommand,
} from "@/lib/chat/commands-data";
import { Tooltip } from "@/components/ui/tooltip";

interface QuickCommandsModalProps {
  isAdmin?: boolean;
  onPopulate?: (text: string) => void;
  trigger?: React.ReactNode;
}

export function QuickCommandsModal({ isAdmin = false, onPopulate, trigger }: QuickCommandsModalProps) {
  const [isOpen, setIsOpen] = useState(false);
  const [isClosing, setIsClosing] = useState(false);
  const [activeTab, setActiveTab] = useState<"all" | "member" | "admin">(isAdmin ? "all" : "member");
  const [search, setSearch] = useState("");
  const [copiedId, setCopiedId] = useState<string | null>(null);

  const handleClose = () => {
    setIsClosing(true);
    setTimeout(() => {
      setIsOpen(false);
      setIsClosing(false);
    }, 180);
  };

  const handleSelect = (text: string, id: string) => {
    if (onPopulate) {
      onPopulate(text);
    } else {
      insertChatCommand(text);
    }
    setCopiedId(id);
    setTimeout(() => {
      setCopiedId(null);
      handleClose();
    }, 200);
  };

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape" && isOpen) {
        handleClose();
      }
    };
    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [isOpen]);

  const displayedCommands = (
    activeTab === "member"
      ? MEMBER_COMMANDS
      : activeTab === "admin"
      ? ADMIN_COMMANDS
      : [...MEMBER_COMMANDS, ...ADMIN_COMMANDS]
  ).filter(
    (c) =>
      c.command.toLowerCase().includes(search.toLowerCase()) ||
      c.description.toLowerCase().includes(search.toLowerCase()) ||
      c.example.toLowerCase().includes(search.toLowerCase())
  );

  return (
    <>
      {trigger ? (
        <div
          onClick={() => {
            setIsClosing(false);
            setIsOpen(true);
          }}
        >
          {trigger}
        </div>
      ) : (
        <Tooltip content="Quick Commands & Guide" position="bottom" shortcut="/">
          <button
            type="button"
            onClick={() => {
              setIsClosing(false);
              setIsOpen(true);
            }}
            className="flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-zinc-50 px-2.5 py-1 text-xs font-semibold text-zinc-700 shadow-2xs transition hover:border-zinc-300 hover:bg-zinc-100 hover:text-zinc-950 active:scale-95 dark:border-zinc-700/80 dark:bg-zinc-800/90 dark:text-zinc-300 dark:hover:border-zinc-600 dark:hover:bg-zinc-700/80 dark:hover:text-zinc-100"
            aria-label="Open commands and guide"
          >
            <span className="font-mono text-blue-600 dark:text-blue-400">/</span>
            <span>Commands</span>
          </button>
        </Tooltip>
      )}

      {isOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4">
          {/* Backdrop */}
          <div
            className={`fixed inset-0 bg-black/60 backdrop-blur-xs transition-opacity duration-200 ease-out ${
              isClosing ? "opacity-0" : "opacity-100"
            }`}
            onClick={handleClose}
            aria-hidden="true"
          />

          {/* Modal Container */}
          <div
            className={`relative flex max-h-[88vh] w-full max-w-2xl flex-col rounded-2xl border border-zinc-200 bg-white shadow-2xl transition-all dark:border-zinc-700/80 dark:bg-[#1a1a1a] z-10 overflow-hidden ${
              isClosing ? "animate-modal-out" : "animate-modal-in"
            }`}
          >
            {/* Header */}
            <div className="border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2.5">
                  <div className="flex h-8 w-8 items-center justify-center rounded-xl bg-blue-500/10 font-mono text-sm font-bold text-blue-600 dark:text-blue-400">
                    /
                  </div>
                  <div>
                    <h3 className="text-base font-semibold text-zinc-900 dark:text-zinc-100">
                      Assistant Commands & Guide
                    </h3>
                    <p className="text-xs text-zinc-500 dark:text-zinc-400">
                      Click any command or example below to auto-populate your chat box
                    </p>
                  </div>
                </div>

                <button
                  type="button"
                  onClick={handleClose}
                  className="rounded-lg p-1.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200 cursor-pointer"
                  aria-label="Close modal"
                >
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <line x1="18" y1="6" x2="6" y2="18" />
                    <line x1="6" y1="6" x2="18" y2="18" />
                  </svg>
                </button>
              </div>

              {/* Search & Tabs */}
              <div className="mt-4 flex flex-col sm:flex-row sm:items-center justify-between gap-2.5">
                {/* Search Bar */}
                <div className="relative flex-1">
                  <svg
                    width="14"
                    height="14"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="2"
                    className="absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400"
                  >
                    <circle cx="11" cy="11" r="8" />
                    <line x1="21" y1="21" x2="16.65" y2="16.65" />
                  </svg>
                  <input
                    type="text"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search commands or examples..."
                    className="w-full rounded-xl border border-zinc-200 bg-zinc-50/80 py-1.5 pl-8 pr-3 text-xs text-zinc-900 placeholder:text-zinc-400 focus:border-zinc-400 focus:outline-none dark:border-zinc-700/80 dark:bg-zinc-900/60 dark:text-zinc-100"
                  />
                </div>

                {/* Category Filter Pills */}
                <div className="flex items-center gap-1.5 self-start sm:self-auto">
                  <button
                    type="button"
                    onClick={() => setActiveTab("all")}
                    className={`rounded-lg px-2.5 py-1 text-xs font-semibold transition ${
                      activeTab === "all"
                        ? "bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900"
                        : "text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800"
                    }`}
                  >
                    All ({ALL_COMMANDS.length})
                  </button>
                  <button
                    type="button"
                    onClick={() => setActiveTab("member")}
                    className={`rounded-lg px-2.5 py-1 text-xs font-semibold transition ${
                      activeTab === "member"
                        ? "bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900"
                        : "text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800"
                    }`}
                  >
                    Member ({MEMBER_COMMANDS.length})
                  </button>
                  <button
                    type="button"
                    onClick={() => setActiveTab("admin")}
                    className={`rounded-lg px-2.5 py-1 text-xs font-semibold transition ${
                      activeTab === "admin"
                        ? "bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900"
                        : "text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800"
                    }`}
                  >
                    Admin ({ADMIN_COMMANDS.length})
                  </button>
                </div>
              </div>
            </div>

            {/* Natural language & Group chat note */}
            <div className="border-b border-zinc-200 bg-zinc-50/60 px-5 py-2.5 text-[11px] text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900/40 dark:text-zinc-400">
              <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                <span>💬 <strong>Plain Language:</strong> No command required — just ask directly anytime.</span>
                <span>👥 <strong>Group chats:</strong> @mention me or reply to my message so I know you mean me.</span>
              </div>
            </div>

            {/* List of Command Cards */}
            <div className="flex-1 overflow-y-auto no-scrollbar p-5 space-y-3">
              {displayedCommands.map((item) => {
                const isCopied = copiedId === item.command;
                return (
                  <div
                    key={item.command}
                    className="group rounded-xl border border-zinc-200/90 bg-white p-3.5 shadow-2xs transition-all hover:border-zinc-300 hover:shadow-xs dark:border-zinc-800 dark:bg-zinc-900/50 dark:hover:border-zinc-700"
                  >
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                      <div className="flex items-center gap-2">
                        {/* Command pill - Clickable */}
                        <button
                          type="button"
                          onClick={() => handleSelect(item.command + " ", item.command)}
                          className="inline-flex items-center gap-1 rounded-lg border border-blue-300 bg-blue-50 px-2.5 py-1 font-mono text-xs font-bold text-blue-800 shadow-2xs transition hover:bg-blue-100 hover:border-blue-400 active:scale-95 dark:border-blue-800/70 dark:bg-blue-950/40 dark:text-blue-300 dark:hover:bg-blue-900/60 cursor-pointer"
                          title="Click to populate this command"
                        >
                          <span>{item.command}</span>
                          <span className="text-[10px] opacity-75">↵</span>
                        </button>

                        <span
                          className={`shrink-0 whitespace-nowrap rounded px-1.5 py-0.5 font-mono text-[9px] font-bold uppercase tracking-wider ${
                            item.category === "admin"
                              ? "bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300"
                              : "bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400"
                          }`}
                        >
                          {item.category}
                        </span>

                        {item.badge && (
                          <span className="shrink-0 whitespace-nowrap rounded-full bg-blue-100 px-1.5 py-0.5 text-[9px] font-semibold text-blue-800 dark:bg-blue-950 dark:text-blue-300">
                            {item.badge}
                          </span>
                        )}
                      </div>

                      {/* Populate / Insert Button */}
                      <button
                        type="button"
                        onClick={() => handleSelect(item.example, item.command)}
                        className={`inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-medium transition cursor-pointer shadow-2xs active:scale-95 ${
                          isCopied
                            ? "bg-blue-600 text-white"
                            : "border border-zinc-200 bg-zinc-50 text-zinc-700 hover:bg-zinc-100 hover:text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                        }`}
                      >
                        {isCopied ? (
                          <>
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                              <polyline points="20 6 9 17 4 12" />
                            </svg>
                            <span>Populated!</span>
                          </>
                        ) : (
                          <>
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                              <path d="M12 5v14M5 12h14" />
                            </svg>
                            <span>Use Example</span>
                          </>
                        )}
                      </button>
                    </div>

                    {/* Description */}
                    <p className="mt-1.5 text-xs text-zinc-600 dark:text-zinc-300 leading-relaxed">
                      {item.description}
                    </p>

                    {/* Example Chip - Clickable to insert exact example */}
                    <div className="mt-2.5 flex items-center gap-2">
                      <span className="text-[11px] font-semibold text-zinc-400 dark:text-zinc-500">
                        e.g.
                      </span>
                      <button
                        type="button"
                        onClick={() => handleSelect(item.example, item.command)}
                        className="inline-flex max-w-full items-center gap-1.5 rounded-lg border border-zinc-200/80 bg-zinc-50/80 px-2.5 py-1 text-left font-mono text-[11px] text-zinc-700 transition hover:border-zinc-300 hover:bg-zinc-100 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-300 dark:hover:border-zinc-700 dark:hover:bg-zinc-800 cursor-pointer"
                        title="Click to populate this example"
                      >
                        <span className="truncate">{item.example}</span>
                        <span className="text-[9px] text-zinc-400">↗</span>
                      </button>
                    </div>
                  </div>
                );
              })}

              {displayedCommands.length === 0 && (
                <div className="py-12 text-center text-xs text-zinc-500">
                  No commands found matching &quot;{search}&quot;.
                </div>
              )}
            </div>

            {/* Footer */}
            <div className="flex items-center justify-between border-t border-zinc-200 bg-zinc-50 px-5 py-3 text-xs text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/50 dark:text-zinc-400">
              <span>Tip: Type <kbd className="rounded border border-zinc-300 px-1 font-mono text-[10px] dark:border-zinc-700">/</kbd> in the chat input for instant commands.</span>
              <button
                type="button"
                onClick={handleClose}
                className="rounded-xl bg-zinc-900 px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-200 cursor-pointer"
              >
                Done
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
