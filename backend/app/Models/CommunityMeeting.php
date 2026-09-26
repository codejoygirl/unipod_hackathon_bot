<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityMeeting extends Model
{
    use HasUlids;

    protected $fillable = [
        'community_id',
        'title',
        'category',
        'platform',
        'platform_name',
        'url',
        'schedule',
        'time_context',
        'host',
        'description',
        'meeting_code',
        'passcode',
        'is_live_now',
        'created_by_phone',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'is_live_now' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }
}
