import Link from "next/link";

import { ANSWER_STATES, ANSWER_STATE_VALUES } from "@/components/chat/answer-state";
import { AnswerStatus } from "@/components/chat/answer-status";
import { CitedAnswer } from "@/components/chat/cited-answer";
import { ServiceStatus } from "@/components/status/service-status";

const HANDOFF = [
  {
    order: "1",
    title: "Filter before retrieval",
    body: "The AI service repeats the requesting member's permission filter on every query, so chunks outside their communities are never candidates.",
  },
  {
    order: "2",
    title: "Revalidate before release",
    body: "Laravel checks every citation against the member who asked before the answer leaves the API. The backend is the security boundary, not the prompt.",
  },
  {
    order: "3",
    title: "Refuse, do not guess",
    body: "When nothing in scope covers the question, the answer is unknown and it escalates to an admin instead of being filled in from a weaker source.",
  },
];

const SCOPE_MATRIX = [
  {
    question: "Design pod studio budget",
    amara: { verdict: "Answered", detail: "2 sources" },
    tunde: { verdict: "Refused", detail: "outside Robotics pod" },
  },
  {
    question: "Robotics pod kit supplier",
    amara: { verdict: "Refused", detail: "outside Design pod" },
    tunde: { verdict: "Answered", detail: "3 sources" },
  },
  {
    question: "UniPods code of conduct",
    amara: { verdict: "Answered", detail: "1 source" },
    tunde: { verdict: "Answered", detail: "1 source" },
  },
];

const CHANNELS = [
  {
    name: "Web chat",
    state: "Live",
    body: "Signs in through this app and asks the API directly.",
  },
  {
    name: "WhatsApp",
    state: "Phase 4",
    body: "A signed webhook enters through Laravel, then becomes the same normalised request.",
  },
  {
    name: "Slack",
    state: "Phase 4",
    body: "Mentions and direct messages, authorised exactly like any other member.",
  },
];

const PLATFORM = [
  {
    surface: "Web chat",
    state: "Live",
    note: "This app: sign in, choose a community, ask a question.",
  },
  {
    surface: "Backend API",
    state: "Phases 1 and 2",
    note: "Sanctum sessions, tenancy, the knowledge lifecycle and the assistant ask, all under /api/v1.",
  },
  {
    surface: "Tenancy and permissions",
    state: "Enforced",
    note: "Policies scope every query. Cross-tenant reads are covered by feature tests.",
  },
  {
    surface: "Citations",
    state: "Revalidated",
    note: "Laravel checks each citation against the asking member before the answer leaves the API.",
  },
  {
    surface: "AI service",
    state: "Internal",
    note: "FastAPI behind HMAC. Reachable by Laravel only, never by the browser.",
  },
  {
    surface: "WhatsApp and Slack",
    state: "Not built",
    note: "Channel adapters are Phase 4. Web chat is the only live channel.",
  },
  {
    surface: "Catch-up and feedback",
    state: "Not built",
    note: "Both are specified, but neither has an endpoint or storage yet.",
  },
];

