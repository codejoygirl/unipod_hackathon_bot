import Link from "next/link";

import { ANSWER_STATES, ANSWER_STATE_VALUES } from "@/components/chat/answer-state";
import { AnswerStatus } from "@/components/chat/answer-status";
import { LanguageShowcase } from "@/components/landing/language-showcase";
import { ThemeToggle } from "@/components/landing/theme-toggle";
import { ServiceStatus } from "@/components/status/service-status";

const NAV = [
  { href: "#boundary", label: "Boundary" },
  { href: "#channels", label: "Channels" },
  { href: "#states", label: "States" },
  { href: "#languages", label: "Languages" },
  { href: "#status", label: "Status" },
];

const HANDOFF = [
  {
    order: "01",
    title: "Filter before retrieval",
    body: "The AI service repeats the requesting member's permission filter on every query, so chunks outside their communities are never candidates.",
  },
  {
    order: "02",
    title: "Revalidate before release",
    body: "Laravel checks every citation against the member who asked before the answer leaves the API. The backend is the security boundary, not the prompt.",
  },
  {
    order: "03",
    title: "Refuse, do not guess",
    body: "When nothing in scope covers the question, the answer is unknown and it escalates to an admin instead of being filled in from a weaker source.",
  },
];

const MEMBERS = [
  {
    name: "Amara",
    pod: "Design pod",
    answers: [
      { question: "Design pod studio budget", verdict: "Answered", detail: "2 sources" },
      { question: "Robotics pod kit supplier", verdict: "Refused", detail: "outside Design pod" },
      { question: "UniPods code of conduct", verdict: "Answered", detail: "1 source" },
    ],
  },
  {
    name: "Tunde",
    pod: "Robotics pod",
    answers: [
      { question: "Design pod studio budget", verdict: "Refused", detail: "outside Robotics pod" },
      { question: "Robotics pod kit supplier", verdict: "Answered", detail: "3 sources" },
      { question: "UniPods code of conduct", verdict: "Answered", detail: "1 source" },
    ],
  },
];

const CHANNELS = [
  {
    name: "Web chat",
    state: "Live",
    live: true,
    body: "Signs in through this app and asks the API directly.",
  },
  {
    name: "WhatsApp",
    state: "Phase 4",
    live: false,
    body: "A signed webhook enters through Laravel, then becomes the same normalised request.",
  },
  {
    name: "Slack",
    state: "Phase 4",
    live: false,
    body: "Mentions and direct messages, authorised exactly like any other member.",
  },
];

const PLATFORM = [
  {
    surface: "Web chat",
    state: "Live",
    tone: "accent",
    note: "This app: sign in, choose a community, ask a question.",
  },
  {
    surface: "Backend API",
    state: "Phases 1 and 2",
    tone: "accent",
    note: "Sanctum sessions, tenancy, the knowledge lifecycle and the assistant ask, all under /api/v1.",
  },
  {
    surface: "Tenancy and permissions",
    state: "Enforced",
    tone: "accent",
    note: "Policies scope every query. Cross-tenant reads are covered by feature tests.",
  },
  {
    surface: "Citations",
    state: "Revalidated",
    tone: "accent",
    note: "Laravel checks each citation against the asking member before the answer leaves the API.",
  },
  {
    surface: "AI service",
    state: "Internal",
    tone: "neutral",
    note: "FastAPI behind HMAC. Reachable by Laravel only, never by the browser.",
  },
  {
    surface: "WhatsApp and Slack",
    state: "Not built",
    tone: "neutral",
    note: "Channel adapters are Phase 4. Web chat is the only live channel.",
  },
  {
    surface: "Catch-up and feedback",
    state: "Not built",
    tone: "neutral",
    note: "Both are specified, but neither has an endpoint or storage yet.",
  },
];

const TONE_DOT: Record<string, string> = {
  accent: "bg-accent",
  amber: "bg-amber",
  clay: "bg-clay",
  neutral: "bg-ink-soft",
};

