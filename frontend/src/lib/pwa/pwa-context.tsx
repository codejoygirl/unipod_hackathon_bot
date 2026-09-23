"use client";

import React, { createContext, useCallback, useContext, useEffect, useState } from "react";

interface BeforeInstallPromptEvent extends Event {
  readonly platforms: string[];
  readonly userChoice: Promise<{
    outcome: "accepted" | "dismissed";
    platform: string;
  }>;
  prompt(): Promise<void>;
}

interface PwaContextValue {
  isInstallable: boolean;
  isInstalled: boolean;
  isIos: boolean;
  promptInstall: () => Promise<boolean>;
  showIosModal: boolean;
  setShowIosModal: (show: boolean) => void;
  dismissed: boolean;
  dismissInstall: () => void;
}

const PWA_DISMISS_STORAGE_KEY = "unipod_pwa_install_dismissed_at_v1";
/** After "Not now", hide the install promo for 14 days. */
const PWA_DISMISS_SNOOZE_MS = 14 * 24 * 60 * 60 * 1000;

function readInstallDismissed(): boolean {
  if (typeof window === "undefined") return false;
  try {
    const raw = localStorage.getItem(PWA_DISMISS_STORAGE_KEY);
    if (!raw) return false;
    const dismissedAt = Number.parseInt(raw, 10);
    if (!Number.isFinite(dismissedAt)) return false;
    return Date.now() - dismissedAt < PWA_DISMISS_SNOOZE_MS;
  } catch {
    return false;
  }
}

const PwaContext = createContext<PwaContextValue>({
  isInstallable: false,
  isInstalled: false,
  isIos: false,
  promptInstall: async () => false,
  showIosModal: false,
  setShowIosModal: () => {},
  dismissed: false,
  dismissInstall: () => {},
});

export function PwaProvider({ children }: { children: React.ReactNode }) {
  const [deferredPrompt, setDeferredPrompt] = useState<BeforeInstallPromptEvent | null>(null);
  const [isInstalled, setIsInstalled] = useState(() => {
    if (typeof window === "undefined") return false;
    return (
      window.matchMedia("(display-mode: standalone)").matches ||
      (window.navigator as unknown as { standalone?: boolean }).standalone === true
    );
  });
  const [isIos] = useState(() => {
    if (typeof window === "undefined") return false;
    const userAgent = window.navigator.userAgent.toLowerCase();
    return /iphone|ipad|ipod/.test(userAgent);
  });
  const [showIosModal, setShowIosModal] = useState(false);
  const [dismissed, setDismissed] = useState(() => readInstallDismissed());

  useEffect(() => {
    // 1. Register Service Worker for offline capability & PWA install criteria
    if (typeof window !== "undefined" && "serviceWorker" in navigator) {
      navigator.serviceWorker
        .register("/sw.js")
        .then((reg) => {
          // Check for updates periodically
          reg.update().catch(() => {});
        })
        .catch(() => {});
    }

    // 2. Capture native beforeinstallprompt (Chrome, Edge, Android)
    const handleBeforeInstall = (e: Event) => {
      e.preventDefault();
      setDeferredPrompt(e as BeforeInstallPromptEvent);
    };

    const handleAppInstalled = () => {
      setIsInstalled(true);
      setDeferredPrompt(null);
    };

    window.addEventListener("beforeinstallprompt", handleBeforeInstall);
    window.addEventListener("appinstalled", handleAppInstalled);

    return () => {
      window.removeEventListener("beforeinstallprompt", handleBeforeInstall);
      window.removeEventListener("appinstalled", handleAppInstalled);
    };
  }, []);

  const promptInstall = useCallback(async (): Promise<boolean> => {
    if (deferredPrompt) {
      try {
        await deferredPrompt.prompt();
        const choice = await deferredPrompt.userChoice;
        if (choice.outcome === "accepted") {
          setIsInstalled(true);
          setDeferredPrompt(null);
          return true;
        }
      } catch {
        // user cancelled or browser error
      }
      return false;
    }

    if (isIos && !isInstalled) {
      setShowIosModal(true);
      return false;
    }

    return false;
  }, [deferredPrompt, isIos, isInstalled]);

  const dismissInstall = useCallback(() => {
    setDismissed(true);
    try {
      localStorage.setItem(PWA_DISMISS_STORAGE_KEY, String(Date.now()));
    } catch {
      // ignore
    }
  }, []);

  const isInstallable = (!isInstalled && (!!deferredPrompt || isIos)) && !dismissed;

  return (
    <PwaContext.Provider
      value={{
        isInstallable,
        isInstalled,
        isIos,
        promptInstall,
        showIosModal,
        setShowIosModal,
        dismissed,
        dismissInstall,
      }}
    >
      {children}
    </PwaContext.Provider>
  );
}

export function usePwa() {
  return useContext(PwaContext);
}
