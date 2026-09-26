<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipRole;
use App\Models\Community;
use App\Models\MemberProject;
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

final class WebChatProjectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('zak_web_chat.enabled', true);
        Storage::fake('local');
    }

    public function test_owner_can_create_project_chat_and_keep_history(): void
    {
        Http::fake([
            '*/conversation/document-reply' => Http::response(['reply' => 'From the project files.'], 200),
            '*/retrieval/grounded-answer' => Http::response(['ok' => true], 200),
        ]);

        [$user, $community] = $this->seedMember();
        $phone = '2348011111111';
        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $created = $this->postJson('/api/v1/web-chat/projects', [
            'phone' => $phone,
            'name' => 'Fellowship pack',
        ])->assertCreated();

        $projectId = (string) $created->json('data.project.id');
        $chatId = (string) $created->json('data.chats.0.id');

        $this->post('/api/v1/web-chat/projects/'.$projectId.'/files', [
            'phone' => $phone,
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'Ada is the founder.'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->postJson('/api/v1/web-chat/projects/'.$projectId.'/chats/'.$chatId.'/ask', [
            'phone' => $phone,
            'query' => 'Who is the founder?',
        ])
            ->assertOk()
            ->assertJsonPath('data.answer', 'From the project files.');

        $this->getJson('/api/v1/web-chat/projects/'.$projectId.'/chats/'.$chatId.'?phone='.$phone)
            ->assertOk()
            ->assertJsonPath('data.messages.0.role', 'user')
            ->assertJsonPath('data.messages.1.role', 'assistant');

        $this->postJson('/api/v1/web-chat/projects/'.$projectId.'/chats', [
            'phone' => $phone,
            'title' => 'Second angle',
        ])->assertUnprocessable();

        $this->getJson('/api/v1/web-chat/projects/'.$projectId.'?phone='.$phone)
            ->assertOk()
            ->assertJsonCount(1, 'data.chats');

        Http::assertNotSent(fn (\Illuminate\Http\Client\Request $request): bool => str_contains($request->url(), '/retrieval/grounded-answer'));
    }

    public function test_regenerate_replaces_assistant_message_without_duplicating_user(): void
    {
        Http::fake([
            '*/conversation/document-reply' => Http::sequence()
                ->push(['reply' => 'First draft.'], 200)
                ->push(['reply' => 'Follow-up draft.'], 200)
                ->push(['reply' => 'Updated draft.'], 200),
        ]);

        [$user, $community] = $this->seedMember();
        $phone = '2348011111111';
        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $created = $this->postJson('/api/v1/web-chat/projects', [
            'phone' => $phone,
            'name' => 'Regenerate pack',
        ])->assertCreated();

        $projectId = (string) $created->json('data.project.id');
        $chatId = (string) $created->json('data.chats.0.id');

        $this->post('/api/v1/web-chat/projects/'.$projectId.'/files', [
            'phone' => $phone,
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'Ada is the founder.'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $first = $this->postJson('/api/v1/web-chat/projects/'.$projectId.'/chats/'.$chatId.'/ask', [
            'phone' => $phone,
            'query' => 'Who is the founder?',
        ])
            ->assertOk()
            ->assertJsonPath('data.answer', 'First draft.');

        $messageId = (string) $first->json('data.message.id');

        $this->postJson('/api/v1/web-chat/projects/'.$projectId.'/chats/'.$chatId.'/ask', [
            'phone' => $phone,
            'query' => 'Say more.',
        ])->assertOk()->assertJsonPath('data.answer', 'Follow-up draft.');

        $this->postJson('/api/v1/web-chat/projects/'.$projectId.'/chats/'.$chatId.'/ask', [
            'phone' => $phone,
            'query' => 'Who is the founder?',
            'replace_message_id' => $messageId,
        ])
            ->assertOk()
            ->assertJsonPath('data.answer', 'Updated draft.');

        $this->getJson('/api/v1/web-chat/projects/'.$projectId.'/chats/'.$chatId.'?phone='.$phone)
            ->assertOk()
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.0.role', 'user')
            ->assertJsonPath('data.messages.0.body', 'Who is the founder?')
            ->assertJsonPath('data.messages.1.role', 'assistant')
            ->assertJsonPath('data.messages.1.body', 'Updated draft.');
    }

    public function test_follow_up_ask_forwards_prior_thread_for_language(): void
    {
        Http::fake([
            '*/conversation/document-reply' => Http::response(['reply' => 'From the project files.'], 200),
        ]);

        [$user, $community] = $this->seedMember();
        $phone = '2348011111111';
        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $created = $this->postJson('/api/v1/web-chat/projects', [
            'phone' => $phone,
            'name' => 'Language lock',
        ])->assertCreated();

        $projectId = (string) $created->json('data.project.id');
        $chatId = (string) $created->json('data.chats.0.id');

        $this->post('/api/v1/web-chat/projects/'.$projectId.'/files', [
            'phone' => $phone,
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'Ada is the founder.'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->postJson('/api/v1/web-chat/projects/'.$projectId.'/chats/'.$chatId.'/ask', [
            'phone' => $phone,
            'query' => 'Who is the founder?',
        ])->assertOk();

        $this->postJson('/api/v1/web-chat/projects/'.$projectId.'/chats/'.$chatId.'/ask', [
            'phone' => $phone,
            'query' => 'ok',
        ])->assertOk();

        $payloads = collect(Http::recorded())
            ->map(fn (array $pair): \Illuminate\Http\Client\Request => $pair[0])
            ->filter(fn (\Illuminate\Http\Client\Request $request): bool => str_contains($request->url(), '/conversation/document-reply'))
            ->map(fn (\Illuminate\Http\Client\Request $request): array => json_decode($request->body(), true) ?: [])
            ->values();

        $this->assertCount(2, $payloads);
        $this->assertSame('Who is the founder?', $payloads[0]['question'] ?? null);
        $this->assertArrayNotHasKey('prior_question', $payloads[0]);
        $this->assertSame('ok', $payloads[1]['question'] ?? null);
        $this->assertSame('Who is the founder?', $payloads[1]['prior_question'] ?? null);
        $this->assertSame('From the project files.', $payloads[1]['prior_answer_excerpt'] ?? null);
        $this->assertSame('user', $payloads[1]['thread'][0]['role'] ?? null);
        $this->assertSame('Who is the founder?', $payloads[1]['thread'][0]['text'] ?? null);
        $this->assertSame('assistant', $payloads[1]['thread'][1]['role'] ?? null);
        $this->assertSame('From the project files.', $payloads[1]['thread'][1]['text'] ?? null);
    }

    public function test_follow_up_can_export_prior_draft_as_pdf(): void
    {
        $letter = "Dear Hiring Manager,\n\nI am writing to apply for the role.\n\nWarm regards,\nAda";
        Http::fake([
            '*/conversation/document-reply' => Http::sequence()
                ->push(['reply' => $letter."\n\nWould you like this pitch converted to a PDF?"], 200)
                ->push(['reply' => $letter, 'export' => 'pdf'], 200),
        ]);

        [$user, $community] = $this->seedMember();
        $phone = '2348011111111';
        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $created = $this->postJson('/api/v1/web-chat/projects', [
            'phone' => $phone,
            'name' => 'Cover letter',
        ])->assertCreated();

        $projectId = (string) $created->json('data.project.id');
        $chatId = (string) $created->json('data.chats.0.id');

        $this->post('/api/v1/web-chat/projects/'.$projectId.'/files', [
            'phone' => $phone,
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'Ada is a senior engineer.'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->postJson('/api/v1/web-chat/projects/'.$projectId.'/chats/'.$chatId.'/ask', [
            'phone' => $phone,
            'query' => 'Write a cover letter.',
        ])->assertOk();

        $exported = $this->postJson('/api/v1/web-chat/projects/'.$projectId.'/chats/'.$chatId.'/ask', [
            'phone' => $phone,
            'query' => 'yes',
        ])
            ->assertOk()
            ->assertJsonPath('data.artefact.kind', 'pdf');

        $payloads = collect(Http::recorded())
            ->map(fn (array $pair): \Illuminate\Http\Client\Request => $pair[0])
            ->filter(fn (\Illuminate\Http\Client\Request $request): bool => str_contains($request->url(), '/conversation/document-reply'))
            ->map(fn (\Illuminate\Http\Client\Request $request): array => json_decode($request->body(), true) ?: [])
            ->values();
        $this->assertTrue((bool) ($payloads[1]['last_turn_was_question'] ?? false));
        $this->assertSame('yes', $payloads[1]['question'] ?? null);

        $artefactId = (string) $exported->json('data.artefact.id');
        $this->get('/api/v1/web-chat/vault/artefacts/'.$artefactId.'/download?phone='.$phone)
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertSee('%PDF', false)
            ->assertSee('Dear Hiring Manager', false);
    }

    public function test_project_generate_saves_downloadable_artefact(): void
    {
        Http::fake([
            '*/conversation/document-reply' => Http::response(['reply' => 'Pitch plan from files.'], 200),
        ]);

        [$user, $community] = $this->seedMember();
        $phone = '2348011111111';
        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $created = $this->postJson('/api/v1/web-chat/projects', [
            'phone' => $phone,
            'name' => 'Pitch pack',
        ])->assertCreated();

        $projectId = (string) $created->json('data.project.id');
        $chatId = (string) $created->json('data.chats.0.id');

        $this->post('/api/v1/web-chat/projects/'.$projectId.'/files', [
            'phone' => $phone,
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'Ada is the founder.'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $asked = $this->postJson('/api/v1/web-chat/projects/'.$projectId.'/chats/'.$chatId.'/ask', [
            'phone' => $phone,
            'query' => 'Write a pitch plan.',
            'kind' => 'pitch_plan',
        ])
            ->assertOk()
            ->assertJsonPath('data.answer', 'Pitch plan from files.')
            ->assertJsonPath('data.artefact.kind', 'pitch_plan');

        $artefactId = (string) $asked->json('data.artefact.id');

        $this->get('/api/v1/web-chat/vault/artefacts/'.$artefactId.'/download?phone='.$phone)
            ->assertOk()
            ->assertHeader('content-disposition')
            ->assertSee('Pitch plan from files.');

        $this->get('/api/v1/web-chat/vault/artefacts/'.$artefactId.'/download?phone=2348099999999')
            ->assertNotFound();
    }

    public function test_other_phone_cannot_open_project(): void
    {
        [$user, $community] = $this->seedMember();
        $owner = '2348011111111';
        $other = '2348099999999';
        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $created = $this->postJson('/api/v1/web-chat/projects', [
            'phone' => $owner,
            'name' => 'Private pack',
        ])->assertCreated();

        $projectId = (string) $created->json('data.project.id');

        $this->getJson('/api/v1/web-chat/projects/'.$projectId.'?phone='.$other)->assertNotFound();
        $this->getJson('/api/v1/web-chat/projects?phone='.$other)
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->assertSame(1, MemberProject::query()->where('owner_phone', $owner)->count());
        $this->assertSame(0, MemberVaultDocument::query()->where('owner_phone', $other)->count());
    }

    public function test_rejects_duplicate_project_name(): void
    {
        [$user, $community] = $this->seedMember();
        $phone = '2348011111111';
        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $this->postJson('/api/v1/web-chat/projects', [
            'phone' => $phone,
            'name' => 'Fellowship pack',
        ])->assertCreated();

        $this->postJson('/api/v1/web-chat/projects', [
            'phone' => $phone,
            'name' => 'fellowship pack',
        ])
            ->assertUnprocessable()
            ->assertJsonFragment(['A project named Fellowship pack already exists.']);

        $this->assertSame(1, MemberProject::query()->where('owner_phone', $phone)->count());
    }

    public function test_owner_can_rename_project(): void
    {
        [$user, $community] = $this->seedMember();
        $phone = '2348011111111';
        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $created = $this->postJson('/api/v1/web-chat/projects', [
            'phone' => $phone,
            'name' => 'Draft pack',
        ])->assertCreated();

        $projectId = (string) $created->json('data.project.id');

        $this->patchJson('/api/v1/web-chat/projects/'.$projectId, [
            'phone' => $phone,
            'name' => 'Final pack',
        ])
            ->assertOk()
            ->assertJsonPath('data.project.name', 'Final pack');

        $this->assertSame('Final pack', MemberProject::query()->find($projectId)?->name);
    }

    public function test_project_ask_explains_unreadable_file_instead_of_library_copy(): void
    {
        Http::fake([
            '*/conversation/document-reply' => Http::response(['reply' => 'Hello, I can still help.'], 200),
        ]);

        [$user, $community] = $this->seedMember();
        $phone = '2348011111111';
        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $created = $this->postJson('/api/v1/web-chat/projects', [
            'phone' => $phone,
            'name' => 'Roadmap',
        ])->assertCreated();

        $projectId = (string) $created->json('data.project.id');
        $chatId = (string) $created->json('data.chats.0.id');

        $vault = \App\Models\MemberVault::query()->firstOrCreate(
            ['owner_phone' => $phone, 'kind' => 'personal'],
            ['name' => 'Library'],
        );
        $doc = MemberVaultDocument::query()->create([
            'vault_id' => $vault->id,
            'owner_phone' => $phone,
            'filename' => '05-journal-publication-roadmap.pdf',
            'mime' => 'application/pdf',
            'byte_size' => 1200,
            'disk' => 'local',
            'storage_path' => 'member-vaults/empty.pdf',
            'extracted_text' => null,
            'status' => 'empty',
        ]);

        $this->postJson('/api/v1/web-chat/projects/'.$projectId.'/files', [
            'phone' => $phone,
            'document_ids' => [$doc->id],
        ])->assertOk();

        $this->postJson('/api/v1/web-chat/projects/'.$projectId.'/chats/'.$chatId.'/ask', [
            'phone' => $phone,
            'query' => 'Hello',
        ])
            ->assertOk()
            ->assertJsonPath('data.used_filenames', [])
            ->assertJsonPath('data.answer', 'Hello, I can still help.');
    }

    public function test_project_ask_does_not_dump_unreadable_pdf_bytes(): void
    {
        Http::fake([
            '*/conversation/document-reply' => Http::response(['reply' => 'I can still answer without that PDF text.'], 200),
        ]);

        [$user, $community] = $this->seedMember();
        $phone = '2348011111111';
        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $created = $this->postJson('/api/v1/web-chat/projects', [
            'phone' => $phone,
            'name' => 'CV pack',
        ])->assertCreated();

        $projectId = (string) $created->json('data.project.id');
        $chatId = (string) $created->json('data.chats.0.id');

        $vault = \App\Models\MemberVault::query()->firstOrCreate(
            ['owner_phone' => $phone, 'kind' => 'personal'],
            ['name' => 'Library'],
        );
        $doc = MemberVaultDocument::query()->create([
            'vault_id' => $vault->id,
            'owner_phone' => $phone,
            'filename' => 'Balogun_Abdulsamad_Senior_Full_Stack_Software_Engineer_CV.pdf',
            'mime' => 'application/pdf',
            'byte_size' => 2200,
            'disk' => 'local',
            'storage_path' => '',
            'extracted_text' => str_repeat('?%PDF-1.7 binary '.chr(0).chr(1).' wLRJQO ', 40),
            'status' => 'ready',
        ]);

        $this->postJson('/api/v1/web-chat/projects/'.$projectId.'/files', [
            'phone' => $phone,
            'document_ids' => [$doc->id],
        ])->assertOk();

        $this->postJson('/api/v1/web-chat/projects/'.$projectId.'/chats/'.$chatId.'/ask', [
            'phone' => $phone,
            'query' => 'What document is this?',
        ])
            ->assertOk()
            ->assertJsonPath('data.used_filenames', [])
            ->assertJsonMissing(['should not leak'])
            ->assertJsonMissing(['About:'])
            ->assertJsonMissing(['wLRJQO'])
            ->assertJsonPath('data.answer', 'I can still answer without that PDF text.');

        Http::assertSent(fn (\Illuminate\Http\Client\Request $request): bool => str_contains($request->url(), '/conversation/document-reply'));
    }

    public function test_project_ask_sends_full_library_and_marks_selected(): void
    {
        Http::fake([
            '*/conversation/document-reply' => Http::response(['reply' => 'You have three files in the library. One is selected.'], 200),
        ]);

        [$user, $community] = $this->seedMember();
        $phone = '2348011111111';
        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $created = $this->postJson('/api/v1/web-chat/projects', [
            'phone' => $phone,
            'name' => 'Pack',
        ])->assertCreated();
        $projectId = (string) $created->json('data.project.id');
        $chatId = (string) $created->json('data.chats.0.id');

        $this->post('/api/v1/web-chat/vault/documents', [
            'phone' => $phone,
            'files' => [
                UploadedFile::fake()->createWithContent('cv.pdf', 'Senior engineer CV text.'),
                UploadedFile::fake()->createWithContent('notes.txt', 'Project notes.'),
                UploadedFile::fake()->createWithContent('plan.txt', 'Pitch plan draft.'),
            ],
        ], ['Accept' => 'application/json'])->assertCreated();

        $cvId = (string) MemberVaultDocument::query()->where('filename', 'cv.pdf')->value('id');
        $this->postJson('/api/v1/web-chat/projects/'.$projectId.'/files', [
            'phone' => $phone,
            'document_ids' => [$cvId],
        ])->assertOk();

        $this->postJson('/api/v1/web-chat/projects/'.$projectId.'/chats/'.$chatId.'/ask', [
            'phone' => $phone,
            'query' => 'How many documents do I have in the library?',
            'document_ids' => [$cvId],
        ])->assertOk();

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            if (! str_contains($request->url(), '/conversation/document-reply')) {
                return false;
            }
            $payload = json_decode($request->body(), true);
            if (! is_array($payload)) {
                return false;
            }
            $library = collect($payload['library'] ?? []);
            if ($library->count() !== 3) {
                return false;
            }
            $cv = $library->firstWhere('filename', 'cv.pdf');
            $notes = $library->firstWhere('filename', 'notes.txt');

            return is_array($cv) && ($cv['selected'] ?? false) === true
                && is_array($notes) && ($notes['selected'] ?? true) === false
                && str_contains($request->body(), 'plan.txt');
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
