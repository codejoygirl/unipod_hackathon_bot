import { ChatHeader } from "./chat-header";

type PlaceholderScreenProps = {
  title: string;
  description: string;
};

export function PlaceholderScreen({ title, description }: PlaceholderScreenProps) {
  return (
    <div className="flex min-h-dvh flex-col bg-zinc-50">
      <ChatHeader />
      <main className="mx-auto flex max-w-lg flex-1 flex-col justify-center px-6 pb-24 text-center">
        <h1 className="text-base font-semibold text-zinc-900 tracking-tight">{title}</h1>
        <p className="mt-2 text-xs leading-relaxed text-zinc-500">{description}</p>
      </main>
    </div>
  );
}
