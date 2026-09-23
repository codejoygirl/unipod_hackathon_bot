import { ChatHeader } from "./chat-header";

type PlaceholderScreenProps = {
  title: string;
  description: string;
};

export function PlaceholderScreen({ title, description }: PlaceholderScreenProps) {
  return (
    <div className="flex min-h-dvh flex-col bg-[#f6f4fa]">
      <ChatHeader />
      <main className="mx-auto flex max-w-lg flex-1 flex-col justify-center px-6 pb-24 text-center">
        <h1 className="text-lg font-semibold text-zinc-900">{title}</h1>
        <p className="mt-2 text-sm leading-relaxed text-zinc-600">{description}</p>
      </main>
    </div>
  );
}
