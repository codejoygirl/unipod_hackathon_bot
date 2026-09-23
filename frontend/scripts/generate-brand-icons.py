#!/usr/bin/env python3
"""Build favicon.ico, touch icons, and PWA install icons from public/unipod-assistant-logo.png."""

from __future__ import annotations

from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
LOGO = ROOT / "public" / "unipod-assistant-logo.png"
PUBLIC = ROOT / "public"
APP = ROOT / "src" / "app"

ICO_SIZES = (16, 32, 48, 64, 128, 256)


def create_maskable_icon(logo: Image.Image, size: int) -> Image.Image:
    """Create a maskable icon with safe-zone margin on a solid white canvas."""
    canvas = Image.new("RGBA", (size, size), (255, 255, 255, 255))
    # Android maskable safe zone is the inner 80% circle
    inner_size = int(size * 0.8)
    resized_logo = logo.resize((inner_size, inner_size), Image.Resampling.LANCZOS)
    offset = (size - inner_size) // 2
    canvas.paste(resized_logo, (offset, offset), resized_logo)
    return canvas


def main() -> None:
    if not LOGO.is_file():
        raise SystemExit(f"Missing logo: {LOGO}")

    logo = Image.open(LOGO).convert("RGBA")
    frames = [logo.resize((s, s), Image.Resampling.LANCZOS) for s in ICO_SIZES]

    favicon_path = PUBLIC / "favicon.ico"
    frames[0].save(
        favicon_path,
        format="ICO",
        sizes=[(s, s) for s in ICO_SIZES],
        append_images=frames[1:],
    )

    logo.resize((32, 32), Image.Resampling.LANCZOS).save(PUBLIC / "favicon-32x32.png")
    logo.resize((180, 180), Image.Resampling.LANCZOS).save(PUBLIC / "apple-touch-icon.png")

    # Standard PWA icons (192 and 512)
    logo.resize((192, 192), Image.Resampling.LANCZOS).save(PUBLIC / "icon-192.png")
    logo.resize((512, 512), Image.Resampling.LANCZOS).save(PUBLIC / "icon-512.png")

    # Maskable PWA icons (192 and 512)
    create_maskable_icon(logo, 192).save(PUBLIC / "icon-maskable-192.png")
    create_maskable_icon(logo, 512).save(PUBLIC / "icon-maskable-512.png")

    APP.mkdir(parents=True, exist_ok=True)
    favicon_path.read_bytes()
    (APP / "favicon.ico").write_bytes(favicon_path.read_bytes())
    logo.resize((512, 512), Image.Resampling.LANCZOS).save(APP / "icon.png")
    logo.resize((180, 180), Image.Resampling.LANCZOS).save(APP / "apple-icon.png")

    print(f"Wrote {favicon_path} ({favicon_path.stat().st_size} bytes)")
    print(f"Wrote {PUBLIC / 'apple-touch-icon.png'}, icon-192.png, icon-512.png, maskable icons")
    print(f"Updated {APP / 'favicon.ico'}, icon.png, apple-icon.png")


if __name__ == "__main__":
    main()
