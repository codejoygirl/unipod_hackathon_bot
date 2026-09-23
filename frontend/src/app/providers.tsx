"use client";

import { InstallAppPromo, IosInstallModal } from "@/components/pwa/install-prompt";
import { PwaProvider } from "@/lib/pwa/pwa-context";
import { ThemeProvider } from "@/lib/theme/theme-context";
import { WebChatProvider } from "@/lib/web-chat/web-chat-context";
import { SidebarProvider } from "@/lib/sidebar/sidebar-context";

/**
 * App-wide client providers (Theme, PWA installability, WebChat, Sidebar, etc.).
 */
export function Providers({ children }: { children: React.ReactNode }) {
  return (
    <ThemeProvider>
      <PwaProvider>
        <WebChatProvider>
          <SidebarProvider>
            {children}
            <InstallAppPromo />
            <IosInstallModal />
          </SidebarProvider>
        </WebChatProvider>
      </PwaProvider>
    </ThemeProvider>
  );
}
