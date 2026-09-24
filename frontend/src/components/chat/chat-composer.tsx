"use client";

import React, { FormEvent, useEffect, useRef, useState } from "react";
import type { QuotedMessage } from "@/lib/web-chat/storage";
import {
  ALLOWED_CHAT_IMAGE_MIMES,
  MAX_CHAT_IMAGES,
  prepareChatImage,
  type ChatImagePayload,
} from "@/lib/web-chat/image";
import { ImagePreviewLightbox } from "./image-preview-lightbox";
import { CircularLoader } from "@/components/ui/circular-loader";
import { Tooltip } from "@/components/ui/tooltip";
import { useTypewriterPlaceholder } from "./typewriter-placeholder";
import { SlashCommandMenu } from "./slash-command-menu";

interface ChatComposerProps {
  disabled?: boolean;
  /** True while the assistant request is in flight */
  sending?: boolean;
  /** Shown on the send control while `sending` (e.g. "Asking Zak…") */
  sendingLabel?: string;
  isAdmin?: boolean;
  onSend: (text: string, quote?: QuotedMessage, images?: ChatImagePayload[]) => void;
  quotedMessage?: QuotedMessage | null;
  onClearQuote?: () => void;
  onFocus?: () => void;
  onTyping?: () => void;
}

// Global type augmentation for Web Speech API
interface SpeechRecognitionResultItem {
  transcript: string;
}

interface SpeechRecognitionResultList {
  length: number;
  [index: number]: {
    [index: number]: SpeechRecognitionResultItem;
  };
}

interface SpeechRecognitionEventLike {
  results: SpeechRecognitionResultList;
}

interface SpeechRecognitionInstance {
  continuous: boolean;
  interimResults: boolean;
  lang: string;
  onstart: (() => void) | null;
  onresult: ((event: SpeechRecognitionEventLike) => void) | null;
  onerror: (() => void) | null;
  onend: (() => void) | null;
  start: () => void;
  stop: () => void;
}

interface SpeechRecognitionConstructor {
  new (): SpeechRecognitionInstance;
}

declare global {
  interface Window {
    SpeechRecognition?: SpeechRecognitionConstructor;
    webkitSpeechRecognition?: SpeechRecognitionConstructor;
  }
}

