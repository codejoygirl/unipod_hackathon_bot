<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Tenants\StoreTenantRequest;
use App\Http\Resources\Api\V1\TenantResource;
use App\Models\Membership;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class TenantController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Tenant::class);

        $tenantIds = $request->user()->accessibleTenantIds();

        $tenants = Tenant::query()
            ->whereIn('id', $tenantIds)
            ->orderBy('name')
            ->get();

        return TenantResource::collection($tenants);
    }

    public function store(StoreTenantRequest $request): TenantResource
    {
        $this->authorize('create', Tenant::class);

        $tenant = DB::transaction(function () use ($request) {
            $tenant = Tenant::query()->create($request->validated());

            Membership::query()->create([
                'tenant_id' => $tenant->id,
                'community_id' => null,
                'group_id' => null,
                'user_id' => $request->user()->id,
                'role' => MembershipRole::TenantOwner,
            ]);

            return $tenant;
        });

        $this->auditLogger->record(
            'tenant.created',
            tenantId: $tenant->id,
            auditable: $tenant,
            request: $request,
        );

        return new TenantResource($tenant);
    }

    public function show(Request $request, Tenant $tenant): TenantResource
    {
        $this->authorize('view', $tenant);

        return new TenantResource($tenant);
    }
}
