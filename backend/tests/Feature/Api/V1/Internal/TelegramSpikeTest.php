<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Internal;

use App\Enums\MembershipRole;
use App\Models\Community;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramSpikeTest extends TestCase
{
    use RefreshDatabase;

    public function test_telegram_spike_disabled_by_default(): void
    {
        config([
            'telegram_spike.enabled' => false,
            'telegram_spike.shared_secret' => 'anything',
        ]);

        $this->postJson('/api/v1/internal/telegram-spike/inbound', [
            'from' => '12345',
            'text' => 'hello',
        ], [
            'X-Spike-Secret' => 'anything',
        ])->assertNotFound();
    }

    public function test_telegram_spike_ask_returns_cited_reply(): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), '/conversation/classify')) {
                return Http::response(['intent' => 'knowledge', 'link_mode' => 'none'], 200);
            }
            if (str_contains($request->url(), '/retrieval/grounded-answer')) {
                return Http::response([
                    'query' => 'When is clinic open?',
                    'detected_language' => 'en',
                    'execution_time_ms' => 12.0,
                    'total_chunks_retrieved' => 1,
                    'validated_payload' => [
                        'state' => 'VERIFIED',
                        'answer' => 'Saturday 9am [E1].',
                        'confidence_score' => 0.9,
                        'needs_escalation' => false,
                        'escalation_reason' => null,
                        'citations' => [[
                            'evidence_id' => 'E1',
                            'chunk_id' => '72b079bc-25c2-4a0b-800f-8ee57de015c9',
                            'source_name' => 'Clinic',
                            'source_uri' => 'doc://clinic-hours',
                            'authority_tier' => 'official_announcement',
                            'exact_quote' => 'Saturday 9am',
                            'context_snippet' => 'Clinic opens Saturday 9am.',
                            'page_number' => 1,
                            'timestamp_seconds' => null,
                            'is_verified' => true,
                        ]],
                        'conflicts' => [],
                    ],
                ], 200);
            }

            return Http::response(['reply' => ''], 500);
        });

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['email' => 'demo@zak.test']);
        Membership::factory()->forCommunity($community, MembershipRole::Member)->create([
            'user_id' => $user->id,
        ]);

        \App\Models\KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'Clinic',
            'uri' => 'doc://clinic-hours',
            'source_type' => 'markdown',
            'authority_tier' => 'official_announcement',
            'lifecycle_status' => 'published',
            'language' => 'en',
            'content' => 'Clinic opens Saturday 9am.',
            'content_sha256' => hash('sha256', 'Clinic opens Saturday 9am.'),
            'published_at' => now(),
        ]);

        config([
            'telegram_spike.enabled' => true,
            'telegram_spike.shared_secret' => 'tg-test-secret',
            'telegram_spike.default_user_email' => 'demo@zak.test',
            'telegram_spike.default_community_id' => $community->id,
        ]);

        $this->postJson('/api/v1/internal/telegram-spike/inbound', [
            'from' => '999001',
            'text' => 'When is clinic open?',
        ], [
            'X-Spike-Secret' => 'tg-test-secret',
        ])
            ->assertOk()
            ->assertJsonPath('data.channel', 'telegram_spike')
            ->assertJsonPath('data.reply', 'Saturday 9am.'."\n\n".'(From clinic hours notes.)');
    }

    public function test_telegram_spike_person_ask_keeps_prose_not_evidence_urls(): void
    {
        $drive = 'https://drive.google.com/file/d/1E5RrwULX8zSjwxHFSxiQzCTtp20ulYQ8/view?usp=sharing';
        $teams = 'https://teams.microsoft.com/l/meetingrecap?driveItemId=abc123';
        $prose = 'Diane is an active member of the community who has shared meeting links and support notes.';

        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($drive, $teams, $prose) {
            if (str_contains($request->url(), '/conversation/classify')) {
                return Http::response(['intent' => 'knowledge', 'link_mode' => 'none'], 200);
            }
            if (str_contains($request->url(), '/retrieval/grounded-answer')) {
                return Http::response([
                    'query' => "Who's Diane?",
                    'detected_language' => 'en',
                    'execution_time_ms' => 12.0,
                    'total_chunks_retrieved' => 2,
                    'validated_payload' => [
                        'state' => 'GROUNDED',
                        'answer' => $prose,
                        'confidence_score' => 0.9,
                        'needs_escalation' => false,
                        'escalation_reason' => null,
                        'citations' => [[
                            'evidence_id' => 'E1',
                            'chunk_id' => '72b079bc-25c2-4a0b-800f-8ee57de015c9',
                            'source_name' => 'UniPods WhatsApp',
                            'source_uri' => 'whatsapp://export/fake',
                            'authority_tier' => 'community_discussion',
                            'exact_quote' => "Diane shared {$teams}",
                            'context_snippet' => "Also {$drive}",
                            'page_number' => null,
                            'timestamp_seconds' => null,
                            'is_verified' => true,
                        ]],
                        'conflicts' => [],
                    ],
                ], 200);
            }

            return Http::response(['reply' => ''], 500);
        });

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['email' => 'demo@zak.test']);
        Membership::factory()->forCommunity($community, MembershipRole::Member)->create([
            'user_id' => $user->id,
        ]);

        \App\Models\KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'UniPods WhatsApp',
            'uri' => 'whatsapp://export/fake',
            'source_type' => 'whatsapp',
            'authority_tier' => 'community_discussion',
            'lifecycle_status' => 'published',
            'language' => 'en',
            'content' => 'Diane notes',
            'content_sha256' => hash('sha256', 'Diane notes'),
            'published_at' => now(),
        ]);

        config([
            'telegram_spike.enabled' => true,
            'telegram_spike.shared_secret' => 'tg-test-secret',
            'telegram_spike.default_user_email' => 'demo@zak.test',
            'telegram_spike.default_community_id' => $community->id,
        ]);

        $reply = (string) $this->postJson('/api/v1/internal/telegram-spike/inbound', [
            'from' => '999011',
            'text' => "Who's Diane?",
        ], [
            'X-Spike-Secret' => 'tg-test-secret',
        ])
            ->assertOk()
            ->json('data.reply');

        $this->assertStringContainsString('Diane is an active member', $reply);
        $this->assertStringNotContainsString('Here they are', $reply);
        $this->assertStringNotContainsString($drive, $reply);
        $this->assertStringNotContainsString($teams, $reply);
        $this->assertStringNotContainsString('usp=sharing', $reply);
    }

    public function test_telegram_spike_meeting_links_exclude_profiles_and_keep_secondary_ask(): void
    {
        $meet = 'https://teams.microsoft.com/meet/419860837373470?p=abc';
        $light = 'https://teams.microsoft.com/light-meetings/launch?p=cM5gEphg2N9i9d0w7G&anon=true';
        $linkedin = 'https://www.linkedin.com/in/matsididi';
        $yt = 'https://youtu.be/yVji4ZQECVw';
        $prose = "I don't see a meeting scheduled for today in the shared notes.";

        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($meet, $light, $linkedin, $yt, $prose) {
            if (str_contains($request->url(), '/conversation/classify')) {
                return Http::response(['intent' => 'knowledge', 'link_mode' => 'meetings'], 200);
            }
            if (str_contains($request->url(), '/retrieval/grounded-answer')) {
                return Http::response([
                    'query' => 'Send me all meeting links ever sent. Also any meeting today?',
                    'detected_language' => 'en',
                    'execution_time_ms' => 12.0,
                    'total_chunks_retrieved' => 3,
                    'validated_payload' => [
                        'state' => 'GROUNDED',
                        'answer' => $prose,
                        'confidence_score' => 0.9,
                        'needs_escalation' => false,
                        'escalation_reason' => null,
                        'citations' => [[
                            'evidence_id' => 'E1',
                            'chunk_id' => '72b079bc-25c2-4a0b-800f-8ee57de015c9',
                            'source_name' => 'UniPods WhatsApp',
                            'source_uri' => 'whatsapp://export/fake',
                            'authority_tier' => 'community_discussion',
                            'exact_quote' => "Join here {$meet}",
                            'context_snippet' => "Also {$light}\nProfile {$linkedin}\nRecap {$yt}",
                            'page_number' => null,
                            'timestamp_seconds' => null,
                            'is_verified' => true,
                        ]],
                        'conflicts' => [],
                    ],
                ], 200);
            }

            return Http::response(['reply' => ''], 500);
        });

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['email' => 'demo@zak.test']);
        Membership::factory()->forCommunity($community, MembershipRole::Member)->create([
            'user_id' => $user->id,
        ]);

        \App\Models\KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'UniPods WhatsApp',
            'uri' => 'whatsapp://export/fake',
            'source_type' => 'whatsapp',
            'authority_tier' => 'community_discussion',
            'lifecycle_status' => 'published',
            'language' => 'en',
            'content' => 'meetings',
            'content_sha256' => hash('sha256', 'meetings'),
            'published_at' => now(),
        ]);

        config([
            'telegram_spike.enabled' => true,
            'telegram_spike.shared_secret' => 'tg-test-secret',
            'telegram_spike.default_user_email' => 'demo@zak.test',
            'telegram_spike.default_community_id' => $community->id,
        ]);

        $reply = (string) $this->postJson('/api/v1/internal/telegram-spike/inbound', [
            'from' => '999012',
            'text' => "Send me all meeting links ever sent.\n\nalso any meeting today?",
        ], [
            'X-Spike-Secret' => 'tg-test-secret',
        ])
            ->assertOk()
            ->json('data.reply');

        $this->assertStringContainsString('meeting join links', mb_strtolower($reply));
        $this->assertStringContainsString($meet, $reply);
        $this->assertStringContainsString($light, $reply);
        $this->assertStringContainsString("don't see a meeting scheduled for today", $reply);
        $this->assertStringNotContainsString($linkedin, $reply);
        $this->assertStringNotContainsString($yt, $reply);
        $this->assertStringNotContainsString('Session recording', $reply);
    }

    public function test_telegram_spike_hello_is_conversational_without_retrieval(): void
    {
        // Social turns may call /conversation/reply; they must not hit retrieval.
        Http::fake([
            '*/conversation/reply' => Http::response(['reply' => ''], 500),
            '*/retrieval/*' => Http::response(['error' => 'should not call retrieval'], 500),
        ]);

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['email' => 'demo@zak.test']);
        Membership::factory()->forCommunity($community, MembershipRole::Member)->create([
            'user_id' => $user->id,
        ]);

        config([
            'telegram_spike.enabled' => true,
            'telegram_spike.shared_secret' => 'tg-test-secret',
            'telegram_spike.default_user_email' => 'demo@zak.test',
            'telegram_spike.default_community_id' => $community->id,
        ]);

        $response = $this->postJson('/api/v1/internal/telegram-spike/inbound', [
            'from' => '999002',
            'text' => 'Hello',
        ], [
            'X-Spike-Secret' => 'tg-test-secret',
        ])
            ->assertOk()
            ->assertJsonPath('data.channel', 'telegram_spike');

        $reply = (string) $response->json('data.reply');
        $this->assertStringContainsString('Good to hear', $reply);
        $this->assertStringNotContainsString('community notes', mb_strtolower($reply));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/retrieval/'));
    }

    public function test_telegram_spike_sanitizes_malformed_utf8_in_reply(): void
    {
        $controller = $this->app->make(\App\Http\Controllers\Api\V1\Internal\TelegramSpikeController::class);
        $method = new \ReflectionMethod($controller, 'utf8Safe');

        $clean = $method->invoke($controller, "Clinic opens Saturday 9am.\xB1 more text");

        $this->assertTrue(mb_check_encoding($clean, 'UTF-8'));
        $this->assertStringContainsString('Clinic opens Saturday 9am.', $clean);
        $this->assertStringContainsString('more text', $clean);
        $this->assertSame(
            JSON_ERROR_NONE,
            json_last_error_msg() === 'No error' || json_encode($clean) !== false
                ? JSON_ERROR_NONE
                : json_last_error()
        );
        $this->assertNotFalse(json_encode(['reply' => $clean]));
    }

    public function test_telegram_spike_recovers_openable_urls_from_citations(): void
    {
        $recordingUrl = 'https://teams.microsoft.com/l/meetingrecap?driveItemId=abc123';
        $youtubeUrl = 'https://youtu.be/-6G7LXiu47o';
        $joinUrl = 'https://teams.microsoft.com/meet/419860837373470?p=jYchWkDZnC4etsclnK';
        $legacyJoinUrl = 'https://teams.microsoft.com/l/meetup-join/19%3ameeting_fake/0';

        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($joinUrl, $recordingUrl, $youtubeUrl, $legacyJoinUrl) {
            if (str_contains($request->url(), '/conversation/classify')) {
                return Http::response(['intent' => 'knowledge', 'link_mode' => 'recordings'], 200);
            }
            if (str_contains($request->url(), '/retrieval/grounded-answer')) {
                return Http::response([
                    'query' => 'Send me all the recording links',
                    'detected_language' => 'en',
                    'execution_time_ms' => 12.0,
                    'total_chunks_retrieved' => 2,
                    'validated_payload' => [
                        'state' => 'POSSIBLE',
                        'answer' => "You can find recordings here:\n1. {$joinUrl}\n2. {$recordingUrl}",
                        'confidence_score' => 0.9,
                        'needs_escalation' => false,
                        'escalation_reason' => null,
                        'citations' => [[
                            'evidence_id' => 'E1',
                            'chunk_id' => '72b079bc-25c2-4a0b-800f-8ee57de015c9',
                            'source_name' => 'UniPods WhatsApp',
                            'source_uri' => 'whatsapp://export/fake',
                            'authority_tier' => 'community_discussion',
                            'exact_quote' => "MIT onboarding: {$recordingUrl}",
                            'context_snippet' => "Wadhwani Module 1 coaching/Q&A - 17 September {$youtubeUrl}\n"
                                ."Live coaching session with Q&A on Module 1, Problem Statement via this link - Join {$joinUrl}\n"
                                ."Drive file https://drive.google.com/file/d/1E5RrwULX8zSjwxHFSxiQzCTtp20ulYQ8/view\n"
                                ."Same drive https://drive.google.com/file/d/1E5RrwULX8zSjwxHFSxiQzCTtp20ulYQ8/view?usp=sharing\n"
                                ."Also {$legacyJoinUrl}",
                            'page_number' => null,
                            'timestamp_seconds' => null,
                            'is_verified' => true,
                        ]],
                        'conflicts' => [],
                    ],
                ], 200);
            }

            return Http::response(['reply' => ''], 500);
        });

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['email' => 'demo@zak.test']);
        Membership::factory()->forCommunity($community, MembershipRole::Member)->create([
            'user_id' => $user->id,
        ]);

        \App\Models\KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'UniPods WhatsApp',
            'uri' => 'whatsapp://export/fake',
            'source_type' => 'whatsapp',
            'authority_tier' => 'community_discussion',
            'lifecycle_status' => 'published',
            'language' => 'en',
            'content' => 'recordings',
            'content_sha256' => hash('sha256', 'recordings'),
            'published_at' => now(),
        ]);

        config([
            'telegram_spike.enabled' => true,
            'telegram_spike.shared_secret' => 'tg-test-secret',
            'telegram_spike.default_user_email' => 'demo@zak.test',
            'telegram_spike.default_community_id' => $community->id,
        ]);

        $reply = (string) $this->postJson('/api/v1/internal/telegram-spike/inbound', [
            'from' => '999003',
            'text' => 'Send me all the recording links of the sessions',
        ], [
            'X-Spike-Secret' => 'tg-test-secret',
        ])
            ->assertOk()
            ->json('data.reply');

        $this->assertStringContainsString($recordingUrl, $reply);
        $this->assertStringContainsString($youtubeUrl, $reply);
        $this->assertStringContainsString('drive.google.com/file/d/1E5RrwULX8zSjwxHFSxiQzCTtp20ulYQ8/view', $reply);
        $this->assertSame(1, substr_count($reply, '1E5RrwULX8zSjwxHFSxiQzCTtp20ulYQ8'));
        $this->assertStringNotContainsString('usp=sharing', $reply);
        $this->assertStringNotContainsString($joinUrl, $reply);
        $this->assertStringNotContainsString($legacyJoinUrl, $reply);
        $this->assertStringNotContainsString('whatsapp://export/fake', $reply);
        $this->assertStringNotContainsString('I found', $reply);
        $this->assertStringNotContainsString('via this link', $reply);
        $this->assertStringContainsString('Here are the session recordings:', $reply);
        $this->assertStringContainsString('MIT onboarding', $reply);
        $this->assertStringContainsString('Wadhwani Module 1 coaching/Q&A - 17 September', $reply);
        $this->assertMatchesRegularExpression('/1\. MIT onboarding\nhttps:\/\//', $reply);
    }

    public function test_telegram_spike_french_recordings_reject_linkedin_noise(): void
    {
        $youtubeUrl = 'https://youtu.be/-6G7LXiu47o';
        $linkedin = 'https://www.linkedin.com/in/ismailaseck/';
        $lectureHome = 'https://weblab.t.u-tokyo.ac.jp/en/lecture/gci/';

        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($youtubeUrl, $linkedin, $lectureHome) {
            if (str_contains($request->url(), '/conversation/classify')) {
                return Http::response(['intent' => 'knowledge', 'link_mode' => 'recordings'], 200);
            }
            if (str_contains($request->url(), '/retrieval/grounded-answer')) {
                return Http::response([
                    'query' => 'Envoyez-moi les liens vers les enregistrements',
                    'detected_language' => 'fr',
                    'execution_time_ms' => 12.0,
                    'total_chunks_retrieved' => 2,
                    'validated_payload' => [
                        'state' => 'POSSIBLE',
                        'answer' => "Here they are:\n\n1. onjour, ça se comprend\n{$lectureHome}\n\n2. Happy to be here\n{$linkedin}",
                        'confidence_score' => 0.9,
                        'needs_escalation' => false,
                        'escalation_reason' => null,
                        'citations' => [[
                            'evidence_id' => 'E1',
                            'chunk_id' => '72b079bc-25c2-4a0b-800f-8ee57de015c9',
                            'source_name' => 'UniPods WhatsApp',
                            'source_uri' => 'whatsapp://export/fake',
                            'authority_tier' => 'community_discussion',
                            'exact_quote' => "Module 1 recording {$youtubeUrl}",
                            'context_snippet' => "Also noise {$linkedin} and {$lectureHome}",
                            'page_number' => null,
                            'timestamp_seconds' => null,
                            'is_verified' => true,
                        ]],
                        'conflicts' => [],
                    ],
                ], 200);
            }

            return Http::response(['reply' => ''], 500);
        });

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['email' => 'demo@zak.test']);
        Membership::factory()->forCommunity($community, MembershipRole::Member)->create([
            'user_id' => $user->id,
        ]);

        \App\Models\KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'UniPods WhatsApp',
            'uri' => 'whatsapp://export/fake',
            'source_type' => 'whatsapp',
            'authority_tier' => 'community_discussion',
            'lifecycle_status' => 'published',
            'language' => 'en',
            'content' => 'recordings',
            'content_sha256' => hash('sha256', 'recordings'),
            'published_at' => now(),
        ]);

        config([
            'telegram_spike.enabled' => true,
            'telegram_spike.shared_secret' => 'tg-test-secret',
            'telegram_spike.default_user_email' => 'demo@zak.test',
            'telegram_spike.default_community_id' => $community->id,
        ]);

        $reply = (string) $this->postJson('/api/v1/internal/telegram-spike/inbound', [
            'from' => '999004',
            'text' => 'Envoyez-moi les liens vers les enregistrements, s\'il vous plaît',
        ], [
            'X-Spike-Secret' => 'tg-test-secret',
        ])
            ->assertOk()
            ->json('data.reply');

        $this->assertStringContainsString($youtubeUrl, $reply);
        $this->assertStringContainsString('Voici les enregistrements des sessions :', $reply);
        $this->assertStringNotContainsString($linkedin, $reply);
        $this->assertStringNotContainsString($lectureHome, $reply);
        $this->assertStringNotContainsString('Here they are', $reply);
    }

    public function test_telegram_spike_leaves_blank_line_after_heading_before_list(): void
    {
        Http::fake([
            '*/conversation/classify' => Http::response(['intent' => 'knowledge'], 200),
            '*/retrieval/grounded-answer' => Http::response([
                'query' => 'Any updates today?',
                'detected_language' => 'en',
                'execution_time_ms' => 12.0,
                'total_chunks_retrieved' => 1,
                'validated_payload' => [
                    'state' => 'POSSIBLE',
                    'answer' => "Today, there are no new official announcements. Here are the key points shared:\n"
                        ."1. No scheduled programme session today.\n"
                        ."2. Bot testing continues.\n"
                        ."If you need more details, let me know!",
                    'confidence_score' => 0.9,
                    'needs_escalation' => false,
                    'escalation_reason' => null,
                    'citations' => [[
                        'evidence_id' => 'E1',
                        'chunk_id' => '72b079bc-25c2-4a0b-800f-8ee57de015c9',
                        'source_name' => 'UniPods WhatsApp',
                        'source_uri' => 'whatsapp://export/fake',
                        'authority_tier' => 'community_discussion',
                        'exact_quote' => 'No scheduled programme session today.',
                        'context_snippet' => 'Bot testing continues.',
                        'page_number' => null,
                        'timestamp_seconds' => null,
                        'is_verified' => true,
                    ]],
                    'conflicts' => [],
                ],
            ], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['email' => 'demo@zak.test']);
        Membership::factory()->forCommunity($community, MembershipRole::Member)->create([
            'user_id' => $user->id,
        ]);

        \App\Models\KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'UniPods WhatsApp',
            'uri' => 'whatsapp://export/fake',
            'source_type' => 'whatsapp',
            'authority_tier' => 'community_discussion',
            'lifecycle_status' => 'published',
            'language' => 'en',
            'content' => 'updates',
            'content_sha256' => hash('sha256', 'updates'),
            'published_at' => now(),
        ]);

        config([
            'telegram_spike.enabled' => true,
            'telegram_spike.shared_secret' => 'tg-test-secret',
            'telegram_spike.default_user_email' => 'demo@zak.test',
            'telegram_spike.default_community_id' => $community->id,
        ]);

        $reply = (string) $this->postJson('/api/v1/internal/telegram-spike/inbound', [
            'from' => '999004',
            'text' => 'Any updates today?',
        ], [
            'X-Spike-Secret' => 'tg-test-secret',
        ])
            ->assertOk()
            ->json('data.reply');

        $this->assertStringContainsString(
            "Here are the key points shared:\n\n1. No scheduled programme session today.",
            $reply
        );
        $this->assertStringContainsString(
            "2. Bot testing continues.\n\nIf you need more details, let me know!",
            $reply
        );
    }

    public function test_telegram_spike_keeps_year_with_date(): void
    {
        Http::fake([
            '*/conversation/classify' => Http::response(['intent' => 'knowledge'], 200),
            '*/retrieval/grounded-answer' => Http::response([
                'query' => 'When is the hackathon deadline?',
                'detected_language' => 'en',
                'execution_time_ms' => 12.0,
                'total_chunks_retrieved' => 1,
                'validated_payload' => [
                    'state' => 'POSSIBLE',
                    'answer' => 'The hackathon deadline is Thursday, September 24, 2026. This is the final submission date.',
                    'confidence_score' => 0.9,
                    'needs_escalation' => false,
                    'escalation_reason' => null,
                    'citations' => [[
                        'evidence_id' => 'E1',
                        'chunk_id' => '72b079bc-25c2-4a0b-800f-8ee57de015c9',
                        'source_name' => 'UniPods WhatsApp',
                        'source_uri' => 'whatsapp://export/fake',
                        'authority_tier' => 'community_discussion',
                        'exact_quote' => 'deadline September 24, 2026',
                        'context_snippet' => 'hackathon deadline',
                        'page_number' => null,
                        'timestamp_seconds' => null,
                        'is_verified' => true,
                    ]],
                    'conflicts' => [],
                ],
            ], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['email' => 'demo@zak.test']);
        Membership::factory()->forCommunity($community, MembershipRole::Member)->create([
            'user_id' => $user->id,
        ]);

        \App\Models\KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'UniPods WhatsApp',
            'uri' => 'whatsapp://export/fake',
            'source_type' => 'whatsapp',
            'authority_tier' => 'community_discussion',
            'lifecycle_status' => 'published',
            'language' => 'en',
            'content' => 'deadline',
            'content_sha256' => hash('sha256', 'deadline'),
            'published_at' => now(),
        ]);

        config([
            'telegram_spike.enabled' => true,
            'telegram_spike.shared_secret' => 'tg-test-secret',
            'telegram_spike.default_user_email' => 'demo@zak.test',
            'telegram_spike.default_community_id' => $community->id,
        ]);

        $reply = (string) $this->postJson('/api/v1/internal/telegram-spike/inbound', [
            'from' => '999006',
            'text' => 'When is the hackathon deadline?',
        ], [
            'X-Spike-Secret' => 'tg-test-secret',
        ])
            ->assertOk()
            ->json('data.reply');

        $this->assertStringContainsString('September 24, 2026', $reply);
        $this->assertStringNotContainsString("24,\n\n2026", $reply);
        $this->assertStringNotContainsString("24,\n2026", $reply);
    }
}
