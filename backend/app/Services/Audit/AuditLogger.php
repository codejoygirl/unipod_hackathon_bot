<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function record(
        string $action,
        ?string $tenantId = null,
        ?Model $auditable = null,
        ?array $meta = null,
        ?Request $request = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'meta' => $meta,
            'ip_address' => $request?->ip(),
            'created_at' => now(),
        ]);
    }
}