/*
 * Channel glyphs are monochrome and always paired with a visible label, so the platform
 * is never carried by colour alone. The shapes are generic marks, not vendor logos.
 */
function GlobeGlyph() {
  return (
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" aria-hidden="true">
      <circle cx="12" cy="12" r="9" />
      <path d="M3 12h18" />
      <path d="M12 3a15 15 0 0 1 4 9 15 15 0 0 1-4 9 15 15 0 0 1-4-9 15 15 0 0 1 4-9Z" />
    </svg>
  );
}

function PhoneGlyph() {
  return (
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M21 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 1.1 4.2 2 2 0 0 1 3.1 2h3a2 2 0 0 1 2 1.7 12.8 12.8 0 0 0 .7 2.8 2 2 0 0 1-.5 2.1L7.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4 12.8 12.8 0 0 0 2.8.7 2 2 0 0 1 1.7 2Z" />
    </svg>
  );
}

function HashGlyph() {
  return (
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" aria-hidden="true">
      <path d="M4 9h16M4 15h16M10 3 8 21M16 3l-2 18" />
    </svg>
  );
}

function Brand() {
  return (
    <Link href="/" className="inline-flex items-baseline gap-0.5">
      <span className="font-display text-[1.375rem] leading-none font-extrabold tracking-[-0.02em]">
        Zak
      </span>
      <span aria-hidden="true" className="font-mono text-[0.625rem] leading-none text-accent">
        [1]
      </span>
      <span className="sr-only">Community assistant, home</span>
    </Link>
  );
}

