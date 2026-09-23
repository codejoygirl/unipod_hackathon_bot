<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ConversationResource;
use App\Models\Conversation;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ConversationController extends Controller
{
    /**
     * Sidebar list: the signed-in member's own threads, most recent first.
     */
    public function index(Request $request): JsonResponse
    {
        $conversations = Conversation::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return ConversationResource::collection($conversations)->response();
    }

    /**
     * Start an empty thread. The tenant is optional: it falls back to the member's first
     * accessible one, then to the only tenant that exists, so opening a chat never
     * requires picking anything.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'tenant_id' => ['nullable', 'ulid'],
        ]);

        $accessibleTenants = $user->accessibleTenantIds();

        // `validate` omits a nullable key that the request never sent, so read it defensively.
        $requestedTenant = $validated['tenant_id'] ?? null;

        if ($requestedTenant !== null) {
            abort_unless(
                in_array($requestedTenant, $accessibleTenants, true),
                403,
                'You do not belong to this tenant.'
            );
        }

        // DEV FALLBACK — an account with no membership yet still gets a thread.
        $tenantId = $requestedTenant
            ?? ($accessibleTenants[0] ?? Tenant::query()->value('id'));

        abort_if($tenantId === null, 403, 'No tenant exists yet.');

        $conversation = Conversation::query()->create([
            'user_id' => $user->id,
            'tenant_id' => (string) $tenantId,
        ]);

        return ConversationResource::make($conversation)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, string $conversation): ConversationResource
    {
        return ConversationResource::make(
            $this->findOwned($request, $conversation)->load('messages')
        );
    }

    public function destroy(Request $request, string $conversation): Response
    {
        $this->findOwned($request, $conversation)->delete();

        return response()->noContent();
    }

    /**
     * Resolve a thread that belongs to the caller. Anything else is a 404, so
     * the endpoint never confirms that another member's conversation exists.
     */
    private function findOwned(Request $request, string $id): Conversation
    {
        $conversation = Conversation::query()
            ->where('user_id', $request->user()->id)
            ->find($id);

        if ($conversation === null) {
            throw new NotFoundHttpException('Conversation not found.');
        }

        return $conversation;
    }
}
