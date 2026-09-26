"use client";

import { useEffect, useState } from "react";
import { apiFetch } from "@/lib/api/client";
import {
  reachChannelsForSignIn,
  type ReachChannel,
} from "@/lib/branding";

type PublicReachApiRow = {
  transport?: string;
  channel_key?: string;
  label: string;
  url: string | null;
};

type PublicReachApiPayload = {
  data: {
    whatsapp: PublicReachApiRow[];
    telegram: { label: string; url: string | null };
    web: { label: string; url: string | null };
  };
};

function channelsFromEnv(): ReachChannel[] {
  return reachChannelsForSignIn();
}

function channelsFromApi(payload: PublicReachApiPayload): ReachChannel[] {
  const { whatsapp, telegram, web } = payload.data;
  const out: ReachChannel[] = [
    {
      id: "web",
      kind: "web",
      label: web.label?.trim() || "Web Chat",
      url: null,
      current: true,
    },
  ];

  for (const row of whatsapp ?? []) {
    const url = row.url?.trim() || null;
    if (!url) {
      continue;
    }
    const transport = row.transport?.trim() || row.channel_key?.trim() || "whatsapp";
    out.push({
      id: `whatsapp:${transport}`,
      kind: "whatsapp",
      label: row.label?.trim() || "WhatsApp",
      url,
    });
  }

  const tgUrl = telegram?.url?.trim() || null;
  if (tgUrl) {
    out.push({
      id: "telegram",
      kind: "telegram",
      label: telegram.label?.trim() || "Telegram",
      url: tgUrl,
    });
  }

  if (web.url?.trim()) {
    const webEntry = out[0];
    if (webEntry) {
      webEntry.url = web.url.trim();
    }
  }

  return out;
}

export function visibleReachChannels(channels: ReachChannel[]): ReachChannel[] {
  // Hide the current web surface — user is already on web.
  return channels.filter((c) => !c.current && Boolean(c.url?.trim()));
}

export function hasMultipleReachSurfaces(channels: ReachChannel[]): boolean {
  return visibleReachChannels(channels).length >= 1;
}

let cachedReach: ReachChannel[] | null = null;
let reachLoadPromise: Promise<ReachChannel[] | null> | null = null;

export async function fetchPublicReachChannels(): Promise<ReachChannel[] | null> {
  if (cachedReach) {
    return cachedReach;
  }
  if (!reachLoadPromise) {
    reachLoadPromise = (async () => {
      try {
        const res = await apiFetch<PublicReachApiPayload>("/api/v1/public/reach");
        const mapped = channelsFromApi(res);
        const env = channelsFromEnv();
        const merged = visibleReachChannels(mapped).length > 0 ? mapped : env;
        if (visibleReachChannels(merged).length === 0) {
          return null;
        }
        cachedReach = merged;
        return merged;
      } catch {
        return null;
      } finally {
        reachLoadPromise = null;
      }
    })();
  }
  return reachLoadPromise;
}

export function usePublicReachChannels(): ReachChannel[] {
  const [channels, setChannels] = useState<ReachChannel[]>(() => channelsFromEnv());

  useEffect(() => {
    let ignore = false;
    void (async () => {
      const fromApi = await fetchPublicReachChannels();
      if (!ignore && fromApi) {
        setChannels(fromApi);
      }
    })();
    return () => {
      ignore = true;
    };
  }, []);

  return channels;
}
