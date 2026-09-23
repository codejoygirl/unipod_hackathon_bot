<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'tenant_id' => $this->tenant_id,
            'last_message_at' => $this->last_message_at,
            'created_at' => $this->created_at,
            'messages' => $this->whenLoaded(
                'messages',
                fn () => MessageResource::collection($this->messages),
            ),
        ];
    }
}
