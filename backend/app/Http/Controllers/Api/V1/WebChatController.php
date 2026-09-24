<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Channels\InboundMessage;
use App\Enums\KnowledgeLifecycleStatus;
use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\FeatureRequest;
use App\Models\KnowledgeSource;
use App\Services\AI\AiServiceClient;
use App\Services\Assistant\GroundedQuestionService;
use App\Services\Channels\AdminCredentialsService;
use App\Services\Channels\AdminKnowledgeDesk;
use App\Services\Channels\ChannelCommandAccess;
use App\Services\Channels\ChannelConversationService;
use App\Services\Channels\ChannelListenGate;
use App\Services\Channels\ImageNoteNormalizer;
use App\Services\Channels\ProgramAssetRegistrar;
use App\Services\Channels\SpikeEscalationNotifier;
use App\Services\Knowledge\KnowledgeLifecycleService;
use App\Services\WebChat\WebChatAccessService;
use App\Services\WebChat\WebChatMemberPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WebChatController extends Controller
{
    public function __construct(
        private readonly WebChatAccessService $access,
        private readonly GroundedQuestionService $groundedAsk,
        private readonly WebChatMemberPhone $phones,
        private readonly ChannelConversationService $conversation,
        private readonly AiServiceClient $aiClient,
        private readonly SpikeEscalationNotifier $escalationNotifier,
        private readonly KnowledgeLifecycleService $lifecycle,
        private readonly AdminCredentialsService $adminCredentials,
        private readonly ChannelCommandAccess $commandAccess,
        private readonly AdminKnowledgeDesk $knowledgeDesk,
        private readonly ProgramAssetRegistrar $assetRegistrar,
        private readonly ImageNoteNormalizer $imageNormalizer,
        private readonly ChannelListenGate $listenGate,
    ) {}

    public function bootstrap(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'password' => ['nullable', 'string', 'max:128'],
            'admin_token' => ['nullable', 'string', 'max:128'],
        ]);

        [$communityId, $sessionId, $memberPhone] = $this->validatedSession($request);
        $community = $this->access->community($communityId);
        $user = $this->access->actorUser();
        $this->access->assertActorCanAccessCommunity($user, $communityId);

        $isAdmin = $this->adminCredentials->isAdminPhone($memberPhone);
        $adminRecord = $isAdmin ? $this->adminCredentials->findAdminByPhone($memberPhone) : null;
        $adminName = $adminRecord['name'] ?? null;
        $adminToken = (string) ($request->header('X-Admin-Token') ?: $request->input('admin_token', ''));

        // If it's an admin phone, they require a password for web chat access
        if ($isAdmin) {
            $tokenValid = $adminToken !== '' && $this->adminCredentials->verifyAdminToken($memberPhone, $adminToken);

            if (! $tokenValid) {
                $password = $request->input('password');
                if ($password === null || trim((string) $password) === '') {
                    return response()->json([
                        'data' => [
                            'requires_password' => true,
                            'is_admin' => true,
                            'role' => 'admin',
                            'admin_name' => $adminName,
                            'member_phone' => $memberPhone,
                            'member_label' => ($adminName ? $adminName . ' (' : '') . $this->phones->displayLabel($memberPhone) . ($adminName ? ')' : ''),
                            'community' => [
                                'id' => $community->id,
                                'name' => $community->name,
                                'slug' => $community->slug,
                            ],
                        ],
                    ]);
                }

                if (! $this->adminCredentials->verifyPassword($memberPhone, (string) $password)) {
                    throw ValidationException::withMessages([
                        'password' => ['Incorrect admin password.'],
                    ]);
                }

                $adminToken = $this->adminCredentials->issueAdminToken($memberPhone);
            }
        }

        $this->rememberSession($communityId, $sessionId, $memberPhone);

        $displayLabel = $adminName
            ? $adminName . ' (' . $this->phones->displayLabel($memberPhone) . ')'
            : $this->phones->displayLabel($memberPhone);

        return response()->json([
            'data' => [
                'session_id' => $sessionId,
                'member_phone' => $memberPhone,
                'member_label' => $displayLabel,
                'is_admin' => $isAdmin,
                'role' => $isAdmin ? 'admin' : 'member',
                'admin_name' => $adminName,
                'admin_token' => $adminToken !== '' ? $adminToken : null,
                'requires_password' => false,
                'community' => [
                    'id' => $community->id,
                    'name' => $community->name,
                    'slug' => $community->slug,
                ],
            ],
        ]);
    }

    public function resources(Request $request): JsonResponse
    {
        [$communityId, $sessionId, $memberPhone] = $this->validatedSession($request);
        $community = $this->access->community($communityId);
        $user = $this->access->actorUser();
        $this->access->assertActorCanAccessCommunity($user, $communityId);

        $isAdmin = $this->adminCredentials->isAdminPhone($memberPhone);

        $sources = KnowledgeSource::query()
            ->where('community_id', $communityId)
            ->where('lifecycle_status', KnowledgeLifecycleStatus::Published)
            ->get()
            ->filter(function (KnowledgeSource $source) {
                $metadata = is_array($source->metadata) ? $source->metadata : [];
                // Exclude automatic chat message indexing from resource cards
                if (($metadata['origin'] ?? '') === 'admin_auto_index') {
                    return false;
                }
                if (str_starts_with(strtolower($source->name), 'admin update')) {
                    return false;
                }
                // Must have either a delivery URL or a valid link in content
                $url = $metadata['delivery_url'] ?? null;
                if (! $url && preg_match('/https?:\/\/[^\s<>"\'\)]+/u', (string) $source->content, $m)) {
                    $url = $m[0];
                }

                return ! empty($url);
            })
            ->sortBy(function (KnowledgeSource $source) {
                $metadata = is_array($source->metadata) ? $source->metadata : [];
                $url = (string) ($metadata['delivery_url'] ?? '');
                $nameLower = strtolower($source->name);
                $isRootFolder = ($metadata['asset_kind'] ?? '') === 'folder'
                    || str_contains($nameLower, 'community resource')
                    || str_contains(strtolower($url), 'drive.google.com/drive/folders');

                // Primary Drive folder first (0), others second (1)
                return $isRootFolder ? 0 : 1;
            })
            ->values();

        $resources = $sources->map(function (KnowledgeSource $source) {
            $metadata = is_array($source->metadata) ? $source->metadata : [];
            $deliveryUrl = $metadata['delivery_url'] ?? null;
            $assetKind = $metadata['asset_kind'] ?? null;

            $url = $deliveryUrl;
            if (! $url && preg_match('/https?:\/\/[^\s<>"\'\)]+/u', (string) $source->content, $m)) {
                $url = $m[0];
            }

            $nameLower = strtolower($source->name);
            $urlLower = strtolower((string) $url);
            $kind = $assetKind;
            if (! $kind) {
                if (str_contains($urlLower, 'drive.google.com/drive/folders') || str_contains($nameLower, 'folder') || str_contains($nameLower, 'resources')) {
                    $kind = 'folder';
                } elseif (str_contains($nameLower, 'handbook') || str_contains($nameLower, 'guide') || str_contains($nameLower, 'manual')) {
                    $kind = 'handbook';
                } elseif (str_contains($nameLower, 'slide') || str_contains($nameLower, 'deck') || str_contains($nameLower, 'module') || str_contains($nameLower, 'presentation')) {
                    $kind = 'slides';
                } elseif (str_contains($nameLower, 'form') || str_contains($nameLower, 'survey') || str_contains($nameLower, 'signup') || str_contains($urlLower, 'forms.gle')) {
                    $kind = 'form';
                } elseif (str_contains($nameLower, 'meeting') || str_contains($nameLower, 'recording') || str_contains($nameLower, 'workshop')) {
                    $kind = 'recording';
                } else {
                    $kind = 'document';
                }
            }

            $content = (string) $source->content;
            $cleanContent = trim((string) preg_replace('/https?:\/\/\S+/u', '', $content));
            $cleanContent = trim((string) preg_replace('/\s+/', ' ', $cleanContent));

            return [
                'id' => (string) $source->id,
                'name' => (string) $source->name,
                'kind' => (string) $kind,
                'url' => $url,
                'description' => mb_substr($cleanContent, 0, 240),
                'authority_tier' => $source->authority_tier?->value ?? 'verified_resource',
                'source_type' => (string) $source->source_type,
                'published_at' => $source->published_at?->toIso8601String(),
                'is_asset' => ! empty($metadata['asset_identity']),
            ];
        })->values();

        return response()->json([
            'data' => [
                'community' => [
                    'id' => $community->id,
                    'name' => $community->name,
                    'slug' => $community->slug,
                ],
                'is_admin' => $isAdmin,
                'resources' => $resources,
            ],
        ]);
    }

    /**
     * Legacy / PRD-style path: POST …/communities/{community}/assistant/ask
     * (same handler as web-chat ask; community must match configured default).
     */
    public function askForCommunity(Request $request, string $community): JsonResponse
    {
        $defaultCommunityId = $this->access->resolveDefaultCommunityId();
        if ($community !== $defaultCommunityId) {
            abort(404);
        }

        return $this->ask($request);
    }

    public function ask(Request $request): JsonResponse
    {
        $queryValidated = $request->validate([
            'query' => ['nullable', 'string', 'max:2000'],
            'image_base64' => ['nullable', 'string', 'max:3500000'],
            'image_mime' => ['nullable', 'string', 'max:120'],
            'image_filename' => ['nullable', 'string', 'max:200'],
            'images' => ['nullable', 'array', 'max:5'],
            'images.*.image_base64' => ['required_with:images', 'string', 'max:3500000'],
            'images.*.mime' => ['nullable', 'string', 'max:120'],
            'images.*.filename' => ['nullable', 'string', 'max:200'],
            'target_language' => ['nullable', 'string', 'max:10'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);

        [$communityId, $sessionId, $memberPhone] = $this->validatedSession($request);
        $community = $this->access->community($communityId);
        $user = $this->access->actorUser();
        $this->access->assertActorCanAccessCommunity($user, $communityId);

        $this->rememberSession($communityId, $sessionId, $memberPhone);

        $isAdmin = $this->adminCredentials->isAdminPhone($memberPhone);
        $adminRecord = $isAdmin ? $this->adminCredentials->findAdminByPhone($memberPhone) : null;
        $adminName = $adminRecord['name'] ?? null;

        $query = trim((string) ($queryValidated['query'] ?? ''));
        $webChatImages = $this->normalizedWebChatImages($queryValidated);
        if ($query === '' && $webChatImages === []) {
            throw ValidationException::withMessages([
                'query' => ['Type a question or attach a photo.'],
            ]);
        }

        $upper = strtoupper($query);

        // 1. Bot commands:
        // /LOGINS, LOGINS, /LOGINAS, LOGINAS
        if (in_array($upper, ['/LOGINS', 'LOGINS', '/LOGINAS', 'LOGINAS'], true)) {
            if ($isAdmin) {
                $reply = $this->adminCredentials->formatLoginsCard('whatsapp');
                return $this->directAnswerResponse($reply, $community, $communityId);
            }

            $reply = $this->commandAccess->adminOnlyDenial('whatsapp');
            return $this->directAnswerResponse($reply, $community, $communityId);
        }

        // /HELP, HELP, /START, START
        if ($upper === '/HELP' || $upper === 'HELP' || $upper === '/START' || $upper === 'START') {
            $reply = $this->conversation->helpTextFor(
                style: 'whatsapp',
                currentChannel: 'web',
                isAdmin: $isAdmin,
                chatType: 'private',
                memberPhoneForWeb: $memberPhone,
            );

            return $this->directAnswerResponse($reply, $community, $communityId);
        }

        // Admin-only commands check
        if (! $isAdmin && $this->commandAccess->isAdminOnlyCommand($query)) {
            $reply = $this->commandAccess->adminOnlyDenial('whatsapp');
            return $this->directAnswerResponse($reply, $community, $communityId);
        }

        if ($isAdmin && $this->commandAccess->isAdminOnlyCommand($query)) {
            $adminCmd = $this->escalationNotifier->tryAdminCommand(
                $query,
                'web_chat',
                $adminName ?? $memberPhone,
                $memberPhone,
            );
            if ($adminCmd !== null) {
                return $this->directAnswerResponse((string) ($adminCmd['reply'] ?? 'Done.'), $community, $communityId);
            }

            if ($this->listenGate->startsWithSlashCommand($query, 'import')
                || $this->listenGate->startsWithSlashCommand($query, 'export')) {
                $body = $this->stripCommandPrefix($query, ['import', 'export']);
                if ($body === '') {
                    return $this->directAnswerResponse(
                        'Usage: /import <pasted chat export text to ingest as a knowledge draft>',
                        $community,
                        $communityId,
                    );
                }

                $source = $this->lifecycle->import($user, [
                    'tenant_id' => $community->tenant_id,
                    'community_id' => $community->id,
                    'name' => $this->knowledgeDesk->suggestImportTitle($body),
                    'uri' => 'web-chat://import/'.Str::ulid(),
                    'source_type' => 'web_chat',
                    'content' => $body,
                    'metadata' => [
                        'channel' => 'web_chat',
                        'from' => $memberPhone,
                        'origin' => 'admin_import',
                    ],
                ]);

                return $this->directAnswerResponse(
                    $this->knowledgeDesk->draftCreatedReply($source, 'whatsapp'),
                    $community,
                    $communityId,
                );
            }

            if ($this->listenGate->startsWithSlashCommand($query, 'asset')) {
                $assetResult = $this->assetRegistrar->registerFromCommand(
                    'web_chat',
                    $query,
                    $user,
                    $community,
                    'whatsapp',
                );

                return $this->directAnswerResponse(
                    (string) ($assetResult['reply'] ?? 'Done.'),
                    $community,
                    $communityId,
                );
            }

            if ($this->listenGate->startsWithSlashCommand($query, 'publish')
                || $this->listenGate->startsWithSlashCommand($query, 'unpublish')
                || $this->listenGate->startsWithSlashCommand($query, 'archive')
                || $this->listenGate->startsWithSlashCommand($query, 'knowledge')
                || $this->listenGate->startsWithSlashCommand($query, 'kb')
                || $this->listenGate->startsWithSlashCommand($query, 'features')) {
                $desk = $this->knowledgeDesk->tryHandle($query, $user, $community, 'whatsapp');
                if ($desk !== null) {
                    return $this->directAnswerResponse(
                        (string) ($desk['reply'] ?? 'Done.'),
                        $community,
                        $communityId,
                    );
                }

                return $this->directAnswerResponse(
                    'Try /publish, /knowledge, or /features.',
                    $community,
                    $communityId,
                );
            }
        }

        if ($this->listenGate->startsWithSlashCommand($query, 'assets')) {
            $arg = $this->listenGate->slashCommandBody($query, 'assets');
            $reply = $this->assetRegistrar->memberCatalogReply($community, $arg, 'whatsapp');

            return $this->directAnswerResponse($reply, $community, $communityId);
        }

        if ($this->listenGate->startsWithSlashCommand($query, 'share')) {
            $body = $this->listenGate->slashCommandBody($query, 'share');
            if ($body === '') {
                $reply = "Share something the community should know, like:\n"
                    ."/share Water off tomorrow morning\n\n"
                    .'An admin will review it before '.$this->conversation->botDisplayName().' can use it in answers.';

                return $this->directAnswerResponse($reply, $community, $communityId);
            }

            $source = $this->lifecycle->import($user, [
                'tenant_id' => $community->tenant_id,
                'community_id' => $community->id,
                'name' => 'Shared web chat note',
                'uri' => 'web-chat://share/'.Str::ulid(),
                'source_type' => 'web_chat',
                'content' => $body,
                'metadata' => [
                    'channel' => 'web_chat',
                    'from' => $memberPhone,
                ],
            ]);
            $this->lifecycle->submitForReview($user, $source);

            $notify = $this->escalationNotifier->notifyShareReview(
                channel: 'web_chat',
                from: $memberPhone,
                content: $body,
                knowledgeSourceId: (string) $source->id,
                communityId: $community->id,
                communityName: $community->name,
                fromName: '+'.$memberPhone,
                fromPhone: $memberPhone,
            );

            $reply = $this->conversation->shareQueuedReply((bool) ($notify['notified'] ?? false));

            return $this->directAnswerResponse($reply, $community, $communityId);
        }

        if ($this->listenGate->startsWithSlashCommand($query, 'feature')) {
            $body = $this->listenGate->slashCommandBody($query, 'feature');
            if ($body === '') {
                $reply = $this->conversation->featureUsageReply('whatsapp');

                return $this->directAnswerResponse($reply, $community, $communityId);
            }

            $notify = $this->escalationNotifier->notifyFeatureRequest(
                channel: 'web_chat',
                from: $memberPhone,
                content: $body,
                communityId: $community->id,
                communityName: $community->name,
                fromName: '+'.$memberPhone,
                fromPhone: $memberPhone,
                chatType: 'private',
                chatId: $sessionId,
                messageId: (string) Str::ulid(),
            );

            $reply = $this->conversation->featureQueuedReply((bool) ($notify['notified'] ?? false));

            return $this->directAnswerResponse($reply, $community, $communityId);
        }

        // /CATCHUP, CATCHUP, /SUMMARY, SUMMARY, RECAP
        if (in_array($upper, ['/CATCHUP', 'CATCHUP', '/SUMMARY', 'SUMMARY', 'RECAP', '/RECAP'], true)) {
            $query = 'Summarize what I missed, recent community announcements, discussions, and important updates';
        }

        if ($this->listenGate->startsWithSlashCommand($query, 'ask')) {
            $query = $this->listenGate->slashCommandBody($query, 'ask');
        }

        if ($webChatImages !== []) {
            $raw = count($webChatImages) === 1
                ? ['media' => array_merge(['kind' => 'image'], $webChatImages[0])]
                : ['images' => $webChatImages];
            $understood = $this->imageNormalizer->normalize(new InboundMessage(
                channel: 'web_chat',
                externalUserId: $memberPhone,
                text: $query,
                messageId: (string) Str::ulid(),
                raw: $raw,
            ));
            if (($understood['error'] ?? null) !== null) {
                return $this->directAnswerResponse((string) $understood['error'], $community, $communityId);
            }
            $query = trim($understood['message']->text);
            if ($query === '') {
                return $this->directAnswerResponse(
                    $this->conversation->imageNoteFailedReply(),
                    $community,
                    $communityId,
                );
            }
        }

        // 2. Purely social greetings
        if ($this->conversation->isPurelySocial($query)) {
            $reply = $this->aiClient->conversationalReply(
                message: $query,
                mode: 'social',
                communityName: $community->name,
                communityScope: 'UniPods community support',
                targetLanguage: $queryValidated['target_language'] ?? null,
            );
            if ($reply === '') {
                $reply = "Hello! I'm your {$community->name} Assistant. How can I help you today?";
            }

            return $this->directAnswerResponse($reply, $community, $communityId);
        }

        // 3. Grounded retrieval
        $payload = $this->groundedAsk->ask(
            user: $user,
            query: $query,
            communityIds: [$communityId],
            targetLanguage: $queryValidated['target_language'] ?? null,
            timezone: $queryValidated['timezone'] ?? null,
        );

        $answer = trim((string) ($payload['data']['answer'] ?? ''));
        $state = (string) ($payload['data']['state'] ?? '');
        $needsEscalation = (bool) ($payload['data']['needs_escalation'] ?? false);

        // 4. If answer is empty or insufficient evidence, notify admins & match WhatsApp/Telegram reassuring response
        if ($answer === '' || $state === 'INSUFFICIENT_EVIDENCE' || $needsEscalation) {
            $shouldEscalate = $this->conversation->shouldEscalateKnowledgeGap($query, $community->description);
            if ($shouldEscalate) {
                $this->escalationNotifier->escalate([
                    'question' => $query,
                    'from' => $memberPhone,
                    'from_name' => '+'.$memberPhone,
                    'from_phone' => $memberPhone,
                    'chat_type' => 'private',
                    'chat_id' => $sessionId,
                    'message_id' => (string) Str::ulid(),
                    'community_id' => $communityId,
                    'community_name' => $community->name,
                    'reason' => (string) ($payload['data']['escalation_reason'] ?? 'insufficient_evidence'),
                    'channel' => 'web_chat',
                ]);

                $payload['data']['answer'] = "I don't have a solid answer for that yet.\n\n"
                    ."I've passed it along, and I'll follow up once I have one. "
                    ."No need to keep checking or asking again.";
                $payload['data']['needs_escalation'] = true;
            } else {
                if ($this->conversation->isClearlyOutOfScope($query)) {
                    $payload['data']['answer'] = $this->conversation->outOfScopeReply('whatsapp');
                    $payload['data']['needs_escalation'] = false;
                } elseif ($answer === '') {
                    $payload['data']['answer'] = "I don't have a solid answer for that yet.\n\n"
                        ."I've passed it along, and I'll follow up once I have one. "
                        ."No need to keep checking or asking again.";
                }
            }
        }

        return response()->json($payload);
    }

    private function directAnswerResponse(string $answer, Community $community, string $communityId): JsonResponse
    {
        return response()->json([
            'data' => [
                'state' => 'VERIFIED',
                'answer' => $answer,
                'confidence' => 1.0,
                'detected_language' => 'en',
                'evidence_drawer' => [],
                'conflicts' => [],
                'needs_escalation' => false,
                'escalation_reason' => null,
            ],
            'meta' => [
                'latency_ms' => 0,
                'chunks_evaluated' => 0,
                'tenant_id' => $community->tenant_id,
                'community_ids' => [$communityId],
            ],
        ]);
    }

    public function featureRequest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:3000'],
            'user_type' => ['required', 'string', 'in:admin,member'],
            'phone' => ['nullable', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'community_id' => ['nullable', 'string'],
        ]);

        $communityId = $validated['community_id'] ?? null;
        if (! $communityId) {
            $communityId = $this->access->resolveDefaultCommunityId();
        }

        $community = null;
        if ($communityId) {
            $community = Community::query()->find($communityId);
        }

        $userType = $validated['user_type'] === 'admin' ? 'admin' : 'member';
        $userPhone = trim((string) ($validated['phone'] ?? ''));
        $userName = trim((string) ($validated['name'] ?? ''));
        if ($userName === '') {
            $userName = $userType === 'admin' ? 'Coordinator' : 'Fellow';
        }

        $title = trim($validated['title']);
        $description = trim($validated['description']);

        // Create feature request in database
        $feature = FeatureRequest::create([
            'community_id' => $community?->id,
            'user_type' => $userType,
            'title' => $title,
            'description' => $description,
            'user_name' => $userName,
            'user_phone' => $userPhone,
            'user_email' => $validated['email'] ?? null,
            'status' => 'open',
            'channel' => 'web_chat',
        ]);

        // Send notification to Telegram and WhatsApp admins
        $content = "[{$userType}] {$title}\n\n{$description}";
        $escalation = $this->escalationNotifier->notifyFeatureRequest(
            channel: 'web_chat',
            from: $userPhone !== '' ? $userPhone : ($userType === 'admin' ? 'Coordinator' : 'Member'),
            content: $content,
            communityId: (string) ($community?->id ?? ''),
            communityName: $community?->name,
            fromName: $userName,
            fromPhone: $userPhone,
            chatType: 'web',
        );

        $ref = $escalation['ref'] ?? null;
        if ($ref) {
            $feature->update(['ref' => $ref]);
        }

        return response()->json([
            'data' => [
                'id' => $feature->id,
                'ref' => $ref,
                'status' => 'submitted',
                'message' => 'Feature request submitted and sent to coordinators.',
            ],
        ], 201);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function validatedSession(Request $request): array
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
        ]);

        $memberPhone = $this->access->memberPhoneFromQuery($validated['phone']);
        if ($memberPhone === null) {
            throw ValidationException::withMessages([
                'phone' => ['Use your phone number in the link (?phone=234…).'],
            ]);
        }

        $communityId = $this->access->resolveDefaultCommunityId();
        $sessionId = $this->phones->sessionIdFromPhone($memberPhone);

        return [$communityId, $sessionId, $memberPhone];
    }

    private function rememberSession(string $communityId, string $sessionId, string $memberPhone): void
    {
        Cache::put(
            $this->sessionCacheKey($communityId, $sessionId),
            [
                'community_id' => $communityId,
                'member_phone' => $memberPhone,
                'updated_at' => now()->toIso8601String(),
            ],
            now()->addDays(30),
        );
    }

    private function sessionCacheKey(string $communityId, string $sessionId): string
    {
        return 'web_chat_session:'.$communityId.':'.$sessionId;
    }

    /**
     * @param  list<string>  $commands
     */
    private function stripCommandPrefix(string $text, array $commands): string
    {
        $body = trim($text);
        foreach ($commands as $command) {
            foreach (['/'.$command, $command] as $prefix) {
                if (str_starts_with(strtoupper($body), strtoupper($prefix))) {
                    return trim(substr($body, strlen($prefix)));
                }
            }
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<array{data_base64: string, mime_type: string, filename: string}>
     */
    private function normalizedWebChatImages(array $validated): array
    {
        $out = [];
        $rows = $validated['images'] ?? null;
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $b64 = trim((string) ($row['image_base64'] ?? ''));
                if ($b64 === '') {
                    continue;
                }
                $mime = trim((string) ($row['mime'] ?? 'image/jpeg'));
                $filename = trim((string) ($row['filename'] ?? ''));
                $out[] = [
                    'data_base64' => $b64,
                    'mime_type' => $mime !== '' ? $mime : 'image/jpeg',
                    'filename' => $filename !== '' ? $filename : 'photo.jpg',
                ];
            }
        }

        if ($out !== []) {
            return $out;
        }

        $legacy = trim((string) ($validated['image_base64'] ?? ''));
        if ($legacy === '') {
            return [];
        }

        $mime = trim((string) ($validated['image_mime'] ?? 'image/jpeg'));
        $filename = trim((string) ($validated['image_filename'] ?? ''));

        return [[
            'data_base64' => $legacy,
            'mime_type' => $mime !== '' ? $mime : 'image/jpeg',
            'filename' => $filename !== '' ? $filename : 'photo.jpg',
        ]];
    }
}
