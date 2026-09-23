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
    entry === "resources" ||
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

/** Prefer branded favicon from frontend/public (Python script), not Next's hashed copy. */
function syncRootFavicon(laravelPublic, frontendPublic) {
  const branded = path.join(frontendPublic, "favicon.ico");
  if (existsSync(branded)) {
    cpSync(branded, path.join(laravelPublic, "favicon.ico"));
    console.log(`Updated ${path.join(laravelPublic, "favicon.ico")} from frontend/public`);
  }
}

/** Keep PWA / shortcut icon at site root in sync with the branded logo in public/. */
function syncRootBrandAssets(laravelPublic, frontendPublic) {
  const logo = path.join(frontendPublic, "unipod-assistant-logo.png");
  const extras = [
    "unipod-assistant-logo.png",
    "apple-touch-icon.png",
    "favicon-32x32.png",
    "favicon.ico",
    "manifest.webmanifest",
    "sw.js",
    "icon-192.png",
    "icon-512.png",
    "icon-maskable-192.png",
    "icon-maskable-512.png",
  ];
  for (const name of extras) {
    const src = path.join(frontendPublic, name);
    if (existsSync(src)) {
      cpSync(src, path.join(laravelPublic, name));
    }
  }
  if (existsSync(logo)) {
    cpSync(logo, path.join(laravelPublic, "icon.png"));
  }
}

const frontendPublic = path.join(frontendRoot, "public");
syncRootFavicon(publicDir, frontendPublic);
syncRootBrandAssets(publicDir, frontendPublic);
console.log(`Synced Next export → ${publicDir}`);
