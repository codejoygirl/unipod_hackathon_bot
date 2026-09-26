/**
 * Keep assistant text readable when a model drops spaces.
 * Structure only: punctuation, camelCase, and a thin English spacing net.
 * URLs, emails, and domains stay intact.
 */

const SPACING_WORDS = new Set(
  `
    a i an as at be by do for from in is it of on or to the and but not
    are
    with this that these those they them their there here where when what
    which who how you your we our us my me he she his her its if so no yes
    all any more most some such than then too very just only also into over
    after before about above below between through during without within
    because while until against among under again still even own both each
    few other another same every much many well back now new old first last
    next long short little big small large high low good great full real
    can could will would should may might must shall need
    make take get give go come see know think look want use find tell ask
    work seem feel try leave call keep let begin show hear play run move
    live believe bring happen write provide sit stand lose pay meet include
    continue set learn change lead understand watch follow stop create speak
    read spend grow open walk win offer remember love consider appear buy
    wait serve send expect build stay fall cut reach raise pass sell decide
    return explain develop carry break receive agree support produce eat
    cover catch draw choose add help start gather solve affect suggest
    convert download upload generate draft review update replace share save
    delete search plan research answer question reply list write turn put
    hold pick fill check rest ready clear close join
    time person year way day thing things man world life hand part child
    eye woman place week case point company number group problem fact name
    title slide deck pitch tagline position statement information project
    business outline heading skill letter cover file document vault chat
    member community source link meeting note team market product customer
    revenue traction competition vision mission value model opportunity
    advantage timeline milestone appendix solution people idea goal aim
    role step help tip example detail summary intro conclusion overview
    section field item bullet page line text word space format style design
    brand story user client founder investor partner cost price growth
    suggested affected technical personal public private current
    hello thanks please sorry ok okay sure
    one two three four five six seven eight nine ten
    using based named been were have has had did does
    specifically asking addressing address previous version like
    highlights highlight
    focused still show component brief venture description addresses
    fragmentation healthcare operations patient across hospitals clinics
    laboratories pharmacies other facilities built providers
    administrators professionals patients connected manage access
    services brings these workflow workflows into one platform covering
    registration appointments electronic health records consultations
    laboratory radiology pharmacy billing referrals inventory through
    integrated layer helps users understand automate routine generate
    clinical summary assess triage risk support prescription safety
    provide patients clearer explanations designed delivery more
    connected efficient accessible particularly environments where
    systems connectivity can fragmented
    yes no
  `.trim().split(/\s+/),
);

const MAX_SPACING_WORD = Math.max(...[...SPACING_WORDS].map((word) => word.length));

const KEEP_PATTERNS = [
  /\[[^\]]+\]\(https?:\/\/[^)\s]+\)/gi,
  /https?:\/\/[^\s<>"')\]]+/gi,
  /\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/gi,
  /\b(?:[a-z0-9-]+\.)+[a-z]{2,}\b/gi,
];

function segmentRun(run: string): string {
  const lower = run.toLowerCase();
  const length = lower.length;
  const parts: string[] = [];
  let index = 0;
  let dictHits = 0;

  while (index < length) {
    let match = 0;
    const maxTry = Math.min(MAX_SPACING_WORD, length - index);
    for (let wordLen = maxTry; wordLen >= 1; wordLen -= 1) {
      if (SPACING_WORDS.has(lower.slice(index, index + wordLen))) {
        match = wordLen;
        break;
      }
    }
    if (match) {
      parts.push(run.slice(index, index + match));
      index += match;
      dictHits += 1;
      continue;
    }

    let next = length;
    for (let cursor = index + 1; cursor < length; cursor += 1) {
      const tryLen = Math.min(MAX_SPACING_WORD, length - cursor);
      let hit = false;
      for (let wordLen = tryLen; wordLen >= 3; wordLen -= 1) {
        if (SPACING_WORDS.has(lower.slice(cursor, cursor + wordLen))) {
          hit = true;
          break;
        }
      }
      if (hit) {
        next = cursor;
        break;
      }
    }
    if (next === index) return run;
    parts.push(run.slice(index, next));
    index = next;
  }

  if (dictHits < 2) {
    const leftoverBrand =
      dictHits === 1
      && parts.length === 2
      && /^[A-Z][a-z]{3,}/.test(parts[0])
      && !SPACING_WORDS.has(parts[0].toLowerCase());
    if (!leftoverBrand) return run;
  }
  const short = parts.filter((part) => part.length <= 2).length;
  if (short > Math.max(2, Math.floor(parts.length / 2))) return run;
  return parts.join(" ");
}

