import { APP_DISPLAY_NAME, appIconUrl } from "@/lib/branding";
import type { Metadata, Viewport } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import { Providers } from "./providers";
import "./globals.css";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const viewport: Viewport = {
  width: "device-width",
  initialScale: 1,
  maximumScale: 1,
  userScalable: false,
  themeColor: "#171717",
};

const favicon32 = appIconUrl("/favicon-32x32.png");
const faviconIco = appIconUrl("/favicon.ico");
const appleTouch = appIconUrl("/apple-touch-icon.png");
const icon192 = appIconUrl("/icon-192.png");
const icon512 = appIconUrl("/icon-512.png");

export const metadata: Metadata = {
  title: APP_DISPLAY_NAME,
  description: "UniPod community knowledge assistant — private web chat",
  manifest: "/manifest.webmanifest",
  icons: {
    icon: [
      { url: favicon32, type: "image/png", sizes: "32x32" },
      { url: icon192, type: "image/png", sizes: "192x192" },
      { url: icon512, type: "image/png", sizes: "512x512" },
      { url: faviconIco, sizes: "any" },
    ],
    shortcut: favicon32,
    apple: [{ url: appleTouch, sizes: "180x180", type: "image/png" }],
  },
  appleWebApp: {
    capable: true,
    statusBarStyle: "black-translucent",
    title: "UniPod",
  },
};

const themeInitScript = `
(function() {
  try {
    var stored = localStorage.getItem('unipod-theme-preference');
    var isDark = stored === 'dark' || (!stored && window.matchMedia('(prefers-color-scheme: dark)').matches) || stored === null;
    if (isDark) {
      document.documentElement.classList.add('dark');
      document.documentElement.style.colorScheme = 'dark';
    } else {
      document.documentElement.classList.remove('dark');
      document.documentElement.style.colorScheme = 'light';
    }
  } catch(e) {}
})();
`;

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html
      lang="en"
      suppressHydrationWarning
      className={`${geistSans.variable} ${geistMono.variable} h-full antialiased dark`}
    >
      <head>
        <script dangerouslySetInnerHTML={{ __html: themeInitScript }} />
      </head>
      <body className="flex min-h-full flex-col bg-background text-foreground">
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
