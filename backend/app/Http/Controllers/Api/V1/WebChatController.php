<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Assistant\GroundedQuestionService;
use App\Services\WebChat\WebChatAccessService;
use App\Services\WebChat\WebChatMemberPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

final class WebChatController extends Controller
{
    public function __construct(
        private readonly WebChatAccessService $access,
        private readonly GroundedQuestionService $groundedAsk,
        private readonly WebChatMemberPhone $phones,
    ) {}

    public function bootstrap(Request $request): JsonResponse
    {
        [$communityId, $sessionId, $memberPhone] = $this->validatedSession($request);
        $community = $this->access->community($communityId);
        $user = $this->access->actorUser();
        $this->access->assertActorCanAccessCommunity($user, $communityId);

        $this->rememberSession($communityId, $sessionId, $memberPhone);

        return response()->json([
            'data' => [
                'session_id' => $sessionId,
                'member_phone' => $memberPhone,
                'member_label' => $memberPhone !== null ? $this->phones->displayLabel($memberPhone) : null,
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
        ]);

        [$communityId, $sessionId, $memberPhone] = $this->validatedSession($request);
        $this->access->community($communityId);
        $user = $this->access->actorUser();
        $this->access->assertActorCanAccessCommunity($user, $communityId);

        $this->rememberSession($communityId, $sessionId, $memberPhone);

        $payload = $this->groundedAsk->ask(
            user: $user,
            query: $queryValidated['query'],
            communityIds: [$communityId],
            targetLanguage: $queryValidated['target_language'] ?? null,
        );

        return response()->json($payload);
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function validatedSession(Request $request): array
    {
        $validated = $request->validate([
            'k' => ['nullable', 'string', 'max:128'],
            's' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
            'p' => ['nullable', 'string', 'max:32'],
        ]);

        $communityId = $this->access->resolveCommunityId($validated['k'] ?? null);
        $memberPhone = $this->phones->normalize($validated['p'] ?? null);

        if (filter_var(config('zak_web_chat.require_member_phone'), FILTER_VALIDATE_BOOLEAN)) {
            if ($memberPhone === null) {
                throw ValidationException::withMessages([
                    'p' => ['Member phone (?p=) is required. Use the personalized link from your admin or bot.'],
                ]);
            }
        }

        $sessionId = $validated['s'];
        if ($memberPhone !== null) {
            $expected = $this->phones->sessionIdFromPhone($memberPhone);
            if ($sessionId !== $expected) {
                throw ValidationException::withMessages([
                    's' => ['Session id does not match phone. Open the full link including ?p= and ?s=.'],
                ]);
            }
        }

        return [$communityId, $sessionId, $memberPhone];
    }

    private function rememberSession(string $communityId, string $sessionId, ?string $memberPhone): void
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
