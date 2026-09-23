import { Badge } from "@/components/ui/badge";
import type { AuthorityTier, LifecycleStatus } from "@/types/api";

type Tone = "accent" | "amber" | "clay" | "neutral";

const LIFECYCLE: Record<LifecycleStatus, { label: string; tone: Tone }> = {
  draft: { label: "draft", tone: "neutral" },
  pending_review: { label: "pending review", tone: "amber" },
  published: { label: "published", tone: "accent" },
  superseded: { label: "superseded", tone: "neutral" },
  archived: { label: "archived", tone: "neutral" },
  rejected: { label: "rejected", tone: "clay" },
};

export function LifecycleBadge({ status }: { status: LifecycleStatus | null }) {
  if (!status) return <Badge tone="neutral">unknown</Badge>;

  const { label, tone } = LIFECYCLE[status];
  return <Badge tone={tone}>{label}</Badge>;
}

/** Authority decides how much a citation is worth, so it is shown, not hidden. */
export function AuthorityBadge({ tier }: { tier: AuthorityTier | null }) {
  if (!tier) return null;

  return (
    <Badge tone={tier === "official_announcement" ? "accent" : "neutral"}>
      {tier.replace(/_/g, " ")}
    </Badge>
  );
}
