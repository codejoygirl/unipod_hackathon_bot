<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MemberProjectChat extends Model
{
    use HasUlids;

    protected $fillable = [
        'project_id',
        'owner_phone',
        'title',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(MemberProject::class, 'project_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MemberProjectMessage::class, 'chat_id');
    }
}
