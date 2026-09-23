/** Shown in UI (not the Laravel “Zak” product name). */
export const APP_DISPLAY_NAME = "UniPod Community Assistant";

export const APP_LOGO_SRC = "/unipod-assistant-logo.png";

/** Bust browser/PWA icon cache after favicon or logo changes. */
export const APP_ICON_CACHE_VERSION = "5";

export function appIconUrl(path: string): string {
  const base = path.split("?")[0] ?? path;
  return `${base}?v=${APP_ICON_CACHE_VERSION}`;
}

/** International format without + — Kenya country code 254. */
export const KENYA_PHONE_PLACEHOLDER = "254712345678";

/** Short sign-in blurb — same assistant on every channel. */
export const APP_SIGN_IN_DESCRIPTION =
  "Use the same UniPod community assistant as on WhatsApp and Telegram: schedules, updates, links, and grounded answers from shared knowledge.";

export type ReachChannel = {
  id: "web" | "whatsapp" | "telegram";
  label: string;
  url: string | null;
  /** Shown on web sign-in when this is the current surface. */
  current?: boolean;
};

/** Public reach links (optional NEXT_PUBLIC_*; empty url hides the chip). */
export function reachChannelsForSignIn(): ReachChannel[] {
  const webLabel =
    process.env.NEXT_PUBLIC_ZAK_WEB_CHAT_LABEL?.trim() || "Web chat";
  const whatsappLabel =
    process.env.NEXT_PUBLIC_ZAK_WHATSAPP_LABEL?.trim() || "WhatsApp";
  const telegramLabel =
    process.env.NEXT_PUBLIC_ZAK_TELEGRAM_LABEL?.trim() || "Telegram";

  const whatsappUrl = process.env.NEXT_PUBLIC_ZAK_WHATSAPP_URL?.trim() || "";
  const telegramUrl = process.env.NEXT_PUBLIC_ZAK_TELEGRAM_URL?.trim() || "";

  return [
    { id: "web", label: webLabel, url: null, current: true },
    { id: "whatsapp", label: whatsappLabel, url: whatsappUrl || null },
    { id: "telegram", label: telegramLabel, url: telegramUrl || null },
  ];
}

