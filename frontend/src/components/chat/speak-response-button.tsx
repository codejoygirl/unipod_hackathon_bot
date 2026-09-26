"use client";

import React, { useEffect, useSyncExternalStore } from "react";
import { Tooltip } from "@/components/ui/tooltip";
import { canSpeak, getSpeakingId, stopSpeaking, subscribeSpeaking, toggleSpeak } from "@/lib/speech/speak";

const subscribe = subscribeSpeaking;
const getSnapshot = getSpeakingId;
const getServerSnapshot = () => null;
const subscribeNever = () => () => {};

interface SpeakResponseButtonProps {
  id: string;
  text: string;
}

export function SpeakResponseButton({ id, text }: SpeakResponseButtonProps) {
  const hydrated = useSyncExternalStore(subscribeNever, () => true, () => false);
  const activeId = useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);

  useEffect(() => {
    return () => {
      if (getSpeakingId() === id) stopSpeaking();
    };
  }, [id]);

  if (!hydrated || !canSpeak() || !text.trim()) return null;

  const speaking = activeId === id;

  return (
    <Tooltip content={speaking ? "Stop reading" : "Read aloud"} position="bottom">
      <button
        type="button"
        onClick={() => toggleSpeak(id, text)}
        className={`flex h-7 w-7 items-center justify-center rounded-lg transition cursor-pointer ${
          speaking
            ? "bg-blue-50 text-blue-600 ring-1 ring-blue-200 dark:bg-blue-950/60 dark:text-blue-400 dark:ring-blue-800"
            : "text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
        }`}
        aria-label={speaking ? "Stop reading" : "Read response aloud"}
        aria-pressed={speaking}
      >
        {speaking ? (
          <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden>
            <rect x="6" y="6" width="12" height="12" rx="2" />
          </svg>
        ) : (
          <svg
            width="13"
            height="13"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden
          >
            <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5" />
            <path d="M15.54 8.46a5 5 0 0 1 0 7.07" />
            <path d="M19.07 4.93a10 10 0 0 1 0 14.14" />
          </svg>
        )}
      </button>
    </Tooltip>
  );
}
