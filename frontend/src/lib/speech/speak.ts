"use client";

const listeners = new Set<() => void>();

let speakingId: string | null = null;
let voicesReady = false;

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
  window.speechSynthesis.addEventListener("voiceschanged", load, { once: true });
}

function scoreBritishFemale(voice: SpeechSynthesisVoice): number {
  const name = voice.name.toLowerCase();
  const lang = (voice.lang || "").toLowerCase().replace("_", "-");
  let score = 0;
  if (lang === "en-gb" || lang.startsWith("en-gb")) score += 50;
  else if (lang.startsWith("en")) score += 12;
  if (/(libby|sonia|susan|hazel|serena|martha|uk english female|british.*female|female)/.test(name)) {
    score += 35;
  }
  if (/(male|george|ryan|thomas|daniel|david|james|guy)/.test(name)) score -= 45;
  if (voice.localService) score += 4;
  return score;
}

export function pickSoftBritishFemaleVoice(): SpeechSynthesisVoice | null {
  const list = voices();
  if (list.length === 0) return null;
  return [...list].sort((a, b) => scoreBritishFemale(b) - scoreBritishFemale(a))[0] ?? null;
}

/** Spoken text only — drop markup, citations, and raw URLs. */
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
  window.speechSynthesis.cancel();
  speakingId = null;
  notify();
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

  const utterance = new SpeechSynthesisUtterance(spoken);
  const voice = pickSoftBritishFemaleVoice();
  if (voice) {
    utterance.voice = voice;
    utterance.lang = voice.lang || "en-GB";
  } else {
    utterance.lang = "en-GB";
  }
  utterance.rate = 0.92;
  utterance.pitch = 1.04;
  utterance.volume = 1;
  utterance.onend = () => {
    if (speakingId === id) {
      speakingId = null;
      notify();
    }
  };
  utterance.onerror = () => {
    if (speakingId === id) {
      speakingId = null;
      notify();
    }
  };

  speakingId = id;
  notify();
  window.speechSynthesis.speak(utterance);
}

export function canSpeak(): boolean {
  return typeof window !== "undefined" && "speechSynthesis" in window;
}
