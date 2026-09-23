"use client";

import { useState, useCallback, useEffect } from "react";
import { usePwa } from "@/lib/pwa/pwa-context";
import Image from "next/image";
import { APP_LOGO_SRC } from "@/lib/branding";
import { Tooltip } from "@/components/ui/tooltip";

const INSTALL_PROMO_SESSION_KEY = "unipod_pwa_promo_shown_session_v1";

/** High-contrast install pill — same visual weight as the install promo primary button */
export const INSTALL_APP_BUTTON_CLASS =
  "inline-flex h-8 shrink-0 cursor-pointer items-center justify-center gap-1.5 rounded-full border border-zinc-300 bg-zinc-900 px-2.5 text-[11px] font-semibold text-white shadow-sm transition hover:bg-zinc-800 active:scale-[0.98] dark:border-zinc-500 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white sm:px-3 sm:text-xs";

/** @deprecated Use INSTALL_APP_BUTTON_CLASS — kept for globals.css fallback on login */
export const INSTALL_PWA_PILL_CLASS = "install-pwa-pill";

export function IosInstallModal() {
  const { showIosModal, setShowIosModal } = usePwa();
  const [isClosing, setIsClosing] = useState(false);

  const handleClose = useCallback(() => {
    setIsClosing(true);
    setTimeout(() => {
      setShowIosModal(false);
      setIsClosing(false);
    }, 180);
  }, [setShowIosModal]);

  if (!showIosModal) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4">
      {/* Backdrop */}
      <div
        className={`fixed inset-0 bg-black/50 backdrop-blur-xs transition-opacity duration-200 ease-out ${
          isClosing ? "opacity-0" : "opacity-100"
        }`}
        onClick={handleClose}
        aria-hidden="true"
      />

      <div
        className={`relative z-10 w-full max-w-sm rounded-3xl bg-white p-6 shadow-2xl transition-all dark:bg-[#181818] dark:border dark:border-zinc-800 ${
          isClosing ? "animate-modal-out" : "animate-modal-in"
        }`}
      >
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl overflow-hidden shadow-2xs">
              <Image
                src={APP_LOGO_SRC}
                alt="UniPod Logo"
                width={44}
                height={44}
                className="h-full w-full object-contain rounded-xl"
              />
            </div>
            <div>
              <h3 className="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Install UniPod Assistant</h3>
              <p className="text-xs text-zinc-500 dark:text-zinc-400">Fast access right from your home screen</p>
            </div>
          </div>
          <button
            type="button"
            onClick={handleClose}
            className="rounded-full p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200 transition cursor-pointer"
            aria-label="Close"
          >
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M18 6L6 18M6 6l12 12" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
          </button>
        </div>

        <div className="mt-5 space-y-3.5 text-xs text-zinc-700 dark:text-zinc-300">
          <div className="flex items-start gap-3 rounded-2xl bg-zinc-50 dark:bg-zinc-900/60 p-3 border border-zinc-100 dark:border-zinc-800">
            <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-zinc-900 dark:bg-zinc-100 text-[11px] font-bold text-white dark:text-zinc-900">
              1
            </span>
            <div className="flex-1 leading-relaxed">
              Tap the <span className="font-semibold text-zinc-900 dark:text-zinc-100">Share button</span>{" "}
              <svg className="inline-block h-4 w-4 align-sub text-sky-600 dark:text-sky-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8" />
                <polyline points="16 6 12 2 8 6" />
                <line x1="12" y1="2" x2="12" y2="15" />
              </svg>{" "}
              in your Safari browser bar.
            </div>
          </div>

          <div className="flex items-start gap-3 rounded-2xl bg-zinc-50 dark:bg-zinc-900/60 p-3 border border-zinc-100 dark:border-zinc-800">
            <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-zinc-900 dark:bg-zinc-100 text-[11px] font-bold text-white dark:text-zinc-900">
              2
            </span>
            <div className="flex-1 leading-relaxed">
              Scroll down and select{" "}
              <span className="font-semibold text-zinc-900 dark:text-zinc-100">Add to Home Screen</span>{" "}
              <svg className="inline-block h-4 w-4 align-sub text-zinc-800 dark:text-zinc-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
                <line x1="12" y1="8" x2="12" y2="16" />
                <line x1="8" y1="12" x2="16" y2="12" />
              </svg>
            </div>
          </div>

          <div className="flex items-start gap-3 rounded-2xl bg-zinc-50 dark:bg-zinc-900/60 p-3 border border-zinc-100 dark:border-zinc-800">
            <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-zinc-900 dark:bg-zinc-100 text-[11px] font-bold text-white dark:text-zinc-900">
              3
            </span>
            <div className="flex-1 leading-relaxed">
              Tap <span className="font-semibold text-zinc-900 dark:text-zinc-100">Add</span> in the top-right corner.
            </div>
          </div>
        </div>

        <button
          type="button"
          onClick={handleClose}
          className="mt-6 w-full rounded-xl bg-zinc-900 py-3 text-xs font-semibold text-white hover:bg-zinc-800 transition active:scale-[0.99] cursor-pointer dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-200"
        >
          Got it
        </button>
      </div>
    </div>
  );
}

