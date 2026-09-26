<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\WebChat\MemberVaultService;
use App\Services\WebChat\SimpleTextPdf;
use App\Services\WebChat\WebChatAccessService;
use App\Services\WebChat\WebChatMemberPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class WebChatVaultController extends Controller
{
    public function __construct(
        private readonly WebChatAccessService $access,
        private readonly WebChatMemberPhone $phones,
        private readonly MemberVaultService $vaults,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $phone = $this->requirePhone($request);
        $snap = $this->vaults->snapshot($phone);

        return response()->json([
            'data' => [
                'id' => $snap['vault']->id,
                'name' => $snap['vault']->name,
                'kind' => $snap['vault']->kind,
                'documents' => $snap['documents'],
                'artefacts' => $snap['artefacts'],
                'limits' => $snap['limits'],
            ],
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'file' => ['nullable', 'file', 'max:8192'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:8192'],
        ]);
        $phone = $this->normalizePhone((string) $validated['phone']);
        $incoming = $this->incomingFiles($request);
        if ($incoming === []) {
            throw ValidationException::withMessages([
                'file' => ['Upload a file.'],
            ]);
        }

        $docs = [];
        foreach ($incoming as $file) {
            $docs[] = $this->vaults->documentPayload($this->vaults->addDocument($phone, $file));
        }

        if (count($docs) === 1) {
            return response()->json(['data' => $docs[0]], 201);
        }

        return response()->json(['data' => ['documents' => $docs]], 201);
    }

    public function download(Request $request, string $document): StreamedResponse
    {
        $phone = $this->requirePhone($request);
        $owned = $this->vaults->ownedDocument($phone, $document);
        if ($owned === null) {
            abort(404);
        }

        $disk = $owned->disk ?: 'local';
        if ($owned->storage_path === '' || ! Storage::disk($disk)->exists($owned->storage_path)) {
            abort(404);
        }

        return Storage::disk($disk)->download($owned->storage_path, $owned->filename);
    }

    public function destroyDocument(Request $request, string $document): JsonResponse
    {
        $phone = $this->requirePhone($request);
        $owned = $this->vaults->ownedDocument($phone, $document);
        if ($owned === null) {
            abort(404);
        }
        $this->vaults->deleteDocument($phone, $document);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function ask(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'query' => ['required', 'string', 'max:2000'],
            'document_ids' => ['nullable', 'array', 'max:20'],
            'document_ids.*' => ['string', 'max:40'],
        ]);
        $phone = $this->normalizePhone((string) $validated['phone']);

        $result = $this->vaults->ask(
            $phone,
            (string) $validated['query'],
            $this->documentIds($validated['document_ids'] ?? []),
        );

        return response()->json(['data' => $result]);
    }

    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'kind' => ['required', 'string', 'max:40'],
            'instruction' => ['nullable', 'string', 'max:2000'],
            'document_ids' => ['nullable', 'array', 'max:20'],
            'document_ids.*' => ['string', 'max:40'],
        ]);
        $phone = $this->normalizePhone((string) $validated['phone']);

        $result = $this->vaults->generate(
            $phone,
            (string) $validated['kind'],
            (string) ($validated['instruction'] ?? ''),
            $this->documentIds($validated['document_ids'] ?? []),
        );

        return response()->json(['data' => $result]);
    }

    public function downloadArtefact(Request $request, string $artefact): Response
    {
        $phone = $this->requirePhone($request);
        $owned = $this->vaults->ownedArtefact($phone, $artefact);
        if ($owned === null) {
            abort(404);
        }

        $filename = $this->vaults->artefactDownloadName($owned);
        if ($owned->kind === 'pdf') {
            return response(SimpleTextPdf::render((string) $owned->title, (string) $owned->body), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
        }

        return response((string) $owned->body, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
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
    private function documentIds(mixed $ids): array
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
