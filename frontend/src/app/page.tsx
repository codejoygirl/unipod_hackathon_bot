import { LandingAsk } from "@/components/landing/landing-ask";
import { ThemeToggle } from "@/components/landing/theme-toggle";
import { Brand } from "@/components/layout/brand";
import Avatar from "@/components/ui/components-primitives-avatar";

export default function Home() {
  return (
    <div className="relative flex min-h-dvh flex-col">
      <header className="relative z-10 flex items-center justify-between gap-4 px-6 py-4 sm:px-8">
        <Brand />
        <ThemeToggle className="flex size-9 items-center justify-center rounded-full border border-rule-strong text-ink-soft transition-colors duration-200 hover:border-accent hover:text-accent" />
      </header>

      <main className="relative z-10 flex flex-1 items-center justify-center px-6 pb-20">
        <div aria-hidden="true" className="zak-glow absolute inset-0 -z-20" />
        <div aria-hidden="true" className="zak-grid-bg absolute inset-0 -z-10" />

        <div className="zak-rise flex w-full max-w-2xl flex-col items-center text-center">
          <Avatar size="lg" color="green" shape="squircle" />

          <h1 className="mt-7 font-display text-[clamp(2.5rem,6vw,4.25rem)] leading-[1.04] font-extrabold tracking-[-0.03em] text-balance">
            The group already answered it.
          </h1>

          <p className="mt-4 max-w-[46ch] text-[1.0625rem] leading-7 text-ink-soft">
            Ask your community&rsquo;s group chat anything. Every answer names the message it came
            from.
          </p>

          <div className="mt-8 flex w-full flex-col items-center">
            <LandingAsk />
          </div>
        </div>
      </main>

      <footer className="relative z-10 px-6 py-6 text-center">
        <p className="text-xs leading-5 text-ink-soft">
          Answers come only from knowledge your community has published.
        </p>
      </footer>
    </div>
  );
}
