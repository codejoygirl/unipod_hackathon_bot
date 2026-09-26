<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberProjectMessage extends Model
{
    use HasUlids;

    protected $fillable = [
        'chat_id',
        'owner_phone',
        'role',
        'body',
        'used_filenames',
        'artefact_id',
    ];

    protected function casts(): array
    {
        return [
            'used_filenames' => 'array',
        ];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(MemberProjectChat::class, 'chat_id');
    }

    public function artefact(): BelongsTo
    {
        return $this->belongsTo(MemberVaultArtefact::class, 'artefact_id');
    }
}
