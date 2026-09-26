"use client";

const listeners = new Set<() => void>();

let speakingId: string | null = null;
let voicesReady = false;
let speakGeneration = 0;

function notify(): void {
  listeners.forEach((fn) => fn());
}

function voices(): SpeechSynthesisVoice[] {
  if (typeof window === "undefined" || !window.speechSynthesis) return [];
  return window.speechSynthesis.getVoices();
}

function ensureVoices(): void {
  if (typeof window === "undefined" || !window.speechSynthesis || voicesReady) return;
  const load = () => {
    if (window.speechSynthesis.getVoices().length > 0) {
      voicesReady = true;
    }
  };
  load();
  window.speechSynthesis.addEventListener("voiceschanged", load);
}

function scoreCalmFemale(voice: SpeechSynthesisVoice): number {
  const name = voice.name.toLowerCase();
  const lang = (voice.lang || "").toLowerCase().replace("_", "-");
  let score = 0;
  if (lang === "en-gb" || lang.startsWith("en-gb")) score += 42;
  else if (lang.startsWith("en-au") || lang.startsWith("en-ie")) score += 24;
  else if (lang.startsWith("en-us") || lang.startsWith("en")) score += 16;

  if (/(natural|neural|online|premium|enhanced|multilingual)/.test(name)) score += 44;
  if (
    /(libby|sonia|aria|jenny|natasha|susan|hazel|serena|martha|zira|samantha|victoria|moira|fiona|karen|catherine|uk english female)/.test(
      name,
    )
  ) {
    score += 46;
  }
  if (/\bfemale\b/.test(name)) score += 18;
  if (/(male|george|ryan|thomas|daniel|david|james|guy|mark|ravi|richard|arthur|david|andrew)/.test(name)) {
    score -= 55;
  }
  if (/(compact|mobile)/.test(name)) score -= 10;
  if (voice.localService) score += 6;
  return score;
}

export function pickSoftBritishFemaleVoice(): SpeechSynthesisVoice | null {
  const list = voices();
  if (list.length === 0) return null;
  return [...list].sort((a, b) => scoreCalmFemale(b) - scoreCalmFemale(a))[0] ?? null;
}

function stripEmojis(text: string): string {
  return text
    .replace(/\p{Extended_Pictographic}/gu, " ")
    .replace(/\p{Emoji_Presentation}/gu, " ")
    .replace(/\p{Regional_Indicator}{2}/gu, " ")
    .replace(/[\u{1F3FB}-\u{1F3FF}]/gu, "")
    .replace(/[\uFE0E\uFE0F\u200D\u20E3]/gu, "")
    .replace(/(?:^|\s):[a-z0-9_+-]+:(?=\s|$)/gi, " ");
}

/** Spoken text only — drop markup, citations, raw URLs, and emojis. */
export function speakableText(raw: string): string {
  let text = (raw || "").trim();
  if (!text) return "";
  text = text.replace(/```[\s\S]*?```/g, " ");
  text = text.replace(/`([^`]+)`/g, "$1");
  text = text.replace(/\[([^\]]+)\]\([^)]+\)/g, "$1");
  text = text.replace(/https?:\/\/\S+/gi, " link ");
  text = text.replace(/\b[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}\b/g, " ");
  text = text.replace(/\[E\d+(?:\s*\([^)]*\))?\]/gi, "");
  text = text.replace(/\*\*(.+?)\*\*/g, "$1");
  text = text.replace(/\*(.+?)\*/g, "$1");
  text = text.replace(/^#{1,6}\s+/gm, "");
  text = text.replace(/^[-*•]\s+/gm, "");
  text = stripEmojis(text);
  text = text.replace(/\s+/g, " ").trim();
  return text.slice(0, 3500);
}

export function getSpeakingId(): string | null {
  return speakingId;
}

export function subscribeSpeaking(listener: () => void): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

export function stopSpeaking(): void {
  if (typeof window === "undefined" || !window.speechSynthesis) return;
  speakGeneration += 1;
  window.speechSynthesis.cancel();
  speakingId = null;
  notify();
}

function startUtterance(id: string, spoken: string, generation: number): void {
  if (typeof window === "undefined" || !window.speechSynthesis) return;
  if (generation !== speakGeneration) return;

  const utterance = new SpeechSynthesisUtterance(spoken);
  const voice = pickSoftBritishFemaleVoice();
  if (voice) {
    utterance.voice = voice;
    utterance.lang = voice.lang || "en-GB";
  } else {
    utterance.lang = "en-GB";
  }
  // Calmer than default: slightly slower, a touch lower, not full blast.
  utterance.rate = 0.84;
  utterance.pitch = 0.93;
  utterance.volume = 0.88;
  utterance.onend = () => {
    if (speakingId === id && generation === speakGeneration) {
      speakingId = null;
      notify();
    }
  };
  utterance.onerror = () => {
    if (speakingId === id && generation === speakGeneration) {
      speakingId = null;
      notify();
    }
  };

  speakingId = id;
  notify();
  window.speechSynthesis.speak(utterance);
}

function whenVoicesReady(run: () => void): void {
  ensureVoices();
  if (voices().length > 0) {
    run();
    return;
  }
  const synth = window.speechSynthesis;
  let done = false;
  const start = () => {
    if (done) return;
    done = true;
    synth.removeEventListener("voiceschanged", start);
    run();
  };
  synth.addEventListener("voiceschanged", start);
  window.setTimeout(start, 450);
}

export function toggleSpeak(id: string, raw: string): void {
  if (typeof window === "undefined" || !window.speechSynthesis) return;
  ensureVoices();

  if (speakingId === id && (window.speechSynthesis.speaking || window.speechSynthesis.pending)) {
    stopSpeaking();
    return;
  }

  const spoken = speakableText(raw);
  if (!spoken) return;

  stopSpeaking();
  const generation = speakGeneration;
  // Chrome can drop the next speak() if it runs in the same tick as cancel().
  whenVoicesReady(() => {
    window.setTimeout(() => startUtterance(id, spoken, generation), 60);
  });
}

export function canSpeak(): boolean {
  return typeof window !== "undefined" && "speechSynthesis" in window;
}
