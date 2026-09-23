import Image from "next/image";
import { APP_DISPLAY_NAME, APP_LOGO_SRC } from "@/lib/branding";

type AppBrandProps = {
  size?: "sm" | "lg";
  showTitle?: boolean;
};

export function AppBrand({ size = "sm", showTitle = true }: AppBrandProps) {
  const imageSize = size === "lg" ? 88 : 48;

  return (
    <div className="flex flex-col items-center text-center">
      <Image
        src={APP_LOGO_SRC}
        alt=""
        width={imageSize}
        height={imageSize}
        className="rounded-2xl object-contain"
        priority
      />
      {showTitle ? (
        <p
          className={
            size === "lg"
              ? "mt-4 text-lg font-semibold tracking-tight text-zinc-900"
              : "mt-2 text-sm font-semibold text-zinc-900"
          }
        >
          {APP_DISPLAY_NAME}
        </p>
      ) : null}
    </div>
  );
}
