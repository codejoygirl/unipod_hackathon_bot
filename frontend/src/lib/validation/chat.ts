import { z } from "zod";

/** Mirrors the assistant ask rule: `query` is required and capped at 2000 characters. */
export const askSchema = z.object({
  query: z
    .string()
    .min(1, "Type a question.")
    .max(2000, "Keep your question under 2000 characters."),
});

export type AskValues = z.infer<typeof askSchema>;
