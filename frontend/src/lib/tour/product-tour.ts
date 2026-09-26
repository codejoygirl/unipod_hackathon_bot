"use client";

import { driver, type DriveStep } from "driver.js";
import "driver.js/dist/driver.css";
import "./product-tour.css";

const TOUR_SEEN_KEY = "unipod_product_tour_seen_v1";

type TourStepDef = {
  element?: string;
  title: string;
  description: string;
  side?: NonNullable<DriveStep["popover"]>["side"];
  align?: NonNullable<DriveStep["popover"]>["align"];
};

const STEP_DEFS: TourStepDef[] = [
  {
    title: "Welcome to UniPod AI Assistant",
    description:
      "A short walkthrough of chat, projects, Library, resources, meetings, and alerts. Replay this anytime from the ⋯ menu.",
  },
  {
    element: '[data-tour="community"]',
    title: "Your community",
    description: "Chat and resources stay scoped to this programme. The METI AI badge means you are in the UniPod assistant.",
    side: "right",
    align: "start",
  },
  {
    element: '[data-tour="new-chat"]',
    title: "Start a new chat",
    description: "Open a fresh conversation when you want a clean thread. Your previous chats stay in this workspace.",
    side: "right",
    align: "start",
  },
  {
    element: '[data-tour="nav-chat"]',
    title: "Chat",
    description: "Ask Zak about sessions, people, deadlines, and what the community has shared.",
    side: "right",
    align: "start",
  },
  {
    element: '[data-tour="new-project"]',
    title: "New project",
    description: "Start a project workspace: upload files, keep a dedicated thread, and ask Zak about that work only.",
    side: "right",
    align: "start",
  },
  {
    element: '[data-tour="projects"]',
    title: "Your projects",
    description: "Open an existing project here. Each one has its own chat and files, separate from community Chat.",
    side: "right",
    align: "start",
  },
  {
    element: '[data-tour="nav-library"]',
    title: "Library",
    description: "Your personal vault. Add docs from chat or this page, then ask Zak using those files.",
    side: "right",
    align: "start",
  },
  {
    element: '[data-tour="nav-resources"]',
    title: "Resources",
    description: "Programme packs, slides, and Drive links shared with the community.",
    side: "right",
    align: "start",
  },
  {
    element: '[data-tour="nav-meetings"]',
    title: "Meetings",
    description: "Upcoming sessions and join links. Coordinators can add meetings from Admin desk.",
    side: "right",
    align: "start",
  },
  {
    element: '[data-tour="nav-integrations"]',
    title: "Integrations",
    description: "See how UniPod connects to WhatsApp, Telegram, and other tools. Some items are coming soon.",
    side: "right",
    align: "start",
  },
  {
    element: '[data-tour="admin-desk"]',
    title: "Admin desk",
    description: "Coordinators publish updates, meetings, and resources from here. Same ingest flow as WhatsApp and Telegram.",
    side: "right",
    align: "start",
  },
  {
    element: '[data-tour="request-feature"]',
    title: "Request a feature",
    description: "Tell the team what would help. You can also open this from the ⋯ menu.",
    side: "right",
    align: "start",
  },
  {
    element: '[data-tour="composer"]',
    title: "Ask Zak",
    description: "Type a question about sessions, materials, or your project. Zak answers from community knowledge and cites sources.",
    side: "top",
    align: "center",
  },
  {
    element: '[data-tour="composer-tools"]',
    title: "Files, photos, and voice",
    description: "Use + for tools and commands, attach a photo, or speak. Add files from Library.",
    side: "top",
    align: "start",
  },
  {
    element: '[data-tour="notifications"]',
    title: "Notifications and updates",
    description: "Community announcements land here. Optional Ask Zak about this opens chat with that question already filled in.",
    side: "bottom",
    align: "end",
  },
  {
    element: '[data-tour="theme"]',
    title: "Light and dark",
    description: "Switch appearance to match the room you are working in.",
    side: "bottom",
    align: "end",
  },
  {
    element: '[data-tour="install-app"]',
    title: "Install the app",
    description: "Add UniPod to your home screen for full-screen access and closed-app alerts.",
    side: "bottom",
    align: "end",
  },
  {
    element: '[data-tour="more-menu"]',
    title: "Replay anytime",
    description: "Open this ⋯ menu whenever you want the product tour again, What’s new, or Request a feature.",
    side: "bottom",
    align: "end",
  },
];

let activeTour: ReturnType<typeof driver> | null = null;
let firstVisitLaunchScheduled = false;
let tourVeil: HTMLDivElement | null = null;
let tourVeilCleanup: (() => void) | null = null;

function removeTourVeil(): void {
  tourVeilCleanup?.();
  tourVeilCleanup = null;
  tourVeil?.remove();
  tourVeil = null;
}

