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
