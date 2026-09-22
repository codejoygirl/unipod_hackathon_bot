"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

import { cn } from "@/lib/utils";

/**
 * Only routes with a real backend surface. Meetings, integrations, escalations,
 * analytics and settings have no endpoints yet, so they are not linked.
 */
const LINKS = [
  { href: "/conversations", label: "Conversations" },
  { href: "/communities", label: "Communities" },
  { href: "/knowledge", label: "Knowledge" },
];

export function AppNav() {
  const pathname = usePathname();

  return (
    <nav aria-label="Sections" className="flex items-center gap-1">
      {LINKS.map((link) => {
        const active = pathname === link.href || pathname.startsWith(`${link.href}/`);

        return (
          <Link
            key={link.href}
            href={link.href}
            aria-current={active ? "page" : undefined}
            className={cn(
              "rounded-sm px-3 py-2 text-sm transition-colors duration-200",
              active ? "bg-paper-sunk text-ink" : "text-ink-soft hover:text-ink",
            )}
          >
            {link.label}
          </Link>
        );
      })}
    </nav>
  );
}