export default function Home() {
  return (
    <>
      <header className="border-b border-rule bg-paper">
        <div className="mx-auto flex max-w-6xl items-center justify-between gap-6 px-6 py-4 sm:px-9">
          <a href="#top" className="flex items-baseline gap-0.5">
            <span className="font-display text-[1.375rem] leading-none font-medium tracking-[-0.015em]">
              Zak
            </span>
            <span aria-hidden="true" className="font-mono text-[0.625rem] leading-none text-accent">
              [1]
            </span>
            <span className="sr-only">Community assistant</span>
          </a>

          <nav aria-label="Sections" className="flex items-center gap-5">
            <a
              href="#scope"
              className="zak-label text-ink-soft transition-colors duration-200 hover:text-accent"
            >
              Boundary
            </a>
            <a
              href="#channels"
              className="zak-label hidden text-ink-soft transition-colors duration-200 hover:text-accent sm:inline"
            >
              Channels
            </a>
            <a
              href="#status"
              className="zak-label text-ink-soft transition-colors duration-200 hover:text-accent"
            >
              Status
            </a>
            <Link
              href="/login"
              className="zak-label rounded-sm border border-rule-strong px-3 py-2 text-ink transition-colors duration-200 hover:border-accent hover:text-accent"
            >
              Sign in
            </Link>
          </nav>
        </div>
      </header>

      <main id="top" className="flex-1">
        <section className="px-6 pt-14 pb-16 sm:px-9 lg:pt-20 lg:pb-24">
          <div className="mx-auto grid max-w-6xl items-start gap-12 lg:grid-cols-[minmax(0,1fr)_minmax(0,30rem)] lg:gap-16">
            <div className="zak-rise">
              <p className="zak-label text-ink-soft">Community assistant</p>

              <h1 className="mt-4 font-display text-[clamp(2.25rem,5.2vw,3.5rem)] leading-[1.08] tracking-[-0.02em] text-balance">
                Answers that show their sources, and stop at the boundary.
              </h1>

              <p className="mt-6 max-w-[60ch] text-[1.0625rem] leading-7 text-ink-soft">
                Members ask in web chat, and next in WhatsApp and Slack. Zak answers from the
                knowledge each member is authorised to see, cites what it used, and refuses anything
                belonging to a community they are not in.
              </p>

              <div className="mt-9 flex flex-wrap items-center gap-3">
                <Link
                  href="/conversations"
                  className="zak-label rounded-sm bg-accent px-5 py-3 text-paper transition-colors duration-200 hover:bg-accent-bright"
                >
                  Open Zak
                </Link>
                <a
                  href="#scope"
                  className="zak-label rounded-sm border border-rule-strong px-5 py-3 text-ink transition-colors duration-200 hover:border-accent hover:text-accent"
                >
                  See the boundary
                </a>
              </div>

              <p className="mt-6 max-w-[54ch] text-xs leading-5 text-ink-soft">
                UniPods is the first deployment. The product is not built around it.
              </p>
            </div>

            <div className="zak-rise" style={{ animationDelay: "90ms" }}>
              <p className="zak-label mb-3 text-ink-soft">Example answer</p>

              <CitedAnswer
                channel="Web chat"
                scope="UniPods · Design pod"
                question="When does the design pod meet this week, and where?"
                state="VERIFIED"
                detectedLanguage="en"
                confidence={0.86}
                answer="The design pod meets Thursday at 18:00. This week the session moves to the annex, because the alumni event has the main studio. The change was announced on Tuesday and applies to this week only."
                evidence={[
                  {
                    evidence_id: "E1",
                    source_name: "Design pod handbook, v4",
                    source_uri: "doc://design-pod-handbook",
                    exact_quote: "The pod meets on Thursdays at 18:00 in the main studio.",
                    context: "Meeting schedule",
                    page: 4,
                    timestamp: null,
                    authority: "official_announcement",
                  },
                  {
                    evidence_id: "E2",
                    source_name: "Slack · #design-announcements",
                    source_uri: "slack://design/announcements",
                    exact_quote: "This week only: we move to the annex because of the alumni event.",
                    context: "Posted Tuesday 09:41",
                    page: null,
                    timestamp: null,
                    authority: "community_discussion",
                  },
                ]}
              />
            </div>
          </div>
        </section>

        <section id="scope" className="border-t border-rule bg-paper-sunk px-6 py-16 sm:px-9 lg:py-24">
          <div className="mx-auto max-w-6xl">
            <p className="zak-label text-ink-soft">Permission boundary</p>

            <h2 className="mt-4 max-w-3xl font-display text-[clamp(1.625rem,3vw,2.25rem)] leading-[1.15] tracking-[-0.015em] text-balance">
              The same question, two members, two different answers.
            </h2>

            <p className="mt-4 max-w-[64ch] text-[0.9375rem] leading-7 text-ink-soft">
              Retrieval filters to the communities a member belongs to before a single chunk is read.
              Laravel then revalidates each citation before the answer leaves the API. A line in a
              prompt is not a security control. A filter and a revalidation are.
            </p>

            <div className="mt-9 grid items-start gap-9 lg:grid-cols-[minmax(0,28rem)_minmax(0,1fr)]">
              <div>
                <p className="zak-label mb-3 text-ink-soft">Example refusal</p>

                <CitedAnswer
                  channel="Web chat"
                  scope="UniPods · Robotics pod"
                  question="What did the design pod decide about the studio budget?"
                  state="BLOCKED"
                  evidence={[]}
                />
              </div>

              <div>
                <div
                  role="region"
                  aria-label="Which member can see which answer"
                  tabIndex={0}
                  className="zak-card overflow-x-auto rounded-sm"
                >
                  <table className="w-full min-w-[30rem] border-collapse text-left">
                    <caption className="zak-label px-6 py-4 text-left text-ink-soft">
                      Three questions, two members, and where each one is allowed to read
                    </caption>

                    <thead>
                      <tr className="border-y border-rule">
                        <th scope="col" className="zak-label px-6 py-3 font-normal text-ink-soft">
                          Question
                        </th>
                        <th scope="col" className="zak-label px-6 py-3 font-normal text-ink-soft">
                          Amara · Design pod
                        </th>
                        <th scope="col" className="zak-label px-6 py-3 font-normal text-ink-soft">
                          Tunde · Robotics pod
                        </th>
                      </tr>
                    </thead>

                    <tbody>
                      {SCOPE_MATRIX.map((row) => (
                        <tr key={row.question} className="border-b border-rule last:border-b-0">
                          <th
                            scope="row"
                            className="px-6 py-4 text-[0.9375rem] leading-6 font-normal text-ink"
                          >
                            {row.question}
                          </th>
                          {[row.amara, row.tunde].map((cell, index) => (
                            <td key={index} className="px-6 py-4 align-top">
                              <span
                                className={
                                  cell.verdict === "Answered"
                                    ? "zak-label block text-accent"
                                    : "zak-label block text-clay"
                                }
                              >
                                {cell.verdict}
                              </span>
                              <span className="mt-1 block text-xs leading-5 text-ink-soft">
                                {cell.detail}
                              </span>
                            </td>
                          ))}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                <ol className="mt-9 space-y-6">
                  {HANDOFF.map((step) => (
                    <li key={step.order} className="flex gap-4">
                      <span
                        aria-hidden="true"
                        className="zak-label mt-0.5 shrink-0 text-accent"
                      >
                        {step.order}
                      </span>
                      <div>
                        <h3 className="text-[0.9375rem] leading-6 font-medium">{step.title}</h3>
                        <p className="mt-1 max-w-[58ch] text-[0.9375rem] leading-7 text-ink-soft">
                          {step.body}
                        </p>
                      </div>
                    </li>
                  ))}
                </ol>
              </div>
            </div>
          </div>
        </section>

        <section id="channels" className="border-t border-rule px-6 py-16 sm:px-9 lg:py-24">
          <div className="mx-auto max-w-6xl">
            <p className="zak-label text-ink-soft">Channels</p>

            <h2 className="mt-4 max-w-3xl font-display text-[clamp(1.625rem,3vw,2.25rem)] leading-[1.15] tracking-[-0.015em] text-balance">
              One question, three doors, one set of rules.
            </h2>

            <p className="mt-4 max-w-[64ch] text-[0.9375rem] leading-7 text-ink-soft">
              Channel webhooks enter through Laravel, and so does this page. Neither reaches the AI
              service directly, so the same permissions decide the answer wherever the question was
              typed.
            </p>

            <div className="mt-9 grid gap-4 sm:grid-cols-3">
              {CHANNELS.map((channel) => (
                <div key={channel.name} className="zak-card rounded-sm px-6 py-5">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="zak-label text-ink">{channel.name}</span>
                    <span
                      className={
                        channel.state === "Live"
                          ? "zak-label text-accent"
                          : "zak-label text-ink-soft"
                      }
                    >
                      {channel.state}
                    </span>
                  </div>
                  <p className="mt-3 text-[0.9375rem] leading-6 text-ink-soft">{channel.body}</p>
                </div>
              ))}
            </div>

            <div className="mt-4 border-t border-rule pt-6">
              <p className="zak-label text-ink-soft">Normalised request</p>
              <p className="mt-2 font-mono text-[0.8125rem] leading-6 text-ink">
                member, community, question, language, channel
              </p>
              <p className="mt-3 max-w-[64ch] text-[0.9375rem] leading-7 text-ink-soft">
                Past this point the channel no longer matters. A member who asks on WhatsApp and the
                same member who asks in the browser get the same answer, with the same citations and
                the same restrictions.
              </p>
            </div>
          </div>
        </section>

        <section id="states" className="border-t border-rule bg-paper-sunk px-6 py-16 sm:px-9 lg:py-24">
          <div className="mx-auto max-w-6xl">
            <p className="zak-label text-ink-soft">Answer states</p>

            <h2 className="mt-4 max-w-3xl font-display text-[clamp(1.625rem,3vw,2.25rem)] leading-[1.15] tracking-[-0.015em] text-balance">
              Six ways an answer can come back.
            </h2>

            <p className="mt-4 max-w-[64ch] text-[0.9375rem] leading-7 text-ink-soft">
              Every reply carries a label, so a member can tell settled knowledge from unsettled
              knowledge instead of being handed one confident voice for both. Three of the six ask a
              human to follow up.
            </p>

            <dl className="mt-9 grid gap-px overflow-hidden rounded-sm border border-rule bg-rule sm:grid-cols-2 lg:grid-cols-3">
              {ANSWER_STATE_VALUES.map((state) => (
                <div key={state} className="bg-paper-raised px-6 py-6">
                  <dt>
                    <AnswerStatus state={state} />
                  </dt>
                  <dd className="mt-3 text-[0.9375rem] leading-6 text-ink-soft">
                    {ANSWER_STATES[state].description}
                  </dd>
                </div>
              ))}
            </dl>
          </div>
        </section>

        <section id="languages" className="border-t border-rule px-6 py-16 sm:px-9 lg:py-24">
          <div className="mx-auto grid max-w-6xl items-start gap-12 lg:grid-cols-[minmax(0,1fr)_minmax(0,28rem)] lg:gap-16">
            <div>
              <p className="zak-label text-ink-soft">Languages</p>

              <h2 className="mt-4 font-display text-[clamp(1.625rem,3vw,2.25rem)] leading-[1.15] tracking-[-0.015em] text-balance">
                The answer follows the language of the question.
              </h2>

              <p className="mt-4 max-w-[60ch] text-[0.9375rem] leading-7 text-ink-soft">
                Members ask in the language they think in. Zak answers in it, keeps citations in
                their original language so they stay verifiable, and lays the page out right to left
                where the script requires it.
              </p>

              <p className="mt-6 max-w-[60ch] border-l-2 border-rule-strong pl-4 text-[0.9375rem] leading-7 text-ink-soft">
                We do not claim equal quality in every language before it has been measured. Arabic
                is a first-class target, and it is evaluated before it is promised.
              </p>
            </div>

            <div dir="rtl" lang="ar" className="font-arabic">
              <p className="zak-label mb-3 text-ink-soft">مثال على إجابة</p>

              <div className="zak-card rounded-sm p-6 sm:p-7">
                <p className="text-[1.0625rem] leading-[1.9] text-ink">
                  متى يجتمع فريق التصميم هذا الأسبوع وأين؟
                </p>

                <div className="mt-5 border-t border-rule pt-5">
                  <p className="text-[1.0625rem] leading-[1.9] text-ink">
                    يجتمع فريق التصميم يوم الخميس الساعة السادسة مساءً في الملحق، لأن فعالية
                    الخريجين تشغل الاستوديو الرئيسي.
                  </p>
                </div>

                <div className="mt-5 border-t border-rule pt-5">
                  <p className="zak-label text-ink-soft">المصادر</p>
                  <ul className="mt-3 space-y-2 text-[0.875rem] leading-6 text-ink-soft">
                    <li>دليل فريق التصميم، الإصدار الرابع</li>
                    <li>سلاك، إعلانات التصميم</li>
                  </ul>
                </div>
              </div>
            </div>
          </div>
        </section>

        <section id="status" className="border-t border-rule bg-paper-sunk px-6 py-16 sm:px-9 lg:py-24">
          <div className="mx-auto max-w-6xl">
            <p className="zak-label text-ink-soft">Platform status</p>

            <h2 className="mt-4 max-w-3xl font-display text-[clamp(1.625rem,3vw,2.25rem)] leading-[1.15] tracking-[-0.015em] text-balance">
              What actually exists today.
            </h2>

            <p className="mt-4 max-w-[64ch] text-[0.9375rem] leading-7 text-ink-soft">
              Phases 1 and 2 are complete. Sessions, tenancy, the knowledge lifecycle and cited
              answers are real; WhatsApp, Slack, catch-up and answer feedback are not. Rather than
              illustrate a dashboard that does not exist, this page reports the actual state of the
              two services behind it.
            </p>

            <ServiceStatus className="mt-9" />

            <dl className="mt-9 grid gap-px overflow-hidden rounded-sm border border-rule bg-rule">
              <div className="hidden bg-paper px-6 py-3 sm:grid sm:grid-cols-[minmax(0,12rem)_minmax(0,9rem)_minmax(0,1fr)] sm:gap-6">
                <dt className="zak-label text-ink-soft">Surface</dt>
                <dt className="zak-label text-ink-soft">State</dt>
                <dt className="zak-label text-ink-soft">Notes</dt>
              </div>

              {PLATFORM.map((row) => (
                <div
                  key={row.surface}
                  className="grid gap-1 bg-paper-raised px-6 py-5 sm:grid-cols-[minmax(0,12rem)_minmax(0,9rem)_minmax(0,1fr)] sm:gap-6"
                >
                  <dt className="zak-label text-ink">{row.surface}</dt>
                  <dd className="zak-label text-ink-soft sm:order-none">{row.state}</dd>
                  <dd className="text-[0.9375rem] leading-6 text-ink-soft">{row.note}</dd>
                </div>
              ))}
            </dl>
          </div>
        </section>
      </main>

      <footer className="border-t border-rule px-6 py-12 sm:px-9">
        <div className="mx-auto flex max-w-6xl flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <p className="flex items-baseline gap-0.5">
              <span className="font-display text-[1.25rem] leading-none font-medium tracking-[-0.015em]">
                Zak
              </span>
              <span aria-hidden="true" className="font-mono text-[0.625rem] leading-none text-accent">
                [1]
              </span>
            </p>
            <p className="mt-2 text-xs leading-5 text-ink-soft">Community assistant</p>
          </div>

          <p className="max-w-[46ch] text-xs leading-5 text-ink-soft">
            Permissions filter before retrieval. Citations are revalidated before release. The AI
            service is never public.
          </p>
        </div>
      </footer>
    </>
  );
}
