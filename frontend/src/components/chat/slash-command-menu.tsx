"use client";

import React, { useState, useEffect, useRef } from "react";
import { ALL_COMMANDS } from "@/lib/chat/commands-data";

interface SlashCommandMenuProps {
  isOpen: boolean;
  filterText: string;
  isAdmin?: boolean;
  onSelect: (commandText: string) => void;
  onClose: () => void;
}

export function SlashCommandMenu({
  isOpen,
  filterText,
  isAdmin = false,
  onSelect,
  onClose,
}: SlashCommandMenuProps) {
  const [selectedIndex, setSelectedIndex] = useState(0);
  const [prevFilter, setPrevFilter] = useState(filterText);
  const containerRef = useRef<HTMLDivElement>(null);

  if (prevFilter !== filterText) {
    setPrevFilter(filterText);
    setSelectedIndex(0);
  }

  const cleanFilter = filterText.startsWith("/") ? filterText.slice(1).toLowerCase() : filterText.toLowerCase();

  const filteredCommands = ALL_COMMANDS.filter((cmd) => {
    if (!isAdmin && cmd.category === "admin") {
      return false;
    }
    if (!cleanFilter) return true;
    return (
      cmd.command.toLowerCase().includes(cleanFilter) ||
      cmd.description.toLowerCase().includes(cleanFilter)
    );
  });

  // Handle keyboard navigation (ArrowUp, ArrowDown, Enter, Escape)
  useEffect(() => {
    if (!isOpen) return;

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === "ArrowDown") {
        e.preventDefault();
        setSelectedIndex((prev) => (prev + 1) % Math.max(1, filteredCommands.length));
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        setSelectedIndex((prev) => (prev - 1 + filteredCommands.length) % Math.max(1, filteredCommands.length));
      } else if (e.key === "Enter" && filteredCommands.length > 0) {
        e.preventDefault();
        const selected = filteredCommands[selectedIndex];
        if (selected) {
          onSelect(selected.example || selected.command + " ");
        }
      } else if (e.key === "Escape") {
        e.preventDefault();
        onClose();
      }
    };

    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [isOpen, filteredCommands, selectedIndex, onSelect, onClose]);

  // Close on outside click
  useEffect(() => {
    if (!isOpen) return;

    const handleClickOutside = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        onClose();
      }
    };

    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, [isOpen, onClose]);

  if (!isOpen || filteredCommands.length === 0) {
    return null;
  }

  return (
    <div
      ref={containerRef}
      className="absolute bottom-full left-0 right-0 mb-2 z-40 max-h-72 overflow-y-auto no-scrollbar rounded-2xl border border-zinc-200/90 bg-white/98 p-1.5 shadow-xl backdrop-blur-md transition-all animate-popover-in dark:border-zinc-700/80 dark:bg-[#1f1f1f]/98"
    >
      <div className="flex items-center justify-between px-3 py-1.5 text-[11px] font-semibold text-zinc-400 dark:text-zinc-500 uppercase tracking-wider border-b border-zinc-100 dark:border-zinc-800">
        <span>Quick Commands</span>
        <span>Use ↑↓ to navigate · Enter to select</span>
      </div>

      <div className="mt-1 space-y-0.5">
        {filteredCommands.map((item, index) => {
          const isSelected = index === selectedIndex;
          return (
            <button
              key={item.command}
              type="button"
              onClick={() => onSelect(item.example || item.command + " ")}
              onMouseEnter={() => setSelectedIndex(index)}
              className={`w-full flex items-center justify-between rounded-xl px-3 py-2 text-left transition cursor-pointer ${
                isSelected
                  ? "bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100"
                  : "text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-800/60"
              }`}
            >
              <div className="flex items-center gap-2.5 min-w-0">
                <span className="font-mono text-xs font-bold text-emerald-600 dark:text-emerald-400 shrink-0">
                  {item.command}
                </span>
                <span className="truncate text-xs text-zinc-600 dark:text-zinc-300">
                  {item.description}
                </span>
              </div>

              <div className="flex items-center gap-1.5 shrink-0 ml-2">
                <span className="hidden sm:inline font-mono text-[10px] text-zinc-400 dark:text-zinc-500 truncate max-w-44">
                  {item.example}
                </span>
                <span className="text-[10px] text-zinc-400">↵</span>
              </div>
            </button>
          );
        })}
      </div>
    </div>
  );
}
