type UserMessageProps = {
  text: string;
  sentAt: string;
};

export function UserMessage({ text, sentAt }: UserMessageProps) {
  return (
    <div className="flex flex-col items-end gap-1">
      <div className="max-w-[min(100%,18rem)] rounded-2xl rounded-br-md bg-zinc-900 px-4 py-3 text-sm leading-relaxed text-white sm:max-w-xs">
        {text}
      </div>
      <div className="flex items-center gap-1 text-[11px] text-zinc-400">
        <time>{sentAt}</time>
        <span aria-label="Sent">✓✓</span>
      </div>
    </div>
  );
}
