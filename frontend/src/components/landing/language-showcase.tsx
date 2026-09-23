"use client";

import { useState } from "react";

import { CitedAnswer } from "@/components/chat/cited-answer";
import type { EvidenceCitation } from "@/types/api";

type LanguageKey = "fr" | "ar" | "en";

const EXAMPLES: Record<
  LanguageKey,
  {
    label: string;
    lang: string;
    question: string;
    answer: string;
    evidence: EvidenceCitation[];
    confidence: number;
  }
> = {
  fr: {
    label: "Français",
    lang: "fr",
    question: "À quelle heure se réunit le pôle design cette semaine ?",
    answer:
      "Le pôle design se réunit jeudi à 18 h dans l'annexe, car l'événement des anciens occupe le studio principal.",
    evidence: [
      {
        evidence_id: "E1",
        source_name: "Guide du pôle design, v4",
        source_uri: "doc://guide-pole-design",
        exact_quote: "Le pôle se réunit le jeudi à 18 h pendant le trimestre.",
        context: "Réunions",
        page: 9,
        timestamp: null,
        authority: "official_announcement",
      },
      {
        evidence_id: "E2",
        source_name: "Slack, annonces design",
        source_uri: "doc://slack-design-annonces",
        exact_quote: "Cette semaine, la réunion a lieu dans l'annexe.",
        context: "Annonce",
        page: null,
        timestamp: null,
        authority: "community_discussion",
      },
    ],
    confidence: 0.86,
  },
  ar: {
    label: "العربية",
    lang: "ar",
    question: "متى يجتمع فريق التصميم هذا الأسبوع وأين؟",
    answer:
      "يجتمع فريق التصميم يوم الخميس الساعة السادسة مساءً في الملحق، لأن فعالية الخريجين تشغل الاستوديو الرئيسي.",
    evidence: [
      {
        evidence_id: "E1",
        source_name: "دليل فريق التصميم، الإصدار الرابع",
        source_uri: "doc://guide-equipe-design",
        exact_quote: "يجتمع الفريق يوم الخميس الساعة السادسة مساءً.",
        context: "الاجتماعات",
        page: 9,
        timestamp: null,
        authority: "official_announcement",
      },
      {
        evidence_id: "E2",
        source_name: "سلاك، إعلانات التصميم",
        source_uri: "doc://slack-design-annonces",
        exact_quote: "اجتماع هذا الأسبوع في الملحق.",
        context: "إعلان",
        page: null,
        timestamp: null,
        authority: "community_discussion",
      },
    ],
    confidence: 0.82,
  },
  en: {
    label: "English",
    lang: "en",
    question: "When does the design pod meet this week, and where?",
    answer:
      "The design pod meets Thursday at 6 pm in the annex, because the alumni event is using the main studio.",
    evidence: [
      {
        evidence_id: "E1",
        source_name: "Design pod handbook, v4",
        source_uri: "doc://design-pod-handbook",
        exact_quote: "The pod meets Thursdays at 6 pm during term.",
        context: "Meetings",
        page: 9,
        timestamp: null,
        authority: "official_announcement",
      },
      {
        evidence_id: "E2",
        source_name: "Slack, design announcements",
        source_uri: "doc://slack-design-announcements",
        exact_quote: "This week's meeting is in the annex.",
        context: "Announcement",
        page: null,
        timestamp: null,
        authority: "community_discussion",
      },
    ],
    confidence: 0.82,
  },
};

const ORDER: LanguageKey[] = ["fr", "ar", "en"];

/**
 * One question asked in three languages, answered in the language it was asked in.
 * French is the default because it is the first of the evaluated languages after English.
 */
export function LanguageShowcase() {
  const [selected, setSelected] = useState<LanguageKey>("fr");

  return (
    <div>
      <div role="group" aria-label="Choose an example language" className="flex flex-wrap gap-1">
        {ORDER.map((key) => {
          const active = key === selected;
          return (
            <button
              key={key}
              type="button"
              aria-pressed={active}
              onClick={() => setSelected(key)}
              className={
                active
                  ? "zak-label rounded-full bg-ink px-4 py-2 text-paper transition-colors duration-200"
                  : "zak-label rounded-full border border-rule-strong px-4 py-2 text-ink-soft transition-colors duration-200 hover:border-accent hover:text-accent"
              }
            >
              {EXAMPLES[key].label}
            </button>
          );
        })}
      </div>

      <div className="mt-5">
        {ORDER.map((key) => (
          <div key={key} hidden={key !== selected}>
            <CitedAnswer
              question={EXAMPLES[key].question}
              state="VERIFIED"
              answer={EXAMPLES[key].answer}
              evidence={EXAMPLES[key].evidence}
              confidence={EXAMPLES[key].confidence}
              detectedLanguage={EXAMPLES[key].lang}
              channel={key === "fr" ? "Web chat" : key === "ar" ? "Web chat" : "Web chat"}
              scope="UniPods · Design pod"
            />
          </div>
        ))}
      </div>
    </div>
  );
}
