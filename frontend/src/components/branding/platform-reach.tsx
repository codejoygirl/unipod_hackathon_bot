"use client";

import {
  reachChannelsForSignIn,
  type ReachChannel,
} from "@/lib/branding";

function ChannelChip({ channel }: { channel: ReachChannel }) {
  const base =
    "inline-flex items-center gap-1 rounded-lg border px-2.5 py-1 text-[11px] font-medium transition shadow-2xs";

  if (channel.current) {
    return (
      <span
        className={`${base} border-emerald-200 bg-emerald-50 text-emerald-900`}
        title="You are on web chat"
      >
        <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden />
        {channel.label}
        <span className="text-emerald-700/80 font-normal">· here</span>
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
      className={`${base} cursor-pointer ${
        isWa
          ? "border-emerald-300/80 bg-emerald-50 text-emerald-900 hover:bg-emerald-100"
          : isTg
            ? "border-sky-200 bg-sky-50 text-sky-900 hover:bg-sky-100"
            : "border-zinc-200 bg-zinc-50 text-zinc-800 hover:bg-zinc-100"
      }`}
    >
      {channel.label}
      <span className="opacity-60 text-[10px]" aria-hidden>
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
    <div className="mt-8 border-t border-zinc-100 pt-6 text-center">
      <p className="text-[11px] font-medium uppercase tracking-wide text-zinc-400">
        Also reach the assistant on
      </p>
      <div className="mt-3 flex flex-wrap items-center justify-center gap-2">
        {channels.map((c) => (
          <ChannelChip key={c.id} channel={c} />
        ))}
      </div>
    </div>
  );
}
