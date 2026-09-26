<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MemberVault extends Model
{
    use HasUlids;

    protected $fillable = [
        'owner_phone',
        'name',
        'kind',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(MemberVaultDocument::class, 'vault_id');
    }

    public function artefacts(): HasMany
    {
        return $this->hasMany(MemberVaultArtefact::class, 'vault_id');
    }
}
