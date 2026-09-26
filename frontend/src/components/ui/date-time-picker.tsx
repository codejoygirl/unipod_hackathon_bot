"use client";

import React, { useEffect, useRef, useState } from "react";
import { DayPicker } from "react-day-picker";
import { format, setHours, setMinutes, startOfDay } from "date-fns";
import "react-day-picker/style.css";

type DateTimePickerProps = {
  /** Display / stored schedule string (human readable). */
  value: string;
  onChange: (display: string, iso: string | null) => void;
  placeholder?: string;
};

const HOURS = Array.from({ length: 24 }, (_, i) => i);
const MINUTES = [0, 15, 30, 45];

function parseExisting(value: string): Date | null {
  if (!value.trim()) return null;
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? null : d;
}

export function formatScheduleLabel(date: Date): string {
  return format(date, "EEE, d MMM yyyy 'at' h:mm a") + " GMT";
}

/**
 * Date + time picker styled for UniPod (react-day-picker).
 * Emits a readable schedule string for the meetings API.
 */
export function DateTimePicker({
  value,
  onChange,
  placeholder = "Pick date and time",
}: DateTimePickerProps) {
  const [open, setOpen] = useState(false);
  const rootRef = useRef<HTMLDivElement>(null);
  const initial = parseExisting(value) ?? setMinutes(setHours(startOfDay(new Date()), 13), 0);
  const [selected, setSelected] = useState<Date>(initial);
  const [hour, setHour] = useState(initial.getHours());
  const [minute, setMinute] = useState(
    MINUTES.includes(initial.getMinutes()) ? initial.getMinutes() : 0,
  );

  useEffect(() => {
    if (!open) return;
    const onDoc = (e: MouseEvent) => {
      if (!rootRef.current?.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };
    document.addEventListener("mousedown", onDoc);
    window.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("mousedown", onDoc);
      window.removeEventListener("keydown", onKey);
    };
  }, [open]);

  const apply = (day: Date, h: number, m: number) => {
    const next = setMinutes(setHours(startOfDay(day), h), m);
    setSelected(next);
    onChange(formatScheduleLabel(next), next.toISOString());
  };

  return (
    <div ref={rootRef} className="relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className={`flex w-full items-center justify-between gap-2 rounded-xl border bg-white px-3.5 py-2.5 text-left text-xs shadow-2xs transition focus:outline-none focus:ring-1 dark:bg-zinc-900 ${
          open
            ? "border-blue-500 ring-1 ring-blue-500"
            : "border-zinc-300 hover:border-zinc-400 focus:border-blue-500 focus:ring-blue-500 dark:border-zinc-700"
        }`}
      >
        <span className={value.trim() ? "text-zinc-900 dark:text-zinc-100" : "text-zinc-400 dark:text-zinc-500"}>
          {value.trim() || placeholder}
        </span>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden className="shrink-0 text-zinc-400">
          <rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" strokeWidth="2" />
          <path d="M3 9h18M8 3v4M16 3v4" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
        </svg>
      </button>

      {open && (
        <div className="absolute z-50 mt-1.5 w-[min(100%,20rem)] rounded-2xl border border-zinc-200 bg-white p-3 shadow-xl dark:border-zinc-700 dark:bg-[#1a1a1a]">
          <DayPicker
            mode="single"
            selected={selected}
            onSelect={(day) => {
              if (!day) return;
              apply(day, hour, minute);
            }}
            className="zak-day-picker"
          />

          <div className="mt-2 flex items-center gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
            <label className="text-[10px] font-semibold uppercase tracking-wide text-zinc-400">Time</label>
            <select
              value={hour}
              onChange={(e) => {
                const h = Number(e.target.value);
                setHour(h);
                apply(selected, h, minute);
              }}
              className="flex-1 rounded-lg border border-zinc-200 bg-zinc-50 px-2 py-1.5 text-xs text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
            >
              {HOURS.map((h) => (
                <option key={h} value={h}>
                  {format(setHours(startOfDay(new Date()), h), "h a")}
                </option>
              ))}
            </select>
            <span className="text-zinc-400">:</span>
            <select
              value={minute}
              onChange={(e) => {
                const m = Number(e.target.value);
                setMinute(m);
                apply(selected, hour, m);
              }}
              className="w-16 rounded-lg border border-zinc-200 bg-zinc-50 px-2 py-1.5 text-xs text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
            >
              {MINUTES.map((m) => (
                <option key={m} value={m}>
                  {String(m).padStart(2, "0")}
                </option>
              ))}
            </select>
            <button
              type="button"
              onClick={() => setOpen(false)}
              className="rounded-lg bg-blue-600 px-2.5 py-1.5 text-[11px] font-semibold text-white hover:bg-blue-500 cursor-pointer"
            >
              Done
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
