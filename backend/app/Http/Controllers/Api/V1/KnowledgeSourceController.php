<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Knowledge\ImportWhatsAppKnowledgeRequest;
use App\Http\Requests\Api\V1\Knowledge\StoreKnowledgeSourceRequest;
use App\Http\Resources\Api\V1\KnowledgeSourceResource;
use App\Models\Community;
use App\Models\KnowledgeSource;
use App\Services\Knowledge\KnowledgeLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KnowledgeSourceController extends Controller
{
    public function __construct(
        private readonly KnowledgeLifecycleService $lifecycle,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', KnowledgeSource::class);

        $communityIds = $request->user()->accessibleCommunityIds();

        $sources = KnowledgeSource::query()
            ->whereIn('community_id', $communityIds)
            ->latest()
            ->paginate(20);

        return KnowledgeSourceResource::collection($sources)->response();
    }

    public function store(StoreKnowledgeSourceRequest $request): JsonResponse
    {
        $this->authorize('create', KnowledgeSource::class);

        $data = $request->validated();
        $community = Community::query()->findOrFail($data['community_id']);

        abort_unless($community->tenant_id === $data['tenant_id'], 422, 'Community does not belong to tenant.');
        abort_unless(
            $request->user()->belongsToCommunity($community->id)
                || $request->user()->belongsToTenant($community->tenant_id),
            403
        );

        $source = $this->lifecycle->createDraft($request->user(), $data);

        return KnowledgeSourceResource::make($source)
            ->response()
            ->setStatusCode(201);
    }

    public function show(KnowledgeSource $knowledgeSource): KnowledgeSourceResource
    {
        $this->authorize('view', $knowledgeSource);

        return KnowledgeSourceResource::make($knowledgeSource);
    }

    public function submitReview(KnowledgeSource $knowledgeSource): KnowledgeSourceResource
    {
        $this->authorize('submitForReview', $knowledgeSource);

        return KnowledgeSourceResource::make(
            $this->lifecycle->submitForReview(request()->user(), $knowledgeSource)
        );
    }

    public function publish(KnowledgeSource $knowledgeSource): KnowledgeSourceResource
    {
        $this->authorize('publish', $knowledgeSource);

        return KnowledgeSourceResource::make(
            $this->lifecycle->publish(request()->user(), $knowledgeSource)
        );
    }

    public function reject(Request $request, KnowledgeSource $knowledgeSource): KnowledgeSourceResource
    {
        $this->authorize('reject', $knowledgeSource);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return KnowledgeSourceResource::make(
            $this->lifecycle->reject($request->user(), $knowledgeSource, $validated['reason'] ?? null)
        );
    }

    public function importWhatsApp(ImportWhatsAppKnowledgeRequest $request): JsonResponse
    {
        $this->authorize('create', KnowledgeSource::class);

        $data = $request->validated();
        $community = Community::query()->findOrFail($data['community_id']);

        abort_unless($community->tenant_id === $data['tenant_id'], 422);
        abort_unless($request->user()->belongsToCommunity($community->id), 403);

        $source = $this->lifecycle->importWhatsAppExport($request->user(), $data);

        return KnowledgeSourceResource::make($source)
            ->response()
            ->setStatusCode(201);
    }
}
