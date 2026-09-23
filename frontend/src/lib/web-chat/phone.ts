export function normalizePhone(raw: string | null | undefined): string | null {
  if (!raw) {
    return null;
  }
  const digits = raw.replace(/\D+/g, "");
  if (digits.length < 8 || digits.length > 15) {
    return null;
  }
  return digits;
}

export function sessionIdFromPhone(phoneDigits: string): string {
  return `m${phoneDigits}`;
}

export function displayPhoneLabel(phoneDigits: string): string {
  if (phoneDigits.length <= 4) {
    return `+${phoneDigits}`;
  }
  return `+${phoneDigits.slice(0, -4)}····${phoneDigits.slice(-4)}`;
}