function roundedHoleClip(x: number, y: number, w: number, h: number, radius: number): string {
  const vw = window.innerWidth;
  const vh = window.innerHeight;
  const rr = Math.max(0, Math.min(radius, w / 2, h / 2));
  const hole = [
    `M${x + rr} ${y}`,
    `H${x + w - rr}`,
    `A${rr} ${rr} 0 0 1 ${x + w} ${y + rr}`,
    `V${y + h - rr}`,
    `A${rr} ${rr} 0 0 1 ${x + w - rr} ${y + h}`,
    `H${x + rr}`,
    `A${rr} ${rr} 0 0 1 ${x} ${y + h - rr}`,
    `V${y + rr}`,
    `A${rr} ${rr} 0 0 1 ${x + rr} ${y}`,
    "Z",
  ].join(" ");
  return `path(evenodd, "M0 0H${vw}V${vh}H0Z ${hole}")`;
}

function updateTourVeil(element?: Element): void {
  if (!tourVeil) return;
  if (!(element instanceof HTMLElement)) {
    tourVeil.style.clipPath = "none";
    tourVeil.style.setProperty("-webkit-clip-path", "none");
    return;
  }
  const pad = isNarrowViewport() ? 6 : 8;
  const radius = isNarrowViewport() ? 10 : 12;
  const box = element.getBoundingClientRect();
  const x = Math.max(0, box.left - pad);
  const y = Math.max(0, box.top - pad);
  const w = Math.min(window.innerWidth, box.right + pad) - x;
  const h = Math.min(window.innerHeight, box.bottom + pad) - y;
  const clip = roundedHoleClip(x, y, w, h, radius);
  tourVeil.style.clipPath = clip;
  tourVeil.style.setProperty("-webkit-clip-path", clip);
}

function ensureTourVeil(): void {
  if (tourVeil) return;
  const veil = document.createElement("div");
  veil.className = "unipod-tour-veil";
  veil.setAttribute("aria-hidden", "true");
  veil.addEventListener("click", () => {
    stopProductTour();
  });
  document.body.appendChild(veil);
  tourVeil = veil;
  const sync = () => {
    const active = document.querySelector(".driver-active-element");
    updateTourVeil(active ?? undefined);
  };
  window.addEventListener("resize", sync);
  window.addEventListener("scroll", sync, true);
  tourVeilCleanup = () => {
    window.removeEventListener("resize", sync);
    window.removeEventListener("scroll", sync, true);
  };
}

function isNarrowViewport(): boolean {
  return typeof window !== "undefined" && window.innerWidth < 768;
}

function withMobileSides(def: TourStepDef): TourStepDef {
  if (!isNarrowViewport()) return def;
  if (def.side === "right" || def.side === "left") {
    return { ...def, side: "bottom", align: "center" };
  }
  return { ...def, align: "center" };
}

export function markProductTourSeen(): void {
  try {
    localStorage.setItem(TOUR_SEEN_KEY, "1");
  } catch {
    // ignore
  }
}

export function hasSeenProductTour(): boolean {
  try {
    return localStorage.getItem(TOUR_SEEN_KEY) === "1";
  } catch {
    return true;
  }
}

export function stopProductTour(): void {
  activeTour?.destroy();
  activeTour = null;
  removeTourVeil();
}

export function maybeStartFirstVisitTour(options: {
  openSidebar: () => void;
  ensureHome: () => boolean;
}): void {
  if (typeof window === "undefined") return;
  if (hasSeenProductTour() || firstVisitLaunchScheduled) return;
  firstVisitLaunchScheduled = true;
  options.openSidebar();
  const navigatedHome = options.ensureHome();
  window.setTimeout(
    () => {
      if (hasSeenProductTour()) return;
      startProductTour();
    },
    navigatedHome ? 1400 : 900,
  );
}

export function startProductTour(): void {
  stopProductTour();

  const steps: DriveStep[] = STEP_DEFS.map((raw) => {
    const def = withMobileSides(raw);
    return {
      element: def.element,
      popover: {
        title: def.title,
        description: def.description,
        side: def.side ?? "bottom",
        align: def.align ?? "start",
      },
    };
  });

  ensureTourVeil();

  const tour = driver({
    showProgress: true,
    animate: true,
    smoothScroll: true,
    allowClose: true,
    overlayClickBehavior: "close",
    skipMissingElement: true,
    waitForElement: 2200,
    overlayColor: "rgba(6, 6, 10, 0.46)",
    stagePadding: isNarrowViewport() ? 6 : 8,
    stageRadius: isNarrowViewport() ? 10 : 12,
    popoverOffset: isNarrowViewport() ? 10 : 14,
    popoverClass: "unipod-tour-popover",
    nextBtnText: "Next",
    prevBtnText: "Back",
    doneBtnText: "Got it",
    progressText: "{{current}} / {{total}}",
    steps,
    onHighlighted: (element) => {
      updateTourVeil(element instanceof Element ? element : undefined);
      if (element instanceof HTMLElement) {
        element.scrollIntoView({ block: "nearest", inline: "nearest", behavior: "smooth" });
      }
    },
    onDestroyed: () => {
      markProductTourSeen();
      removeTourVeil();
      if (activeTour === tour) {
        activeTour = null;
      }
    },
  });

  activeTour = tour;
  tour.drive();
}
