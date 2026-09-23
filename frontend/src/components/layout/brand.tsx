import Image from "next/image";
import Link from "next/link";

import { cn } from "@/lib/utils";

/**
 * The mark plus the wordmark, used in the landing header and the chat rail.
 *
 * The mark is decorative — the word carries the name for screen readers, so the image is
 * `aria-hidden` rather than duplicated in the accessible name.
 */
export function Brand({ className }: { className?: string }) {
  return (
    <Link href="/" className={cn("inline-flex items-center gap-2", className)}>
      <Image
        src="/zaklogo.png"
        alt=""
        aria-hidden="true"
        width={28}
        height={28}
        className="size-7 shrink-0 rounded-lg object-cover"
      />

      <span className="font-display text-[1.25rem] leading-none font-extrabold tracking-[-0.02em]">
        Zak
      </span>

      <span className="sr-only">Zak, home</span>
    </Link>
  );
}
