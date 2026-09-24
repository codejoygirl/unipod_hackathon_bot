/**
 * Human-friendly labels while an outbound request is in flight (UI chrome only).
 */

export function chatSendingLabel(
  isAdmin: boolean,
  queryText: string,
  hasImage = false,
): string {
  const trimmed = queryText.trim();
  const lower = trimmed.toLowerCase();

  if (hasImage && !trimmed) {
    return "Reading your photo…";
  }
  if (hasImage) {
    return "Reading your photo…";
  }

  if (isAdmin && trimmed.startsWith("/")) {
    if (lower.startsWith("/asset")) return "Publishing that resource…";
    if (lower.startsWith("/import")) return "Importing knowledge…";
    if (lower.startsWith("/export")) return "Preparing export…";
    return "Running your admin command…";
  }
  if (trimmed.startsWith("/")) {
    return "Working on that…";
  }
  return "Asking Zak…";
}

export function featureRequestSendingLabel(): string {
  return "Submitting your idea…";
}
