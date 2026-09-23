const knowledgeSourcesRoot = ["knowledge-sources"] as const;
const conversationsRoot = ["conversations"] as const;

export const queryKeys = {
  session: ["session"] as const,
  tenants: ["tenants"] as const,
  communities: (tenantId: string) => ["communities", tenantId] as const,
  knowledgeSourcesRoot,
  knowledgeSources: (page: number) => [...knowledgeSourcesRoot, page] as const,
  conversationsRoot,
  conversations: () => [...conversationsRoot, "list"] as const,
  conversation: (id: string) => [...conversationsRoot, "detail", id] as const,
  resources: ["resources"] as const,
};
