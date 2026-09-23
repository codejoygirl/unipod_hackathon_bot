import Link from "next/link";

export default function AuthLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex min-h-full flex-1 flex-col">
      <header className="border-b border-rule px-6 py-4 sm:px-9">
        <Link href="/" className="inline-flex items-baseline gap-0.5">
          <span className="font-display text-[1.375rem] leading-none font-medium tracking-[-0.015em]">
            Zak
          </span>
          <span aria-hidden="true" className="font-mono text-[0.625rem] leading-none text-accent">
            [1]
          </span>
          <span className="sr-only">Community assistant</span>
        </Link>
      </header>

      <main className="flex flex-1 items-center justify-center px-6 py-12">
        <div className="w-full max-w-sm">{children}</div>
      </main>
    </div>
  );
}
