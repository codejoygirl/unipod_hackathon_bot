<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipRole;
use App\Models\Community;
use App\Models\MemberVaultDocument;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class WebChatVaultTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('zak_web_chat.enabled', true);
        Storage::fake('local');
    }

    public function test_owner_can_upload_ask_and_generate_without_community_retrieval(): void
    {
        $groundedHits = 0;
        $replyHits = 0;

        Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$groundedHits, &$replyHits) {
            if (str_contains($request->url(), '/retrieval/grounded-answer')) {
                $groundedHits++;

                return Http::response([
                    'query' => 'leaked',
                    'detected_language' => 'en',
                    'execution_time_ms' => 1.0,
                    'total_chunks_retrieved' => 1,
                    'validated_payload' => [
                        'state' => 'VERIFIED',
                        'answer' => 'Community syllabus leak.',
                        'confidence_score' => 0.9,
                        'needs_escalation' => false,
                        'escalation_reason' => null,
                        'citations' => [],
                        'conflicts' => [],
                    ],
                ], 200);
            }

            if (str_contains($request->url(), '/conversation/document-reply')) {
                $replyHits++;

                return Http::response([
                    'reply' => 'Founder is Ada. Source: founder-notes.txt',
                ], 200);
            }

            return Http::response(['ok' => true], 200);
        });

        [$user, $community] = $this->seedMember();
        $phone = '2348011111111';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $this->getJson('/api/v1/web-chat/vault?phone='.$phone)
            ->assertOk()
            ->assertJsonPath('data.kind', 'personal')
            ->assertJsonPath('data.documents', []);

        $this->post('/api/v1/web-chat/vault/documents', [
            'phone' => $phone,
            'file' => UploadedFile::fake()->createWithContent(
                'founder-notes.txt',
                'Founder is Ada. Secret token ALPHA_VAULT_ONLY.',
            ),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.filename', 'founder-notes.txt')
            ->assertJsonPath('data.status', 'ready');

        $this->assertDatabaseHas('member_vault_documents', [
            'owner_phone' => $phone,
            'filename' => 'founder-notes.txt',
        ]);

        $this->postJson('/api/v1/web-chat/vault/ask', [
            'phone' => $phone,
            'query' => 'Who is the founder?',
        ])
            ->assertOk()
            ->assertJsonPath('data.answer', 'Founder is Ada. Source: founder-notes.txt')
            ->assertJsonPath('data.used_filenames.0', 'founder-notes.txt');

        $this->postJson('/api/v1/web-chat/vault/generate', [
            'phone' => $phone,
            'kind' => 'pitch_plan',
            'instruction' => 'Keep it short',
        ])
            ->assertOk()
            ->assertJsonPath('data.artefact.kind', 'pitch_plan');

        $this->assertDatabaseHas('member_vault_artefacts', [
            'owner_phone' => $phone,
            'kind' => 'pitch_plan',
        ]);

        $this->assertSame(0, $groundedHits);
        $this->assertGreaterThanOrEqual(2, $replyHits);

        $this->postJson('/api/v1/web-chat/ask', [
            'phone' => $phone,
            'query' => 'What is the syllabus?',
        ])->assertOk();

        $this->assertSame(1, $groundedHits);
    }

    public function test_other_phone_cannot_read_or_delete_owner_files(): void
    {
        Http::fake([
            '*/conversation/document-reply' => Http::response(['reply' => 'should not leak'], 200),
            '*/retrieval/grounded-answer' => Http::response(['ok' => true], 200),
        ]);

        [$user, $community] = $this->seedMember();
        $owner = '2348011111111';
        $other = '2348099999999';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $this->post('/api/v1/web-chat/vault/documents', [
            'phone' => $owner,
            'file' => UploadedFile::fake()->createWithContent(
                'private-notes.txt',
                'Owner-only line BETA_VAULT_SECRET.',
            ),
        ], ['Accept' => 'application/json'])->assertCreated();

        $docId = (string) MemberVaultDocument::query()->where('owner_phone', $owner)->value('id');

        $this->getJson('/api/v1/web-chat/vault?phone='.$other)
            ->assertOk()
            ->assertJsonPath('data.documents', [])
            ->assertJsonMissing(['BETA_VAULT_SECRET']);

        $this->deleteJson('/api/v1/web-chat/vault/documents/'.$docId.'?phone='.$other)
            ->assertNotFound();

        $this->assertDatabaseHas('member_vault_documents', [
            'id' => $docId,
            'owner_phone' => $owner,
        ]);

        $this->postJson('/api/v1/web-chat/vault/ask', [
            'phone' => $other,
            'query' => 'What is the secret?',
        ])
            ->assertOk()
            ->assertJsonPath('data.used_filenames', [])
            ->assertJsonMissing(['BETA_VAULT_SECRET']);
    }

    public function test_owner_can_delete_own_document(): void
    {
        [$user, $community] = $this->seedMember();
        $phone = '2348011111111';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $this->post('/api/v1/web-chat/vault/documents', [
            'phone' => $phone,
            'file' => UploadedFile::fake()->createWithContent('notes.md', '# Hello vault'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $docId = (string) MemberVaultDocument::query()->where('owner_phone', $phone)->value('id');

        $this->deleteJson('/api/v1/web-chat/vault/documents/'.$docId.'?phone='.$phone)
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('member_vault_documents', ['id' => $docId]);
    }

    public function test_rejects_duplicate_filename(): void
    {
        [$user, $community] = $this->seedMember();
        $phone = '2348011111111';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $this->post('/api/v1/web-chat/vault/documents', [
            'phone' => $phone,
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'First copy.'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->post('/api/v1/web-chat/vault/documents', [
            'phone' => $phone,
            'file' => UploadedFile::fake()->createWithContent('Notes.txt', 'Second copy.'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonFragment(['A file named notes.txt is already in Library.']);

        $this->assertSame(1, MemberVaultDocument::query()->where('owner_phone', $phone)->count());
    }

    public function test_rejects_disallowed_file_type(): void
    {
        [$user, $community] = $this->seedMember();
        $phone = '2348011111111';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $this->post('/api/v1/web-chat/vault/documents', [
            'phone' => $phone,
            'file' => UploadedFile::fake()->create('payload.exe', 20, 'application/x-msdownload'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertDatabaseCount('member_vault_documents', 0);
    }

    public function test_specified_file_is_marked_selected_and_library_stays_visible(): void
    {
        Http::fake([
            '*/conversation/document-reply' => Http::response(['reply' => 'From the chosen file only.'], 200),
            '*/retrieval/grounded-answer' => Http::response(['ok' => true], 200),
        ]);

        [$user, $community] = $this->seedMember();
        $phone = '2348011111111';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $this->post('/api/v1/web-chat/vault/documents', [
            'phone' => $phone,
            'files' => [
                UploadedFile::fake()->createWithContent('orchard.txt', 'Avocado orchard harvest notes.'),
                UploadedFile::fake()->createWithContent('syllabus.txt', 'Week 3 syllabus and lecture list.'),
            ],
        ], ['Accept' => 'application/json'])->assertCreated();

        $orchardId = (string) MemberVaultDocument::query()->where('filename', 'orchard.txt')->value('id');

        $used = $this->postJson('/api/v1/web-chat/vault/ask', [
            'phone' => $phone,
            'query' => 'What is in week 3?',
            'document_ids' => [$orchardId],
        ])
            ->assertOk()
            ->json('data.used_filenames');
        $this->assertSame('orchard.txt', $used[0] ?? null);
        $this->assertContains('syllabus.txt', $used);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            if (! str_contains($request->url(), '/conversation/document-reply')) {
                return false;
            }

            $body = $request->body();

            if (! str_contains($body, 'orchard.txt') || ! str_contains($body, 'syllabus.txt')) {
                return false;
            }

            $payload = json_decode($body, true);
            if (! is_array($payload)) {
                return false;
            }
            $library = $payload['library'] ?? [];
            $orchard = collect($library)->firstWhere('filename', 'orchard.txt');
            $syllabus = collect($library)->firstWhere('filename', 'syllabus.txt');

            return is_array($orchard) && ($orchard['selected'] ?? false) === true
                && is_array($syllabus) && ($syllabus['selected'] ?? true) === false;
        });
    }

    public function test_unspecified_ask_searches_relevant_file(): void
    {
        Http::fake([
            '*/conversation/document-reply' => Http::response(['reply' => 'Harvest is in the orchard notes.'], 200),
        ]);

        [$user, $community] = $this->seedMember();
        $phone = '2348011111111';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        foreach (range(1, 6) as $i) {
            $this->post('/api/v1/web-chat/vault/documents', [
                'phone' => $phone,
                'file' => UploadedFile::fake()->createWithContent("filler-{$i}.txt", "Generic filler document number {$i}."),
            ], ['Accept' => 'application/json'])->assertCreated();
        }

        $this->post('/api/v1/web-chat/vault/documents', [
            'phone' => $phone,
            'file' => UploadedFile::fake()->createWithContent('harvest.txt', 'Avocado harvest starts in October.'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $used = $this->postJson('/api/v1/web-chat/vault/ask', [
            'phone' => $phone,
            'query' => 'When does avocado harvest start?',
        ])
            ->assertOk()
            ->json('data.used_filenames');
        $this->assertContains('harvest.txt', $used);
    }

    public function test_cannot_ask_using_another_phone_document_id(): void
    {
        Http::fake([
            '*/conversation/document-reply' => Http::response(['reply' => 'should not leak'], 200),
        ]);

        [$user, $community] = $this->seedMember();
        $owner = '2348011111111';
        $other = '2348099999999';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $this->post('/api/v1/web-chat/vault/documents', [
            'phone' => $owner,
            'file' => UploadedFile::fake()->createWithContent('private.txt', 'Owner-only GAMMA_VAULT_SECRET.'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $docId = (string) MemberVaultDocument::query()->where('owner_phone', $owner)->value('id');

        $this->postJson('/api/v1/web-chat/vault/ask', [
            'phone' => $other,
            'query' => 'What is the secret?',
            'document_ids' => [$docId],
        ])
            ->assertOk()
            ->assertJsonPath('data.used_filenames', []);

        Http::assertNotSent(function (\Illuminate\Http\Client\Request $request): bool {
            return str_contains($request->url(), '/conversation/document-reply')
                && str_contains($request->body(), 'GAMMA_VAULT_SECRET');
        });
    }

    /**
     * @return array{0: User, 1: Community}
     */
    private function seedMember(): array
    {
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();
        Membership::factory()->forCommunity($community, MembershipRole::Member)->create([
            'user_id' => $user->id,
        ]);

        return [$user, $community];
    }
}
