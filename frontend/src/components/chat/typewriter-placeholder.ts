"use client";

import { useEffect, useState, useRef } from "react";

export const PROGRAMME_PROMPT_EXAMPLES = [
  "Ask anything...",
  "When is the next live session?",
  "Where can I find the UniPods handbook?",
  "What is the hackathon submission deadline?",
  "How will training in Ethiopia work?",
  "How do I submit my project draft?",
  "Where are the Wadhwani resource links?",
  "How do I request a mentor meeting?",
  "Send a photo of the flyer or schedule…",
  "Type / for quick commands...",
];

interface TypewriterOptions {
  typingSpeed?: number;
  deletingSpeed?: number;
  pauseAfterType?: number;
  pauseAfterDelete?: number;
  paused?: boolean;
}

export function useTypewriterPlaceholder(
  examples: string[] = PROGRAMME_PROMPT_EXAMPLES,
  options: TypewriterOptions = {}
): string {
  const {
    typingSpeed = 50,
    deletingSpeed = 24,
    pauseAfterType = 2400,
    pauseAfterDelete = 350,
    paused = false,
  } = options;

  const [displayedText, setDisplayedText] = useState(examples[0] ?? "Ask anything...");
  const stateRef = useRef({
    exampleIndex: 0,
    charIndex: examples[0]?.length ?? 0,
    isDeleting: false,
  });

  useEffect(() => {
    if (paused || examples.length === 0) {
      return;
    }

    let timeoutId: NodeJS.Timeout;

    const tick = () => {
      const { exampleIndex, charIndex, isDeleting } = stateRef.current;
      const currentTarget = examples[exampleIndex % examples.length];

      if (!isDeleting) {
        // Typing forward
        if (charIndex < currentTarget.length) {
          stateRef.current.charIndex = charIndex + 1;
          setDisplayedText(currentTarget.slice(0, charIndex + 1));
          timeoutId = setTimeout(tick, typingSpeed);
        } else {
          // Finished typing, pause before deleting
          stateRef.current.isDeleting = true;
          timeoutId = setTimeout(tick, pauseAfterType);
        }
      } else {
        // Deleting backward
        if (charIndex > 0) {
          stateRef.current.charIndex = charIndex - 1;
          setDisplayedText(currentTarget.slice(0, charIndex - 1));
          timeoutId = setTimeout(tick, deletingSpeed);
        } else {
          // Finished deleting, move to next example
          stateRef.current.isDeleting = false;
          stateRef.current.exampleIndex = (exampleIndex + 1) % examples.length;
          timeoutId = setTimeout(tick, pauseAfterDelete);
        }
      }
    };

    timeoutId = setTimeout(tick, typingSpeed);

    return () => {
      clearTimeout(timeoutId);
    };
  }, [examples, typingSpeed, deletingSpeed, pauseAfterType, pauseAfterDelete, paused]);

  return displayedText;
}
