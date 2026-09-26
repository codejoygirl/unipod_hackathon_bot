import { apiFetch } from "@/lib/api/client";

type VapidResponse = {
  data: {
    configured: boolean;
    public_key: string | null;
  };
};

function urlBase64ToUint8Array(base64String: string): Uint8Array {
  const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");
  const raw = atob(base64);
  const output = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i += 1) {
    output[i] = raw.charCodeAt(i);
  }
  return output;
}

function arrayBufferToBase64Url(buffer: ArrayBuffer): string {
  const bytes = new Uint8Array(buffer);
  let binary = "";
  for (let i = 0; i < bytes.byteLength; i += 1) {
    binary += String.fromCharCode(bytes[i]);
  }
  return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
}

export function pushSupported(): boolean {
  return (
    typeof window !== "undefined" &&
    "serviceWorker" in navigator &&
    "PushManager" in window &&
    "Notification" in window
  );
}

export async function ensureWebPushSubscription(memberPhone: string): Promise<"subscribed" | "skipped" | "denied"> {
  if (!pushSupported() || !memberPhone.trim()) {
    return "skipped";
  }

  if (Notification.permission === "denied") {
    return "denied";
  }

  if (Notification.permission === "default") {
    const permission = await Notification.requestPermission();
    if (permission !== "granted") {
      return permission === "denied" ? "denied" : "skipped";
    }
  }

  const vapid = await apiFetch<VapidResponse>("/api/v1/web-chat/push/vapid-public-key");
  if (!vapid.data.configured || !vapid.data.public_key) {
    return "skipped";
  }

  const registration = await navigator.serviceWorker.ready;
  let subscription = await registration.pushManager.getSubscription();
  if (!subscription) {
    subscription = await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(vapid.data.public_key) as BufferSource,
    });
  }

  const json = subscription.toJSON();
  const p256dh = json.keys?.p256dh || (subscription.getKey("p256dh") ? arrayBufferToBase64Url(subscription.getKey("p256dh")!) : "");
  const auth = json.keys?.auth || (subscription.getKey("auth") ? arrayBufferToBase64Url(subscription.getKey("auth")!) : "");

  await apiFetch("/api/v1/web-chat/push/subscribe", {
    method: "POST",
    body: JSON.stringify({
      phone: memberPhone,
      endpoint: subscription.endpoint,
      keys: { p256dh, auth },
      contentEncoding: "aes128gcm",
    }),
  });

  return "subscribed";
}
