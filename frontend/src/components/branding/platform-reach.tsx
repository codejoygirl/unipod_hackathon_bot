"use client";

import type { ReachChannel, ReachChannelKind } from "@/lib/branding";
import {
  hasMultipleReachSurfaces,
  usePublicReachChannels,
  visibleReachChannels,
} from "@/lib/branding/public-reach";

function channelKind(channel: ReachChannel): ReachChannelKind {
  return channel.kind ?? (channel.id.startsWith("whatsapp") ? "whatsapp" : (channel.id as ReachChannelKind));
}

export function WhatsAppIcon({ className }: { className?: string }) {
  return (
    <svg
      width="18"
      height="18"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
      aria-hidden
    >
      <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" />
    </svg>
  );
}

export function TelegramIcon({ className }: { className?: string }) {
  return (
    <svg
      width="18"
      height="18"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
      aria-hidden
    >
      <line x1="22" y1="2" x2="11" y2="13" />
      <polygon points="22 2 15 22 11 13 2 9 22 2" />
    </svg>
  );
}

function ChannelCard({ channel }: { channel: ReachChannel }) {
  const kind = channelKind(channel);
  if (!channel.url) return null;

  const isWa = kind === "whatsapp";
  const isTg = kind === "telegram";
  const title = isWa ? "WhatsApp" : isTg ? "Telegram" : channel.label;
  const hint = isWa ? "Message the assistant" : isTg ? "Open the bot" : "Continue there";

  return (
    <a
      href={channel.url}
      target="_blank"
      rel="noopener noreferrer"
      className="group flex min-h-12 w-full items-center gap-3 rounded-2xl border border-zinc-200/90 bg-white px-3 py-2.5 text-left shadow-2xs transition hover:border-zinc-300 hover:bg-zinc-50 active:scale-[0.99] dark:border-zinc-800 dark:bg-[#171717] dark:hover:border-zinc-700 dark:hover:bg-zinc-800/80 sm:min-h-[3.25rem] sm:px-3.5"
    >
      <span
        className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${
          isWa
            ? "bg-[#25D366]/12 text-[#1f9c4d] dark:bg-[#25D366]/15 dark:text-[#4ade80]"
            : isTg
              ? "bg-sky-500/12 text-sky-600 dark:bg-sky-500/15 dark:text-sky-400"
              : "bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-300"
        }`}
      >
        {isWa ? <WhatsAppIcon /> : isTg ? <TelegramIcon /> : null}
      </span>
      <span className="min-w-0 flex-1">
        <span className="block text-sm font-semibold text-zinc-900 dark:text-zinc-100">{title}</span>
        <span className="block truncate text-[11px] text-zinc-500 dark:text-zinc-400">{hint}</span>
      </span>
      <span className="shrink-0 text-xs font-semibold text-zinc-400 group-hover:text-zinc-700 dark:group-hover:text-zinc-200">
        Open
      </span>
    </a>
  );
}

function ChannelChip({ channel, compact }: { channel: ReachChannel; compact?: boolean }) {
  const kind = channelKind(channel);
  const pad = compact ? "px-2.5 py-1 text-[11px]" : "px-3 py-1.5 text-xs";

  if (channel.current) {
    return null;
  }

  if (!channel.url) {
    return null;
  }

  return (
    <a
      href={channel.url}
      target="_blank"
      rel="noopener noreferrer"
      className={`inline-flex items-center gap-1.5 rounded-full border border-zinc-200/90 bg-white font-medium text-zinc-700 shadow-2xs hover:border-zinc-300 hover:bg-zinc-50 hover:text-zinc-900 transition-all cursor-pointer dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:border-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-100 ${pad}`}
    >
      {kind === "whatsapp" ? (
        <WhatsAppIcon className="shrink-0 text-[#25D366]" />
      ) : kind === "telegram" ? (
        <TelegramIcon className="shrink-0 text-sky-500 dark:text-sky-400" />
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
          aria-hidden
        >
          <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
          <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
        </svg>
      )}
      <span className="truncate max-w-[10rem] sm:max-w-none">{channel.label}</span>
      <span className="text-[10px] text-zinc-400 dark:text-zinc-500 shrink-0" aria-hidden>
        ↗
      </span>
    </a>
  );
}

function ReachIconLink({ channel }: { channel: ReachChannel }) {
  if (!channel.url) {
    return null;
  }
  const kind = channelKind(channel);
  const isWa = kind === "whatsapp";
  const isTg = kind === "telegram";

  return (
    <a
      href={channel.url}
      target="_blank"
      rel="noopener noreferrer"
      title={channel.label}
      aria-label={channel.label}
      className={`flex h-9 w-9 items-center justify-center rounded-xl border border-zinc-200/80 bg-white text-zinc-600 shadow-2xs transition hover:border-zinc-300 hover:bg-zinc-50 active:scale-95 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:border-zinc-700 dark:hover:bg-zinc-800 ${
        isWa ? "text-[#25D366]" : isTg ? "text-sky-500 dark:text-sky-400" : ""
      }`}
    >
      {isWa ? <WhatsAppIcon className="shrink-0" /> : isTg ? <TelegramIcon className="shrink-0" /> : null}
    </a>
  );
}

type PlatformReachProps = {
  variant?: "row" | "card" | "sidebar" | "strip";
  className?: string;
};

/** Shared reach UI — loads /public/reach when available (multi-WhatsApp), else env chips. */
export function PlatformReach({ variant = "row", className = "" }: PlatformReachProps) {
  const all = usePublicReachChannels();
  const channels = visibleReachChannels(all);

  if (!hasMultipleReachSurfaces(all)) {
    return null;
  }

  if (variant === "strip") {
    return (
      <div
        className={`md:hidden border-b border-zinc-100 bg-zinc-50/80 px-3 py-2 dark:border-zinc-800/80 dark:bg-[#111111]/80 ${className}`}
      >
        <p className="mb-1.5 text-[10px] font-medium uppercase tracking-wide text-zinc-400 dark:text-zinc-500">
          Also on
        </p>
        <div className="flex gap-2 overflow-x-auto no-scrollbar pb-0.5">
          {channels.map((c) => (
            <ChannelChip key={c.id} channel={c} compact />
          ))}
        </div>
      </div>
    );
  }

  if (variant === "sidebar") {
    return (
      <div className={`px-1 pb-2 ${className}`}>
        <p className="px-1.5 mb-2 text-[10px] font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">
          Same assistant on
        </p>
        <div className="flex flex-wrap gap-1.5">
          {channels.map((c) => (
            <ChannelChip key={c.id} channel={c} compact />
          ))}
        </div>
      </div>
    );
  }

  if (variant === "card") {
    return (
      <div
        className={`rounded-2xl border border-zinc-200/80 bg-zinc-50/50 px-4 py-3.5 dark:border-zinc-800/80 dark:bg-[#141414] ${className}`}
      >
        <p className="text-xs font-semibold text-zinc-800 dark:text-zinc-200">Same assistant on other channels</p>
        <p className="mt-0.5 text-[11px] text-zinc-500 dark:text-zinc-400 leading-relaxed">
          Continue on WhatsApp or Telegram with the same community knowledge and commands.
        </p>
        <div className="mt-3 flex flex-wrap gap-2">
          {channels.map((c) => (
            <ChannelChip key={c.id} channel={c} />
          ))}
        </div>
      </div>
    );
  }

  return (
    <div className={`w-full mt-7 pt-6 border-t border-zinc-100 dark:border-zinc-800/80 ${className}`}>
      <p className="text-center text-[11px] font-medium tracking-wide uppercase text-zinc-400 dark:text-zinc-500">
        Or continue on a channel
      </p>
      <p className="mt-1 text-center text-[12px] leading-relaxed text-zinc-500 dark:text-zinc-400">
        Same assistant on WhatsApp and Telegram.
      </p>
      <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
        {channels.map((c) => (
          <ChannelCard key={c.id} channel={c} />
        ))}
      </div>
    </div>
  );
}

/** Sign-in footer: web (here) + WhatsApp + Telegram. */
export function PlatformReachRow() {
  return <PlatformReach variant="row" />;
}

/** Collapsed sidebar: icon links for external channels only. */
export function PlatformReachRailLinks({ className = "" }: { className?: string }) {
  const all = usePublicReachChannels();
  const external = visibleReachChannels(all).filter((c) => !c.current && c.url);

  if (external.length === 0) {
    return null;
  }

  return (
    <div className={`flex flex-col items-center gap-1.5 ${className}`}>
      {external.map((c) => (
        <ReachIconLink key={c.id} channel={c} />
      ))}
    </div>
  );
}
