"use client";

import React, {
  useState,
  useRef,
  useEffect,
  useLayoutEffect,
  useCallback,
  useSyncExternalStore,
  type ReactNode,
} from "react";
import { createPortal } from "react-dom";

type TooltipPosition = "top" | "bottom" | "left" | "right";

interface TooltipProps {
  content: ReactNode;
  shortcut?: string;
  position?: TooltipPosition;
  delay?: number;
  disabled?: boolean;
  children: ReactNode;
  className?: string;
}

const VIEWPORT_PAD = 8;
const GAP = 8;

const subscribe = () => () => {};
const getSnapshot = () => true;
const getServerSnapshot = () => false;

export function Tooltip({
  content,
  shortcut,
  position = "top",
  delay = 180,
  disabled = false,
  children,
  className = "",
}: TooltipProps) {
  const [isVisible, setIsVisible] = useState(false);
  const mounted = useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
  const [coords, setCoords] = useState({ top: 0, left: 0 });
  const [placed, setPlaced] = useState(false);
  const timeoutRef = useRef<NodeJS.Timeout | null>(null);
  const triggerRef = useRef<HTMLDivElement>(null);
  const tooltipRef = useRef<HTMLDivElement>(null);

  const showTooltip = () => {
    if (disabled || !content) return;
    timeoutRef.current = setTimeout(() => {
      setIsVisible(true);
    }, delay);
  };

  const hideTooltip = () => {
    if (timeoutRef.current) {
      clearTimeout(timeoutRef.current);
      timeoutRef.current = null;
    }
    setIsVisible(false);
    setPlaced(false);
  };

  useEffect(() => {
    return () => {
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current);
      }
    };
  }, []);

  const updatePosition = useCallback(() => {
    const trigger = triggerRef.current;
    const tip = tooltipRef.current;
    if (!trigger || !tip) {
      return;
    }

    const triggerRect = trigger.getBoundingClientRect();
    const tipRect = tip.getBoundingClientRect();
    const tipW = tipRect.width;
    const tipH = tipRect.height;

    let top = 0;
    let left = 0;

    switch (position) {
      case "top":
        top = triggerRect.top - tipH - GAP;
        left = triggerRect.left + triggerRect.width / 2 - tipW / 2;
        break;
      case "bottom":
        top = triggerRect.bottom + GAP;
        left = triggerRect.left + triggerRect.width / 2 - tipW / 2;
        break;
      case "left":
        top = triggerRect.top + triggerRect.height / 2 - tipH / 2;
        left = triggerRect.left - tipW - GAP;
        break;
      case "right":
        top = triggerRect.top + triggerRect.height / 2 - tipH / 2;
        left = triggerRect.right + GAP;
        break;
    }

    left = Math.max(
      VIEWPORT_PAD,
      Math.min(left, window.innerWidth - tipW - VIEWPORT_PAD),
    );
    top = Math.max(
      VIEWPORT_PAD,
      Math.min(top, window.innerHeight - tipH - VIEWPORT_PAD),
    );

    setCoords({ top, left });
    setPlaced(true);
  }, [position]);

  useLayoutEffect(() => {
    if (!isVisible) {
      return;
    }
    updatePosition();
  }, [isVisible, updatePosition, content, shortcut]);

  useEffect(() => {
    if (!isVisible) {
      return;
    }

    const onScrollOrResize = () => {
      hideTooltip();
    };

    window.addEventListener("scroll", onScrollOrResize, true);
    window.addEventListener("resize", onScrollOrResize);

    return () => {
      window.removeEventListener("scroll", onScrollOrResize, true);
      window.removeEventListener("resize", onScrollOrResize);
    };
  }, [isVisible]);

  const tooltipNode =
    isVisible && !disabled && mounted ? (
      <div
        ref={tooltipRef}
        role="tooltip"
        style={{ top: coords.top, left: coords.left }}
        className={`pointer-events-none fixed z-[9999] max-w-[min(20rem,calc(100vw-1rem))] rounded-lg border border-zinc-700/80 bg-zinc-900/95 px-2.5 py-1 text-[11px] font-medium text-zinc-100 shadow-xl backdrop-blur-xs transition-opacity duration-100 dark:border-zinc-700 dark:bg-zinc-800/95 ${
          placed ? "opacity-100" : "opacity-0"
        }`}
      >
        <div className="flex flex-wrap items-center gap-1.5">
          <span className="whitespace-normal">{content}</span>
          {shortcut && (
            <kbd className="shrink-0 rounded border border-zinc-600/60 bg-zinc-800 px-1 py-0.5 font-mono text-[9px] text-zinc-300 dark:bg-zinc-700">
              {shortcut}
            </kbd>
          )}
        </div>
      </div>
    ) : null;

  return (
    <div
      ref={triggerRef}
      className={`relative inline-flex items-center ${className}`}
      onMouseEnter={showTooltip}
      onMouseLeave={hideTooltip}
      onFocus={showTooltip}
      onBlur={hideTooltip}
      onClick={hideTooltip}
    >
      {children}
      {tooltipNode && createPortal(tooltipNode, document.body)}
    </div>
  );
}
