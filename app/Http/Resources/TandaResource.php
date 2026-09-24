<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TandaResource extends JsonResource
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
            'name' => $this->name,
            'description' => $this->description,
            'contribution_amount' => $this->contribution_amount,
            'num_members' => $this->num_members,
            'rounds_total' => $this->rounds_total,
            'pot_amount' => $this->pot_amount,
            'frequency' => $this->frequency,
            'start_date' => $this->start_date?->toDateString(),
            'current_round' => $this->current_round,
            'next_payment_date' => $this->next_payment_date?->toDateString(),
            'status' => $this->status,
            'progress_percent' => $this->progress_percent,
            'members' => TandaMemberResource::collection($this->whenLoaded('members')),
            'payments' => $this->whenLoaded('payments'),
        ];
    }
}
