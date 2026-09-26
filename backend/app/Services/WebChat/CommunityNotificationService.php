<?php

declare(strict_types=1);

namespace App\Services\WebChat;

use App\Models\CommunityNotification;
use App\Models\CommunityNotificationRead;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Community-scoped published notifications for web chat (real DB, not mock UI).
 */
final class CommunityNotificationService
{
    /**
     * Default pack seeded once per community so the inbox is never blank after migrate.
     *
     * @return list<array{title: string, message: string, category: string, action_query: ?string, published_at: Carbon}>
     */
    public function defaultSeedItems(): array
    {
        $now = now();

        return [
            [
                'title' => 'New Meetings Hub & Live Sessions Active',
                'message' => 'Browse weekly cohort syncs, mentor office hours, and open sessions with direct Teams, Zoom & Meet links.',
                'category' => 'Live Session',
                'action_query' => 'What meetings and live sessions are scheduled for this week?',
                'published_at' => $now->copy(),
            ],
            [
                'title' => 'UniPods METI AI Hackathon Kickoff',
                'message' => 'Initial project registration and team roster submission closes this Friday. Review the criteria in the handbook.',
                'category' => 'Deadline',
                'action_query' => 'When is the hackathon deadline and submission requirements?',
                'published_at' => $now->copy()->subHours(2),
            ],
            [
                'title' => 'Live Mentorship Q&A on Microsoft Teams',
                'message' => 'Mentors host a live office hour tomorrow at 4:00 PM to review project ideas and next steps.',
                'category' => 'Live Session',
                'action_query' => 'When is the next live session and how do I join?',
                'published_at' => $now->copy()->subDay(),
            ],
            [
                'title' => 'Wadhwani Resource Pack Updated',
                'message' => 'New reference materials on generative models and training notebooks have been added to the knowledge hub.',
                'category' => 'Announcement',
                'action_query' => 'Where can I find the UniPods handbook and Wadhwani resource pack?',
                'published_at' => $now->copy()->subDays(3),
            ],
            [
                'title' => 'Community Share Approved',
                'message' => 'Your submitted tip regarding prompt optimization has been approved and published to member drafts.',
                'category' => 'System',
                'action_query' => null,
                'published_at' => $now->copy()->subDays(5),
            ],
        ];
    }

    public function ensureDefaultsForCommunity(string $communityId): void
    {
        $exists = CommunityNotification::query()
            ->where('community_id', $communityId)
            ->exists();

        if ($exists) {
            return;
        }

        DB::transaction(function () use ($communityId): void {
            // Double-check inside transaction to avoid duplicate seed races.
            if (CommunityNotification::query()->where('community_id', $communityId)->exists()) {
                return;
            }

            foreach ($this->defaultSeedItems() as $item) {
                CommunityNotification::query()->create([
                    'community_id' => $communityId,
                    'title' => $item['title'],
                    'message' => $item['message'],
                    'category' => $item['category'],
                    'action_query' => $item['action_query'],
                    'created_by_phone' => null,
                    'published_at' => $item['published_at'],
                ]);
            }
        });
    }

    /**
     * @return array{notifications: list<array<string, mixed>>, unread_count: int}
     */
    public function listForMember(string $communityId, string $memberPhone): array
    {
        $this->ensureDefaultsForCommunity($communityId);

        $rows = CommunityNotification::query()
            ->where('community_id', $communityId)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderByDesc('published_at')
            ->limit(50)
            ->get();

        $readIds = CommunityNotificationRead::query()
            ->where('member_phone', $memberPhone)
            ->whereIn('notification_id', $rows->pluck('id'))
            ->pluck('notification_id')
            ->all();

        $readSet = array_fill_keys($readIds, true);
        $notifications = [];
        $unread = 0;

        foreach ($rows as $row) {
            $isRead = isset($readSet[$row->id]);
            if (! $isRead) {
                $unread++;
            }

            $notifications[] = [
                'id' => $row->id,
                'title' => $row->title,
                'message' => $row->message,
                'category' => $row->category,
                'action_query' => $row->action_query,
                'published_at' => $row->published_at?->toIso8601String(),
                'timestamp' => $this->relativeLabel($row->published_at),
                'read' => $isRead,
            ];
        }

        return [
            'notifications' => $notifications,
            'unread_count' => $unread,
        ];
    }

    public function markRead(string $communityId, string $notificationId, string $memberPhone): bool
    {
        $notification = CommunityNotification::query()
            ->where('community_id', $communityId)
            ->whereKey($notificationId)
            ->whereNotNull('published_at')
            ->first();

        if ($notification === null) {
            return false;
        }

        CommunityNotificationRead::query()->updateOrCreate(
            [
                'notification_id' => $notification->id,
                'member_phone' => $memberPhone,
            ],
            [
                'read_at' => now(),
            ],
        );

        return true;
    }

    public function markAllRead(string $communityId, string $memberPhone): int
    {
        $ids = CommunityNotification::query()
            ->where('community_id', $communityId)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->pluck('id');

        $count = 0;
        foreach ($ids as $id) {
            CommunityNotificationRead::query()->updateOrCreate(
                [
                    'notification_id' => $id,
                    'member_phone' => $memberPhone,
                ],
                [
                    'read_at' => now(),
                ],
            );
            $count++;
        }

        return $count;
    }

    /**
     * @param  array{title: string, message: string, category?: string, action_query?: ?string}  $payload
     */
    public function publish(string $communityId, array $payload, ?string $createdByPhone = null): CommunityNotification
    {
        $category = trim((string) ($payload['category'] ?? 'Announcement'));
        $allowed = ['Announcement', 'Live Session', 'Deadline', 'System'];
        if (! in_array($category, $allowed, true)) {
            $category = 'Announcement';
        }

        return CommunityNotification::query()->create([
            'community_id' => $communityId,
            'title' => trim($payload['title']),
            'message' => trim($payload['message']),
            'category' => $category,
            'action_query' => isset($payload['action_query']) ? trim((string) $payload['action_query']) ?: null : null,
            'created_by_phone' => $createdByPhone,
            'published_at' => now(),
        ]);
    }

    private function relativeLabel(?Carbon $at): string
    {
        if ($at === null) {
            return '';
        }

        $seconds = (int) abs($at->diffInSeconds(now()));
        if ($seconds < 90) {
            return 'Just now';
        }
        if ($seconds < 3600) {
            $m = max(1, (int) floor($seconds / 60));

            return $m === 1 ? '1 minute ago' : "{$m} minutes ago";
        }
        if ($seconds < 86400) {
            $h = max(1, (int) floor($seconds / 3600));

            return $h === 1 ? '1 hour ago' : "{$h} hours ago";
        }
        if ($seconds < 172800) {
            return 'Yesterday';
        }
        $d = max(2, (int) floor($seconds / 86400));

        return "{$d} days ago";
    }
}
