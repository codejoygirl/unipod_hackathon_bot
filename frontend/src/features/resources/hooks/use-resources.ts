"use client";

import { useQuery } from "@tanstack/react-query";

import { listResources } from "@/lib/api/resources";
import { queryKeys } from "@/lib/query/keys";

/** Links found in the member's published community knowledge. */
export function useResources() {
  return useQuery({
    queryKey: queryKeys.resources,
    queryFn: async ({ signal }) => {
      const response = await listResources(signal);
      return response;
    },
  });
}
