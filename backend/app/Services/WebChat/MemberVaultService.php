<?php

declare(strict_types=1);

namespace App\Services\WebChat;

use App\Models\MemberVault;
use App\Models\MemberVaultArtefact;
use App\Models\MemberVaultDocument;
use App\Services\AI\AiServiceClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MemberVaultService
{
    public const MAX_FILES = 30;

    public const MAX_BYTES = 8_000_000;

    /** @var list<string> */
    public const ALLOWED_MIMES = [
        'text/plain',
        'text/markdown',
        'text/csv',
        'application/json',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    public function __construct(
        private readonly AiServiceClient $aiClient,
    ) {}

    public function personalVault(string $ownerPhone): MemberVault
    {
        return MemberVault::query()->firstOrCreate(
            ['owner_phone' => $ownerPhone, 'kind' => 'personal'],
            ['name' => 'Library'],
        );
    }

    /**
     * @return array{vault: MemberVault, documents: list<array<string, mixed>>, artefacts: list<array<string, mixed>>, limits: array{max_files: int, max_bytes: int, file_count: int}}
     */
    public function snapshot(string $ownerPhone): array
    {
        $vault = $this->personalVault($ownerPhone);
        $documents = $vault->documents()->latest()->get()->map(fn (MemberVaultDocument $doc): array => $this->documentPayload($doc))->all();
        $artefacts = $vault->artefacts()->latest()->limit(20)->get()->map(fn (MemberVaultArtefact $item): array => $this->artefactPayload($item))->all();

        return [
            'vault' => $vault,
            'documents' => $documents,
            'artefacts' => $artefacts,
            'limits' => [
                'max_files' => self::MAX_FILES,
                'max_bytes' => self::MAX_BYTES,
                'file_count' => count($documents),
            ],
        ];
    }

    public function addDocument(string $ownerPhone, UploadedFile $file): MemberVaultDocument
    {
        $vault = $this->personalVault($ownerPhone);
        if ($vault->documents()->count() >= self::MAX_FILES) {
            throw ValidationException::withMessages([
                'file' => ['You can keep up to '.self::MAX_FILES.' files in Library.'],
            ]);
        }

        $mime = strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream'));
        $mime = explode(';', $mime, 2)[0];
        if ($mime === 'image/jpg') {
            $mime = 'image/jpeg';
        }
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw ValidationException::withMessages([
                'file' => ['Use a PDF, Word, text, markdown, CSV, or image file.'],
            ]);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'file' => ['Each file must be under 8 MB.'],
            ]);
        }

        $filename = $this->safeFilename((string) $file->getClientOriginalName());
        $existingName = $vault->documents()
            ->whereRaw('LOWER(filename) = ?', [mb_strtolower($filename)])
            ->value('filename');
        if (is_string($existingName) && $existingName !== '') {
            throw ValidationException::withMessages([
                'file' => ['A file named '.$existingName.' is already in Library.'],
            ]);
        }

        $path = $file->storeAs(
            'member-vaults/'.$ownerPhone.'/'.$vault->id,
            (string) Str::ulid().'-'.$filename,
            'local',
        );
        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages([
                'file' => ['I could not store that file. Try again.'],
            ]);
        }

        $extracted = $this->usableExtract($this->extractText($file, $mime, $filename));
        $status = $extracted === '' ? 'empty' : 'ready';

        return MemberVaultDocument::query()->create([
            'vault_id' => $vault->id,
            'owner_phone' => $ownerPhone,
            'filename' => $filename,
            'mime' => $mime,
            'byte_size' => (int) $file->getSize(),
            'disk' => 'local',
            'storage_path' => $path,
            'extracted_text' => $extracted !== '' ? $extracted : null,
            'status' => $status,
        ]);
    }

    public function deleteDocument(string $ownerPhone, string $documentId): void
    {
        $doc = MemberVaultDocument::query()
            ->where('id', $documentId)
            ->where('owner_phone', $ownerPhone)
            ->first();
        if ($doc === null) {
            return;
        }

        if ($doc->storage_path !== '') {
            Storage::disk($doc->disk ?: 'local')->delete($doc->storage_path);
        }
        $doc->delete();
    }

    public function ownedDocument(string $ownerPhone, string $documentId): ?MemberVaultDocument
    {
        return MemberVaultDocument::query()
            ->where('id', $documentId)
            ->where('owner_phone', $ownerPhone)
            ->first();
    }

    public function ownedArtefact(string $ownerPhone, string $artefactId): ?MemberVaultArtefact
    {
        return MemberVaultArtefact::query()
            ->where('id', $artefactId)
            ->where('owner_phone', $ownerPhone)
            ->first();
    }

    public function artefactDownloadName(MemberVaultArtefact $item): string
    {
        $base = Str::slug((string) $item->title) ?: 'document';
        $ext = $item->kind === 'pdf' ? 'pdf' : 'md';

        return $base.'.'.$ext;
    }

    /**
     * @param  list<string>  $documentIds
     * @return array{answer: string, citations: list<string>, used_filenames: list<string>}
     */
    public function ask(string $ownerPhone, string $question, array $documentIds = [], ?string $emptyAnswer = null): array
    {
        return $this->generate($ownerPhone, 'ask', $question, $documentIds, $emptyAnswer);
    }

    /**
     * @param  list<string>  $documentIds
     * @param  list<array{role?: string, text?: string}>  $thread
     * @return array{answer: string, citations: list<string>, used_filenames: list<string>, artefact?: array<string, mixed>}
     */
    public function generate(
        string $ownerPhone,
        string $kind,
        string $instruction = '',
        array $documentIds = [],
        ?string $emptyAnswer = null,
        ?string $priorQuestion = null,
        ?string $priorAnswerExcerpt = null,
        array $thread = [],
        bool $lastTurnWasQuestion = false,
    ): array {
        $kind = $this->normalizeKind($kind);
        $selectedIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): string => is_string($id) ? trim($id) : '',
            $documentIds,
        ))));
        $all = MemberVaultDocument::query()
            ->where('owner_phone', $ownerPhone)
            ->latest()
            ->get()
            ->map(fn (MemberVaultDocument $doc): MemberVaultDocument => $this->refreshUnreadableDocument($doc));
        $library = $all
            ->map(function (MemberVaultDocument $doc) use ($selectedIds): array {
                $name = trim((string) $doc->filename);

                return [
                    'filename' => $name,
                    'selected' => $selectedIds !== [] && in_array((string) $doc->id, $selectedIds, true),
                    'readable' => $this->usableExtract((string) $doc->extracted_text) !== '',
                ];
            })
            ->filter(fn (array $item): bool => $item['filename'] !== '')
            ->values()
            ->all();
        $isSelected = fn (MemberVaultDocument $doc): bool => $selectedIds !== []
            && in_array((string) $doc->id, $selectedIds, true);
        $files = $all
            ->filter($isSelected)
            ->concat($all->reject($isSelected))
            ->map(function (MemberVaultDocument $doc) use ($isSelected): array {
                return [
                    'filename' => (string) $doc->filename,
                    'text' => $this->clipText($this->usableExtract((string) $doc->extracted_text), 8000),
                    'selected' => $isSelected($doc),
                ];
            })
            ->filter(fn (array $file): bool => $file['filename'] !== '' && $file['text'] !== '')
            ->values()
            ->all();

        $question = trim($instruction) !== '' ? trim($instruction) : $kind;
        $result = $this->aiClient->documentReplyResult(
            question: $question,
            files: $files,
            task: $kind,
            priorQuestion: $priorQuestion,
            priorAnswerExcerpt: $priorAnswerExcerpt,
            thread: $thread,
            lastTurnWasQuestion: $lastTurnWasQuestion,
            library: $library,
        );
        $reply = trim($result['reply']);
        $export = $result['export'];

        if ($reply === '') {
            $reply = $this->offlineFallback();
            $export = 'none';
        }

        $used = array_values(array_filter(array_map(
            static fn (array $file): string => (string) ($file['filename'] ?? ''),
            $files,
        )));
        $out = [
            'answer' => $reply,
            'citations' => $used,
            'used_filenames' => $used,
        ];

        $artefactKind = $kind !== 'ask' ? $kind : ($export === 'pdf' || $export === 'markdown' ? $export : null);
        if ($artefactKind === null && substr_count($reply, '#') >= 2 && mb_strlen($reply) > 400) {
            $artefactKind = 'markdown';
        }
        $artefactBody = $reply;
        $priorDraft = trim((string) $priorAnswerExcerpt);
        if ($artefactKind === 'pdf' && mb_strlen($reply) < 400 && mb_strlen($priorDraft) >= 400) {
            $artefactBody = $priorDraft;
        }

        if ($artefactKind !== null && $reply !== $this->offlineFallback()) {
            $artefact = MemberVaultArtefact::query()->create([
                'vault_id' => $this->personalVault($ownerPhone)->id,
                'owner_phone' => $ownerPhone,
                'kind' => $artefactKind,
                'title' => $this->artefactTitle($artefactKind, $instruction, $artefactBody),
                'body' => $artefactBody,
            ]);
            $out['artefact'] = $this->artefactPayload($artefact);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function documentPayload(MemberVaultDocument $doc): array
    {
        return [
            'id' => $doc->id,
            'filename' => $doc->filename,
            'mime' => $doc->mime,
            'byte_size' => $doc->byte_size,
            'status' => $doc->status,
            'excerpt' => mb_substr(trim((string) $doc->extracted_text), 0, 180),
            'created_at' => optional($doc->created_at)?->toIso8601String(),
            'kind' => $this->documentKind((string) $doc->mime, $doc->filename),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function artefactPayload(MemberVaultArtefact $item): array
    {
        return [
            'id' => $item->id,
            'kind' => $item->kind,
            'title' => $item->title,
            'body' => $item->body,
            'created_at' => optional($item->created_at)?->toIso8601String(),
        ];
    }

    private function extractText(UploadedFile $file, string $mime, string $filename): string
    {
        if (str_starts_with($mime, 'image/')) {
            $b64 = base64_encode((string) file_get_contents($file->getRealPath()));
            $result = $this->aiClient->understandImage(
                imageBase64: $b64,
                mimeType: $mime,
                filename: $filename,
                caption: 'Extract all readable text and a short note of what this file shows.',
            );

            return $this->clipText(trim((string) ($result['text'] ?? '')));
        }

        if (in_array($mime, ['text/plain', 'text/markdown', 'text/csv', 'application/json'], true)) {
            $raw = (string) file_get_contents($file->getRealPath());

            return $this->clipText($raw);
        }

        if ($mime === 'application/pdf') {
            return $this->extractPdfText((string) $file->getRealPath());
        }

        if (str_contains($mime, 'wordprocessingml') || $mime === 'application/msword') {
            return $this->extractDocxText((string) $file->getRealPath());
        }

        return '';
    }

    private function extractPdfText(string $path): string
    {
        $parsed = '';
        if (class_exists(\Smalot\PdfParser\Parser::class)) {
            try {
                $parsed = trim((string) (new \Smalot\PdfParser\Parser())->parseFile($path)->getText());
            } catch (\Throwable) {
                $parsed = '';
            }
        }
        $usable = $this->usableExtract($parsed);
        if ($usable !== '' && ! $this->looksLikeEncodedJunk($usable)) {
            return $this->clipText($usable);
        }

        $fromPages = $this->extractPdfViaVision($path);
        if ($fromPages !== '') {
            return $this->clipText($fromPages);
        }

        return '';
    }

    private function extractPdfViaVision(string $path): string
    {
        if (! class_exists(\Imagick::class)) {
            return '';
        }

        try {
            $images = new \Imagick;
            $images->setResolution(140, 140);
            $images->readImage($path);
            $images = $images->coalesceImages();
            $parts = [];
            $page = 0;
            foreach ($images as $frame) {
                if ($page >= 3) {
                    break;
                }
                $frame->setImageFormat('png');
                $frame->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                $b64 = base64_encode((string) $frame->getImageBlob());
                $result = $this->aiClient->understandImage(
                    imageBase64: $b64,
                    mimeType: 'image/png',
                    filename: basename($path).'-page-'.($page + 1).'.png',
                    caption: 'Extract all readable text from this document page. Keep names, roles, and section headings.',
                );
                $chunk = $this->usableExtract(trim((string) ($result['text'] ?? '')));
                if ($chunk !== '') {
                    $parts[] = $chunk;
                }
                $page++;
            }
            $images->clear();
            $images->destroy();

            return $this->usableExtract(implode("\n\n", $parts));
        } catch (\Throwable) {
            return '';
        }
    }

    private function refreshUnreadableDocument(MemberVaultDocument $doc): MemberVaultDocument
    {
        if ($this->usableExtract((string) $doc->extracted_text) !== '') {
            return $doc;
        }

        $disk = $doc->disk ?: 'local';
        $path = (string) $doc->storage_path;
        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            if ($doc->status !== 'empty') {
                $doc->status = 'empty';
                $doc->save();
            }

            return $doc;
        }

        $absolute = Storage::disk($disk)->path($path);
        $fresh = $this->extractTextFromStoredFile($absolute, (string) $doc->mime, (string) $doc->filename);
        $usable = $this->usableExtract($fresh);
        $doc->extracted_text = $usable !== '' ? $usable : null;
        $doc->status = $usable !== '' ? 'ready' : 'empty';
        $doc->save();

        return $doc;
    }

    private function extractTextFromStoredFile(string $path, string $mime, string $filename): string
    {
        if (! is_file($path)) {
            return '';
        }
        if (str_starts_with($mime, 'image/')) {
            $b64 = base64_encode((string) file_get_contents($path));
            $result = $this->aiClient->understandImage(
                imageBase64: $b64,
                mimeType: $mime,
                filename: $filename,
                caption: 'Extract all readable text and a short note of what this file shows.',
            );

            return $this->clipText(trim((string) ($result['text'] ?? '')));
        }
        if ($mime === 'application/pdf' || str_ends_with(strtolower($filename), '.pdf')) {
            return $this->extractPdfText($path);
        }
        if (str_contains($mime, 'wordprocessingml') || $mime === 'application/msword') {
            return $this->extractDocxText($path);
        }
        if (in_array($mime, ['text/plain', 'text/markdown', 'text/csv', 'application/json'], true)) {
            return $this->clipText((string) file_get_contents($path));
        }

        return '';
    }

    private function usableExtract(string $text): string
    {
        $text = trim($text);
        if ($text === '' || str_contains($text, "\0")) {
            return '';
        }
        $replacementCount = preg_match_all("/\x{FFFD}/u", $text) ?: 0;
        if ($replacementCount >= 5) {
            return '';
        }
        $cleaned = trim(preg_replace("/\x{FFFD}+/u", '', $text) ?? $text);
        $letters = preg_match_all('/\p{L}/u', $cleaned) ?: 0;
        if ($letters < 8) {
            return '';
        }
        $compact = preg_replace('/\s+/u', '', $cleaned) ?? $cleaned;
        $compactLen = max(1, mb_strlen($compact));
        if (($letters / $compactLen) < 0.4) {
            return '';
        }
        $marks = substr_count($cleaned, '?');
        if ($marks >= 12 && $marks * 2 > $letters) {
            return '';
        }
        if ($this->looksLikeEncodedJunk($cleaned)) {
            return '';
        }

        return $cleaned;
    }

    private function looksLikeEncodedJunk(string $text): bool
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        if (count($words) < 12) {
            return false;
        }
        $short = 0;
        foreach ($words as $word) {
            if (mb_strlen($word) <= 2) {
                $short++;
            }
        }

        return ($short / count($words)) > 0.7;
    }

    private function extractDocxText(string $path): string
    {
        if (! class_exists(\ZipArchive::class)) {
            return '';
        }
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return '';
        }
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === '') {
            return '';
        }
        $xml = preg_replace('/<\/w:p>/', "\n", $xml) ?? $xml;
        $text = strip_tags(str_replace(['<w:tab/>', '</w:tr>'], ["\t", "\n"], $xml));

        return $this->clipText(html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    /**
     * @param  list<string>  $documentIds
     */
    private function defaultEmptyAnswer(string $ownerPhone, array $documentIds): string
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): string => is_string($id) ? trim($id) : '',
            $documentIds,
        ))));
        $owned = MemberVaultDocument::query()
            ->where('owner_phone', $ownerPhone)
            ->when($ids !== [], fn ($query) => $query->whereIn('id', $ids))
            ->get();
        if ($owned->isEmpty()) {
            return 'Please add a file I can read first, and I will help from there.';
        }
        $names = $owned
            ->pluck('filename')
            ->map(fn ($name): string => (string) $name)
            ->filter()
            ->values()
            ->all();

        return 'I could not read text from '.implode(', ', $names).'. A Word or text file works well, or a PDF with selectable text.';
    }

    private function documentKind(string $mime, string $filename): string
    {
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if ($mime === 'application/pdf' || str_ends_with(strtolower($filename), '.pdf')) {
            return 'pdf';
        }
        if (str_contains($mime, 'word') || str_ends_with(strtolower($filename), '.doc') || str_ends_with(strtolower($filename), '.docx')) {
            return 'document';
        }

        return 'text';
    }

    private function offlineFallback(): string
    {
        return 'Sorry, I could not finish that just now. Please try again in a moment.';
    }

    private function normalizeKind(string $kind): string
    {
        $kind = strtolower(trim($kind));
        $allowed = ['ask', 'application', 'pitch_plan', 'deck_outline', 'recommendations', 'practice_qa', 'pdf', 'markdown'];

        return in_array($kind, $allowed, true) ? $kind : 'ask';
    }

    private function artefactTitle(string $kind, string $instruction, string $body = ''): string
    {
        if ($kind === 'pdf') {
            foreach (preg_split("/\r\n|\n|\r/", $body) ?: [] as $line) {
                $line = trim(preg_replace('/[#*_`]+/u', '', $line) ?? $line);
                if ($line !== '' && mb_strlen($line) <= 80) {
                    return mb_substr($line, 0, 80);
                }
            }

            return 'Document';
        }

        $labels = [
            'application' => 'Application answers',
            'pitch_plan' => 'Pitch plan',
            'deck_outline' => 'Pitch deck outline',
            'recommendations' => 'Recommendations',
            'practice_qa' => 'Practice Q&A',
            'markdown' => 'Draft',
        ];
        $base = $labels[$kind] ?? 'Draft';
        $extra = trim($instruction);

        return $extra === '' ? $base : $base.': '.mb_substr($extra, 0, 60);
    }

    private function safeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\w.\- ()]+/u', '_', $name) ?: 'file';

        return mb_substr($name, 0, 120);
    }

    private function clipText(string $text, int $max = 20000): string
    {
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if (is_string($converted)) {
                $text = $converted;
            }
        }
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;
        $text = trim(preg_replace("/[ \t]+/u", ' ', str_replace("\r\n", "\n", $text)) ?? $text);

        return mb_substr($text, 0, $max);
    }
}
