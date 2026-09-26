<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberVaultDocument extends Model
{
    use HasUlids;

    protected $fillable = [
        'vault_id',
        'owner_phone',
        'filename',
        'mime',
        'byte_size',
        'disk',
        'storage_path',
        'extracted_text',
        'status',
    ];

    public function vault(): BelongsTo
    {
        return $this->belongsTo(MemberVault::class, 'vault_id');
    }
}
