<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function belongsToTenant(string $tenantId): bool
    {
        return $this->memberships()->where('tenant_id', $tenantId)->exists();
    }

    public function belongsToCommunity(string $communityId): bool
    {
        return $this->memberships()->where('community_id', $communityId)->exists();
    }

    /**
     * @return list<string>
     */
    public function accessibleTenantIds(): array
    {
        return $this->memberships()->pluck('tenant_id')->unique()->values()->all();
    }

    /**
     * @return list<string>
     */
    public function accessibleCommunityIds(?string $tenantId = null): array
    {
        $query = $this->memberships()->whereNotNull('community_id');

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->pluck('community_id')->unique()->values()->all();
    }
}
