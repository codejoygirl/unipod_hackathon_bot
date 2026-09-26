/**
 * WhatsApp-style *bold* / **bold** → clean pairs for web rendering.
 * Drops orphan asterisks so they never show as litter. List markers ("* item") stay.
 */
export function normalizeWhatsAppEmphasis(text: string): string {
  let out = (text || "").replace(/\r\n/g, "\n");

  // Protect list markers: "* item" / "• item" on a line with no other * (not emphasis)
  out = out.replace(/(^|\n)\*(?=\s+\S)(?![^*\n]*\*)/gm, "$1{{LISTSTAR}}");
  out = out.replace(/(^|\n)•(?=\s)/gm, "$1{{LISTBULLET}}");

  // Markdown **bold** → WhatsApp *bold*
  out = out.replace(/\*\*([^*\n]+?)\*\*/g, (_, inner: string) => `*${inner.trim()}*`);

  // Unclosed title opener: "*Title: rest" → "*Title:* rest"
  out = out.replace(
    /(^|\n)\*([^*\n]{1,120}?):\s+/gm,
    (_, lead: string, title: string) => `${lead}*${title.trim()}:* `,
  );

  // Protect valid WhatsApp *bold* pairs (no spaces against the asterisks)
  const protectedPairs: string[] = [];
  out = out.replace(
    /\*([^*\n\s](?:[^*\n]*[^*\n\s])?)\*/g,
    (match, inner: string) => {
      const i = protectedPairs.length;
      protectedPairs.push(`*${inner.trim()}*`);
      return `{{WBOLD${i}}}`;
    },
  );

  // Drop leftover lone asterisks (formatting litter)
  out = out.replace(/\*/g, "");

  out = out.replace(/\{\{WBOLD(\d+)\}\}/g, (_, i: string) => protectedPairs[Number(i)] ?? "");
  out = out.replace(/\{\{LISTSTAR\}\}/g, "*");
  out = out.replace(/\{\{LISTBULLET\}\}/g, "•");
  return out;
}
