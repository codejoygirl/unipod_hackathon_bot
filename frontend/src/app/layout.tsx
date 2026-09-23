import { APP_DISPLAY_NAME, APP_LOGO_SRC } from "@/lib/branding";
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
  themeColor: "#ffffff",
};

export const metadata: Metadata = {
  title: APP_DISPLAY_NAME,
  description: "UniPod community knowledge assistant — private web chat",
  icons: {
    icon: [
      { url: "/unipod-assistant-logo.png?v=3", type: "image/png" },
      { url: "/favicon.ico?v=3" },
    ],
    shortcut: "/unipod-assistant-logo.png?v=3",
    apple: "/unipod-assistant-logo.png?v=3",
  },
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html
      lang="en"
      className={`${geistSans.variable} ${geistMono.variable} h-full antialiased`}
    >
      <head>
        <link rel="icon" type="image/png" href="/unipod-assistant-logo.png?v=3" />
        <link rel="shortcut icon" type="image/png" href="/unipod-assistant-logo.png?v=3" />
        <link rel="apple-touch-icon" href="/unipod-assistant-logo.png?v=3" />
      </head>
      <body className="flex min-h-full flex-col">
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
