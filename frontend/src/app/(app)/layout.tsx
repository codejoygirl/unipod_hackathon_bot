import { WebChatGate } from "@/components/chat/web-chat-gate";

export default function AppShellLayout({ children }: LayoutProps<"/">) {
  return <WebChatGate>{children}</WebChatGate>;
}
