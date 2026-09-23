export function createSessionId(): string {
  if (typeof crypto !== "undefined" && "randomUUID" in crypto) {
    return crypto.randomUUID().replace(/-/g, "");
  }
  return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 10)}`;
}

export function isValidSessionId(value: string): boolean {
  return /^[A-Za-z0-9_-]{8,64}$/.test(value);
}
