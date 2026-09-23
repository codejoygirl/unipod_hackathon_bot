import { cpSync, existsSync, mkdirSync, readdirSync, rmSync, statSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, "..");
const outDir = path.join(frontendRoot, "out");
const publicDir = path.resolve(frontendRoot, "..", "backend", "public");

const PRESERVE = new Set(["index.php", ".htaccess"]);

if (!existsSync(outDir)) {
  console.error("Missing Next export at frontend/out — run `npm run build` first.");
  process.exit(1);
}

if (!existsSync(publicDir)) {
  console.error(`Laravel public directory not found: ${publicDir}`);
  process.exit(1);
}

function copyRecursive(src, dest) {
  for (const entry of readdirSync(src)) {
    const srcPath = path.join(src, entry);
    const destPath = path.join(dest, entry);
    if (PRESERVE.has(entry) && existsSync(destPath)) {
      continue;
    }
    const info = statSync(srcPath);
    if (info.isDirectory()) {
      mkdirSync(destPath, { recursive: true });
      copyRecursive(srcPath, destPath);
    } else {
      cpSync(srcPath, destPath);
    }
  }
}

// Remove prior Next artifacts (keep Laravel files).
for (const entry of readdirSync(publicDir)) {
  if (PRESERVE.has(entry)) {
    continue;
  }
  if (
    entry === "_next" ||
    entry.startsWith("__next") ||
    entry === "_not-found" ||
    entry === "404" ||
    entry === "index.html" ||
    entry === "index.txt" ||
    entry === "login" ||
    entry === "catch-up" ||
    entry === "meetings" ||
    entry === "tasks" ||
    entry === "more" ||
    entry.endsWith(".html") ||
    entry.endsWith(".txt")
  ) {
    const target = path.join(publicDir, entry);
    rmSync(target, { recursive: true, force: true });
  }
}

copyRecursive(outDir, publicDir);
console.log(`Synced Next export → ${publicDir}`);
