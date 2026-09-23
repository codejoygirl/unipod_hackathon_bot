<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Services\AI\AiServiceClient;
use App\Services\Assistant\GroundedQuestionService;
use App\Services\Channels\AdminCredentialsService;
use App\Services\Channels\ChannelCommandAccess;
use App\Services\Channels\ChannelConversationService;
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

    public function ask(Request $request): JsonResponse
    {
        $queryValidated = $request->validate([
            'query' => ['required', 'string', 'max:2000'],
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

        $query = trim($queryValidated['query']);
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

        // Admin decision commands: /APPROVE, /DECLINE, /REJECT, /REPLY
        if ($isAdmin && (
            str_starts_with($upper, '/APPROVE') ||
            str_starts_with($upper, '/DECLINE') ||
            str_starts_with($upper, '/REJECT') ||
            str_starts_with($upper, '/REPLY')
        )) {
            $result = $this->escalationNotifier->tryAdminCommand($query, 'web_chat', $adminName ?? $memberPhone, $memberPhone);
            if ($result !== null) {
                return $this->directAnswerResponse((string) ($result['reply'] ?? 'Done.'), $community, $communityId);
            }
        }

        // /SHARE or SHARE
        if (str_starts_with($upper, '/SHARE') || str_starts_with($upper, 'SHARE')) {
            $body = trim((string) preg_replace('/^\/?share\s*/i', '', $query));
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

        // /FEATURE or FEATURE
        if (str_starts_with($upper, '/FEATURE') || str_starts_with($upper, 'FEATURE')) {
            $body = trim((string) preg_replace('/^\/?features?\s*/i', '', $query));
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

        // /ASK or ASK
        if (str_starts_with($upper, '/ASK') || str_starts_with($upper, 'ASK ')) {
            $query = trim((string) preg_replace('/^\/?ask\s*/i', '', $query));
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
}
