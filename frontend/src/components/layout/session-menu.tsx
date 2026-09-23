"use client";

import { useRouter } from "next/navigation";

import { Button } from "@/components/ui/button";
import { useLogout, useSession } from "@/lib/auth/session";

export function SessionMenu() {
  const { user } = useSession();
  const logout = useLogout();
  const router = useRouter();

  if (!user) return null;

  return (
    <div className="flex items-center gap-3">
      <span className="hidden max-w-[16ch] truncate text-xs leading-5 text-ink-soft sm:inline">
        {user.email}
      </span>

      <Button
        size="sm"
        variant="secondary"
        disabled={logout.isPending}
        onClick={() => {
          logout.mutate(undefined, {
            onSuccess: () => router.replace("/login"),
          });
        }}
      >
        {logout.isPending ? "Signing out" : "Sign out"}
      </Button>
    </div>
  );
}
