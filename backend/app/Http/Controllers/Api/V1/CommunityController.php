<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Communities\StoreCommunityRequest;
use App\Http\Resources\Api\V1\CommunityResource;
use App\Models\Community;
use App\Models\Membership;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class CommunityController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request, Tenant $tenant): AnonymousResourceCollection
    {
        $this->authorize('view', $tenant);

        $communityIds = $request->user()->accessibleCommunityIds($tenant->id);

        $isOwner = Membership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $request->user()->id)
            ->where('role', MembershipRole::TenantOwner)
            ->exists();

        $communities = Community::query()
            ->forTenant($tenant->id)
            ->when(! $isOwner, fn ($query) => $query->whereIn('id', $communityIds))
            ->orderBy('name')
            ->get();

        return CommunityResource::collection($communities);
    }

    public function store(StoreCommunityRequest $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('create', Community::class);

        $community = DB::transaction(function () use ($request, $tenant) {
            $community = Community::query()->create([
                ...$request->validated(),
                'tenant_id' => $tenant->id,
            ]);

            Membership::query()->create([
                'tenant_id' => $tenant->id,
                'community_id' => $community->id,
                'group_id' => null,
                'user_id' => $request->user()->id,
                'role' => MembershipRole::CommunityAdmin,
            ]);

            return $community;
        });

        $this->auditLogger->record(
            'community.created',
            tenantId: $tenant->id,
            auditable: $community,
            request: $request,
        );

        return (new CommunityResource($community))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Tenant $tenant, Community $community): CommunityResource
    {
        abort_unless($community->tenant_id === $tenant->id, 404);

        $this->authorize('view', $community);

        return new CommunityResource($community);
    }
}
