import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  output: "export",
  trailingSlash: true,
  images: {
    unoptimized: true,
  },
  // Static assets live beside Laravel's public/index.php
  distDir: ".next",
};

export default nextConfig;
