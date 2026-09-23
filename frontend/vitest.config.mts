import react from "@vitejs/plugin-react";
import { fileURLToPath } from "node:url";
import { defineConfig } from "vitest/config";

export default defineConfig({
  plugins: [react()],
  test: {
    // jsdom supplies `document` for the CSRF cookie reader.
    environment: "jsdom",
    include: ["src/**/*.test.{ts,tsx}"],
    // The repository lives on a synced folder, where the default forks pool can take
    // longer than Vitest's worker-start timeout. Threads start faster and the suite is
    // small enough that parallelism is not worth the risk.
    pool: "threads",
    fileParallelism: false,
  },
  resolve: {
    alias: {
      "@": fileURLToPath(new URL("./src", import.meta.url)),
    },
  },
});