export function InstallHeaderButton() {
  const { isInstallable, isInstalled, promptInstall } = usePwa();

  if (isInstalled || !isInstallable) return null;

  return (
    <Tooltip content="Add UniPod to your home screen" position="bottom">
      <button
        type="button"
        onClick={() => void promptInstall()}
        className={INSTALL_APP_BUTTON_CLASS}
        aria-label="Install app"
      >
        <svg
          width="14"
          height="14"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2.2"
          strokeLinecap="round"
          strokeLinejoin="round"
          className="shrink-0 text-emerald-300 dark:text-emerald-600"
        >
          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
          <polyline points="7 10 12 15 17 10" />
          <line x1="12" y1="15" x2="12" y2="3" />
        </svg>
        <span className="whitespace-nowrap">Install app</span>
      </button>
    </Tooltip>
  );
}

/**
 * Gentle install promo — once per session when the app is installable and not yet on the home screen.
 */
export function InstallAppPromo() {
  const { isInstallable, isInstalled, isIos, promptInstall, dismissInstall, showIosModal } = usePwa();
  const [visible, setVisible] = useState(false);
  const [isClosing, setIsClosing] = useState(false);

  useEffect(() => {
    if (!isInstallable || isInstalled || showIosModal) return;

    try {
      if (sessionStorage.getItem(INSTALL_PROMO_SESSION_KEY) === "1") return;
    } catch {
      // ignore
    }

    const timer = window.setTimeout(() => {
      setVisible(true);
      try {
        sessionStorage.setItem(INSTALL_PROMO_SESSION_KEY, "1");
      } catch {
        // ignore
      }
    }, 2200);

    return () => window.clearTimeout(timer);
  }, [isInstallable, isInstalled, showIosModal]);

  const handleDismiss = useCallback(() => {
    setIsClosing(true);
    dismissInstall();
    window.setTimeout(() => setVisible(false), 200);
  }, [dismissInstall]);

  const handleInstall = useCallback(() => {
    void promptInstall().then((accepted) => {
      if (accepted) {
        setVisible(false);
      }
    });
  }, [promptInstall]);

  if (!visible) return null;

  return (
    <div
      className="pointer-events-none fixed inset-x-0 bottom-0 z-40 flex justify-center p-4 pb-[max(1rem,env(safe-area-inset-bottom))] sm:inset-x-auto sm:right-4 sm:bottom-4 sm:justify-end sm:p-0"
      role="region"
      aria-label="Install app suggestion"
    >
      <div
        className={`pointer-events-auto w-full max-w-md rounded-2xl border border-zinc-200/90 bg-white/95 p-4 shadow-xl backdrop-blur-md dark:border-zinc-700/80 dark:bg-[#1a1a1a]/95 ${
          isClosing ? "animate-modal-out" : "animate-fade-in"
        }`}
      >
        <div className="flex items-start gap-3">
          <div className="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-xl shadow-2xs">
            <Image src={APP_LOGO_SRC} alt="" width={44} height={44} className="h-full w-full object-contain rounded-xl" />
          </div>
          <div className="min-w-0 flex-1">
            <p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">Install UniPod Assistant</p>
            <p className="mt-0.5 text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">
              {isIos
                ? "Add to your home screen for quick access, full-screen chat, and faster launches."
                : "Install on this device for a standalone app experience with offline-ready caching."}
            </p>
          </div>
          <button
            type="button"
            onClick={handleDismiss}
            className="shrink-0 rounded-lg p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600 dark:hover:bg-zinc-800 dark:hover:text-zinc-200 transition cursor-pointer"
            aria-label="Dismiss install suggestion"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M18 6L6 18M6 6l12 12" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
          </button>
        </div>

        <div className="mt-3.5 flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-end">
          <button
            type="button"
            onClick={handleDismiss}
            className="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2.5 text-xs font-medium text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800 transition cursor-pointer sm:w-auto"
          >
            Not now
          </button>
          <button
            type="button"
            onClick={handleInstall}
            className="inline-flex w-full items-center justify-center gap-1.5 rounded-xl bg-zinc-900 px-4 py-2.5 text-xs font-semibold text-white shadow-2xs hover:bg-zinc-800 active:scale-[0.99] dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white transition cursor-pointer sm:w-auto"
          >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
              <polyline points="7 10 12 15 17 10" />
              <line x1="12" y1="15" x2="12" y2="3" />
            </svg>
            {isIos ? "How to install" : "Install app"}
          </button>
        </div>
      </div>
    </div>
  );
}
