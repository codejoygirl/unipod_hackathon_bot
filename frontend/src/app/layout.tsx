import type { Metadata } from "next";
import { Geist, Geist_Mono, Newsreader, Noto_Naskh_Arabic } from "next/font/google";

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

const newsreader = Newsreader({
  variable: "--font-newsreader",
  subsets: ["latin"],
  axes: ["opsz"],
  display: "swap",
});

const notoNaskhArabic = Noto_Naskh_Arabic({
  variable: "--font-noto-naskh",
  subsets: ["arabic"],
  display: "swap",
});

export const metadata: Metadata = {
  title: "Zak: permission-scoped answers for community knowledge",
  description:
    "Zak answers member questions from the knowledge each person is authorised to see. Every answer cites its sources, and anything outside a member's communities is refused rather than guessed.",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html
      lang="en"
      className={`${geistSans.variable} ${geistMono.variable} ${newsreader.variable} ${notoNaskhArabic.variable} h-full antialiased`}
    >
      <body className="flex min-h-full flex-col bg-paper text-ink">
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
