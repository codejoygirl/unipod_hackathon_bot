<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberVaultArtefact extends Model
{
    use HasUlids;

    protected $fillable = [
        'vault_id',
        'owner_phone',
        'kind',
        'title',
        'body',
    ];

    public function vault(): BelongsTo
    {
        return $this->belongsTo(MemberVault::class, 'vault_id');
    }
}
