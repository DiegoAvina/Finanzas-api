<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TandaMemberResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->guest_name ?? $this->user?->name ?? 'Miembro',
            'email' => $this->user?->email,
            'avatar_url' => $this->user?->avatar_url,
            'is_guest' => is_null($this->user_id),
            'pivot' => [
                'turn_order' => $this->turn_order,
                'has_received' => (bool) $this->has_received,
                'received_at' => $this->received_at?->toDateString(),
            ],
        ];
    }
}
