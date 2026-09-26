/**
 * Home-screen / taskbar badge for installed PWAs (Badging API).
 * No-ops where unsupported (e.g. iOS Safari).
 */
export async function syncAppBadge(unreadCount: number): Promise<void> {
  if (typeof navigator === "undefined") {
    return;
  }
  try {
    const nav = navigator as Navigator & {
      setAppBadge?: (n?: number) => Promise<void>;
      clearAppBadge?: () => Promise<void>;
    };
    if (unreadCount > 0 && typeof nav.setAppBadge === "function") {
      await nav.setAppBadge(unreadCount);
      return;
    }
    if (typeof nav.clearAppBadge === "function") {
      await nav.clearAppBadge();
    }
  } catch {
    // ignore unsupported / permission errors
  }
}
