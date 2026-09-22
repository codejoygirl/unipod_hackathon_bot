<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait BelongsToTenant
{
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where($this->getTable().'.tenant_id', $tenantId);
    }
}
