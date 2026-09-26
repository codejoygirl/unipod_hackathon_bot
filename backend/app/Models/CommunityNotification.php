<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommunityNotification extends Model
{
    use HasUlids;

    protected $fillable = [
        'community_id',
        'title',
        'message',
        'category',
        'action_query',
        'created_by_phone',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function reads(): HasMany
    {
        return $this->hasMany(CommunityNotificationRead::class, 'notification_id');
    }
}