function unstickJammedWords(text: string): string {
  const withClitics = text.replace(/(['’](?:ll|re|ve|d|s|t|m))([A-Za-z])/gi, "$1 $2");
  return withClitics.replace(/[A-Za-z]{6,}/g, (run) => segmentRun(run));
}

function shouldLeaveAlone(line: string): boolean {
  const trimmed = line.trim();
  if (!trimmed) return true;
  if (/^https?:\/\//i.test(trimmed)) return true;
  if (/^```/.test(trimmed)) return true;
  if (/^\|/.test(trimmed)) return true;
  if (/^\[[^\]]+\]\(https?:\/\//i.test(trimmed)) return true;
  if (/\.[a-z0-9]{2,4}\b/i.test(trimmed) && trimmed.length < 120 && !/\s/.test(trimmed)) {
    return true;
  }
  return false;
}

function protectKeepables(text: string): { text: string; held: string[] } {
  const held: string[] = [];
  let out = text;
  for (const pattern of KEEP_PATTERNS) {
    out = out.replace(pattern, (match) => {
      held.push(match);
      return `@@KEEP${held.length - 1}@@`;
    });
  }
  return { text: out, held };
}

function restoreKeepables(text: string, held: string[]): string {
  return held.reduce((acc, value, index) => acc.replace(`@@KEEP${index}@@`, value), text);
}

function joinSplitDomains(text: string): string {
  return text.replace(/\b((?:[A-Za-z0-9-]+\s*\.\s*)+)([a-z]{2,10})\b/g, (match) =>
    match.replace(/\s+/g, ""),
  );
}

function repairLine(line: string): string {
  if (shouldLeaveAlone(line)) return line;
  let out = joinSplitDomains(line);
  const protectedText = protectKeepables(out);
  out = protectedText.text;
  out = out.replace(/([.!?])(["“]?)([A-Za-z])/g, "$1$2 $3");
  out = out.replace(/([,;:])([A-Za-z])/g, "$1 $2");
  out = out.replace(/([a-zA-Z]["”])([A-Za-z])/g, "$1 $2");
  out = out.replace(/([a-z])([A-Z][a-z])/g, "$1 $2");
  out = out.replace(/([.!?])[ \t]{2,}/g, "$1 ");
  out = unstickJammedWords(out);
  return restoreKeepables(out, protectedText.held);
}

export function restoreReadableSpacing(text: string): string {
  if (!text) return text;
  return text
    .replace(/\r\n/g, "\n")
    .split("\n")
    .map((line) => repairLine(line))
    .join("\n");
}

/** Shape only: put markdown headings and list markers on their own lines. */
export function normalizeChatMarkdown(text: string): string {
  if (!text) return text;
  let out = text.replace(/\r\n/g, "\n");
  out = out.replace(/(?<!\n)[ \t]+(?=#{1,3}\s+\S)/g, "\n\n");
  out = out.replace(/^(#{1,3})([^\s#])/gm, "$1 $2");
  out = out.replace(/(?<!\n)\s+(?=[-•]\s+\S)/g, "\n");
  out = out.replace(/(?<![#\n])[ \t]+(?=\d{1,2}\.\s+\S)/g, "\n");
  out = out.replace(/^#{1,3}[ \t]*\n+/gm, "");
  out = out.replace(/^(#{1,3})\s+(\d{1,2})\.\s*\n+(?=\S)/gm, "$1 $2. ");
  out = out.replace(/^(\d{1,2})\.[ \t]*\n+(?=[A-Za-z])/gm, "$1. ");
  out = out.replace(/\n{3,}/g, "\n\n");
  return out;
}
