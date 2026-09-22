const knowledgeSourcesRoot = ["knowledge-sources"] as const;

export const queryKeys = {
  session: ["session"] as const,
  tenants: ["tenants"] as const,
  communities: (tenantId: string) => ["communities", tenantId] as const,
  knowledgeSourcesRoot,
  knowledgeSources: (page: number) => [...knowledgeSourcesRoot, page] as const,
};
