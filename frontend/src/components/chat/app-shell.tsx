"use client";

import React, { useCallback } from "react";
import { ChatSidebar } from "./chat-sidebar";
import { useSidebar } from "@/lib/sidebar/sidebar-context";
import { useRouter, usePathname } from "next/navigation";
import { insertChatCommand } from "@/lib/chat/commands-data";
import { useWebChat } from "@/lib/web-chat/web-chat-context";
import { withPhoneQuery } from "@/lib/web-chat/url-params";
import { QuickCommandsModal } from "./quick-commands-modal";
import { RequestFeatureModal } from "@/components/features/request-feature-modal";

export function AppShell({ children }: { children: React.ReactNode }) {
  const {
    isOpen,
    toggleSidebar,
    isCommandsModalOpen,
    openCommandsModal,
    isFeatureModalOpen,
    closeFeatureModal,
  } = useSidebar();
  const { memberPhone, isAdmin } = useWebChat();
  const router = useRouter();
  const pathname = usePathname();

  const handleNewChat = useCallback(() => {
    if (pathname !== "/") {
      router.push(withPhoneQuery("/", memberPhone));
    }
    // Dispatch new-chat event so ChatView resets its conversation
    window.dispatchEvent(new CustomEvent("new-chat"));
  }, [pathname, router, memberPhone]);

  const handleSelectPrompt = useCallback(
    (prompt: string) => {
      if (pathname !== "/") {
        router.push(withPhoneQuery(`/?prompt=${encodeURIComponent(prompt)}`, memberPhone));
      } else {
        insertChatCommand(prompt);
      }
    },
    [pathname, router, memberPhone]
  );

  return (
    <div className="flex h-dvh w-full overflow-hidden bg-white text-zinc-900 transition-colors duration-200 dark:bg-[#0d0d0d] dark:text-zinc-100">
      {/* Desktop & Mobile Collapsible Sidebar */}
      <ChatSidebar
        isOpen={isOpen}
        onToggle={toggleSidebar}
        onNewChat={handleNewChat}
        onSelectPrompt={handleSelectPrompt}
        onOpenCommands={openCommandsModal}
      />

      {/* Main Content Area */}
      <div className="flex flex-1 flex-col h-full min-w-0 overflow-hidden relative">
        {children}
      </div>

      {/* Quick Commands Modal */}
      {isCommandsModalOpen && (
        <QuickCommandsModal
          isAdmin={isAdmin}
          onPopulate={(cmd) => handleSelectPrompt(cmd)}
          trigger={<span className="hidden" />}
        />
      )}

      {/* Feature Request Modal */}
      <RequestFeatureModal
        isOpen={isFeatureModalOpen}
        onClose={closeFeatureModal}
      />
    </div>
  );
}