export function ChatComposer({
  disabled,
  sending = false,
  sendingLabel = "One moment…",
  isAdmin = false,
  onSend,
  quotedMessage,
  onClearQuote,
  onFocus,
  onTyping,
}: ChatComposerProps) {
  const [value, setValue] = useState("");
  const [isRecording, setIsRecording] = useState(false);
  const [recordingSeconds, setRecordingSeconds] = useState(0);
  const [isSlashMenuOpen, setIsSlashMenuOpen] = useState(false);
  const [slashFilter, setSlashFilter] = useState("");
  const [pendingImages, setPendingImages] = useState<ChatImagePayload[]>([]);
  const [previewImage, setPreviewImage] = useState<ChatImagePayload | null>(null);
  const [imageError, setImageError] = useState<string | null>(null);
  const [imageBusy, setImageBusy] = useState(false);

  const recognitionRef = useRef<SpeechRecognitionInstance | null>(null);
  const timerRef = useRef<NodeJS.Timeout | null>(null);
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  // Typewriter animated placeholder with programme question examples
  const isInputEmpty = value.length === 0;
  const isMultiline = value.includes("\n") || value.length > 55;
  const animatedPlaceholder = useTypewriterPlaceholder(undefined, {
    paused: !isInputEmpty || isRecording,
  });

  // Adjust textarea height dynamically to content (ChatGPT style auto-resize)
  const adjustHeight = () => {
    const el = textareaRef.current;
    if (el) {
      el.style.height = "auto";
      const newHeight = Math.min(el.scrollHeight, 180);
      el.style.height = `${Math.max(24, newHeight)}px`;
    }
  };

  useEffect(() => {
    adjustHeight();
  }, [value]);

  // Listen for insert-chat-command custom event from anywhere (Modals, Help cards, Recents)
  useEffect(() => {
    const handleCommand = (e: Event) => {
      const custom = e as CustomEvent<{ command?: string }>;
      if (custom.detail?.command) {
        setValue(custom.detail.command);
        setIsSlashMenuOpen(false);
        setTimeout(() => {
          if (textareaRef.current) {
            textareaRef.current.focus();
            textareaRef.current.setSelectionRange(
              custom.detail?.command?.length ?? 0,
              custom.detail?.command?.length ?? 0
            );
          }
          onTyping?.();
        }, 15);
      }
    };
    window.addEventListener("insert-chat-command", handleCommand);
    return () => {
      window.removeEventListener("insert-chat-command", handleCommand);
    };
  }, [onTyping]);

  // Voice recording timer
  useEffect(() => {
    if (!isRecording) {
      if (timerRef.current) {
        clearInterval(timerRef.current);
        timerRef.current = null;
      }
      return;
    }

    timerRef.current = setInterval(() => {
      setRecordingSeconds((prev) => prev + 1);
    }, 1000);

    return () => {
      if (timerRef.current) {
        clearInterval(timerRef.current);
        timerRef.current = null;
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
        setRecordingSeconds(0);
        setIsRecording(true);
      };

      recognition.onresult = (event: SpeechRecognitionEventLike) => {
        let transcript = "";
        for (let i = 0; i < event.results.length; i++) {
          transcript += event.results[i][0].transcript;
        }
        if (transcript.trim()) {
          setValue(transcript);
          onTyping?.();
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

  function clearPendingImages() {
    setPendingImages([]);
    setImageError(null);
    if (fileInputRef.current) {
      fileInputRef.current.value = "";
    }
  }

  function removePendingImage(index: number) {
    setPendingImages((prev) => prev.filter((_, i) => i !== index));
    setImageError(null);
  }

  async function attachImageFiles(incoming: FileList | File[] | null | undefined) {
    if (!incoming || disabled || sending) {
      return;
    }
    const files = Array.from(incoming).filter((f) => f.type.startsWith("image/"));
    if (files.length === 0) {
      return;
    }

    const slotsLeft = MAX_CHAT_IMAGES - pendingImages.length;
    if (slotsLeft <= 0) {
      setImageError(`You can attach up to ${MAX_CHAT_IMAGES} photos at once.`);
      return;
    }

    setImageBusy(true);
    setImageError(null);
    try {
      const batch = files.slice(0, slotsLeft);
      const prepared: ChatImagePayload[] = [];
      for (const file of batch) {
        prepared.push(await prepareChatImage(file));
      }
      setPendingImages((prev) => [...prev, ...prepared]);
      if (files.length > slotsLeft) {
        setImageError(`Only ${MAX_CHAT_IMAGES} photos per message. Extra files were skipped.`);
      }
    } catch (err) {
      const code = err instanceof Error ? err.message : "failed";
      if (code === "unsupported_type") {
        setImageError("Use JPEG, PNG, WebP, or GIF photos.");
      } else if (code === "too_large") {
        setImageError("A photo is still too large. Try smaller images.");
      } else {
        setImageError("I couldn't read one of those photos. Try again.");
      }
    } finally {
      setImageBusy(false);
      if (fileInputRef.current) {
        fileInputRef.current.value = "";
      }
    }
  }

  function handleSendVoice() {
    stopVoiceRecording();
    const trimmed = value.trim();
    if (trimmed || pendingImages.length > 0) {
      onSend(trimmed, quotedMessage ?? undefined, pendingImages.length ? pendingImages : undefined);
      setValue("");
      setPendingImages([]);
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
    if (disabled || (!trimmed && pendingImages.length === 0)) {
      return;
    }
    onSend(trimmed, quotedMessage ?? undefined, pendingImages.length ? pendingImages : undefined);
    setValue("");
    setPendingImages([]);
    setIsSlashMenuOpen(false);
    setImageError(null);
    if (fileInputRef.current) {
      fileInputRef.current.value = "";
    }
    onClearQuote?.();
  }

  const handleTextChange = (e: React.ChangeEvent<HTMLTextAreaElement>) => {
    const text = e.target.value;
    setValue(text);
    onTyping?.();

    // If starts with / or currently typing slash command, show menu
    if (text.startsWith("/")) {
      setIsSlashMenuOpen(true);
      setSlashFilter(text);
    } else {
      setIsSlashMenuOpen(false);
    }
  };

  const handleSlashSelect = (commandText: string) => {
    setValue(commandText);
    setIsSlashMenuOpen(false);
    setTimeout(() => {
      textareaRef.current?.focus();
      onTyping?.();
    }, 10);
  };

  const formatRecordTime = (secs: number) => {
    const mins = Math.floor(secs / 60);
    const s = secs % 60;
    return `${mins}:${s < 10 ? "0" : ""}${s}`;
  };

  return (
    <div className="relative bg-gradient-to-t from-white via-white/95 to-transparent pt-2 pb-3 backdrop-blur-md dark:from-[#212121] dark:via-[#212121]/95">
      <form
        onSubmit={handleSubmit}
        onDragOver={(e) => {
          if (e.dataTransfer.types.includes("Files")) {
            e.preventDefault();
          }
        }}
        onDrop={(e) => {
          const file = e.dataTransfer.files?.[0];
          if (!file || !file.type.startsWith("image/")) {
            return;
          }
          e.preventDefault();
          void attachImageFiles(e.dataTransfer.files);
        }}
        className="mx-auto max-w-3xl px-3 sm:px-4"
      >
        {/* Reply preview banner */}
        {quotedMessage && (
          <div className="mb-2 flex items-center justify-between rounded-2xl border border-zinc-200/90 bg-zinc-50/95 px-3.5 py-2 shadow-2xs dark:border-zinc-700/80 dark:bg-zinc-800/90">
            <div className="flex items-center gap-2.5 min-w-0 border-l-[3px] border-emerald-500 pl-2.5">
              <div className="min-w-0">
                <div className="flex items-center gap-1.5 text-xs font-semibold text-emerald-700 dark:text-emerald-400">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                    <path d="M9 14L4 9l5-5" strokeLinecap="round" strokeLinejoin="round" />
                    <path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5v2" strokeLinecap="round" />
                  </svg>
                  <span>Replying to {quotedMessage.sender}</span>
                </div>
                <p className="text-xs text-zinc-600 dark:text-zinc-300 truncate max-w-lg mt-0.5">
                  {quotedMessage.text}
                </p>
              </div>
            </div>
            <button
              type="button"
              onClick={onClearQuote}
              className="text-zinc-400 hover:text-zinc-700 p-1 rounded-full hover:bg-zinc-200/70 transition cursor-pointer dark:hover:bg-zinc-700 dark:hover:text-zinc-200"
              title="Cancel reply"
              aria-label="Cancel reply"
            >
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M18 6L6 18M6 6l12 12" strokeLinecap="round" strokeLinejoin="round" />
              </svg>
            </button>
          </div>
        )}

        {pendingImages.length > 0 || imageError ? (
          <div className="mb-2 rounded-2xl border border-zinc-200/90 bg-zinc-50/95 px-2.5 py-2 shadow-2xs dark:border-zinc-700/80 dark:bg-zinc-800/90">
            {pendingImages.length > 0 ? (
              <div className="flex flex-wrap items-start gap-2">
                {pendingImages.map((img, index) => (
                  <div
                    key={`${img.filename}-${index}`}
                    className="flex max-w-[220px] min-w-0 items-center gap-2 rounded-xl bg-white/80 py-1 pl-1 pr-1.5 dark:bg-zinc-900/60"
                  >
                    <button
                      type="button"
                      onClick={() => setPreviewImage(img)}
                      className="shrink-0 overflow-hidden rounded-lg ring-1 ring-zinc-200/80 transition hover:ring-emerald-500/60 dark:ring-zinc-600"
                      title={`Preview ${img.filename}`}
                      aria-label={`Preview ${img.filename}`}
                    >
                      {/* eslint-disable-next-line @next/next/no-img-element */}
                      <img
                        src={img.previewUrl}
                        alt=""
                        className="h-11 w-11 object-cover"
                      />
                    </button>
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-[11px] font-medium text-zinc-800 dark:text-zinc-100">
                        {img.filename}
                      </p>
                      <p className="text-[10px] text-zinc-500 dark:text-zinc-400">Tap to preview</p>
                    </div>
                    <button
                      type="button"
                      onClick={() => removePendingImage(index)}
                      className="shrink-0 rounded-full p-0.5 text-zinc-400 transition hover:bg-zinc-200/70 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-200"
                      title="Remove"
                      aria-label={`Remove ${img.filename}`}
                    >
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <path d="M18 6L6 18M6 6l12 12" strokeLinecap="round" strokeLinejoin="round" />
                      </svg>
                    </button>
                  </div>
                ))}
                <button
                  type="button"
                  onClick={clearPendingImages}
                  className="ml-auto shrink-0 text-[10px] text-zinc-500 underline-offset-2 hover:text-zinc-800 hover:underline dark:hover:text-zinc-200"
                >
                  Clear all
                </button>
              </div>
            ) : null}
            <p
              className={`text-[11px] text-zinc-500 dark:text-zinc-400 ${
                pendingImages.length > 0 ? "mt-1.5 px-0.5" : "px-0.5 py-0.5"
              }`}
            >
              {imageError ??
                (pendingImages.length === 1
                  ? "Add a caption, or send the photo as-is."
                  : `${pendingImages.length} photos — add a caption or send as-is.`)}
            </p>
          </div>
        ) : null}

        {previewImage ? (
          <ImagePreviewLightbox
            previewUrl={previewImage.previewUrl}
            filename={previewImage.filename}
            onClose={() => setPreviewImage(null)}
          />
        ) : null}

        {/* Slash Command Autocomplete Menu */}
        <div className="relative">
          <SlashCommandMenu
            isOpen={isSlashMenuOpen}
            filterText={slashFilter}
            isAdmin={isAdmin}
            onSelect={handleSlashSelect}
            onAttachPhoto={() => {
              setIsSlashMenuOpen(false);
              setSlashFilter("");
              fileInputRef.current?.click();
            }}
            onClose={() => setIsSlashMenuOpen(false)}
          />
        </div>

        {isRecording ? (
          /* Live Voice Recording UI - Sleek Capsule matching ChatGPT / modern voice aesthetic (no harsh red) */
          <div className="flex items-center justify-between rounded-3xl border border-zinc-300/80 bg-white px-3.5 py-2 shadow-sm transition-all dark:border-zinc-700 dark:bg-[#2f2f2f]">
            <div className="flex items-center gap-3 min-w-0">
              {/* Animated audio wave indicator */}
              <div className="flex items-center gap-0.5 h-6 px-1" aria-hidden="true">
                <span className="w-1 bg-emerald-500 dark:bg-emerald-400 rounded-full animate-voice-wave-1" />
                <span className="w-1 bg-emerald-500 dark:bg-emerald-400 rounded-full animate-voice-wave-2" />
                <span className="w-1 bg-emerald-500 dark:bg-emerald-400 rounded-full animate-voice-wave-3" />
                <span className="w-1 bg-emerald-500 dark:bg-emerald-400 rounded-full animate-voice-wave-4" />
                <span className="w-1 bg-emerald-500 dark:bg-emerald-400 rounded-full animate-voice-wave-5" />
              </div>

              {/* Timer */}
              <span className="font-mono text-xs font-semibold text-zinc-900 dark:text-zinc-100">
                {formatRecordTime(recordingSeconds)}
              </span>

              {/* Live transcript or status */}
              <span className="text-xs text-zinc-500 dark:text-zinc-400 italic truncate max-w-[180px] sm:max-w-xs md:max-w-md">
                {value ? `"${value}"` : "Listening to your question..."}
              </span>
            </div>

            {/* Cancel & Send voice buttons */}
            <div className="flex items-center gap-1.5 shrink-0">
              <Tooltip content="Cancel recording" position="top">
                <button
                  type="button"
                  onClick={cancelVoiceRecording}
                  className="flex h-8 w-8 items-center justify-center rounded-full text-zinc-400 hover:text-zinc-700 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:text-zinc-200 dark:hover:bg-zinc-800 transition cursor-pointer"
                  aria-label="Cancel recording"
                >
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <line x1="18" y1="6" x2="6" y2="18" />
                    <line x1="6" y1="6" x2="18" y2="18" />
                  </svg>
                </button>
              </Tooltip>

              <Tooltip content="Send voice query" position="top">
                <button
                  type="button"
                  onClick={handleSendVoice}
                  className="flex h-8 w-8 items-center justify-center rounded-full bg-zinc-900 text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200 shadow-xs transition active:scale-95 cursor-pointer"
                  aria-label="Send voice"
                >
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                    <line x1="12" y1="19" x2="12" y2="5" />
                    <polyline points="5 12 12 5 19 12" />
                  </svg>
                </button>
              </Tooltip>
            </div>
          </div>
        ) : (
          /* ChatGPT Style Floating Pill Capsule Chatbox */
          <div
            className={`relative flex ${
              isMultiline ? "items-end pb-1.5" : "items-center"
            } rounded-3xl border border-zinc-300/80 bg-white px-2.5 py-1.5 shadow-sm transition-all focus-within:border-zinc-400 focus-within:shadow-md dark:border-zinc-700 dark:bg-[#2f2f2f] dark:focus-within:border-zinc-500`}
          >
            {/* Left: + (commands) and photo attach */}
            <div
              className={`flex shrink-0 items-center -space-x-0.5 ${isMultiline ? "self-end pb-0.5" : ""}`}
            >
              <input
                ref={fileInputRef}
                type="file"
                accept={ALLOWED_CHAT_IMAGE_MIMES.join(",")}
                multiple
                className="sr-only"
                tabIndex={-1}
                onChange={(e) => {
                  void attachImageFiles(e.target.files);
                }}
              />
              <Tooltip content="Tools & commands" position="top" shortcut="/">
                <button
                  type="button"
                  onClick={() => setIsSlashMenuOpen((prev) => !prev)}
                  disabled={disabled}
                  className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-900 active:scale-95 disabled:opacity-40 dark:text-zinc-400 dark:hover:bg-zinc-700 dark:hover:text-zinc-100 cursor-pointer"
                  aria-label="Toggle quick commands menu"
                >
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <line x1="12" y1="5" x2="12" y2="19" />
                    <line x1="5" y1="12" x2="19" y2="12" />
                  </svg>
                </button>
              </Tooltip>
              <Tooltip content="Attach a photo" position="top">
                <button
                  type="button"
                  onClick={() => fileInputRef.current?.click()}
                  disabled={disabled || imageBusy}
                  className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-900 active:scale-95 disabled:opacity-40 dark:text-zinc-400 dark:hover:bg-zinc-700 dark:hover:text-zinc-100 cursor-pointer"
                  aria-label="Attach a photo"
                >
                  {imageBusy ? (
                    <CircularLoader
                      size="xs"
                      className="border-zinc-400/40 border-t-zinc-800 dark:border-zinc-300/30 dark:border-t-zinc-100"
                    />
                  ) : (
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                      <rect x="3" y="3" width="18" height="18" rx="2" />
                      <circle cx="8.5" cy="8.5" r="1.5" />
                      <path d="M21 15l-5-5L5 21" strokeLinecap="round" strokeLinejoin="round" />
                    </svg>
                  )}
                </button>
              </Tooltip>
            </div>

            {/* Middle: Textarea with Animated Typewriter Placeholder - Vertically Centralized */}
            <div className="relative min-w-0 flex-1 px-2.5">
              <label className="sr-only" htmlFor="chat-input">
                Ask UniPod Assistant anything
              </label>

              <textarea
                ref={textareaRef}
                id="chat-input"
                rows={1}
                value={value}
                onChange={handleTextChange}
                onFocus={() => {
                  onFocus?.();
                  onTyping?.();
                }}
                onPaste={(e) => {
                  const item = Array.from(e.clipboardData?.items ?? []).find((entry) =>
                    entry.type.startsWith("image/"),
                  );
                  const file = item?.getAsFile();
                  if (file) {
                    e.preventDefault();
                    void attachImageFiles([file]);
                  }
                }}
                onKeyDown={(e) => {
                  if (e.key === "Enter" && !e.shiftKey) {
                    // If slash menu is open, let menu handle Enter
                    if (isSlashMenuOpen) {
                      return;
                    }
                    e.preventDefault();
                    handleSubmit(e);
                  }
                }}
                placeholder={animatedPlaceholder}
                disabled={disabled}
                className="w-full resize-none bg-transparent py-1 text-[14.5px] leading-5 text-zinc-900 placeholder:text-zinc-400 focus:outline-none disabled:opacity-60 dark:text-zinc-100 dark:placeholder:text-zinc-500 max-h-44 block"
              />
            </div>

            {/* Right: mic and send */}
            <div className={`flex items-center gap-1 shrink-0 ${isMultiline ? "self-end pb-0.5" : ""}`}>
              {/* Voice / Mic Button */}
              <Tooltip content="Voice dictation" position="top">
                <button
                  type="button"
                  onClick={startVoiceRecording}
                  disabled={disabled}
                  className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-900 active:scale-95 disabled:opacity-40 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100 cursor-pointer"
                  aria-label="Voice input"
                >
                  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z" />
                    <path d="M19 10v2a7 7 0 0 1-14 0v-2" strokeLinecap="round" />
                    <line x1="12" y1="19" x2="12" y2="22" strokeLinecap="round" />
                  </svg>
                </button>
              </Tooltip>

              {/* Send — stays visible; disabled while in flight (ChatGPT-style) */}
              <Tooltip
                content={sending ? sendingLabel : value.trim() || pendingImages.length ? "Send message" : "Send message"}
                position="top"
                shortcut={!sending && (value.trim() || pendingImages.length) ? "Enter" : undefined}
              >
                <button
                  type="submit"
                  disabled={disabled || sending || (!value.trim() && pendingImages.length === 0)}
                  className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full transition-all shadow-xs ${
                    disabled || sending || (!value.trim() && pendingImages.length === 0)
                      ? "bg-zinc-100 text-zinc-300 dark:bg-zinc-800 dark:text-zinc-600 cursor-not-allowed"
                      : "bg-zinc-900 text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200 active:scale-95 cursor-pointer"
                  }`}
                  aria-label={sending ? sendingLabel : "Send message"}
                  aria-busy={sending}
                >
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                    <line x1="12" y1="19" x2="12" y2="5" />
                    <polyline points="5 12 12 5 19 12" />
                  </svg>
                </button>
              </Tooltip>
            </div>
          </div>
        )}

        {/* ChatGPT Style Disclaimer */}
        <p className="mt-2 text-center text-[11px] text-zinc-400 dark:text-zinc-500 select-none">
          UniPod Assistant can make mistakes. Check important programme info &middot;{" "}
          <button
            type="button"
            onClick={() => {
              if (typeof window !== "undefined") {
                window.dispatchEvent(
                  new CustomEvent("open-notifications", { detail: { tab: "changelog" } })
                );
              }
            }}
            className="underline underline-offset-2 hover:text-zinc-700 dark:hover:text-zinc-300 cursor-pointer transition-colors"
          >
            What&apos;s new
          </button>
        </p>
      </form>
    </div>
  );
}
