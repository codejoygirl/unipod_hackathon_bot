<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MemberProject;
use App\Services\WebChat\MemberProjectService;
use App\Services\WebChat\WebChatAccessService;
use App\Services\WebChat\WebChatMemberPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class WebChatProjectController extends Controller
{
    public function __construct(
        private readonly WebChatAccessService $access,
        private readonly WebChatMemberPhone $phones,
        private readonly MemberProjectService $projects,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $phone = $this->requirePhone($request);

        return response()->json([
            'data' => $this->projects->list($phone),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:80'],
        ]);
        $phone = $this->normalizePhone((string) $validated['phone']);
        $project = $this->projects->create($phone, (string) $validated['name']);

        return response()->json([
            'data' => $this->projects->snapshot($project),
        ], 201);
    }

    public function show(Request $request, string $project): JsonResponse
    {
        $owned = $this->ownedProject($request, $project);

        return response()->json([
            'data' => $this->projects->snapshot($owned),
        ]);
    }

    public function update(Request $request, string $project): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:80'],
        ]);
        $phone = $this->normalizePhone((string) $validated['phone']);
        $owned = $this->projects->owned($phone, $project);
        if ($owned === null) {
            abort(404);
        }
        $this->projects->rename($owned, (string) $validated['name']);

        return response()->json([
            'data' => $this->projects->snapshot($owned->fresh() ?? $owned),
        ]);
    }

    public function destroy(Request $request, string $project): JsonResponse
    {
        $owned = $this->ownedProject($request, $project);
        $this->projects->delete($owned);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function attachFiles(Request $request, string $project): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'document_ids' => ['nullable', 'array', 'max:30'],
            'document_ids.*' => ['string', 'max:40'],
            'file' => ['nullable', 'file', 'max:8192'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:8192'],
        ]);
        $phone = $this->normalizePhone((string) $validated['phone']);
        $owned = $this->projects->owned($phone, $project);
        if ($owned === null) {
            abort(404);
        }

        $uploaded = [];
        foreach ($this->incomingFiles($request) as $file) {
            $uploaded = [...$uploaded, ...$this->projects->uploadFiles($phone, $owned, $file)];
        }

        $attached = $this->projects->attachDocuments($phone, $owned, $this->stringIds($validated['document_ids'] ?? []));

        return response()->json([
            'data' => [
                'documents' => $attached,
                'uploaded' => $uploaded,
            ],
        ], $uploaded !== [] ? 201 : 200);
    }

    public function detachFile(Request $request, string $project, string $document): JsonResponse
    {
        $owned = $this->ownedProject($request, $project);
        $this->projects->detachDocument($owned, $document);

        return response()->json(['data' => ['detached' => true]]);
    }

    public function storeChat(Request $request, string $project): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'title' => ['nullable', 'string', 'max:80'],
        ]);
        $phone = $this->normalizePhone((string) $validated['phone']);
        $owned = $this->projects->owned($phone, $project);
        if ($owned === null) {
            abort(404);
        }
        $chat = $this->projects->createChat($phone, $owned, (string) ($validated['title'] ?? 'New chat'));

        return response()->json([
            'data' => $this->projects->chatDetail($chat),
        ], 201);
    }

    public function showChat(Request $request, string $project, string $chat): JsonResponse
    {
        [$owned, $ownedChat] = $this->ownedChat($request, $project, $chat);

        return response()->json([
            'data' => $this->projects->chatDetail($ownedChat),
        ]);
    }

    public function destroyChat(Request $request, string $project, string $chat): JsonResponse
    {
        [, $ownedChat] = $this->ownedChat($request, $project, $chat);
        $this->projects->deleteChat($ownedChat);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function askChat(Request $request, string $project, string $chat): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'query' => ['required', 'string', 'max:2000'],
            'kind' => ['nullable', 'string', 'max:40'],
            'document_ids' => ['nullable', 'array', 'max:30'],
            'document_ids.*' => ['string', 'max:40'],
            'replace_message_id' => ['nullable', 'string', 'max:40'],
        ]);
        $phone = $this->normalizePhone((string) $validated['phone']);
        $owned = $this->projects->owned($phone, $project);
        if ($owned === null) {
            abort(404);
        }
        $ownedChat = $this->projects->ownedChat($phone, $owned, $chat);
        if ($ownedChat === null) {
            abort(404);
        }

        $result = $this->projects->ask(
            $phone,
            $owned,
            $ownedChat,
            (string) $validated['query'],
            $this->stringIds($validated['document_ids'] ?? []),
            (string) ($validated['kind'] ?? 'ask'),
            isset($validated['replace_message_id']) ? (string) $validated['replace_message_id'] : null,
        );

        return response()->json(['data' => $result]);
    }

    private function ownedProject(Request $request, string $projectId): MemberProject
    {
        $phone = $this->requirePhone($request);
        $owned = $this->projects->owned($phone, $projectId);
        if ($owned === null) {
            abort(404);
        }

        return $owned;
    }

    /**
     * @return array{0: MemberProject, 1: \App\Models\MemberProjectChat}
     */
    private function ownedChat(Request $request, string $projectId, string $chatId): array
    {
        $owned = $this->ownedProject($request, $projectId);
        $chat = $this->projects->ownedChat($this->requirePhone($request), $owned, $chatId);
        if ($chat === null) {
            abort(404);
        }

        return [$owned, $chat];
    }

    /**
     * @return list<UploadedFile>
     */
    private function incomingFiles(Request $request): array
    {
        $files = [];
        $many = $request->file('files');
        if (is_array($many)) {
            foreach ($many as $file) {
                if ($file instanceof UploadedFile) {
                    $files[] = $file;
                }
            }
        }
        $one = $request->file('file');
        if ($one instanceof UploadedFile) {
            $files[] = $one;
        }

        return $files;
    }

    /**
     * @param  mixed  $ids
     * @return list<string>
     */
    private function stringIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }
        $out = [];
        foreach ($ids as $id) {
            if (is_string($id) && trim($id) !== '') {
                $out[] = trim($id);
            }
        }

        return array_values(array_unique($out));
    }

    private function requirePhone(Request $request): string
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
        ]);

        return $this->normalizePhone((string) $validated['phone']);
    }

    private function normalizePhone(string $phone): string
    {
        abort_unless($this->access->isEnabled(), 503, 'Web chat is disabled.');
        $memberPhone = $this->access->memberPhoneFromQuery($phone);
        if ($memberPhone === null) {
            throw ValidationException::withMessages([
                'phone' => ['Use your phone number in the link (?phone=234…).'],
            ]);
        }
        $this->phones->sessionIdFromPhone($memberPhone);

        return $memberPhone;
    }
}
