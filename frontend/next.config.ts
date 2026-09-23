import { fileURLToPath } from "node:url";
import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  turbopack: {
    // Pinned so module resolution does not depend on lockfiles found above the repo.
    root: fileURLToPath(new URL(".", import.meta.url)),
  },
};

export default nextConfig;
