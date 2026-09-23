type EscalationNoticeProps = {
  reason: string | null;
};

export function EscalationNotice({ reason }: EscalationNoticeProps) {
  return (
    <div className="rounded-xl border border-amber-200/80 bg-amber-50/50 p-3.5 text-xs text-amber-900">
      <div className="flex items-start gap-2.5">
        <svg
          width="16"
          height="16"
          viewBox="0 0 24 24"
          fill="none"
          className="mt-0.5 shrink-0 text-amber-600"
          aria-hidden
        >
          <path
            d="M12 3a7 7 0 0 0-4 12v3h8v-3a7 7 0 0 0-4-12Z"
            stroke="currentColor"
            strokeWidth="1.75"
          />
          <path d="M10 21h4" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" />
        </svg>
        <div className="min-w-0 flex-1 space-y-1">
          <p className="font-semibold text-amber-950">Needs admin verification</p>
          <p className="leading-relaxed text-amber-900/90">
            {reason ||
              "This answer could not be fully verified from community knowledge sources. Escalation to admins is available through your linked WhatsApp or Telegram channel."}
          </p>
        </div>
      </div>
    </div>
  );
}
