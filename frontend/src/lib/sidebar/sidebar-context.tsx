"use client";

import React, { createContext, useContext, useEffect, useState, useCallback } from "react";

interface SidebarContextValue {
  isOpen: boolean;
  setIsOpen: (open: boolean) => void;
  toggleSidebar: () => void;
  isCommandsModalOpen: boolean;
  openCommandsModal: () => void;
  closeCommandsModal: () => void;
  isFeatureModalOpen: boolean;
  openFeatureModal: () => void;
  closeFeatureModal: () => void;
}

const SidebarContext = createContext<SidebarContextValue | undefined>(undefined);

export function SidebarProvider({ children }: { children: React.ReactNode }) {
  const [isOpen, setIsOpenState] = useState<boolean>(() => {
    if (typeof window === "undefined") return true;
    try {
      const stored = localStorage.getItem("unipod_sidebar_open");
      if (stored !== null) return stored === "true";
      return window.innerWidth >= 768;
    } catch {
      return true;
    }
  });

  const [isCommandsModalOpen, setIsCommandsModalOpen] = useState(false);
  const [isFeatureModalOpen, setIsFeatureModalOpen] = useState(false);

  const setIsOpen = useCallback((open: boolean) => {
    setIsOpenState(open);
    try {
      localStorage.setItem("unipod_sidebar_open", String(open));
    } catch {
      // ignore
    }
  }, []);

  const toggleSidebar = useCallback(() => {
    setIsOpenState((prev) => {
      const next = !prev;
      try {
        localStorage.setItem("unipod_sidebar_open", String(next));
      } catch {
        // ignore
      }
      return next;
    });
  }, []);

  const openCommandsModal = useCallback(() => setIsCommandsModalOpen(true), []);
  const closeCommandsModal = useCallback(() => setIsCommandsModalOpen(false), []);
  const openFeatureModal = useCallback(() => setIsFeatureModalOpen(true), []);
  const closeFeatureModal = useCallback(() => setIsFeatureModalOpen(false), []);

  // Global event listener for open-feature-request
  useEffect(() => {
    const handleOpenFeature = () => setIsFeatureModalOpen(true);
    window.addEventListener("open-feature-request", handleOpenFeature);
    return () => window.removeEventListener("open-feature-request", handleOpenFeature);
  }, []);

  // Keyboard shortcut Ctrl+S / Cmd+S to toggle sidebar
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "s") {
        e.preventDefault();
        toggleSidebar();
      }
    };
    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [toggleSidebar]);

  return (
    <SidebarContext.Provider
      value={{
        isOpen,
        setIsOpen,
        toggleSidebar,
        isCommandsModalOpen,
        openCommandsModal,
        closeCommandsModal,
        isFeatureModalOpen,
        openFeatureModal,
        closeFeatureModal,
      }}
    >
      {children}
    </SidebarContext.Provider>
  );
}

export function useSidebar(): SidebarContextValue {
  const context = useContext(SidebarContext);
  if (!context) {
    throw new Error("useSidebar must be used within a SidebarProvider");
  }
  return context;
}
