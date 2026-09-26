export type AnswerState =
  | "VERIFIED"
  | "POSSIBLE"
  | "CONFLICT"
  | "INSUFFICIENT_EVIDENCE"
  | "UNKNOWN"
  | "BLOCKED";

export type TenantMembership = {
  id: string;
  name: string | null;
  slug: string | null;
  roles: string[];
  community_ids: string[];
};

export type User = {
  id: string;
  name: string;
  email: string;
  tenants?: TenantMembership[];
};

export type Community = {
  id: string;
  tenant_id: string;
  name: string;
  slug: string;
  created_at?: string;
};

export type EvidenceItem = {
  evidence_id: string;
  source_name: string;
  source_uri: string;
  exact_quote: string;
  context: string | null;
  page: number | null;
  timestamp: number | null;
  authority: string | null;
};

export type AssistantAskResponse = {
  data: {
    state: AnswerState;
    answer: string;
    confidence: number | null;
    detected_language: string | null;
    evidence_drawer: EvidenceItem[];
    conflicts: Array<{
      topic: string;
      claims: string[];
      action: string;
    }>;
    needs_escalation: boolean;
    escalation_reason: string | null;
  };
  meta: {
    latency_ms: number;
    chunks_evaluated: number;
    tenant_id: string;
    community_ids: string[];
  };
};

export type ApiErrorBody = {
  message?: string;
  errors?: Record<string, string[] | string>;
};

export type CommunityResource = {
  id: string;
  name: string;
  kind: "folder" | "handbook" | "slides" | "form" | "recording" | "document" | "other" | string;
  url: string | null;
  description: string;
  authority_tier: string;
  source_type: string;
  published_at?: string | null;
  is_asset?: boolean;
};

export type CommunityNotificationItem = {
  id: string;
  title: string;
  message: string;
  category: string;
  action_query: string | null;
  published_at: string | null;
  timestamp: string;
  read: boolean;
};

export type CommunityNotificationsResponse = {
  data: {
    community_id: string;
    unread_count: number;
    notifications: CommunityNotificationItem[];
  };
};

export type CommunityResourcesResponse = {
  data: {
    community: {
      id: string;
      name: string;
      slug: string;
    };
    is_admin: boolean;
    resources: CommunityResource[];
  };
};

export type MemberVaultDocument = {
  id: string;
  filename: string;
  mime: string | null;
  byte_size: number;
  status: string;
  excerpt: string;
  created_at: string | null;
  kind?: "image" | "pdf" | "document" | "text" | string;
};

export type MemberVaultArtefact = {
  id: string;
  kind: string;
  title: string;
  body: string;
  created_at: string | null;
};

export type MemberVaultSnapshotResponse = {
  data: {
    id: string;
    name: string;
    kind: string;
    documents: MemberVaultDocument[];
    artefacts: MemberVaultArtefact[];
    limits?: {
      max_files: number;
      max_bytes: number;
      file_count: number;
    };
  };
};

export type MemberVaultAskResponse = {
  data: {
    answer: string;
    citations: string[];
    used_filenames: string[];
    artefact?: MemberVaultArtefact;
  };
};

export type MemberProjectSummary = {
  id: string;
  name: string;
  file_count: number;
  chat_count: number;
  updated_at: string | null;
};

export type MemberProjectChatSummary = {
  id: string;
  title: string;
  updated_at: string | null;
};

export type MemberProjectMessage = {
  id: string;
  role: "user" | "assistant" | string;
  body: string;
  used_filenames: string[];
  artefact?: MemberVaultArtefact | null;
  created_at: string | null;
};

export type MemberProjectSnapshotResponse = {
  data: {
    project: MemberProjectSummary;
    documents: MemberVaultDocument[];
    chats: MemberProjectChatSummary[];
  };
};

export type MemberProjectChatResponse = {
  data: MemberProjectChatSummary & {
    messages: MemberProjectMessage[];
  };
};
