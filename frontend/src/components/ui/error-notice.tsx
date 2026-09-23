import { ApiError } from "@/lib/api/client";

/** Turns a failed request into something a member can act on. */
export function ErrorNotice({ error, title }: { error: unknown; title: string }) {
  const message =
    error instanceof ApiError
      ? error.status === 0
        ? "The API is not reachable. Start the backend, then reload."
        : error.message
      : error instanceof Error
        ? error.message
        : "Unknown error.";

  return (
    <p
      role="alert"
      className="rounded-sm border border-clay bg-clay-wash px-4 py-3 text-sm leading-5 text-clay"
    >
      <span className="font-medium">{title}.</span> {message}
    </p>
  );
}
