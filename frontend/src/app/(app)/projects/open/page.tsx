"use client";

import { ChatHeader } from "@/components/chat/chat-header";
import { FormattedMessage } from "@/components/chat/formatted-message";
import { RenameProjectModal } from "@/components/projects/rename-project-modal";
import { AssistantReplyToolbar } from "@/components/chat/assistant-reply-toolbar";
import { PROJECT_PROMPT_EXAMPLES, useTypewriterPlaceholder } from "@/components/chat/typewriter-placeholder";
import { restoreReadableSpacing } from "@/lib/web-chat/readable-text";
import { GrokThinkingLoader } from "@/components/chat/grok-thinking-loader";
import { CircularLoader } from "@/components/ui/circular-loader";
import { DownloadIconButton } from "@/components/ui/download-icon-button";
import { Tooltip } from "@/components/ui/tooltip";
import { ApiError } from "@/lib/api/client";
import type {
  MemberProjectMessage,
  MemberProjectSummary,
  MemberVaultDocument,
} from "@/lib/api/types";
import { APP_LOGO_SRC } from "@/lib/branding";
import {
  askProjectChat,
  attachProjectDocuments,
  attachProjectFile,
  createProjectChat,
  detachProjectFile,
  fetchProject,
  fetchProjectChat,
} from "@/lib/web-chat/projects";
import {
  downloadTextFile,
  downloadVaultArtefact,
  fetchVaultSnapshot,
  isVaultFile,
  VAULT_ACCEPT,
  VAULT_MAX_BATCH,
} from "@/lib/web-chat/vault";
import {
  speechRecognitionCtor,
  type SpeechRecognitionEventLike,
  type SpeechRecognitionInstance,
} from "@/lib/speech/recognition";
import { useWebChat } from "@/lib/web-chat/web-chat-context";
import { withPhoneQuery } from "@/lib/web-chat/url-params";
import Image from "next/image";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { Suspense, useCallback, useEffect, useRef, useState } from "react";

const STARTERS = [
  { title: "Summarize", prompt: "Summarize the key points in these files." },
  { title: "Gaps", prompt: "What is missing or unclear in these files?" },
  { title: "Next steps", prompt: "Give practical next steps based only on these files." },
];

const GENERATE_PROMPTS = [
  { title: "Application answers", kind: "application", prompt: "Write application / form answers grounded only in these files. Use short labelled answers. If a fact is missing, say it is not in the files." },
  { title: "Pitch plan", kind: "pitch_plan", prompt: "Write a pitch plan from these files only: problem, solution, who it is for, evidence, team, and the ask. Do not invent numbers." },
  { title: "Deck outline", kind: "deck_outline", prompt: "Write a 6–10 slide pitch-deck outline from these files only. Each slide: title + 3–5 bullets." },
  { title: "Recommendations", kind: "recommendations", prompt: "Give practical next-step recommendations based only on gaps and strengths visible in these files." },
  { title: "Practice Q&A", kind: "practice_qa", prompt: "List likely reviewer questions and suggested answers grounded only in these files." },
];

function formatRecordTime(seconds: number): string {
  const mins = Math.floor(seconds / 60);
  const secs = seconds % 60;
  return `${mins}:${secs.toString().padStart(2, "0")}`;
}

