<?php

declare(strict_types=1);

namespace App\Services\WebChat;

use App\Models\MemberProject;
use App\Models\MemberProjectChat;
use App\Models\MemberProjectMessage;
use App\Models\MemberVaultDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class MemberProjectService
{
    public const MAX_PROJECTS = 30;

    public const MAX_CHATS = 1;

    public function __construct(
        private readonly MemberVaultService $vaults,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $ownerPhone): array
    {
        return MemberProject::query()
            ->where('owner_phone', $ownerPhone)
            ->latest()
            ->get()
            ->map(fn (MemberProject $project): array => $this->summary($project))
            ->all();
    }

    public function create(string $ownerPhone, string $name): MemberProject
    {
        $count = MemberProject::query()->where('owner_phone', $ownerPhone)->count();
        if ($count >= self::MAX_PROJECTS) {
            throw ValidationException::withMessages([
                'name' => ['You can keep up to '.self::MAX_PROJECTS.' projects.'],
            ]);
        }

        $name = $this->safeName($name);
        $this->assertUniqueName($ownerPhone, $name);
        $project = MemberProject::query()->create([
            'owner_phone' => $ownerPhone,
            'name' => $name,
        ]);
        $this->createChat($ownerPhone, $project, 'New chat');

        return $project->fresh() ?? $project;
    }

    public function owned(string $ownerPhone, string $projectId): ?MemberProject
    {
        return MemberProject::query()
            ->where('id', $projectId)
            ->where('owner_phone', $ownerPhone)
            ->first();
    }

    public function rename(MemberProject $project, string $name): MemberProject
    {
        $name = $this->safeName($name);
        $this->assertUniqueName((string) $project->owner_phone, $name, (string) $project->id);
        $project->name = $name;
        $project->save();

        return $project;
    }

    public function delete(MemberProject $project): void
    {
        $project->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(MemberProject $project): array
    {
        $documents = $project->documents()->latest()->get()
            ->map(fn (MemberVaultDocument $doc): array => $this->vaults->documentPayload($doc))
            ->all();
        $chats = $project->chats()->latest()->get()
            ->map(fn (MemberProjectChat $chat): array => $this->chatSummary($chat))
            ->all();

        return [
            'project' => $this->summary($project, count($documents), count($chats)),
            'documents' => $documents,
            'chats' => $chats,
        ];
    }

    /**
     * @param  list<string>  $documentIds
     * @return list<array<string, mixed>>
     */
    public function attachDocuments(string $ownerPhone, MemberProject $project, array $documentIds): array
    {
        $ids = array_values(array_unique(array_filter($documentIds)));
        $docs = MemberVaultDocument::query()
            ->where('owner_phone', $ownerPhone)
            ->whereIn('id', $ids)
            ->get();

        foreach ($docs as $doc) {
            $project->documents()->syncWithoutDetaching([
                $doc->id => ['owner_phone' => $ownerPhone],
            ]);
        }

        return $project->documents()->latest()->get()
            ->map(fn (MemberVaultDocument $doc): array => $this->vaults->documentPayload($doc))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function uploadFiles(string $ownerPhone, MemberProject $project, UploadedFile $file): array
    {
        $doc = $this->vaults->addDocument($ownerPhone, $file);
        $project->documents()->syncWithoutDetaching([
            $doc->id => ['owner_phone' => $ownerPhone],
        ]);

        return [$this->vaults->documentPayload($doc)];
    }

    public function detachDocument(MemberProject $project, string $documentId): void
    {
        $project->documents()->detach($documentId);
    }

    public function createChat(string $ownerPhone, MemberProject $project, string $title = 'New chat'): MemberProjectChat
    {
        $count = $project->chats()->count();
        if ($count >= self::MAX_CHATS) {
            throw ValidationException::withMessages([
                'title' => ['This project uses one chat, like WhatsApp and Telegram.'],
            ]);
        }

        return MemberProjectChat::query()->create([
            'project_id' => $project->id,
            'owner_phone' => $ownerPhone,
            'title' => $this->safeName($title === '' ? 'New chat' : $title),
        ]);
    }

    public function ownedChat(string $ownerPhone, MemberProject $project, string $chatId): ?MemberProjectChat
    {
        return MemberProjectChat::query()
            ->where('id', $chatId)
            ->where('project_id', $project->id)
            ->where('owner_phone', $ownerPhone)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function chatDetail(MemberProjectChat $chat): array
    {
        $messages = $chat->messages()->with('artefact')->orderBy('created_at')->get()
            ->map(fn (MemberProjectMessage $message): array => $this->messagePayload($message))
            ->all();

        return [
            ...$this->chatSummary($chat),
            'messages' => $messages,
        ];
    }

    public function deleteChat(MemberProjectChat $chat): void
    {
        $chat->delete();
    }

    /**
     * @param  list<string>  $documentIds
     * @return array<string, mixed>
     */
    public function ask(string $ownerPhone, MemberProject $project, MemberProjectChat $chat, string $query, array $documentIds = [], string $kind = 'ask', ?string $replaceMessageId = null): array
    {
        $ids = $documentIds !== []
            ? $documentIds
            : $project->documents()->pluck('member_vault_documents.id')->map(fn ($id): string => (string) $id)->all();

        $regenerating = false;
        $excludeIds = [];
        if (is_string($replaceMessageId) && $replaceMessageId !== '') {
            $target = MemberProjectMessage::query()
                ->where('chat_id', $chat->id)
                ->where('owner_phone', $ownerPhone)
                ->where('role', 'assistant')
                ->where('id', $replaceMessageId)
                ->first();
            if ($target === null) {
                abort(404);
            }
            MemberProjectMessage::query()
                ->where('chat_id', $chat->id)
                ->where('id', '>=', $target->id)
                ->delete();
            $regenerating = true;
            $keptUser = MemberProjectMessage::query()
                ->where('chat_id', $chat->id)
                ->where('role', 'user')
                ->orderByDesc('id')
                ->first();
            if ($keptUser !== null) {
                $excludeIds[] = $keptUser->id;
            }
        } else {
            $userMessage = MemberProjectMessage::query()->create([
                'chat_id' => $chat->id,
                'owner_phone' => $ownerPhone,
                'role' => 'user',
                'body' => $query,
            ]);
            $excludeIds[] = $userMessage->id;

            if ($chat->title === 'New chat' || $chat->title === '') {
                $chat->title = mb_substr(trim($query), 0, 60) ?: 'New chat';
                $chat->save();
            }
        }

        $priorQuestion = null;
        $priorAnswerExcerpt = null;
        $thread = [];
        foreach (
            MemberProjectMessage::query()
                ->where('chat_id', $chat->id)
                ->whereNotIn('id', $excludeIds)
                ->orderByDesc('id')
                ->limit(8)
                ->get()
                ->reverse()
                ->values() as $prior
        ) {
            $text = trim((string) $prior->body);
            if ($text === '') {
                continue;
            }
            $role = $prior->role === 'assistant' ? 'assistant' : 'user';
            $cap = $role === 'assistant' ? 10000 : 1500;
            $thread[] = [
                'role' => $role,
                'text' => mb_substr($text, 0, $cap),
            ];
            if ($role === 'assistant') {
                $priorAnswerExcerpt = mb_substr($text, 0, 12000);
            }
            if ($role === 'user') {
                $priorQuestion = mb_substr($text, 0, 1000);
            }
        }
        $lastTurnWasQuestion = ! $regenerating
            && is_string($priorAnswerExcerpt)
            && str_ends_with(rtrim($priorAnswerExcerpt, " \t\"'"), '?');

        $ownedDocs = MemberVaultDocument::query()
            ->where('owner_phone', $ownerPhone)
            ->whereIn('id', $ids)
            ->get();
        $names = $ownedDocs
            ->pluck('filename')
            ->map(fn ($name): string => (string) $name)
            ->filter()
            ->values()
            ->all();
        $emptyAnswer = $ownedDocs->isEmpty()
            ? 'Please add a file to this project first, and I will help from there.'
            : 'I could not read text from '.implode(', ', $names).'. A Word or text file works well, or a PDF with selectable text.';

        $result = $this->vaults->generate(
            $ownerPhone,
            $kind,
            $query,
            $ids,
            $emptyAnswer,
            $priorQuestion,
            $priorAnswerExcerpt,
            $thread,
            $lastTurnWasQuestion,
        );
        $artefact = is_array($result['artefact'] ?? null) ? $result['artefact'] : null;
        $artefactId = is_string($artefact['id'] ?? null) ? $artefact['id'] : null;

        $assistant = MemberProjectMessage::query()->create([
            'chat_id' => $chat->id,
            'owner_phone' => $ownerPhone,
            'role' => 'assistant',
            'body' => (string) $result['answer'],
            'used_filenames' => $result['used_filenames'] ?? [],
            'artefact_id' => $artefactId,
        ]);

        return [
            'chat' => $this->chatSummary($chat->fresh() ?? $chat),
            'message' => $this->messagePayload($assistant->fresh(['artefact']) ?? $assistant),
            'answer' => $result['answer'],
            'used_filenames' => $result['used_filenames'] ?? [],
            'artefact' => $artefact,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(MemberProject $project, ?int $fileCount = null, ?int $chatCount = null): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'file_count' => $fileCount ?? $project->documents()->count(),
            'chat_count' => $chatCount ?? $project->chats()->count(),
            'updated_at' => optional($project->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function chatSummary(MemberProjectChat $chat): array
    {
        return [
            'id' => $chat->id,
            'title' => $chat->title,
            'updated_at' => optional($chat->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function messagePayload(MemberProjectMessage $message): array
    {
        $artefact = $message->relationLoaded('artefact')
            ? $message->artefact
            : $message->artefact()->first();

        return [
            'id' => $message->id,
            'role' => $message->role,
            'body' => $message->body,
            'used_filenames' => is_array($message->used_filenames) ? $message->used_filenames : [],
            'artefact' => $artefact ? $this->vaults->artefactPayload($artefact) : null,
            'created_at' => optional($message->created_at)?->toIso8601String(),
        ];
    }

    private function safeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            $name = 'Untitled project';
        }

        return mb_substr($name, 0, 80);
    }

    private function assertUniqueName(string $ownerPhone, string $name, ?string $ignoreId = null): void
    {
        $existing = MemberProject::query()
            ->where('owner_phone', $ownerPhone)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->value('name');
        if (is_string($existing) && $existing !== '') {
            throw ValidationException::withMessages([
                'name' => ['A project named '.$existing.' already exists.'],
            ]);
        }
    }
}
