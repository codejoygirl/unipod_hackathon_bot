import { WebChatGate } from "@/components/chat/web-chat-gate";
import { AppShell } from "@/components/chat/app-shell";

export default function AppShellLayout({ children }: LayoutProps<"/">) {
  return (
    <WebChatGate>
      <AppShell>{children}</AppShell>
    </WebChatGate>
  );
}
