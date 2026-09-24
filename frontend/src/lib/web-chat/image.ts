export const ALLOWED_CHAT_IMAGE_MIMES = [
  "image/jpeg",
  "image/jpg",
  "image/png",
  "image/webp",
  "image/gif",
] as const;

export type ChatImagePayload = {
  base64: string;
  mime: string;
  filename: string;
  previewUrl: string;
};

export const MAX_CHAT_IMAGES = 5;

const MAX_SEND_EDGE = 1600;
const MAX_SEND_BYTES = 2_200_000;
const MAX_PREVIEW_EDGE = 480;

function normalizeMime(mime: string): string {
  const clean = mime.toLowerCase().split(";")[0]?.trim() ?? "";
  return clean === "image/jpg" ? "image/jpeg" : clean;
}

export function isAllowedChatImageMime(mime: string): boolean {
  return ALLOWED_CHAT_IMAGE_MIMES.includes(
    normalizeMime(mime) as (typeof ALLOWED_CHAT_IMAGE_MIMES)[number],
  );
}

function blobToBase64(blob: Blob): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => {
      const result = String(reader.result ?? "");
      const comma = result.indexOf(",");
      resolve(comma >= 0 ? result.slice(comma + 1) : result);
    };
    reader.onerror = () => reject(reader.error ?? new Error("read_failed"));
    reader.readAsDataURL(blob);
  });
}

function canvasToBlob(canvas: HTMLCanvasElement, mime: string, quality: number): Promise<Blob> {
  return new Promise((resolve, reject) => {
    canvas.toBlob(
      (blob) => {
        if (!blob) {
          reject(new Error("compress_failed"));
          return;
        }
        resolve(blob);
      },
      mime,
      quality,
    );
  });
}

function drawScaled(
  source: CanvasImageSource,
  width: number,
  height: number,
  maxEdge: number,
): HTMLCanvasElement {
  const scale = Math.min(1, maxEdge / Math.max(width, height));
  const w = Math.max(1, Math.round(width * scale));
  const h = Math.max(1, Math.round(height * scale));
  const canvas = document.createElement("canvas");
  canvas.width = w;
  canvas.height = h;
  const ctx = canvas.getContext("2d");
  if (!ctx) {
    throw new Error("canvas_unavailable");
  }
  ctx.drawImage(source, 0, 0, w, h);
  return canvas;
}

export async function prepareChatImage(file: File): Promise<ChatImagePayload> {
  const mime = normalizeMime(file.type || "image/jpeg");
  if (!isAllowedChatImageMime(mime)) {
    throw new Error("unsupported_type");
  }

  const bitmap = await createImageBitmap(file);
  try {
    const sendCanvas = drawScaled(bitmap, bitmap.width, bitmap.height, MAX_SEND_EDGE);
    const outputMime = mime === "image/png" && file.size < 400_000 ? "image/png" : "image/jpeg";
    let quality = 0.82;
    let sendBlob = await canvasToBlob(sendCanvas, outputMime, quality);
    if (sendBlob.size > MAX_SEND_BYTES) {
      quality = 0.65;
      sendBlob = await canvasToBlob(sendCanvas, "image/jpeg", quality);
    }
    if (sendBlob.size > MAX_SEND_BYTES) {
      sendBlob = await canvasToBlob(sendCanvas, "image/jpeg", 0.5);
    }
    if (sendBlob.size > MAX_SEND_BYTES) {
      throw new Error("too_large");
    }

    const previewCanvas = drawScaled(bitmap, bitmap.width, bitmap.height, MAX_PREVIEW_EDGE);
    const previewBlob = await canvasToBlob(previewCanvas, "image/jpeg", 0.6);

    return {
      base64: await blobToBase64(sendBlob),
      mime: sendBlob.type || outputMime,
      filename: file.name?.trim() || (outputMime === "image/png" ? "photo.png" : "photo.jpg"),
      previewUrl: `data:image/jpeg;base64,${await blobToBase64(previewBlob)}`,
    };
  } finally {
    bitmap.close();
  }
}
