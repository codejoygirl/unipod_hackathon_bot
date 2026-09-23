import type { ReactNode } from "react";

import Image from "next/image";

/** A right-aligned member message: light grey, generously rounded. */
export function UserTurn({ children }: { children: ReactNode }) {
  return (
    <div className="flex justify-end">
      <div className="max-w-[85%] rounded-3xl rounded-br-lg bg-paper-sunk px-4 py-2.5">
        <p className="text-[0.9375rem] leading-6 text-ink">{children}</p>
      </div>
    </div>
  );
}

/** The assistant's mark. Decorative: the reply is announced by the message itself. */
export function AssistantAvatar() {
  return (
    <Image
      src="/zak-mascot.png"
      alt=""
      aria-hidden="true"
      width={28}
      height={28}
      className="mt-0.5 size-7 shrink-0 rounded-full object-cover"
    />
  );
}

/**
 * A left-aligned assistant message with no bubble. The reply is the page, the way a chat
 * transcript reads; only the member's own words get a surface.
 */
export function AssistantTurn({ children }: { children: ReactNode }) {
  return (
    <div className="flex gap-3">
      <AssistantAvatar />
      <div className="min-w-0 flex-1 space-y-3">{children}</div>
    </div>
  );
}
