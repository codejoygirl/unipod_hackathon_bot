"use client";

import React, { useEffect, useLayoutEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { usePathname, useRouter } from "next/navigation";
import { useSidebar } from "@/lib/sidebar/sidebar-context";
import { useWebChat } from "@/lib/web-chat/web-chat-context";
import { withPhoneQuery } from "@/lib/web-chat/url-params";
import { openNotificationsModal } from "@/components/notifications/notifications-changelog-modal";
import { startProductTour } from "@/lib/tour/product-tour";
import { Tooltip } from "@/components/ui/tooltip";

type MenuItem = {
  id: string;
  label: string;
  hint: string;
  onSelect: () => void;
  icon: React.ReactNode;
};

export function HeaderMoreMenu() {
  const [open, setOpen] = useState(false);
  const [menuStyle, setMenuStyle] = useState<React.CSSProperties>({});
  const rootRef = useRef<HTMLDivElement>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);
  const { setIsOpen, openFeatureModal } = useSidebar();
  const { memberPhone } = useWebChat();
  const router = useRouter();
  const pathname = usePathname();

  const placeMenu = () => {
    const btn = buttonRef.current;
    if (!btn) return;
    const rect = btn.getBoundingClientRect();
    const width = 248;
    const left = Math.min(Math.max(8, rect.right - width), window.innerWidth - width - 8);
    setMenuStyle({
      position: "fixed",
      top: rect.bottom + 8,
      left,
      width,
      zIndex: 70,
    });
  };

  useLayoutEffect(() => {
    if (!open) return;
    placeMenu();
  }, [open]);

  useEffect(() => {
    if (!open) return;
    const onDoc = (event: MouseEvent) => {
      const target = event.target as Node;
      if (rootRef.current?.contains(target) || menuRef.current?.contains(target)) return;
      setOpen(false);
    };
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") setOpen(false);
    };
    const onReposition = () => placeMenu();
    document.addEventListener("mousedown", onDoc);
    window.addEventListener("keydown", onKey);
    window.addEventListener("resize", onReposition);
    window.addEventListener("scroll", onReposition, true);
    return () => {
      document.removeEventListener("mousedown", onDoc);
      window.removeEventListener("keydown", onKey);
      window.removeEventListener("resize", onReposition);
      window.removeEventListener("scroll", onReposition, true);
    };
  }, [open]);

  const launchTour = () => {
    setOpen(false);
    setIsOpen(true);
    const alreadyHome = pathname === "/" || pathname === "";
    if (!alreadyHome) {
      router.push(withPhoneQuery("/", memberPhone));
    }
    window.setTimeout(
      () => {
        startProductTour();
      },
      alreadyHome ? 380 : 720,
    );
  };

  const items: MenuItem[] = [
    {
      id: "tour",
      label: "Product tour",
      hint: "Walk through the workspace",
      onSelect: launchTour,
      icon: (
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden>
          <circle cx="12" cy="12" r="10" />
          <polygon points="10 8 16 12 10 16 10 8" fill="currentColor" stroke="none" />
        </svg>
      ),
    },
    {
      id: "whats-new",
      label: "What’s new",
      hint: "Release notes and updates",
      onSelect: () => {
        setOpen(false);
        openNotificationsModal("changelog");
      },
      icon: (
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden>
          <path d="M12 6v6l4 2" strokeLinecap="round" strokeLinejoin="round" />
          <circle cx="12" cy="12" r="9" />
        </svg>
      ),
    },
    {
      id: "feature",
      label: "Request a feature",
      hint: "Tell us what would help",
      onSelect: () => {
        setOpen(false);
        openFeatureModal();
      },
      icon: (
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden>
          <path
            d="M12 2a7 7 0 0 0-7 7c0 2.38 1.19 4.47 3 5.74V17a2 2 0 0 0 2 2h4a2 2 0 0 0 2-2v-2.26c1.81-1.27 3-3.36 3-5.74a7 7 0 0 0-7-7M9 21a1 1 0 0 0 1 1h4a1 1 0 0 0 1-1v-1H9v1Z"
            strokeLinecap="round"
            strokeLinejoin="round"
          />
        </svg>
      ),
    },
  ];

  return (
    <div ref={rootRef} className="relative flex h-8 w-8 items-center justify-center" data-tour="more-menu">
      <Tooltip content="More" position="bottom" disabled={open}>
        <button
          ref={buttonRef}
          type="button"
          onClick={() => setOpen((prev) => !prev)}
          className={`flex h-8 w-8 items-center justify-center rounded-lg transition active:scale-95 cursor-pointer ${
            open
              ? "bg-zinc-200/80 text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100"
              : "text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
          }`}
          aria-label="More actions"
          aria-haspopup="menu"
          aria-expanded={open}
        >
          <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden>
            <circle cx="12" cy="5.5" r="1.85" />
            <circle cx="12" cy="12" r="1.85" />
            <circle cx="12" cy="18.5" r="1.85" />
          </svg>
        </button>
      </Tooltip>

      {open && typeof document !== "undefined"
        ? createPortal(
            <div
              ref={menuRef}
              role="menu"
              aria-label="More actions"
              style={menuStyle}
              className="overflow-hidden rounded-2xl border border-zinc-200/90 bg-white/95 py-1.5 shadow-xl backdrop-blur-md dark:border-zinc-700/80 dark:bg-[#1a1a1a]/95"
            >
              <p className="px-3 pb-1 pt-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                Help
              </p>
              {items.map((item) => (
                <button
                  key={item.id}
                  type="button"
                  role="menuitem"
                  onClick={item.onSelect}
                  className="flex w-full items-start gap-2.5 px-3 py-2 text-left transition hover:bg-zinc-100 dark:hover:bg-zinc-800/80 cursor-pointer"
                >
                  <span className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-700 dark:bg-blue-950/70 dark:text-blue-300">
                    {item.icon}
                  </span>
                  <span className="min-w-0">
                    <span className="block text-xs font-semibold text-zinc-900 dark:text-zinc-100">{item.label}</span>
                    <span className="block text-[11px] leading-snug text-zinc-500 dark:text-zinc-400">{item.hint}</span>
                  </span>
                </button>
              ))}
            </div>,
            document.body,
          )
        : null}
    </div>
  );
}
