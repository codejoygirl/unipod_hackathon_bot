/**
 * Hand-written from the verified Laravel contracts (controllers, API Resources, enums, and
 * feature tests) because the backend could not run locally to serve `/docs/api.json`.
 *
 * These types MUST be reconciled against the generated spec once Sail is up:
 *   npm run api:types
 *
 * Conventions:
 * - Every `JsonResource` response is wrapped in a top-level `data` key.
 * - Error responses are NOT wrapped: `{ message, errors? }`.
 * - All tenancy identifiers are ULID strings. `User.id` is an integer.
 */

export type ApiResource<T> = { data: T };

export type ApiErrorBody = {
  message: string;
  errors?: Record<string, string[]>;
};

/** Laravel's length-aware paginator, as returned by `GET /knowledge-sources`. */
export type Paginated<T> = {
  data: T[];
  links: {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
  };
  meta: {
    current_page: number;
    from: number | null;
    last_page: number;
    path: string;
    per_page: number;
    to: number | null;
    total: number;
  };
};

/* ------------------------------------------------------------------ auth */

/** `App\Enums\MembershipRole` string values. */
export type UserRole =
  | "tenant_owner"
  | "community_admin"
  | "trusted_organiser"
  | "moderator"
  | "member"
  | "guest";

/** One entry per tenant the user holds a membership in. */
export type UserTenant = {
  id: string;
  name: string | null;
  slug: string | null;
  roles: UserRole[];
  /** ULIDs of the communities inside this tenant the user can reach. */
  community_ids: string[];
};

export type User = {
  /** Integer id: the `users` table uses `$table->id()`. */
  id: number;
  name: string;
  email: string;
  /** Present on login and `me`. Absent on the register response. */
  tenants?: UserTenant[];
};

export type LoginPayload = {
  email: string;
  password: string;
};

export type RegisterPayload = {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
};

/* --------------------------------------------------------------- tenancy */

export type Tenant = {
  id: string;
  name: string;
  slug: string;
  created_at: string;
};

export type Community = {
  id: string;
  tenant_id: string;
  name: string;
  slug: string;
  created_at: string;
};

/* ------------------------------------------------------------- knowledge */

/** `App\Enums\KnowledgeAuthorityTier`. */
export type AuthorityTier =
  | "official_announcement"
  | "policy_document"
  | "verified_resource"
  | "community_discussion";

/** `App\Enums\KnowledgeLifecycleStatus`. */
export type LifecycleStatus =
  | "draft"
  | "pending_review"
  | "published"
  | "superseded"
  | "archived"
  | "rejected";

export type KnowledgeSource = {
  id: string;
  tenant_id: string;
  community_id: string;
  name: string;
  uri: string;
  source_type: string;
  authority_tier: AuthorityTier | null;
  lifecycle_status: LifecycleStatus | null;
  language: string;
  ai_source_id: string | null;
  published_at: string | null;
  created_at: string | null;
};

export type StoreKnowledgeSourcePayload = {
  tenant_id: string;
  community_id: string;
  name: string;
  source_type: string;
  content: string;
  uri?: string | null;
  authority_tier?: AuthorityTier | null;
  language?: string | null;
  metadata?: Record<string, unknown> | null;
};

/**
 * `POST /knowledge-sources/import`. Text import: the endpoint also accepts a multipart
 * `file`, which no surface uploads yet.
 */
export type ImportKnowledgePayload = {
  tenant_id: string;
  community_id: string;
  content: string;
  name?: string | null;
  uri?: string | null;
  source_type?: string | null;
  language?: string | null;
  authority_tier?: AuthorityTier | null;
  metadata?: Record<string, unknown> | null;
};

/* ------------------------------------------------------------- assistant */

/**
 * `App\Enums\AnswerState`. Six UPPERCASE values.
 * `INSUFFICIENT_EVIDENCE` is absent from the PRD's five-status list but is a real API value.
 */
export type AnswerState =
  | "VERIFIED"
  | "POSSIBLE"
  | "CONFLICT"
  | "INSUFFICIENT_EVIDENCE"
  | "UNKNOWN"
  | "BLOCKED";

/** One row of `data.evidence_drawer`. */
export type EvidenceCitation = {
  evidence_id: string;
  source_name: string;
  source_uri: string;
  exact_quote: string;
  context: string;
  page: number | null;
  timestamp: number | null;
  authority: string;
};

export type AnswerConflict = {
  topic: string;
  claims: string[];
  action: string;
};

export type GroundedAnswer = {
  state: AnswerState;
  answer: string;
  confidence: number;
  detected_language: string;
  evidence_drawer: EvidenceCitation[];
  conflicts: AnswerConflict[];
  needs_escalation: boolean;
  escalation_reason: string | null;
};

export type AskMeta = {
  latency_ms: number;
  chunks_evaluated: number;
  tenant_id: string;
  community_ids: string[];
};

export type AskPayload = {
  query: string;
  /** ULIDs. All must belong to one tenant, or the API returns 422. */
  community_ids: string[];
  target_language?: string | null;
};

export type AskResponse = {
  data: GroundedAnswer;
  meta: AskMeta;
};

/* ----------------------------------------------------------------- health */

export type Liveness = {
  status: string;
  service: string;
};