export default function Home() {
  return (
    <>
      {/* First in the DOM so a keyboard user never has to tab the whole header to skip it. */}
      <a
        href="#main"
        className="zak-label fixed top-3 left-4 z-50 -translate-y-24 rounded-full bg-accent px-4 py-3 text-paper transition-transform duration-200 focus:translate-y-0"
      >
        Skip to content
      </a>

      <header className="sticky top-0 z-40 border-b border-rule bg-paper/80 backdrop-blur-md">
        <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-6 py-3 sm:px-9">
          <Brand />

          <nav aria-label="Sections" className="flex items-center gap-1">
            {NAV.map((item) => (
              <a
                key={item.href}
                href={item.href}
                className="zak-label hidden rounded-full px-3 py-2 text-ink-soft transition-colors duration-200 hover:text-accent lg:inline-block"
              >
                {item.label}
              </a>
            ))}

            <ThemeToggle className="flex size-9 shrink-0 items-center justify-center rounded-full border border-rule-strong text-ink-soft transition-colors duration-200 hover:border-accent hover:text-accent" />

            <Link
              href="/login"
              className="ml-1 rounded-full border border-rule-strong px-4 py-2 text-sm font-medium text-ink transition-colors duration-200 hover:border-accent hover:text-accent"
            >
              Sign in
            </Link>
          </nav>
        </div>
      </header>

      <main id="main" className="flex-1">
        <section className="px-6 pt-20 pb-20 sm:px-9 lg:pt-28 lg:pb-32">
          <div className="zak-rise mx-auto flex max-w-3xl flex-col items-center text-center">
            <p className="zak-label text-accent">Zak · community assistant</p>

            <h1 className="mt-5 font-display text-[clamp(2.25rem,5vw,3.5rem)] leading-[1.08] font-extrabold tracking-[-0.03em] text-balance">
              The answer in your language, the proof in every reply.
            </h1>

            <p className="mt-5 max-w-[54ch] text-[1.0625rem] leading-7 text-ink-soft">
              Zak answers from the knowledge each member is authorised to see, in English, French,
              Arabic or the language they ask in. Every answer cites what it used, and refuses
              anything outside their communities.
            </p>

            <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
              <Link
                href="/conversations"
                className="rounded-full bg-ink px-6 py-3 text-sm font-medium text-paper transition-colors duration-200 hover:bg-ink/90"
              >
                Open Zak
              </Link>
              <a
                href="#boundary"
                className="rounded-full border border-rule-strong px-6 py-3 text-sm font-medium text-ink transition-colors duration-200 hover:border-accent hover:text-accent"
              >
                See how it decides
              </a>
            </div>
          </div>
        </section>

        <section
          id="boundary"
          className="zak-section border-t border-rule bg-paper-sunk px-6 py-16 sm:px-9 lg:py-24"
        >
          <div className="zak-reveal mx-auto max-w-6xl">
            <p className="zak-label text-accent">Permission boundary</p>

            <h2 className="mt-4 max-w-3xl font-display text-[clamp(1.75rem,3vw,2.5rem)] leading-[1.1] font-extrabold tracking-[-0.02em] text-balance">
              Enforcement is a filter and a recheck, not a line in a prompt.
            </h2>

            <p className="mt-4 max-w-[64ch] text-[0.9375rem] leading-7 text-ink-soft">
              A prompt is a request. A filter and a revalidation are controls. The first runs
              before anything is retrieved; the second after the model has answered and before
              you see it.
            </p>

            <ol className="mt-10 grid gap-px overflow-hidden rounded-2xl border border-rule bg-rule sm:grid-cols-3">
              {HANDOFF.map((step) => (
                <li key={step.order} className="bg-paper-raised px-6 py-7">
                  <span aria-hidden="true" className="zak-label text-accent">
                    {step.order}
                  </span>
                  <h3 className="mt-4 text-[0.9375rem] leading-6 font-semibold">{step.title}</h3>
                  <p className="mt-2 text-[0.9375rem] leading-7 text-ink-soft">{step.body}</p>
                </li>
              ))}
            </ol>

            <p className="zak-label mt-12 text-ink-soft">Same question, two members</p>

            <div className="mt-5 grid gap-4 lg:grid-cols-2">
              {MEMBERS.map((member) => (
                <div key={member.name} className="rounded-2xl border border-rule bg-paper-raised p-6">
                  <div className="flex items-center gap-3">
                    <span
                      aria-hidden="true"
                      className="flex size-9 items-center justify-center rounded-full border border-rule-strong font-mono text-xs text-accent"
                    >
                      {member.name.charAt(0)}
                    </span>
                    <div>
                      <p className="text-[0.9375rem] leading-6 font-semibold">{member.name}</p>
                      <p className="zak-label text-ink-soft">{member.pod}</p>
                    </div>
                  </div>

                  <ul className="mt-6 space-y-4">
                    {member.answers.map((row) => {
                      const answered = row.verdict === "Answered";
                      return (
                        <li key={row.question} className="border-t border-rule pt-4">
                          <p className="text-[0.9375rem] leading-6 text-ink">{row.question}</p>
                          <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span className="flex items-center gap-2">
                              <span
                                aria-hidden="true"
                                className={`size-1.5 rounded-full ${answered ? "bg-accent" : "bg-clay"}`}
                              />
                              <span className={`zak-label ${answered ? "text-accent" : "text-clay"}`}>
                                {row.verdict}
                              </span>
                            </span>
                            <span className="text-xs leading-5 text-ink-soft">{row.detail}</span>
                          </div>
                        </li>
                      );
                    })}
                  </ul>
                </div>
              ))}
            </div>
          </div>
        </section>

        <section id="channels" className="zak-section border-t border-rule px-6 py-16 sm:px-9 lg:py-24">
          <div className="zak-reveal mx-auto grid max-w-6xl items-start gap-12 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)] lg:gap-16">
            <div>
              <p className="zak-label text-accent">Channels</p>

              <h2 className="mt-4 font-display text-[clamp(1.75rem,3vw,2.5rem)] leading-[1.1] font-extrabold tracking-[-0.02em] text-balance">
                One question, three doors, one set of rules.
              </h2>

              <p className="mt-4 max-w-[56ch] text-[0.9375rem] leading-7 text-ink-soft">
                Every webhook enters through Laravel, so the same permissions decide the answer
                wherever the question was typed.
              </p>
            </div>

            <div>
              <ul className="divide-y divide-rule overflow-hidden rounded-2xl border border-rule">
                {CHANNELS.map((channel) => (
                  <li key={channel.name} className="flex flex-wrap items-center gap-4 bg-paper-raised px-5 py-5">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-full border border-rule-strong text-accent">
                      {channel.name === "Web chat" ? (
                        <GlobeGlyph />
                      ) : channel.name === "WhatsApp" ? (
                        <PhoneGlyph />
                      ) : (
                        <HashGlyph />
                      )}
                    </span>

                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span className="text-[0.9375rem] leading-6 font-semibold">{channel.name}</span>
                        <span className="flex items-center gap-1.5">
                          <span
                            aria-hidden="true"
                            className={`size-1.5 rounded-full ${channel.live ? "bg-accent" : "bg-ink-soft"}`}
                          />
                          <span className="zak-label text-ink-soft">{channel.state}</span>
                        </span>
                      </div>
                      <p className="mt-1 text-[0.9375rem] leading-6 text-ink-soft">{channel.body}</p>
                    </div>
                  </li>
                ))}
              </ul>

              <div className="mt-4 rounded-xl border border-rule bg-paper-sunk px-5 py-5">
                <p className="zak-label text-ink-soft">Normalised request</p>
                <p className="mt-2 font-mono text-[0.8125rem] leading-6 text-ink">
                  member, community, question, language, channel
                </p>
                <p className="mt-3 text-[0.9375rem] leading-6 text-ink-soft">
                  Past this point the channel no longer matters. A member on WhatsApp and the same
                  member in the browser get the same answer, with the same citations and the same
                  restrictions.
                </p>
              </div>
            </div>
          </div>
        </section>

        <section
          id="states"
          className="zak-section border-t border-rule bg-paper-sunk px-6 py-16 sm:px-9 lg:py-24"
        >
          <div className="zak-reveal mx-auto max-w-6xl">
            <p className="zak-label text-accent">Answer states</p>

            <h2 className="mt-4 max-w-3xl font-display text-[clamp(1.75rem,3vw,2.5rem)] leading-[1.1] font-extrabold tracking-[-0.02em] text-balance">
              Six ways an answer can come back.
            </h2>

            <p className="mt-4 max-w-[64ch] text-[0.9375rem] leading-7 text-ink-soft">
              Every reply carries a label, so settled knowledge never masquerades as unsettled.
              Three of the six ask a human to follow up.
            </p>

            <dl className="mt-10 overflow-hidden rounded-2xl border border-rule">
              {ANSWER_STATE_VALUES.map((state) => {
                const presentation = ANSWER_STATES[state];
                return (
                  <div
                    key={state}
                    className="grid grid-cols-[auto_1fr] items-center gap-x-4 gap-y-2 border-b border-rule bg-paper-raised px-6 py-5 last:border-b-0 sm:grid-cols-[minmax(0,15rem)_minmax(0,1fr)_minmax(0,7rem)]"
                  >
                    <div className="flex items-center gap-3">
                      <span
                        aria-hidden="true"
                        className={`size-1.5 shrink-0 rounded-full ${TONE_DOT[presentation.tone]}`}
                      />
                      <dt>
                        <AnswerStatus state={state} />
                      </dt>
                    </div>

                    <dd className="text-[0.9375rem] leading-6 text-ink-soft">
                      {presentation.description}
                    </dd>

                    <dd
                      className={`zak-label ${presentation.escalates ? "text-amber" : "text-accent"} sm:text-right`}
                    >
                      {presentation.escalates ? "escalates" : "resolved"}
                    </dd>
                  </div>
                );
              })}
            </dl>
          </div>
        </section>

        <section id="languages" className="zak-section border-t border-rule px-6 py-16 sm:px-9 lg:py-24">
          <div className="zak-reveal mx-auto grid max-w-6xl items-start gap-12 lg:grid-cols-[minmax(0,1fr)_minmax(0,30rem)] lg:gap-16">
            <div>
              <p className="zak-label text-accent">Languages</p>

              <h2 className="mt-4 font-display text-[clamp(1.75rem,3vw,2.5rem)] leading-[1.1] font-extrabold tracking-[-0.02em] text-balance">
                The answer follows the language of the question.
              </h2>

              <p className="mt-4 max-w-[60ch] text-[0.9375rem] leading-7 text-ink-soft">
                Members ask in the language they think in. Zak answers in it, keeps citations in
                their original language so they stay verifiable, and lays the page out right to
                left where the script requires it.
              </p>

              <p className="mt-6 max-w-[60ch] border-l-2 border-rule-strong pl-4 text-[0.9375rem] leading-7 text-ink-soft">
                We do not claim equal quality in a language before it has been measured. Arabic is
                a first-class target, and it is evaluated before it is promised.
              </p>
            </div>

            <LanguageShowcase />
          </div>
        </section>

        <section
          id="status"
          className="zak-section border-t border-rule bg-paper-sunk px-6 py-16 sm:px-9 lg:py-24"
        >
          <div className="zak-reveal mx-auto max-w-6xl">
            <p className="zak-label text-accent">Platform status</p>

            <h2 className="mt-4 max-w-3xl font-display text-[clamp(1.75rem,3vw,2.5rem)] leading-[1.1] font-extrabold tracking-[-0.02em] text-balance">
              What actually exists today.
            </h2>

            <p className="mt-4 max-w-[64ch] text-[0.9375rem] leading-7 text-ink-soft">
              Phases 1 and 2 are complete. Sessions, tenancy, the knowledge lifecycle and cited
              answers are real; WhatsApp, Slack, catch-up and feedback are not. This reports the
              actual state of the two services behind it.
            </p>

            <ServiceStatus className="mt-9" />

            <dl className="mt-9 overflow-hidden rounded-2xl border border-rule">
              {PLATFORM.map((row) => (
                <div
                  key={row.surface}
                  className="grid gap-1 border-b border-rule bg-paper-raised px-6 py-4 last:border-b-0 sm:grid-cols-[minmax(0,12rem)_minmax(0,9rem)_minmax(0,1fr)] sm:gap-6"
                >
                  <dt className="zak-label text-ink">{row.surface}</dt>
                  <dd className="flex items-center gap-2">
                    <span
                      aria-hidden="true"
                      className={`size-1.5 rounded-full ${TONE_DOT[row.tone]}`}
                    />
                    <span className="zak-label text-ink-soft">{row.state}</span>
                  </dd>
                  <dd className="text-[0.9375rem] leading-6 text-ink-soft">{row.note}</dd>
                </div>
              ))}
            </dl>
          </div>
        </section>
      </main>

      <footer className="border-t border-rule px-6 py-12 sm:px-9">
        <div className="mx-auto flex max-w-6xl flex-col gap-9">
          <nav aria-label="Sections" className="flex flex-wrap gap-x-6 gap-y-2 lg:hidden">
            {NAV.map((item) => (
              <a
                key={item.href}
                href={item.href}
                className="zak-label text-ink-soft transition-colors duration-200 hover:text-accent"
              >
                {item.label}
              </a>
            ))}
          </nav>

          <div className="flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <Brand />
              <p className="mt-2 text-xs leading-5 text-ink-soft">Community assistant</p>
            </div>

            <p className="max-w-[46ch] text-xs leading-5 text-ink-soft">
              Permissions filter before retrieval. Citations are revalidated before release. The
              AI service is never public.
            </p>
          </div>
        </div>
      </footer>
    </>
  );
}
