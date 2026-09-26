<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MemberProject extends Model
{
    use HasUlids;

    protected $fillable = [
        'owner_phone',
        'name',
    ];

    public function chats(): HasMany
    {
        return $this->hasMany(MemberProjectChat::class, 'project_id');
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(
            MemberVaultDocument::class,
            'member_project_files',
            'project_id',
            'document_id',
        )->withTimestamps();
    }
}
