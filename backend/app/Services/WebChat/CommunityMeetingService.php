<?php

declare(strict_types=1);

namespace App\Services\WebChat;

use App\Models\CommunityMeeting;
use Illuminate\Support\Facades\DB;

final class CommunityMeetingService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function defaultSeedItems(): array
    {
        return [
            [
                'title' => "METI Open Hour: 'Ask Us Anything'",
                'category' => 'office_hours',
                'platform' => 'teams',
                'platform_name' => 'Microsoft Teams',
                'url' => 'https://teams.microsoft.com/l/meetup-join/19%3ameeting_MjlkNWYyMjYtMGNhMi00NDM1LTlkNmYtOTZhYTU2MDU4MDc2%40thread.v2/0?context=%7B%22Tid%22%3A%22b3e5db5e-2944-4837-99f5-7488ace54319%22%2C%22Oid%22%3A%2225f213f2-0e2f-4763-83fa-0d909a0e9701%22%7D',
                'schedule' => 'Every Friday at 1:00 PM GMT',
                'time_context' => '2:00 PM WAT / 3:00 PM CAT / 4:00 PM EAT',
                'host' => 'Diane & METI Programme Team',
                'description' => 'Weekly open office hour to ask anything about your venture, technical bottlenecks, prototype feedback, and cohort progress.',
            ],
            [
                'title' => 'Weekly Cohort Sync & Demo Standup',
                'category' => 'weekly_sync',
                'platform' => 'meet',
                'platform_name' => 'Google Meet',
                'url' => 'https://meet.google.com/unipod-cohort-sync',
                'schedule' => 'Tuesdays & Thursdays at 2:00 PM GMT',
                'time_context' => '3:00 PM WAT / 4:00 PM CAT / 5:00 PM EAT',
                'host' => 'Programme Coordinators & Leads',
                'description' => 'Live demonstration of sprint deliverables, peer feedback, milestone tracking, and cross-team collaboration.',
            ],
            [
                'title' => 'Mentor Technical Office Hours: AI Architecture & Systems',
                'category' => 'office_hours',
                'platform' => 'zoom',
                'platform_name' => 'Zoom',
                'url' => 'https://zoom.us/j/92485710294',
                'schedule' => 'Wednesdays at 11:00 AM GMT',
                'time_context' => '12:00 PM WAT / 1:00 PM CAT / 2:00 PM EAT',
                'host' => 'Technical Lead Mentors',
                'description' => 'One-on-one and breakout architectural reviews: LLM integration, RAG pipelines, API scalability, and cloud deployments.',
                'meeting_code' => '924 8571 0294',
                'passcode' => 'UNIPOD2026',
            ],
        ];
    }

    public function ensureDefaultsForCommunity(string $communityId): void
    {
        if (CommunityMeeting::query()->where('community_id', $communityId)->exists()) {
            return;
        }

        DB::transaction(function () use ($communityId): void {
            if (CommunityMeeting::query()->where('community_id', $communityId)->exists()) {
                return;
            }
            foreach ($this->defaultSeedItems() as $item) {
                CommunityMeeting::query()->create([
                    'community_id' => $communityId,
                    'title' => $item['title'],
                    'category' => $item['category'],
                    'platform' => $item['platform'],
                    'platform_name' => $item['platform_name'],
                    'url' => $item['url'],
                    'schedule' => $item['schedule'] ?? null,
                    'time_context' => $item['time_context'] ?? null,
                    'host' => $item['host'] ?? null,
                    'description' => $item['description'] ?? null,
                    'meeting_code' => $item['meeting_code'] ?? null,
                    'passcode' => $item['passcode'] ?? null,
                    'is_live_now' => false,
                    'published_at' => now(),
                ]);
            }
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForCommunity(string $communityId): array
    {
        $this->ensureDefaultsForCommunity($communityId);

        return CommunityMeeting::query()
            ->where('community_id', $communityId)
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->limit(50)
            ->get()
            ->map(fn (CommunityMeeting $m) => $this->toArray($m))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function publish(string $communityId, array $payload, ?string $createdByPhone = null): CommunityMeeting
    {
        $platform = strtolower(trim((string) ($payload['platform'] ?? 'teams')));
        if (! in_array($platform, ['teams', 'meet', 'zoom'], true)) {
            $platform = 'teams';
        }

        $category = strtolower(trim((string) ($payload['category'] ?? 'office_hours')));
        $allowedCats = ['weekly_sync', 'office_hours', 'workshop', 'hackathon', 'recap'];
        if (! in_array($category, $allowedCats, true)) {
            $category = 'office_hours';
        }

        $platformNames = [
            'teams' => 'Microsoft Teams',
            'meet' => 'Google Meet',
            'zoom' => 'Zoom',
        ];

        return CommunityMeeting::query()->create([
            'community_id' => $communityId,
            'title' => trim((string) $payload['title']),
            'category' => $category,
            'platform' => $platform,
            'platform_name' => trim((string) ($payload['platform_name'] ?? $platformNames[$platform])),
            'url' => trim((string) $payload['url']),
            'schedule' => isset($payload['schedule']) ? trim((string) $payload['schedule']) ?: null : null,
            'time_context' => isset($payload['time_context']) ? trim((string) $payload['time_context']) ?: null : null,
            'host' => isset($payload['host']) ? trim((string) $payload['host']) ?: null : null,
            'description' => isset($payload['description']) ? trim((string) $payload['description']) ?: null : null,
            'meeting_code' => isset($payload['meeting_code']) ? trim((string) $payload['meeting_code']) ?: null : null,
            'passcode' => isset($payload['passcode']) ? trim((string) $payload['passcode']) ?: null : null,
            'is_live_now' => (bool) ($payload['is_live_now'] ?? false),
            'created_by_phone' => $createdByPhone,
            'published_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(CommunityMeeting $m): array
    {
        return [
            'id' => $m->id,
            'title' => $m->title,
            'category' => $m->category,
            'platform' => $m->platform,
            'platformName' => $m->platform_name,
            'url' => $m->url,
            'schedule' => $m->schedule,
            'timeContext' => $m->time_context,
            'host' => $m->host,
            'description' => $m->description,
            'meetingId' => $m->meeting_code,
            'passcode' => $m->passcode,
            'isLiveNow' => (bool) $m->is_live_now,
        ];
    }
}
