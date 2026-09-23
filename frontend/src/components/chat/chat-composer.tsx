"use client";

import type { QuotedMessage } from "@/lib/web-chat/storage";
import { FormEvent, useEffect, useRef, useState } from "react";

type ChatComposerProps = {
  disabled?: boolean;
  onSend: (text: string, quote?: QuotedMessage) => void;
  quotedMessage?: QuotedMessage | null;
  onClearQuote?: () => void;
  onFocus?: () => void;
};

// Global type augmentation for Web Speech API
declare global {
  interface Window {
    SpeechRecognition?: any;
    webkitSpeechRecognition?: any;
  }
}

export function ChatComposer({
  disabled,
  onSend,
  quotedMessage,
  onClearQuote,
  onFocus,
}: ChatComposerProps) {
  const [value, setValue] = useState("");
  const [isRecording, setIsRecording] = useState(false);
  const [recordingSeconds, setRecordingSeconds] = useState(0);
  const recognitionRef = useRef<any>(null);
  const timerRef = useRef<NodeJS.Timeout | null>(null);
  const textareaRef = useRef<HTMLTextAreaElement>(null);

  // Allow clicking on inline command badges (like /help, /catchup, /summary) to fill input
  useEffect(() => {
    const handleCommand = (e: CustomEvent<{ command: string }>) => {
      if (e.detail?.command) {
        setValue(e.detail.command + " ");
        setTimeout(() => {
          textareaRef.current?.focus();
        }, 10);
      }
    };
    window.addEventListener("insert-chat-command" as any, handleCommand);
    return () => {
      window.removeEventListener("insert-chat-command" as any, handleCommand);
    };
  }, []);

  // Stop recording timer when recording stops
  useEffect(() => {
    if (isRecording) {
      setRecordingSeconds(0);
      timerRef.current = setInterval(() => {
        setRecordingSeconds((prev) => prev + 1);
      }, 1000);
    } else {
      if (timerRef.current) {
        clearInterval(timerRef.current);
        timerRef.current = null;
      }
    }
    return () => {
      if (timerRef.current) {
        clearInterval(timerRef.current);
      }
    };
  }, [isRecording]);

  function startVoiceRecording() {
    const SpeechRecognition =
      window.SpeechRecognition || window.webkitSpeechRecognition;

    if (!SpeechRecognition) {
      alert("Voice recognition is not supported in this browser. Please type your message.");
      return;
    }

    try {
      const recognition = new SpeechRecognition();
      recognition.continuous = true;
      recognition.interimResults = true;
      recognition.lang = "en-US";

      recognition.onstart = () => {
        setIsRecording(true);
      };

      recognition.onresult = (event: any) => {
        let transcript = "";
        for (let i = 0; i < event.results.length; i++) {
          transcript += event.results[i][0].transcript;
        }
        if (transcript.trim()) {
          setValue(transcript);
        }
      };

      recognition.onerror = () => {
        stopVoiceRecording();
      };

      recognition.onend = () => {
        setIsRecording(false);
      };

      recognitionRef.current = recognition;
      recognition.start();
    } catch {
      setIsRecording(false);
    }
  }

  function stopVoiceRecording() {
    if (recognitionRef.current) {
      try {
        recognitionRef.current.stop();
      } catch {
        /* ignore */
      }
      recognitionRef.current = null;
    }
    setIsRecording(false);
  }

  function cancelVoiceRecording() {
    stopVoiceRecording();
    setValue("");
  }

  function handleSendVoice() {
    stopVoiceRecording();
    const trimmed = value.trim();
    if (trimmed) {
      onSend(trimmed, quotedMessage ?? undefined);
      setValue("");
      onClearQuote?.();
    }
  }

  function handleSubmit(e: FormEvent) {
    e.preventDefault();
    if (isRecording) {
      handleSendVoice();
      return;
    }
    const trimmed = value.trim();
    if (!trimmed || disabled) {
      return;
    }
    onSend(trimmed, quotedMessage ?? undefined);
    setValue("");
    onClearQuote?.();
  }

  const formatRecordTime = (secs: number) => {
    const mins = Math.floor(secs / 60);
    const s = secs % 60;
    return `${mins}:${s < 10 ? "0" : ""}${s}`;
  };

  return (
    <div className="border-t border-zinc-200/80 bg-white/95 backdrop-blur-md">
      {/* WhatsApp-style quote reply preview banner */}
      {quotedMessage ? (
        <div className="mx-auto flex max-w-2xl items-center justify-between border-b border-zinc-100 bg-zinc-50/90 px-4 py-2">
          <div className="flex items-center gap-2.5 min-w-0 border-l-3 border-emerald-500 pl-2.5">
            <div className="min-w-0">
              <p className="text-xs font-semibold text-emerald-600 truncate">
                {quotedMessage.sender}
              </p>
              <p className="text-xs text-zinc-500 truncate max-w-md">
                {quotedMessage.text}
              </p>
            </div>
          </div>
          <button
            type="button"
            onClick={onClearQuote}
            className="text-zinc-400 hover:text-zinc-700 p-1 rounded-full cursor-pointer transition"
            title="Cancel reply"
          >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M18 6L6 18M6 6l12 12" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
          </button>
        </div>
      ) : null}

      <form onSubmit={handleSubmit} className="px-3 pt-2.5 pb-4 sm:px-4 sm:pb-5">
        <div className="mx-auto flex max-w-2xl items-center gap-2">
          {isRecording ? (
            /* Live Voice Recording UI (WhatsApp / Telegram style) */
            <div className="flex flex-1 items-center justify-between rounded-full border border-rose-200 bg-rose-50/70 px-3.5 py-1.5 transition-all">
              <div className="flex items-center gap-2.5">
                <span className="relative flex h-2.5 w-2.5">
                  <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75" />
                  <span className="relative inline-flex rounded-full h-2.5 w-2.5 bg-rose-600" />
                </span>
                <span className="text-xs font-medium text-rose-700">
                  {formatRecordTime(recordingSeconds)}
                </span>
                <span className="text-xs text-zinc-500 italic truncate max-w-xs">
                  {value || "Listening..."}
                </span>
              </div>

              <div className="flex items-center gap-1.5">
                <button
                  type="button"
                  onClick={cancelVoiceRecording}
                  className="rounded-full p-1.5 text-zinc-400 hover:text-rose-600 hover:bg-rose-100 transition cursor-pointer"
                  title="Cancel recording"
                >
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <path d="M19 6L5 6M10 11v6M14 11v6M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M4 6h16l-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6z" strokeLinecap="round" strokeLinejoin="round" />
                  </svg>
                </button>
                <button
                  type="button"
                  onClick={handleSendVoice}
                  className="flex h-7 w-7 items-center justify-center rounded-full bg-emerald-600 text-white shadow-xs hover:bg-emerald-700 transition cursor-pointer"
                  title="Send voice query"
                >
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <path d="M5 12h14M13 6l6 6-6 6" strokeLinecap="round" strokeLinejoin="round" />
                  </svg>
                </button>
              </div>
            </div>
          ) : (
            /* Unified chatbox pill: Speaker/Mic on LEFT, Input in MIDDLE, Send on RIGHT inside pill */
            <div className="relative min-w-0 flex-1 flex items-center rounded-full border border-zinc-200/90 bg-zinc-50/80 px-2 py-1 focus-within:border-zinc-400 focus-within:bg-white transition-all shadow-xs">
              {/* Left: Speaker / Microphone Button */}
              <button
                type="button"
                onClick={startVoiceRecording}
                disabled={disabled}
                className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-zinc-400 hover:text-zinc-700 hover:bg-zinc-200/60 transition cursor-pointer disabled:opacity-40"
                title="Voice note / speak"
              >
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z" />
                  <path d="M19 10v2a7 7 0 0 1-14 0v-2" strokeLinecap="round" />
                  <line x1="12" y1="19" x2="12" y2="22" strokeLinecap="round" />
                </svg>
              </button>

              {/* Middle: Text input with concise placeholder */}
              <label className="sr-only" htmlFor="chat-input">
                Type a message
              </label>
              <textarea
                ref={textareaRef}
                id="chat-input"
                rows={1}
                value={value}
                onChange={(e) => setValue(e.target.value)}
                onFocus={onFocus}
                onKeyDown={(e) => {
                  if (e.key === "Enter" && !e.shiftKey) {
                    e.preventDefault();
                    handleSubmit(e);
                  }
                }}
                placeholder="Type a message..."
                disabled={disabled}
                className="max-h-32 min-h-[36px] flex-1 resize-none bg-transparent px-2.5 py-2 text-sm text-zinc-900 placeholder:text-zinc-400 focus:outline-none disabled:opacity-60"
              />

              {/* Right: Send Button INSIDE the chatbox */}
              <button
                type="submit"
                disabled={disabled || !value.trim()}
                className={`flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-full transition shadow-xs active:scale-95 ${
                  value.trim()
                    ? "bg-zinc-900 text-white hover:bg-zinc-800"
                    : "bg-zinc-200/70 text-zinc-400 cursor-not-allowed"
                }`}
                aria-label="Send message"
              >
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden>
                  <path
                    d="M5 12h14M13 6l6 6-6 6"
                    stroke="currentColor"
                    strokeWidth="2.2"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                  />
                </svg>
              </button>
            </div>
          )}
        </div>
      </form>
    </div>
  );
}
