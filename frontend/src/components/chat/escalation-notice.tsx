type EscalationNoticeProps = {
  reason: string | null;
};

export function EscalationNotice({ reason }: EscalationNoticeProps) {
  return (
    <div className="rounded-2xl border border-sky-100 bg-white p-4 shadow-sm">
      <div className="flex gap-3">
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-sky-50 text-sky-600">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden>
            <path
              d="M12 3a7 7 0 0 0-4 12v3h8v-3a7 7 0 0 0-4-12Z"
              stroke="currentColor"
              strokeWidth="1.75"
            />
            <path d="M10 21h4" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" />
          </svg>
        </div>
        <div className="min-w-0 space-y-2">
          <p className="text-sm font-semibold text-zinc-900">Needs an admin</p>
          <p className="text-sm leading-relaxed text-zinc-600">
            {reason ||
              "This answer could not be verified from community knowledge. Escalation to admins is available today through your linked WhatsApp or Telegram private chat with Zak."}
          </p>
          <p className="text-xs text-zinc-500">
            Web admin inbox is not connected yet — use the same private DM flow as on messaging
            channels.
          </p>
        </div>
      </div>
    </div>
  );
}
