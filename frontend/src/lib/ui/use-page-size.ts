"use client";

import { useEffect, useState } from "react";

/** 6 items on small screens, 8 from the `sm` breakpoint up. */
export function usePageSize(small = 6, large = 8): number {
  const [pageSize, setPageSize] = useState(small);

  useEffect(() => {
    const media = window.matchMedia("(min-width: 640px)");
    const update = () => setPageSize(media.matches ? large : small);
    update();
    media.addEventListener("change", update);
    return () => media.removeEventListener("change", update);
  }, [small, large]);

  return pageSize;
}