function ProjectWorkspace() {
  const searchParams = useSearchParams();
  const projectId = searchParams.get("id")?.trim() || "";
  const { memberPhone } = useWebChat();
  const fileInputRef = useRef<HTMLInputElement>(null);
  const chatInputRef = useRef<HTMLTextAreaElement>(null);
  const chatEndRef = useRef<HTMLDivElement>(null);
  const scrollRef = useRef<HTMLDivElement>(null);
  const composerRef = useRef<HTMLDivElement>(null);
  const pinToBottomRef = useRef(true);
  const programmaticScrollRef = useRef(false);
  const recognitionRef = useRef<SpeechRecognitionInstance | null>(null);
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const [project, setProject] = useState<MemberProjectSummary | null>(null);
  const [documents, setDocuments] = useState<MemberVaultDocument[]>([]);
  const [libraryDocs, setLibraryDocs] = useState<MemberVaultDocument[]>([]);
  const [threadId, setThreadId] = useState<string | null>(null);
  const [messages, setMessages] = useState<MemberProjectMessage[]>([]);
  const [selectedIds, setSelectedIds] = useState<string[]>([]);
  const [query, setQuery] = useState("");
  const [loading, setLoading] = useState(true);
  const [asking, setAsking] = useState(false);
  const [uploadingNames, setUploadingNames] = useState<string[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [isRecording, setIsRecording] = useState(false);
  const [recordingSeconds, setRecordingSeconds] = useState(0);
  const [renaming, setRenaming] = useState(false);
  const [reactions, setReactions] = useState<Record<string, "up" | "down" | null>>({});
  const [replyingTo, setReplyingTo] = useState<{ id: string; text: string } | null>(null);
  const [composerH, setComposerH] = useState(220);
  const [voiceSupported, setVoiceSupported] = useState(false);
  const [downloadingId, setDownloadingId] = useState<string | null>(null);
  const animatedPlaceholder = useTypewriterPlaceholder(PROJECT_PROMPT_EXAMPLES, {
    paused: query.length > 0 || isRecording,
  });

  const loadProject = useCallback(async () => {
    if (!memberPhone || !projectId) {
      setLoading(false);
      return;
    }
    setLoading(true);
    try {
      const [snap, library] = await Promise.all([
        fetchProject(memberPhone, projectId),
        fetchVaultSnapshot(memberPhone).catch(() => null),
      ]);
      setProject(snap.data.project);
      setDocuments(snap.data.documents ?? []);
      setLibraryDocs(library?.data.documents ?? []);
      setSelectedIds((snap.data.documents ?? []).map((doc) => doc.id));
      const thread = [...(snap.data.chats ?? [])].at(-1) ?? snap.data.chats?.[0] ?? null;
      setThreadId(thread?.id ?? null);
      setError(null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load this project.");
    } finally {
      setLoading(false);
    }
  }, [memberPhone, projectId]);

  useEffect(() => {
    void loadProject();
  }, [loadProject]);

  useEffect(() => {
    setVoiceSupported(Boolean(speechRecognitionCtor()));
  }, []);

  useEffect(() => {
    if (!memberPhone || !projectId || !threadId) {
      setMessages([]);
      return;
    }
    fetchProjectChat(memberPhone, projectId, threadId)
      .then((res) => setMessages(res.data.messages ?? []))
      .catch(() => setMessages([]));
  }, [memberPhone, projectId, threadId]);

  const scrollToLatest = useCallback((smooth = false) => {
    pinToBottomRef.current = true;
    programmaticScrollRef.current = true;
    const performScroll = () => {
      const el = scrollRef.current;
      if (!el) return;
      const top = Math.max(0, el.scrollHeight - el.clientHeight);
      el.scrollTo({ top, behavior: smooth ? "smooth" : "auto" });
    };
    requestAnimationFrame(performScroll);
    window.setTimeout(performScroll, 40);
    window.setTimeout(performScroll, 160);
    window.setTimeout(performScroll, 360);
    window.setTimeout(performScroll, 640);
    window.setTimeout(() => {
      programmaticScrollRef.current = false;
    }, 800);
  }, []);

  useEffect(() => {
    if (!pinToBottomRef.current && !asking) {
      return;
    }
    scrollToLatest(false);
  }, [messages, asking, composerH, scrollToLatest]);

  useEffect(() => {
    if (!asking) {
      return;
    }
    const node = scrollRef.current;
    if (!node) {
      return;
    }
    const pin = () => scrollToLatest(false);
    const observer = new ResizeObserver(pin);
    observer.observe(node);
    return () => observer.disconnect();
  }, [asking, scrollToLatest]);

  useEffect(() => {
    const viewport = window.visualViewport;
    if (!viewport) {
      return;
    }
    const pin = () => {
      if (pinToBottomRef.current || asking) {
        scrollToLatest(false);
      }
    };
    viewport.addEventListener("resize", pin);
    viewport.addEventListener("scroll", pin);
    return () => {
      viewport.removeEventListener("resize", pin);
      viewport.removeEventListener("scroll", pin);
    };
  }, [asking, scrollToLatest]);

  useEffect(() => {
    const el = chatInputRef.current;
    if (!el) return;
    el.style.height = "auto";
    el.style.height = `${Math.min(el.scrollHeight, 180)}px`;
  }, [query]);

  useEffect(() => {
    const node = composerRef.current;
    if (!node) return;
    const update = () => setComposerH(Math.ceil(node.getBoundingClientRect().height + 24));
    update();
    const observer = new ResizeObserver(update);
    observer.observe(node);
    return () => observer.disconnect();
  }, [isRecording, replyingTo, documents.length, libraryDocs.length, uploadingNames.length]);

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

  useEffect(() => {
    return () => {
      if (recognitionRef.current) {
        try {
          recognitionRef.current.stop();
        } catch {
          /* ignore */
        }
      }
    };
  }, []);

  const stopVoiceRecording = () => {
    if (recognitionRef.current) {
      try {
        recognitionRef.current.stop();
      } catch {
        /* ignore */
      }
      recognitionRef.current = null;
    }
    setIsRecording(false);
  };

  const startVoiceRecording = () => {
    if (asking || uploadingNames.length > 0 || !voiceSupported) return;
    const SpeechRecognition = speechRecognitionCtor();
    if (!SpeechRecognition) {
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
        setError(null);
      };
      recognition.onresult = (event: SpeechRecognitionEventLike) => {
        let transcript = "";
        for (let i = 0; i < event.results.length; i++) {
          transcript += event.results[i][0].transcript;
        }
        if (transcript.trim()) setQuery(transcript);
      };
      recognition.onerror = () => stopVoiceRecording();
      recognition.onend = () => setIsRecording(false);
      recognitionRef.current = recognition;
      recognition.start();
    } catch {
      setIsRecording(false);
    }
  };

  const cancelVoiceRecording = () => {
    stopVoiceRecording();
    setQuery("");
  };

  const sendVoice = () => {
    stopVoiceRecording();
    const text = query.trim();
    if (text) void handleAsk(text);
  };

  const unusedLibrary = libraryDocs.filter((doc) => !documents.some((item) => item.id === doc.id));
  const isMultiline = query.includes("\n") || query.length > 55;

  const ensureChat = async (): Promise<string | null> => {
    if (threadId) return threadId;
    if (!memberPhone || !projectId) return null;
    const res = await createProjectChat(memberPhone, projectId);
    setThreadId(res.data.id);
    return res.data.id;
  };

  const handleAsk = async (textOverride?: string, kind = "ask", replaceMessageId?: string) => {
    if (!memberPhone || !projectId) return;
    const text = (textOverride ?? query).trim();
    if (!text || asking || uploadingNames.length > 0) return;
    if (!replaceMessageId) {
      setQuery("");
      setReplyingTo(null);
    }
    setError(null);
    pinToBottomRef.current = true;
    scrollToLatest(false);
    if (replaceMessageId) {
      setMessages((prev) => {
        const idx = prev.findIndex((item) => item.id === replaceMessageId);
        let keepThrough = idx;
        for (let i = idx - 1; i >= 0; i--) {
          if (prev[i].role === "user") {
            keepThrough = i;
            break;
          }
        }
        return keepThrough >= 0 ? prev.slice(0, keepThrough + 1) : prev;
      });
    } else {
      setMessages((prev) => [
        ...prev,
        { id: crypto.randomUUID(), role: "user", body: text, used_filenames: [], created_at: null },
      ]);
    }
    setAsking(true);
    try {
      const chatId = await ensureChat();
      if (!chatId) return;
      const res = await askProjectChat(memberPhone, projectId, chatId, text, selectedIds, kind, replaceMessageId);
      const next: MemberProjectMessage = {
        id: res.data.message.id,
        role: "assistant",
        body: res.data.answer,
        used_filenames: res.data.used_filenames ?? [],
        artefact: res.data.artefact ?? res.data.message.artefact ?? null,
        created_at: null,
      };
      setMessages((prev) => [...prev, next]);
      setThreadId(chatId);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Sorry, I could not answer just now. Please try again in a moment.");
    } finally {
      setAsking(false);
    }
  };

  const handleDownload = async (message: MemberProjectMessage) => {
    if (downloadingId) return;
    const title = message.artefact?.title || project?.name || "Project answer";
    setDownloadingId(message.id);
    try {
      if (memberPhone && message.artefact?.id) {
        try {
          await downloadVaultArtefact(memberPhone, message.artefact);
          return;
        } catch {
          /* fall through to local file */
        }
      }
      if (!message.body.trim()) return;
      downloadTextFile(title, message.body);
    } finally {
      setDownloadingId(null);
    }
  };

  const handleUpload = async (incoming: FileList | File[] | null) => {
    if (!memberPhone || !projectId || !incoming) return;
    const files = Array.from(incoming).filter(isVaultFile).slice(0, VAULT_MAX_BATCH);
    if (files.length === 0) return;
    setError(null);
    setUploadingNames((prev) => [...prev, ...files.map((file) => file.name)]);
    try {
      let docs = documents;
      for (const file of files) {
        docs = await attachProjectFile(memberPhone, projectId, file);
        setDocuments(docs);
        setSelectedIds(docs.map((doc) => doc.id));
        setUploadingNames((prev) => {
          const index = prev.indexOf(file.name);
          return index === -1 ? prev : [...prev.slice(0, index), ...prev.slice(index + 1)];
        });
      }
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not add that file.");
      setUploadingNames([]);
    } finally {
      if (fileInputRef.current) fileInputRef.current.value = "";
    }
  };

  const handleAttachLibrary = async (documentId: string) => {
    if (!memberPhone || !projectId) return;
    const docs = await attachProjectDocuments(memberPhone, projectId, [documentId]);
    setDocuments(docs);
    setSelectedIds((prev) => (prev.includes(documentId) ? prev : [...prev, documentId]));
  };

  const handleDetach = async (documentId: string) => {
    if (!memberPhone || !projectId) return;
    await detachProjectFile(memberPhone, projectId, documentId);
    setDocuments((prev) => prev.filter((doc) => doc.id !== documentId));
    setSelectedIds((prev) => prev.filter((id) => id !== documentId));
  };

  if (!projectId) {
    return (
      <div className="relative flex h-full min-w-0 flex-1 flex-col overflow-hidden bg-white dark:bg-[#0d0d0d]">
        <ChatHeader />
        <div className="flex flex-1 flex-col items-center justify-center px-4 text-center">
          <p className="text-sm font-semibold">Choose a project</p>
          <Link href={withPhoneQuery("/projects", memberPhone)} className="mt-2 text-xs font-semibold text-blue-600">
            All projects
          </Link>
        </div>
      </div>
    );
  }

  return (
    <div className="relative flex h-full min-w-0 flex-1 flex-col overflow-hidden bg-white text-zinc-900 dark:bg-[#0d0d0d] dark:text-zinc-100">
      <ChatHeader />

      <input
        ref={fileInputRef}
        type="file"
        multiple
        className="sr-only"
        accept={VAULT_ACCEPT}
        onChange={(event) => void handleUpload(event.target.files)}
      />

      <div
        ref={scrollRef}
        onScroll={() => {
          if (programmaticScrollRef.current || asking) return;
          const el = scrollRef.current;
          if (!el) return;
          const distance = el.scrollHeight - el.scrollTop - el.clientHeight;
          if (distance > 160) {
            pinToBottomRef.current = false;
          }
        }}
        style={{ paddingBottom: composerH }}
        className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 overflow-y-auto no-scrollbar px-4 pt-4"
      >
        {project && !loading ? (
          <div className="flex items-center gap-1.5 px-0.5">
            <h1 className="min-w-0 truncate text-sm font-semibold text-zinc-800 dark:text-zinc-100">{project.name}</h1>
            <button
              type="button"
              onClick={() => setRenaming(true)}
              className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
              aria-label={`Rename ${project.name}`}
            >
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
                <path d="M12 20h9" />
                <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z" />
              </svg>
            </button>
          </div>
        ) : null}
        {loading ? (
          <div className="my-auto flex flex-col items-center py-10 animate-pulse" aria-busy="true" aria-label="Loading project">
            <div className="h-14 w-14 rounded-2xl bg-zinc-200 dark:bg-zinc-800" />
            <div className="mt-4 h-5 w-56 rounded bg-zinc-200 dark:bg-zinc-800" />
            <div className="mt-2 h-3 w-72 max-w-full rounded bg-zinc-100 dark:bg-zinc-800/60" />
            <div className="mt-8 grid w-full max-w-lg grid-cols-1 gap-2.5 sm:grid-cols-3">
              {[1, 2, 3].map((i) => (
                <div
                  key={i}
                  className="h-20 rounded-2xl border border-zinc-200/80 bg-zinc-100 dark:border-zinc-800 dark:bg-zinc-800/60"
                />
              ))}
            </div>
          </div>
        ) : error && messages.length === 0 ? (
          <p className="my-auto text-center text-xs text-red-600 dark:text-red-300">{error}</p>
        ) : messages.length === 0 ? (
          <div className="my-auto flex flex-col items-center justify-center py-10 text-center animate-fade-in select-none">
            <div className="mb-4 flex h-14 w-14 items-center justify-center overflow-hidden rounded-2xl shadow-sm">
              <Image src={APP_LOGO_SRC} alt="" width={56} height={56} className="h-full w-full object-contain rounded-2xl" />
            </div>
            <h2 className="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
              What would you like to work on?
            </h2>
            <div className="mt-2 inline-flex items-center gap-1.5 text-sm font-normal text-zinc-500 dark:text-zinc-400">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" className="shrink-0" aria-hidden>
                <path
                  d="M3 7h6l2 2h10v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"
                  stroke="currentColor"
                  strokeWidth="1.6"
                  strokeLinejoin="round"
                />
              </svg>
              <button
                type="button"
                onClick={() => setRenaming(true)}
                className="inline-flex items-center gap-1 rounded-md px-1 py-0.5 hover:bg-zinc-100 dark:hover:bg-zinc-800"
              >
                <span>{project?.name ?? "Project"}</span>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden>
                  <path d="M12 20h9" />
                  <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z" />
                </svg>
              </button>
            </div>
            <div className="mt-8 grid w-full max-w-lg grid-cols-1 gap-2.5 text-left sm:grid-cols-3">
              {STARTERS.map((item) => (
                <button
                  key={item.title}
                  type="button"
                  onClick={() => void handleAsk(item.prompt)}
                  className="rounded-2xl border border-zinc-200/80 bg-white p-3.5 text-xs text-zinc-700 shadow-2xs transition-all hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-[#171717] dark:text-zinc-300 dark:hover:border-zinc-700 dark:hover:bg-zinc-800/80"
                >
                  <span className="font-semibold text-zinc-900 dark:text-zinc-100">{item.title}</span>
                  <span className="mt-1.5 block text-[11px] leading-relaxed text-zinc-500 dark:text-zinc-400">
                    {item.prompt}
                  </span>
                </button>
              ))}
            </div>
          </div>
        ) : (
          messages.map((message) =>
            message.role === "user" ? (
              <div key={message.id} className="group relative my-1.5 flex w-full max-w-[min(85%,36rem)] min-w-0 flex-col items-end self-end sm:max-w-[75%]">
                <div className="min-w-0 max-w-full rounded-3xl rounded-br-md bg-zinc-900 px-4 py-3 text-zinc-100 shadow-xs dark:bg-[#183660] dark:text-white">
                  <p className="whitespace-pre-wrap break-words [overflow-wrap:anywhere] text-[14.5px] leading-relaxed">{message.body}</p>
                </div>
              </div>
            ) : message.role === "assistant" ? (
              <div key={message.id} className="my-1.5 w-full max-w-[min(92%,42rem)] min-w-0 space-y-1.5 self-start">
                {message.used_filenames?.filter((name) => message.body.includes(name)).length ? (
                  <div className="flex min-w-0 flex-wrap items-center gap-1.5">
                    <span className="text-[10px] font-medium text-zinc-400">From your files</span>
                    {message.used_filenames
                      .filter((name) => message.body.includes(name))
                      .map((name) => (
                      <span
                        key={name}
                        className="max-w-[min(220px,70vw)] truncate rounded-md border border-zinc-200/80 bg-zinc-50 px-1.5 py-0.5 text-[10px] font-medium text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/80 dark:text-zinc-400"
                      >
                        {name}
                      </span>
                    ))}
                  </div>
                ) : null}
                <div className="min-w-0 overflow-hidden rounded-2xl rounded-tl-sm border border-zinc-200/90 bg-white text-zinc-950 shadow-xs dark:border-zinc-800 dark:bg-[#141414] dark:text-zinc-50">
                  {message.artefact?.title ? (
                    <div className="flex items-center justify-between gap-3 border-b border-zinc-200/80 px-4 py-2.5 dark:border-zinc-800">
                      <p className="min-w-0 truncate text-[13px] font-medium text-zinc-600 dark:text-zinc-300">
                        {restoreReadableSpacing(message.artefact.title)}
                      </p>
                    </div>
                  ) : null}
                  <div className="px-4 py-3.5 text-[15.5px] leading-8 sm:px-5 sm:py-4">
                    <FormattedMessage content={message.body} />
                  </div>
                </div>
                <AssistantReplyToolbar
                  id={message.id}
                  text={message.body}
                  reaction={reactions[message.id] ?? null}
                  onReact={(type) =>
                    setReactions((prev) => ({
                      ...prev,
                      [message.id]: prev[message.id] === type ? null : type,
                    }))
                  }
                  onReply={() => {
                    setReplyingTo({ id: message.id, text: message.body });
                    window.setTimeout(() => chatInputRef.current?.focus(), 0);
                  }}
                  onRegenerate={() => {
                    const idx = messages.findIndex((item) => item.id === message.id);
                    let prior: string | null = null;
                    for (let i = idx - 1; i >= 0; i--) {
                      if (messages[i].role === "user") {
                        prior = messages[i].body;
                        break;
                      }
                    }
                    if (prior) void handleAsk(prior, "ask", message.id);
                  }}
                  extra={
                    message.artefact?.id ? (
                      <DownloadIconButton
                        busy={downloadingId === message.id}
                        onClick={() => void handleDownload(message)}
                      />
                    ) : null
                  }
                />
              </div>
            ) : null,
          )
        )}
        {asking ? <GrokThinkingLoader statusText="Reading your files…" /> : null}
        {error && messages.length > 0 ? (
          <p className="self-center text-xs text-red-600 dark:text-red-300">{error}</p>
        ) : null}
        <div ref={chatEndRef} className="h-2 w-full shrink-0" aria-hidden />
      </div>

      <div ref={composerRef} className="absolute inset-x-0 bottom-0 z-30 pb-[env(safe-area-inset-bottom)]">
        <div className="relative bg-gradient-to-t from-white from-55% via-white/80 to-transparent pt-6 pb-3 dark:from-[#212121] dark:via-[#212121]/80">
          <div
            className="mx-auto max-w-3xl px-3 sm:px-4"
            onDragOver={(event) => {
              if (event.dataTransfer.types.includes("Files")) event.preventDefault();
            }}
            onDrop={(event) => {
              const list = event.dataTransfer.files;
              if (!list?.length) return;
              event.preventDefault();
              void handleUpload(list);
            }}
          >
            <div className="mb-2 flex items-center gap-1.5 overflow-x-auto no-scrollbar px-0.5 sm:flex-wrap">
              {GENERATE_PROMPTS.map((item) => (
                <button
                  key={item.title}
                  type="button"
                  disabled={asking || uploadingNames.length > 0 || documents.length === 0}
                  onClick={() => void handleAsk(item.prompt, item.kind)}
                  className="shrink-0 rounded-full border border-zinc-200/80 bg-white px-2.5 py-1 text-[11px] font-medium text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 disabled:opacity-40 dark:border-zinc-700 dark:bg-[#2f2f2f] dark:text-zinc-400 dark:hover:bg-zinc-700 dark:hover:text-zinc-200 cursor-pointer"
                >
                  {item.title}
                </button>
              ))}
            </div>
            {documents.length > 0 || unusedLibrary.length > 0 || uploadingNames.length > 0 ? (
              <div className="mb-2 flex flex-wrap items-center gap-1.5 px-0.5">
                {uploadingNames.map((name, index) => (
                  <span
                    key={`${name}-${index}`}
                    title={name}
                    className="inline-flex max-w-[220px] min-w-0 items-center gap-1.5 rounded-full border border-zinc-200 bg-white px-2 py-1 text-[11px] font-medium text-zinc-600 dark:border-zinc-700 dark:bg-[#2f2f2f] dark:text-zinc-300"
                  >
                    <CircularLoader
                      size="xs"
                      className="border-zinc-300/80 border-t-zinc-700 dark:border-zinc-600 dark:border-t-zinc-200"
                    />
                    <span className="min-w-0 truncate">{name}</span>
                  </span>
                ))}
                {documents.map((doc) => (
                  <span
                    key={doc.id}
                    title={doc.filename}
                    className={`inline-flex max-w-[220px] min-w-0 items-center gap-1 rounded-full border px-2 py-1 text-[11px] font-medium ${
                      selectedIds.includes(doc.id)
                        ? "border-blue-500 bg-blue-50 text-blue-800 dark:border-blue-400 dark:bg-blue-950/50 dark:text-blue-200"
                        : "border-zinc-200 text-zinc-500 dark:border-zinc-700"
                    }`}
                  >
                    <button
                      type="button"
                      className="min-w-0 truncate"
                      onClick={() =>
                        setSelectedIds((prev) =>
                          prev.includes(doc.id) ? prev.filter((id) => id !== doc.id) : [...prev, doc.id],
                        )
                      }
                    >
                      {doc.filename}
                    </button>
                    <button
                      type="button"
                      className="shrink-0 text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200"
                      onClick={() => void handleDetach(doc.id)}
                      aria-label={`Remove ${doc.filename}`}
                    >
                      ×
                    </button>
                  </span>
                ))}
                {unusedLibrary.slice(0, 6).map((doc) => (
                  <button
                    key={doc.id}
                    type="button"
                    onClick={() => void handleAttachLibrary(doc.id)}
                    className="max-w-[140px] truncate rounded-full border border-dashed border-zinc-300 px-2 py-1 text-[10px] font-medium text-zinc-500 dark:border-zinc-700"
                  >
                    + {doc.filename}
                  </button>
                ))}
              </div>
            ) : null}
            {isRecording ? (
              <div className="flex items-center justify-between rounded-3xl border border-zinc-300/80 bg-white px-3.5 py-2 shadow-sm dark:border-zinc-700 dark:bg-[#2f2f2f]">
                <div className="flex min-w-0 items-center gap-3">
                  <div className="flex h-6 items-center gap-0.5 px-1" aria-hidden>
                    <span className="w-1 rounded-full bg-blue-500 animate-voice-wave-1 dark:bg-blue-400" />
                    <span className="w-1 rounded-full bg-blue-500 animate-voice-wave-2 dark:bg-blue-400" />
                    <span className="w-1 rounded-full bg-blue-500 animate-voice-wave-3 dark:bg-blue-400" />
                    <span className="w-1 rounded-full bg-blue-500 animate-voice-wave-4 dark:bg-blue-400" />
                    <span className="w-1 rounded-full bg-blue-500 animate-voice-wave-5 dark:bg-blue-400" />
                  </div>
                  <span className="font-mono text-xs font-semibold">{formatRecordTime(recordingSeconds)}</span>
                  <span className="max-w-[180px] truncate text-xs italic text-zinc-500 sm:max-w-xs">
                    {query ? `“${query}”` : "Listening…"}
                  </span>
                </div>
                <div className="flex shrink-0 items-center gap-1.5">
                  <Tooltip content="Cancel recording" position="top">
                    <button
                      type="button"
                      onClick={cancelVoiceRecording}
                      className="flex h-8 w-8 items-center justify-center rounded-full text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                      aria-label="Cancel recording"
                    >
                      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                      </svg>
                    </button>
                  </Tooltip>
                  <Tooltip content="Send voice question" position="top">
                    <button
                      type="button"
                      onClick={sendVoice}
                      className="flex h-8 w-8 items-center justify-center rounded-full bg-zinc-900 text-white dark:bg-white dark:text-zinc-900"
                      aria-label="Send voice question"
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
            <>
            {replyingTo ? (
              <div className="mb-2 flex items-center justify-between rounded-2xl border border-zinc-200/90 bg-zinc-50/95 px-3.5 py-2 shadow-2xs dark:border-zinc-700/80 dark:bg-zinc-800/90">
                <div className="flex min-w-0 items-center gap-2.5 border-l-[3px] border-blue-500 pl-2.5">
                  <div className="min-w-0">
                    <div className="flex items-center gap-1.5 text-xs font-semibold text-blue-700 dark:text-blue-400">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                        <path d="M9 14L4 9l5-5" strokeLinecap="round" strokeLinejoin="round" />
                        <path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5v2" strokeLinecap="round" />
                      </svg>
                      <span>Replying to UniPod Assistant</span>
                    </div>
                    <p className="mt-0.5 max-w-lg truncate text-xs text-zinc-600 dark:text-zinc-300">
                      {replyingTo.text}
                    </p>
                  </div>
                </div>
                <button
                  type="button"
                  onClick={() => setReplyingTo(null)}
                  className="rounded-full p-1 text-zinc-400 transition hover:bg-zinc-200/70 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-200"
                  title="Cancel reply"
                  aria-label="Cancel reply"
                >
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <path d="M18 6L6 18M6 6l12 12" strokeLinecap="round" strokeLinejoin="round" />
                  </svg>
                </button>
              </div>
            ) : null}
            <div
              className={`relative flex min-h-[72px] ${
                isMultiline ? "items-end pb-2.5" : "items-center"
              } rounded-3xl border border-zinc-300/80 bg-white px-3.5 py-3 shadow-sm transition-all focus-within:border-zinc-400 focus-within:shadow-md dark:border-zinc-700 dark:bg-[#2f2f2f] dark:focus-within:border-zinc-500`}
            >
              <Tooltip content="Add files" position="top">
                <button
                  type="button"
                  onClick={() => fileInputRef.current?.click()}
                  disabled={asking || uploadingNames.length > 0}
                  className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-900 disabled:cursor-not-allowed disabled:opacity-35 dark:text-zinc-400 dark:hover:bg-zinc-700 dark:hover:text-zinc-100"
                  aria-label="Add files"
                >
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" strokeLinecap="round" strokeLinejoin="round" />
                  </svg>
                </button>
              </Tooltip>
              <div className="relative min-w-0 flex-1 px-2.5">
                <textarea
                  ref={chatInputRef}
                  rows={1}
                  value={query}
                  onChange={(event) => setQuery(event.target.value)}
                  onFocus={() => {
                    pinToBottomRef.current = true;
                    scrollToLatest(true);
                  }}
                  onClick={() => {
                    pinToBottomRef.current = true;
                    scrollToLatest(true);
                  }}
                  onKeyDown={(event) => {
                    if (event.key === "Enter" && !event.shiftKey) {
                      event.preventDefault();
                      void handleAsk();
                    }
                  }}
                  placeholder={animatedPlaceholder || "Ask anything..."}
                  disabled={asking || uploadingNames.length > 0}
                  className="block max-h-52 min-h-[40px] w-full resize-none bg-transparent py-2 text-[15px] leading-6 text-zinc-900 placeholder:text-zinc-400 focus:outline-none disabled:cursor-not-allowed disabled:opacity-60 dark:text-zinc-100 dark:placeholder:text-zinc-500"
                />
              </div>
              <div className={`flex shrink-0 items-center gap-1 ${isMultiline ? "self-end pb-0.5" : ""}`}>
                <Tooltip content={voiceSupported ? "Voice dictation" : "Voice input is not available here"} position="top">
                  <button
                    type="button"
                    onClick={startVoiceRecording}
                    disabled={asking || uploadingNames.length > 0 || !voiceSupported}
                    className="flex h-9 w-9 items-center justify-center rounded-full text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-900 disabled:cursor-not-allowed disabled:opacity-35 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100 cursor-pointer"
                    aria-label="Voice input"
                  >
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                      <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z" />
                      <path d="M19 10v2a7 7 0 0 1-14 0v-2" strokeLinecap="round" />
                      <line x1="12" y1="19" x2="12" y2="22" strokeLinecap="round" />
                    </svg>
                  </button>
                </Tooltip>
                <button
                  type="button"
                  disabled={asking || uploadingNames.length > 0 || !query.trim()}
                  onClick={() => void handleAsk()}
                  className={`flex h-9 w-9 items-center justify-center rounded-full ${
                    asking || uploadingNames.length > 0 || !query.trim()
                      ? "cursor-not-allowed bg-zinc-100 text-zinc-300 dark:bg-zinc-800 dark:text-zinc-600"
                      : "bg-zinc-900 text-white dark:bg-white dark:text-zinc-900"
                  }`}
                  aria-label="Send message"
                >
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                    <line x1="12" y1="19" x2="12" y2="5" />
                    <polyline points="5 12 12 5 19 12" />
                  </svg>
                </button>
              </div>
            </div>
            </>
            )}
          </div>
        </div>
      </div>
      <RenameProjectModal
        project={renaming && project ? project : null}
        phone={memberPhone}
        onClose={() => setRenaming(false)}
        onRenamed={(name) => setProject((prev) => (prev ? { ...prev, name } : prev))}
      />
    </div>
  );
}

export default function ProjectWorkspacePage() {
  return (
    <Suspense
      fallback={
        <div className="flex h-full min-h-0 flex-1 items-center justify-center bg-white dark:bg-[#0d0d0d]">
          <div className="flex w-full max-w-lg flex-col items-center px-4 py-10 animate-pulse" aria-busy="true" aria-label="Loading project">
            <div className="h-14 w-14 rounded-2xl bg-zinc-200 dark:bg-zinc-800" />
            <div className="mt-4 h-5 w-56 rounded bg-zinc-200 dark:bg-zinc-800" />
            <div className="mt-2 h-3 w-72 max-w-full rounded bg-zinc-100 dark:bg-zinc-800/60" />
          </div>
        </div>
      }
    >
      <ProjectWorkspace />
    </Suspense>
  );
}
