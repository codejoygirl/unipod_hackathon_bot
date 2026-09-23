import { ChatHeader } from "./chat-header";

type PlaceholderScreenProps = {
  title: string;
  description: string;
};

export function PlaceholderScreen({ title, description }: PlaceholderScreenProps) {
  return (
    <div className="flex flex-1 flex-col h-full min-w-0 overflow-y-auto no-scrollbar bg-zinc-50 dark:bg-[#0d0d0d] text-zinc-900 dark:text-zinc-100">
      <ChatHeader />
      <main className="mx-auto flex max-w-lg flex-1 flex-col justify-center px-6 pb-24 text-center">
        <h1 className="text-base font-semibold text-zinc-900 dark:text-zinc-100 tracking-tight">{title}</h1>
        <p className="mt-2 text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">{description}</p>
      </main>
    </div>
  );
}
