"use client";

import {
  reachChannelsForSignIn,
  type ReachChannel,
} from "@/lib/branding";

function ChannelChip({ channel }: { channel: ReachChannel }) {
  if (channel.current) {
    return (
      <span
        className="inline-flex items-center gap-1.5 rounded-full border border-emerald-200/80 bg-emerald-50/80 px-3 py-1.5 text-xs font-medium text-emerald-900 shadow-2xs dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-300"
        title="Current web app"
      >
        <span className="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse" aria-hidden />
        <span>{channel.label}</span>
        <span className="text-emerald-700/70 dark:text-emerald-400/70 text-[11px] font-normal">(web)</span>
      </span>
    );
  }

  if (!channel.url) {
    return null;
  }

  const isWa = channel.id === "whatsapp";
  const isTg = channel.id === "telegram";

  return (
    <a
      href={channel.url}
      target="_blank"
      rel="noopener noreferrer"
      className="inline-flex items-center gap-1.5 rounded-full border border-zinc-200/90 bg-white px-3 py-1.5 text-xs font-medium text-zinc-700 shadow-2xs hover:border-zinc-300 hover:bg-zinc-50 hover:text-zinc-900 transition-all cursor-pointer dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:border-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
    >
      {isWa ? (
        <svg
          width="13"
          height="13"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
          className="shrink-0 text-emerald-600 dark:text-emerald-400"
        >
          <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" />
        </svg>
      ) : isTg ? (
        <svg
          width="13"
          height="13"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
          className="shrink-0 text-sky-500 dark:text-sky-400"
        >
          <line x1="22" y1="2" x2="11" y2="13" />
          <polygon points="22 2 15 22 11 13 2 9 22 2" />
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
          className="shrink-0 text-zinc-400"
        >
          <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
          <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
        </svg>
      )}
      <span>{channel.label}</span>
      <span className="text-[10px] text-zinc-400 dark:text-zinc-500" aria-hidden>
        ↗
      </span>
    </a>
  );
}

/** Sign-in footer: web (here) + WhatsApp + Telegram from env. */
export function PlatformReachRow() {
  const channels = reachChannelsForSignIn().filter(
    (c) => c.current || (c.url && c.url.length > 0),
  );

  if (channels.length <= 1) {
    return null;
  }

  return (
    <div className="w-full mt-8 pt-6 border-t border-zinc-100 dark:border-zinc-800/80 text-center">
      <p className="text-[11px] font-medium tracking-wide uppercase text-zinc-400 dark:text-zinc-500 mb-3">
        Also available on
      </p>
      <div className="flex flex-wrap items-center justify-center gap-2">
        {channels.map((c) => (
          <ChannelChip key={c.id} channel={c} />
        ))}
      </div>
    </div>
  );
}
